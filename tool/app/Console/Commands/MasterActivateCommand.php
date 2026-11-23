<?php

namespace App\Console\Commands;

use App\Services\MasterActivationService;

/**
 * 再開（切り替え行の is_active を戻す。掃除済みの版は成果物から復元する）
 *
 * 設計: docs/design/master-distribution/api.md 9.6・10 節
 */
class MasterActivateCommand extends MasterCommand
{
    protected $signature = 'master:activate {sys_deploy_id : 対象の切り替え行（sys_deploy.id）}';

    protected $description = '再開（切り替え行の is_active を戻す。掃除済みの版は成果物から復元する）';

    public function handle(MasterActivationService $runner): int
    {
        $id = $this->positiveInt('sys_deploy_id');
        if ($id === null) {
            return self::EXIT_INPUT;
        }

        return $this->runOperation(fn (): array => $runner->activate($id));
    }
}
