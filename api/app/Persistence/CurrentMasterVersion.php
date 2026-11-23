<?php

namespace App\Persistence;

use App\Exceptions\SystemDataException;
use App\Models\Sys\SysDeploy;
use App\Repositories\Sys\SysDeployRepository;
use Illuminate\Support\Facades\DB;
use Nexus\Core\Utilities\ClockUtility;

/**
 * CurrentMasterVersion
 *
 * リクエストの冒頭で「いまの版」（start_at 基準の最新の sys_deploy）を 1 つに固定するサービス。
 * scoped 束縛（リクエスト単位）。設計: docs/design/master-distribution/db.md 3.3・8.1〜8.3
 *
 * - 最初に current() を呼んだ時刻で版を決め、以後そのリクエストの間は同じ版を返す。
 *   途中で start_at を過ぎても切り替わらないので、レスポンスの版と CheckMasterHash が比べる版がずれない。
 * - 版は切り替え予定の一覧（SysDeployRepository::selectSchedule）からリクエスト時刻で選ぶ。
 * - applyMstConnection() が、mst 接続の database をその版の mst_database に差し替える。
 *   接続名は mst のまま（モデル・Repository の $connection は変えない）。
 *
 * 常駐プロセス（Octane・ワーカー）は今は無い。入れるときは、リクエスト・ジョブの単位で
 * 「解決 → purge → 終了時に戻す」を足す（db.md 8.2）。CLI（シーダー・artisan・テスト）は
 * ミドルウェアを通らないので、明示的に呼ばない限り基底 DB のまま。
 */
class CurrentMasterVersion
{
    private bool $resolved = false;

    private ?SysDeploy $deploy = null;

    public function __construct(
        private readonly SysDeployRepository $sysDeployRepository,
    ) {}

    /**
     * いまの版を返す（無ければ null）。初回の呼び出しで固定する
     *
     * 時刻は、リクエスト開始時刻が ApiSession に載っていればそれ、まだ（認証より前のミドルウェアなど）なら
     * ClockUtility::now()（同じ時計。ApiSession の時刻も ClockUtility::now() で作られる）。
     */
    public function current(): ?SysDeploy
    {
        if (! $this->resolved) {
            $now = ApiSession::hasNow() ? ApiSession::getNow() : ClockUtility::now();
            $this->deploy = $this->sysDeployRepository->selectLatestDownloadableAt($now);
            $this->resolved = true;
        }

        return $this->deploy;
    }

    /**
     * いまの版を返す。無ければ 299 / SystemDataException::deploy()
     *
     * 「いまの版が無い」は、start_at が未来のものしか無い・本番で予約帯しか無い・is_active が全部偽のとき。
     * 基底 DB にフォールバックしない（本番で空の基底 DB を読まないため。db.md 8.1）。
     *
     * @throws SystemDataException
     */
    public function currentOrFail(): SysDeploy
    {
        return $this->current() ?? throw SystemDataException::deploy();
    }

    /**
     * mst 接続の database を、いまの版の mst_database に差し替える
     *
     * 既に同じ database に向いているときは何もしない（purge すると、同じ DB を指す接続の
     * トランザクションまで捨ててしまう。テストの基底 DB の版がこれ）。
     * 選んだ版の DB が掃除済み（mst_dropped_at あり）なら、古い版に黙って落とさず 299 にする。
     *
     * @throws SystemDataException
     */
    public function applyMstConnection(): void
    {
        $deploy = $this->currentOrFail();
        $master = $deploy->deployMaster;

        if ($master === null || $master->isMstDropped()) {
            throw SystemDataException::deploy((int) $deploy->getKey());
        }

        $database = $master->getMstDatabase();

        if ($database === null || $database === '') {
            throw SystemDataException::deploy((int) $deploy->getKey());
        }

        if (config('database.connections.mst.database') === $database) {
            return;
        }

        config(['database.connections.mst.database' => $database]);

        // まだ接続していなければ何もしない。接続済みなら切って、次の利用で新しい database に繋ぎ直す
        DB::purge('mst');
    }
}
