<?php

namespace App\Console\Commands;

use App\Services\MasterArtifactStore;
use App\Services\MasterFinalizeRunner;

/**
 * 成果物が現在のシートに追従しているかを確かめる（読み取り専用。finalize --dry-run と同じ処理）
 *
 * 設計: docs/design/master-distribution/api.md 10 節（8 節の追従検出）
 */
class MasterVerifySheetCommand extends MasterCommand
{
    protected $signature = 'master:verify-sheet {artifact_dir : 確認する成果物}';

    protected $description = '成果物が現在のシートに追従しているかを確かめる（読み取り専用。finalize --dry-run と同じ処理）';

    public function handle(MasterFinalizeRunner $runner): int
    {
        $artifactDir = (string) $this->argument('artifact_dir');
        if (preg_match(MasterArtifactStore::DIR_PATTERN, $artifactDir) !== 1) {
            $this->line("[失敗] invalid_input: artifact_dir の形が不正です: {$artifactDir}");

            return self::EXIT_INPUT;
        }

        return $this->runOperation(fn (): array => $runner->run($artifactDir, ['dry_run' => true]));
    }
}
