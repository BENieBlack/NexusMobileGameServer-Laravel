<?php

namespace App\Services;

use App\Exceptions\MasterDeployKeyException;
use App\Exceptions\MasterDeployRunException;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * QA 完了の印（final.json）を成果物に付ける（master:finalize。db.md 6.5.1・6.7 の R28。api.md 8 節。提案）
 *
 * - role = build の環境（QA）で使う。予約帯の成果物は reserved_key_forbidden、成果物の deploy_key が環境の T と違えば deploy_key_mismatch
 * - final.json が既にあるとき（追従検出より先に確認する）: deploy_key・hash が一致 → 何もせず already_finalized（成功・冪等）、一致しない・壊れている → finalize_conflict（上書きしない）
 * - 追従検出: 現在のシートを T で畳み直した row_digest を、マニフェストの row_digest_list と deploy_key ごとに比べる。違えば sheet_changed_since_build
 *   （allow_unverified では通せない）。シートを読めない・認証情報が無いときは follow_check_unavailable。allow_unverified なら警告 follow_check_unverified で通し、
 *   summary.follow_check = {status: unverified, reason}・summary.allow_unverified = true を実行記録に残す
 * - 印は manifest.json の後に別に書く（`{deploy_key, hash, finalized_at, finalized_by_env}`。hash に含めない）。書けなければ store_failed
 * - dry_run（verify-sheet と同じ追従検出）はロックを取らず、実行記録も書かず、何も書かない
 * - kind = finalize。phase は lock・check（追従検出）・register（final.json の書き込み）・done
 */
class MasterFinalizeRunner extends MasterDeployOperation
{
    /** @var Closure(int): list<array{deploy_key: int, digest: string}> */
    private readonly Closure $digestProvider;

    private readonly MasterDeployKeyPolicy $policy;

    /**
     * @param  Closure(int): list<array{deploy_key: int, digest: string}>|null  $digestProvider  追従検出のシート読み（既定は MasterBuildRunner::computeRowDigest）
     */
    public function __construct(
        ?Closure $digestProvider = null,
        ?MasterDeployKeyPolicy $policy = null,
        ?MasterDeployLock $lock = null,
        ?MasterVersionDatabase $databases = null,
        ?MasterCacheInvalidator $invalidator = null,
        ?MasterArtifactStore $store = null,
        ?MasterArtifactVerifier $verifier = null,
    ) {
        parent::__construct($lock, $databases, $invalidator, $store, null, null, $verifier);
        $this->policy = $policy ?? MasterDeployKeyPolicy::fromConfig();
        $this->digestProvider = $digestProvider ?? static fn (int $deployKey): array => (new MasterBuildRunner)->computeRowDigest($deployKey);
    }

    /**
     * @param  array{dry_run?: bool, allow_unverified?: bool, adm_account_id?: int|null}  $options
     * @return array<string, mixed>
     *
     * @throws MasterDeployRunException
     */
    public function run(string $artifactDir, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $allowUnverified = (bool) ($options['allow_unverified'] ?? false);
        $admAccountId = isset($options['adm_account_id']) ? (int) $options['adm_account_id'] : null;

        if (config('master_deploy.role') !== 'build') {
            throw new MasterDeployRunException(MasterDeployRunException::ROLE_FORBIDDEN, 'この環境（role='.(string) config('master_deploy.role').'）では finalize はできません（build の環境だけ）。', 403);
        }

        if ($dryRun) {
            $this->resetState();
            $manifest = $this->assertTarget($artifactDir);
            $check = $this->existingFinal($artifactDir, $manifest);
            if ($check === 'already') {
                return $this->alreadyFinalized(null, true);
            }
            $followCheck = $this->followCheck($manifest, $allowUnverified);

            return [
                'status' => 'success',
                'code' => null,
                'message' => 'dry_run: 追従検出だけを行いました（印は付けていません）。',
                'dry_run' => true,
                'run_id' => null,
                'follow_check' => $followCheck,
                'warning_list' => $this->unverifiedWarning($followCheck),
            ];
        }

        $manifestForKey = $this->store->manifest($artifactDir);

        return $this->runLocked('finalize', $admAccountId, $manifestForKey === null ? null : (int) ($manifestForKey['deploy_key'] ?? 0), function () use ($artifactDir, $allowUnverified): array {
            $this->phase('check');
            $manifest = $this->assertTarget($artifactDir);
            $this->updateRun([
                'deploy_key' => (int) $manifest['deploy_key'],
                'master_hash' => (string) $manifest['hash'],
                'artifact_dir' => $artifactDir,
            ], true);

            if ($this->existingFinal($artifactDir, $manifest) === 'already') {
                $this->summary['already_finalized'] = true;

                return ['response' => $this->alreadyFinalized($this->runId, false)];
            }

            $followCheck = $this->followCheck($manifest, $allowUnverified);
            $this->summary['follow_check'] = $followCheck;
            $this->summary['allow_unverified'] = $allowUnverified && $followCheck['status'] === 'unverified';

            $this->phase('register');
            $final = [
                'deploy_key' => (int) $manifest['deploy_key'],
                'hash' => (string) $manifest['hash'],
                'finalized_at' => $this->nowString(),
                'finalized_by_env' => (string) config('app.env'),
            ];
            try {
                $this->store->writeFile($artifactDir, 'final.json', json_encode($final, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            } catch (Throwable $e) {
                Log::error('final.json の書き込みに失敗', ['exception' => $e]);
                throw new MasterDeployRunException(MasterDeployRunException::STORE_FAILED, 'final.json の書き込みに失敗しました: '.MasterVersionDatabase::safeMessage($e), 500, previous: $e);
            }
            $warningList = $this->unverifiedWarning($followCheck);
            $this->summary['warning_list'] = $warningList;

            return ['response' => [
                'status' => 'success',
                'code' => 'finalized',
                'message' => 'QA 完了の印（final.json）を付けました。',
                'dry_run' => false,
                'follow_check' => $followCheck,
                'warning_list' => $warningList,
            ]];
        });
    }

    /**
     * 対象の成果物の検査（自環境のストレージにある・I1・予約帯でない・T と一致）。マニフェストを返す
     *
     * @return array<string, mixed>
     *
     * @throws MasterDeployRunException
     */
    private function assertTarget(string $artifactDir): array
    {
        try {
            $manifest = $this->verifier->verify($this->store->disk(), $artifactDir, $artifactDir, false);
        } catch (MasterDeployRunException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MasterDeployRunException(MasterDeployRunException::PULL_FAILED, '成果物の読み込みに失敗しました: '.MasterVersionDatabase::safeMessage($e), 502, previous: $e);
        }
        $deployKey = (int) $manifest['deploy_key'];
        if ($this->policy->isReservedBand($deployKey)) {
            throw new MasterDeployRunException(MasterDeployRunException::RESERVED_KEY_FORBIDDEN, "予約帯の成果物（deploy_key={$deployKey}）は finalize できません。", 403);
        }
        try {
            $target = $this->policy->resolveBuildKey();
        } catch (MasterDeployKeyException $e) {
            throw $this->fromKeyException($e);
        }
        if ($target['mode'] === 'fixed') {
            throw new MasterDeployRunException(MasterDeployRunException::RESERVED_KEY_FORBIDDEN, 'fixed_key の環境（予約キー）では finalize できません。', 403);
        }
        if ($target['deploy_key'] !== $deployKey) {
            throw new MasterDeployRunException(MasterDeployRunException::DEPLOY_KEY_MISMATCH, "成果物の deploy_key（{$deployKey}）が環境の T（{$target['deploy_key']}）と違います。", 422);
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return 'none'|'already'
     *
     * @throws MasterDeployRunException finalize_conflict
     */
    private function existingFinal(string $artifactDir, array $manifest): string
    {
        $path = $artifactDir.'/final.json';
        if (! $this->store->disk()->exists($path)) {
            return 'none';
        }
        if ($this->verifier->isFinal($this->store->disk(), $path, (int) $manifest['deploy_key'], (string) $manifest['hash'])) {
            return 'already';
        }

        throw new MasterDeployRunException(MasterDeployRunException::FINALIZE_CONFLICT, 'final.json が既にあり、この成果物と一致しません（上書きしません）。', 409);
    }

    /**
     * @return array<string, mixed>
     */
    private function alreadyFinalized(?int $runId, bool $dryRun): array
    {
        return [
            'status' => 'success',
            'code' => 'already_finalized',
            'message' => 'すでに QA 完了の印（final.json）があります。',
            'dry_run' => $dryRun,
            'run_id' => $runId,
            'follow_check' => null,
            'warning_list' => [],
        ];
    }

    /**
     * 追従検出。シートを読めない・認証情報が無いときは、allow_unverified でなければ follow_check_unavailable
     *
     * @param  array<string, mixed>  $manifest
     * @return array{status: 'match'|'changed'|'unverified', checked_at: string, artifact_dir: string, changed_deploy_key_list: list<int>, reason: string|null}
     *
     * @throws MasterDeployRunException
     */
    private function followCheck(array $manifest, bool $allowUnverified): array
    {
        $deployKey = (int) $manifest['deploy_key'];
        $artifactDir = $deployKey.'_'.substr((string) $manifest['hash'], 0, MasterVersionDatabase::HASH_LENGTH);
        $base = ['checked_at' => $this->nowString(), 'artifact_dir' => $artifactDir, 'changed_deploy_key_list' => [], 'reason' => null];

        try {
            $current = ($this->digestProvider)($deployKey);
        } catch (MasterDeployRunException $e) {
            if (! in_array($e->errorCode, [MasterDeployRunException::SHEET_NOT_CONFIGURED, MasterDeployRunException::SHEET_READ_FAILED], true)) {
                throw $e;
            }
            if (! $allowUnverified) {
                throw new MasterDeployRunException(
                    MasterDeployRunException::FOLLOW_CHECK_UNAVAILABLE,
                    'シートを読めないため、追従検出ができません。読める環境でやり直すか、allow_unverified で警告として通してください: '.$e->getMessage(),
                    422,
                    previous: $e,
                );
            }

            return [...$base, 'status' => 'unverified', 'reason' => $e->errorCode];
        }

        $expected = [];
        foreach ((array) ($manifest['row_digest_list'] ?? []) as $item) {
            $expected[(int) $item['deploy_key']] = (string) $item['digest'];
        }
        $actual = [];
        foreach ($current as $item) {
            $actual[$item['deploy_key']] = $item['digest'];
        }
        $changed = [];
        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                $changed[] = (int) $key;
            }
        }
        sort($changed);
        if ($changed !== []) {
            $this->summary['follow_check'] = [...$base, 'status' => 'changed', 'changed_deploy_key_list' => $changed];

            throw new MasterDeployRunException(
                MasterDeployRunException::SHEET_CHANGED_SINCE_BUILD,
                'build の後にシートが変わっています（deploy_key: '.implode(', ', $changed).'）。build をやり直して再度 QA してください。',
                409,
                array_map(static fn (int $key): array => ['deploy_key' => $key], $changed),
                null,
                ['changed_deploy_key_list' => $changed],
            );
        }

        return [...$base, 'status' => 'match'];
    }

    /**
     * @param  array<string, mixed>  $followCheck
     * @return list<array{code: string, message: string}>
     */
    private function unverifiedWarning(array $followCheck): array
    {
        return ($followCheck['status'] ?? null) === 'unverified'
            ? [['code' => 'follow_check_unverified', 'message' => 'シートを読めず追従検出ができないまま、allow_unverified で通しました（実行記録に残します）']]
            : [];
    }
}
