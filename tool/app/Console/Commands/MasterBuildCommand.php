<?php

namespace App\Console\Commands;

use App\Services\MasterBuildRunner;

/**
 * シートから成果物と版の DB を作り、sys に登録する（build の環境）
 *
 * 設計: docs/design/master-distribution/api.md 6 節・10 節
 */
class MasterBuildCommand extends MasterCommand
{
    protected $signature = 'master:build {--dry-run : 検証だけを行う（DB・ストレージ・sys を変えない）} {--start-at= : 切り替え時刻（UTC の "Y-m-d H:i:s"。省略は「すぐ」）}';

    protected $description = 'シートから成果物と版の DB を作り、sys に登録する（build の環境）';

    public function handle(MasterBuildRunner $runner): int
    {
        $startAt = $this->parseStartAt($this->option('start-at'), false);
        if ($startAt === false) {
            return self::EXIT_INPUT;
        }

        return $this->runOperation(fn (): array => $runner->run([
            'dry_run' => (bool) $this->option('dry-run'),
            'start_at' => $startAt,
        ]));
    }
}
