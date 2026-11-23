<?php

namespace App\Console\Commands;

use App\Services\MasterRollbackService;

/**
 * 戻したい版を指す新しい切り替え行を足す
 *
 * 設計: docs/design/master-distribution/api.md 9.1・10 節
 */
class MasterRollbackCommand extends MasterCommand
{
    protected $signature = 'master:rollback {sys_deploy_master_id : 戻し先の版（sys_deploy_master.id）} {--dry-run : 検査だけを行う}';

    protected $description = '戻したい版を指す新しい切り替え行を足す';

    public function handle(MasterRollbackService $runner): int
    {
        $id = $this->positiveInt('sys_deploy_master_id');
        if ($id === null) {
            return self::EXIT_INPUT;
        }

        return $this->runOperation(fn (): array => $runner->run($id, ['dry_run' => (bool) $this->option('dry-run')]));
    }
}
