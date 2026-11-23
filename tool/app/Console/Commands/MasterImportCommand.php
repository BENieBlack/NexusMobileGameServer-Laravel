<?php

namespace App\Console\Commands;

use App\Services\MasterArtifactStore;
use App\Services\MasterImportRunner;

/**
 * 上流の成果物を版の DB に取り込み、sys に登録する（import の環境）
 *
 * 設計: docs/design/master-distribution/api.md 7 節・10 節
 */
class MasterImportCommand extends MasterCommand
{
    protected $signature = 'master:import {artifact_dir : 取り込む成果物（ディレクトリ名: deploy_key_hash16 の形）} {--start-at= : 切り替え時刻（UTC の "Y-m-d H:i:s"。必須。now で「すぐ」）} {--dry-run : 検査だけを行う}';

    protected $description = '上流の成果物を版の DB に取り込み、sys に登録する（import の環境）';

    public function handle(MasterImportRunner $runner): int
    {
        // start_at は必須（誤って即時にしないため。7 節）。now が「すぐ」
        if (! is_string($this->option('start-at')) || $this->option('start-at') === '') {
            $this->line('[失敗] invalid_input: --start-at は必須です（UTC の "Y-m-d H:i:s"、または now で「すぐ」）。');

            return self::EXIT_INPUT;
        }
        $startAt = $this->parseStartAt($this->option('start-at'), true);
        if ($startAt === false) {
            return self::EXIT_INPUT;
        }
        $artifactDir = (string) $this->argument('artifact_dir');
        if (preg_match(MasterArtifactStore::DIR_PATTERN, $artifactDir) !== 1) {
            $this->line("[失敗] invalid_input: artifact_dir の形が不正です: {$artifactDir}");

            return self::EXIT_INPUT;
        }

        return $this->runOperation(fn (): array => $runner->run($artifactDir, [
            'dry_run' => (bool) $this->option('dry-run'),
            'start_at' => $startAt,
        ]));
    }
}
