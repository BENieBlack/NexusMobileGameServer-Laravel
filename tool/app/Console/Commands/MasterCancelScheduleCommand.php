<?php

namespace App\Console\Commands;

use App\Services\MasterCancelScheduleService;

/**
 * 到達していない切り替え行（予約）を取り消す
 *
 * 設計: docs/design/master-distribution/api.md 9.2・10 節
 */
class MasterCancelScheduleCommand extends MasterCommand
{
    protected $signature = 'master:cancel-schedule {sys_deploy_id : 取り消す予約（sys_deploy.id）}';

    protected $description = '到達していない切り替え行（予約）を取り消す';

    public function handle(MasterCancelScheduleService $runner): int
    {
        $id = $this->positiveInt('sys_deploy_id');
        if ($id === null) {
            return self::EXIT_INPUT;
        }

        return $this->runOperation(fn (): array => $runner->run($id));
    }
}
