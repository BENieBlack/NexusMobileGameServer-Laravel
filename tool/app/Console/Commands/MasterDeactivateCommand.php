<?php

namespace App\Console\Commands;

use App\Services\MasterActivationService;

/**
 * 緊急停止（切り替え行の is_active を落とす）
 *
 * 設計: docs/design/master-distribution/api.md 9.6・10 節
 */
class MasterDeactivateCommand extends MasterCommand
{
    protected $signature = 'master:deactivate {sys_deploy_id : 対象の切り替え行（sys_deploy.id）}';

    protected $description = '緊急停止（切り替え行の is_active を落とす）';

    public function handle(MasterActivationService $runner): int
    {
        $id = $this->positiveInt('sys_deploy_id');
        if ($id === null) {
            return self::EXIT_INPUT;
        }

        return $this->runOperation(fn (): array => $runner->deactivate($id));
    }
}
