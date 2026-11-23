<?php

namespace App\Console\Commands;

use App\Services\MasterArtifactStore;
use App\Services\MasterFinalizeRunner;

/**
 * 成果物の追従検出を行い、QA 完了の印（final.json）を付ける（build の環境）
 *
 * 設計: docs/design/master-distribution/api.md 8 節・10 節
 */
class MasterFinalizeCommand extends MasterCommand
{
    protected $signature = 'master:finalize {artifact_dir : QA 完了にする成果物} {--dry-run : 追従検出だけを行う（印を付けない）} {--allow-unverified : シートを読めないときだけ、警告つきで通す}';

    protected $description = '成果物の追従検出を行い、QA 完了の印（final.json）を付ける（build の環境）';

    public function handle(MasterFinalizeRunner $runner): int
    {
        $artifactDir = (string) $this->argument('artifact_dir');
        if (preg_match(MasterArtifactStore::DIR_PATTERN, $artifactDir) !== 1) {
            $this->line("[失敗] invalid_input: artifact_dir の形が不正です: {$artifactDir}");

            return self::EXIT_INPUT;
        }

        return $this->runOperation(fn (): array => $runner->run($artifactDir, [
            'dry_run' => (bool) $this->option('dry-run'),
            'allow_unverified' => (bool) $this->option('allow-unverified'),
        ]));
    }
}
