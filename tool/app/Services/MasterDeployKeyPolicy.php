<?php

namespace App\Services;

use App\Exceptions\MasterDeployKeyException;

/**
 * deploy_key の固定キー・予約帯・本番ガードの検査（DB を読まない）
 *
 * 設計: docs/design/master-distribution/db.md 7.1〜7.3、3.4 の 4
 * 設定は config/master_deploy.php。デプロイ操作の最初（排他の前）で resolveFixedKey() を呼ぶ想定（接続は別 PR）。
 * 公開メソッドのうち assertRealKey は S3-10（採番 MasterDeployService）、isRejectedReservedKey・assertNotRejectedReservedKey は
 * シート検証（MasterSheetValidator の V04）、resolveFixedKey は S3-10 の MasterDeployRunner・S3-13 の target 表示で使う。
 * 禁止環境の判定は APP_ENV を trim・小文字化して比べる。forbidden_envs に無い未知の env（local・testing・qa など）では固定キーが通る方針（db.md 7.2）。
 */
class MasterDeployKeyPolicy
{
    /**
     * @param  list<int>  $allowedFixedKeys
     * @param  list<string>  $forbiddenEnvs
     */
    public function __construct(
        private readonly mixed $fixedKey,
        private readonly array $allowedFixedKeys,
        private readonly array $forbiddenEnvs,
        private readonly int $reservedFloor,
        private readonly string $appEnv,
        private readonly mixed $explicitKey = null,
    ) {}

    /**
     * 環境変数の値を config 用に変える。空は null、先頭ゼロなしの数字（int に収まるもの）は int、
     * それ以外（不正な文字列・先頭ゼロ・桁あふれ）は文字列のまま返し、resolveFixedKey が fixed_key_invalid で拒否する。
     */
    public static function parseFixedKeyEnv(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[1-9]\d*$/', $value) === 1 && (string) (int) $value === $value) {
            return (int) $value;
        }

        return $value;
    }

    public static function fromConfig(): self
    {
        return new self(
            config('master_deploy.fixed_key'),
            array_map('intval', (array) config('master_deploy.allowed_fixed_keys')),
            array_map(fn ($e) => strtolower(trim((string) $e)), (array) config('master_deploy.fixed_key_forbidden_envs')),
            (int) config('master_deploy.reserved_floor'),
            strtolower(trim((string) config('app.env'))),
            config('master_deploy.explicit_key'),
        );
    }

    /**
     * 固定キーを検証して返す。未設定なら null（本物の採番）。
     *
     * @throws MasterDeployKeyException 許可リスト外は fixed_key_invalid、禁止環境で設定ありは fixed_key_forbidden
     */
    public function resolveFixedKey(): ?int
    {
        if ($this->fixedKey === null) {
            return null;
        }
        if (! is_int($this->fixedKey) || ! in_array($this->fixedKey, $this->allowedFixedKeys, true)) {
            throw MasterDeployKeyException::fixedKeyInvalid($this->fixedKey);
        }
        if (in_array(strtolower(trim($this->appEnv)), array_map(fn ($e) => strtolower(trim($e)), $this->forbiddenEnvs), true)) {
            throw MasterDeployKeyException::fixedKeyForbidden($this->appEnv);
        }

        return $this->fixedKey;
    }

    /**
     * build の T を決める（db.md 7.2）。fixed_key（予約キー）か explicit_key（本物の形 YYYYMMDDNN）のどちらか 1 つが必須。
     * 排他の前に呼ぶ。fixed_key の検査（許可リスト・本番ガード）は resolveFixedKey に任せる。
     *
     * @return array{mode: 'fixed'|'explicit', deploy_key: int}
     *
     * @throws MasterDeployKeyException fixed_key_invalid・fixed_key_forbidden・deploy_key_not_configured・reserved_key_forbidden
     */
    public function resolveBuildKey(): array
    {
        $fixed = $this->resolveFixedKey();
        $hasExplicit = $this->explicitKey !== null;
        if ($fixed !== null && $hasExplicit) {
            throw MasterDeployKeyException::deployKeyNotConfigured('fixed_key と explicit_key が両方あります');
        }
        if ($fixed !== null) {
            return ['mode' => 'fixed', 'deploy_key' => $fixed];
        }
        if (! $hasExplicit) {
            throw MasterDeployKeyException::deployKeyNotConfigured('fixed_key も explicit_key もありません');
        }
        if (! is_int($this->explicitKey)) {
            throw MasterDeployKeyException::deployKeyNotConfigured('explicit_key が数値ではありません');
        }
        $this->assertRealKey($this->explicitKey);
        if (! self::isRealKeyShape($this->explicitKey)) {
            throw MasterDeployKeyException::deployKeyNotConfigured('explicit_key が YYYYMMDDNN の形ではありません');
        }

        return ['mode' => 'explicit', 'deploy_key' => $this->explicitKey];
    }

    /**
     * 本物の形 YYYYMMDDNN か（2026010101〜2999123199。NN は 01〜99。月日は暦として正しいこと）
     */
    public static function isRealKeyShape(int $key): bool
    {
        if ($key < 2026010101 || $key > 2999123199) {
            return false;
        }
        $nn = $key % 100;
        $date = intdiv($key, 100);
        $year = intdiv($date, 10000);
        $month = intdiv($date % 10000, 100);
        $day = $date % 100;

        return $nn >= 1 && checkdate($month, $day, $year);
    }

    /**
     * 本物のモードで採番した結果が予約帯なら拒否する（3.4 の 4）。
     *
     * @throws MasterDeployKeyException reserved_key_forbidden
     */
    public function assertRealKey(int $key): void
    {
        if ($key >= $this->reservedFloor) {
            throw MasterDeployKeyException::reservedKeyForbidden($key);
        }
    }

    public function isReservedBand(int $key): bool
    {
        return $key >= $this->reservedFloor;
    }

    /**
     * 予約帯のうち許可リストの固定キー以外か（V04。`7777777778` のような誤記）。
     * 予約帯でない値は false。
     */
    public function isRejectedReservedKey(int $key): bool
    {
        return $this->isReservedBand($key) && ! in_array($key, $this->allowedFixedKeys, true);
    }

    /**
     * @throws MasterDeployKeyException reserved_key_forbidden
     */
    public function assertNotRejectedReservedKey(int $key): void
    {
        if ($this->isRejectedReservedKey($key)) {
            throw MasterDeployKeyException::reservedKeyForbidden($key);
        }
    }
}
