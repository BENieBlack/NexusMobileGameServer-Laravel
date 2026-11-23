<?php

namespace App\Console\Commands;

use App\Services\MasterCleanupService;

/**
 * 古い版の DB・孤児・一時の場所を掃除する（既定は dry run）
 *
 * 設計: docs/design/master-distribution/api.md 9.3・10 節
 */
class MasterCleanupCommand extends MasterCommand
{
    protected $signature = 'master:cleanup {--execute : 実際に消す（付けないと対象の一覧だけ）}';

    protected $description = '古い版の DB・孤児・一時の場所を掃除する（既定は dry run）';

    public function handle(MasterCleanupService $runner): int
    {
        return $this->runOperation(fn (): array => $runner->run(['dry_run' => ! $this->option('execute')]));
    }
}
