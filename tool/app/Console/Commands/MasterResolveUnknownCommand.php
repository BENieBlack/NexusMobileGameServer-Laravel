<?php

namespace App\Console\Commands;

use App\Services\MasterResolveUnknownService;

/**
 * sys の登録の成否が不明だった run を、sys を再確認して片付ける
 *
 * 設計: docs/design/master-distribution/api.md 9.5・10 節
 */
class MasterResolveUnknownCommand extends MasterCommand
{
    protected $signature = 'master:resolve-unknown {run_id : register_unknown の実行記録（tol_master_deploy_run.id）}';

    protected $description = 'sys の登録の成否が不明だった run を、sys を再確認して片付ける';

    public function handle(MasterResolveUnknownService $runner): int
    {
        $id = $this->positiveInt('run_id');
        if ($id === null) {
            return self::EXIT_INPUT;
        }

        return $this->runOperation(fn (): array => $runner->run($id));
    }
}
