<?php

namespace App\Repositories\Sys;

use App\Models\Sys\SysDeploy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Nexus\Core\Support\CustomCollection;
use Nexus\Core\Utilities\ClockUtility;

/**
 * SysDeployRepository
 *
 * デプロイ情報のRepository実装
 * 例外的に Redis キャッシュを使用
 *
 * @extends _BaseSysRepository<SysDeploy>
 */
class SysDeployRepository extends _BaseSysRepository
{
    protected string $modelClass = SysDeploy::class;

    /**
     * 「最新」を引くクエリを組み立てる
     *
     * is_active かつ start_at <= 現在のうち、start_at 降順 → deploy_key 降順 → id 降順の先頭。
     * 本番（APP_ENV=production）は予約帯を除く。
     * 設計: docs/design/master-distribution/db.md 3.3
     *
     * @return Builder<SysDeploy>
     */
    public function buildLatestDownloadableQuery(): Builder
    {
        return $this->modelClass::with(['deployMaster', 'deployAsset'])
            ->where('is_active', true)
            ->where('start_at', '<=', ClockUtility::now())
            ->latestOrder();
    }

    /**
     * 切り替え予定の一覧のキャッシュキー（sys:sys_deploy:schedule）
     */
    public function scheduleCacheKey(): string
    {
        return $this->buildCacheKey('schedule');
    }

    /**
     * 廃止した旧キー（sys:sys_deploy:latest_downloadable。最大 3600 秒 Redis に残る）
     */
    public function legacyLatestDownloadableCacheKey(): string
    {
        return $this->buildCacheKey('latest_downloadable');
    }

    /**
     * 切り替え予定の一覧（Redis キャッシュ付き）
     *
     * 「最新の 1 件」ではなく、一覧をキャッシュしてリクエスト時刻で毎回選ぶ（TTL が start_at をまたぐ問題の解き方。db.md 8.3）。
     * 中身は、作った時点の時刻 t0 に対して
     * - start_at > t0 のすべての行（予約済みの版）
     * - start_at <= t0 のうち「最新」の並びで先頭の 1 行（いまの版）
     * is_active と本番の予約帯の除外は、一覧を作るときに適用する（環境ごとに固定の条件）。
     * 旧キーは、一覧を作り直すたびに消す（切り替えの初回に Redis に残る旧キーの掃除。残っていても読まない）。
     *
     * @return list<SysDeploy>
     */
    public function selectSchedule(): array
    {
        /** @var list<SysDeploy> $schedule */
        $schedule = Cache::store($this->cacheDriver)->remember(
            $this->scheduleCacheKey(),
            (int) config('master_deploy.schedule_ttl'),
            function () {
                Cache::store($this->cacheDriver)->forget($this->legacyLatestDownloadableCacheKey());

                $future = $this->modelClass::with(['deployMaster', 'deployAsset'])
                    ->where('is_active', true)
                    ->where('start_at', '>', ClockUtility::now())
                    ->latestOrder()
                    ->get()
                    ->all();
                $current = $this->buildLatestDownloadableQuery()->first();

                return array_values($current !== null ? [...$future, $current] : $future);
            }
        );

        return $schedule;
    }

    /**
     * 切り替え予定の一覧のキャッシュを捨てる（止める・登録・巻き戻しの直後に使う）
     */
    public function forgetSchedule(): void
    {
        Cache::store($this->cacheDriver)->forget($this->scheduleCacheKey());
    }

    /**
     * 指定時刻の「最新」のデプロイを、切り替え予定の一覧から選ぶ
     *
     * start_at <= $at の行のうち、start_at 降順 → deploy_key 降順 → id 降順の先頭（db.md 3.3）。
     * 一覧に対して PHP で並びを適用するので、TTL の途中で start_at を過ぎても遅れない。
     */
    public function selectLatestDownloadableAt(CarbonInterface $at): ?SysDeploy
    {
        $atString = $at->format('Y-m-d H:i:s');

        $candidates = array_values(array_filter(
            $this->selectSchedule(),
            fn (SysDeploy $deploy): bool => (string) $deploy->getStartAt() <= $atString
        ));

        usort($candidates, fn (SysDeploy $a, SysDeploy $b): int => [(string) $b->getStartAt(), $b->getDeployKey(), $b->getKey()]
            <=> [(string) $a->getStartAt(), $a->getDeployKey(), $a->getKey()]);

        return $candidates[0] ?? null;
    }

    /**
     * 最新のダウンロード可能なデプロイを取得（現在時刻。切り替え予定の一覧から選ぶ）
     */
    public function selectLatestDownloadable(): ?SysDeploy
    {
        return $this->selectLatestDownloadableAt(ClockUtility::now());
    }

    /**
     * デプロイキーからデプロイを検索（Redis キャッシュ付き）
     */
    public function selectByDeployKey(int $deployKey): ?SysDeploy
    {
        $cacheKey = $this->buildCacheKey("deploy_key:{$deployKey}");

        return Cache::store($this->cacheDriver)->remember(
            $cacheKey,
            $this->cacheTtl,
            function () use ($deployKey) {
                $sysDeploy = $this->modelClass::with(['deployMaster', 'deployAsset'])
                    ->where('deploy_key', $deployKey)
                    ->orderByDesc('id')
                    ->first();

                // メモリキャッシュにも保存
                if ($sysDeploy !== null) {
                    $this->setModel($sysDeploy);
                }

                return $sysDeploy;
            }
        );
    }

    /**
     * 有効なデプロイ一覧を取得
     *
     * @return CustomCollection<array-key, SysDeploy>
     */
    public function selectActiveList(): CustomCollection
    {
        $records = $this->modelClass::where('is_active', true)
            ->orderBy('deploy_key', 'desc')
            ->get();

        return new CustomCollection($records->all());
    }

    /**
     * IDで検索（オーバーライドして Redis キャッシュ付き、リレーション付き）
     */
    public function selectById(int $sysDeployId): ?SysDeploy
    {
        $cacheKey = $this->buildCacheKey("id:{$sysDeployId}");

        return Cache::store($this->cacheDriver)->remember(
            $cacheKey,
            $this->cacheTtl,
            function () use ($sysDeployId) {
                $sysDeploy = $this->modelClass::with(['deployMaster', 'deployAsset'])
                    ->find($sysDeployId);

                // メモリキャッシュにも保存
                if ($sysDeploy !== null) {
                    $this->setModel($sysDeploy);
                }

                return $sysDeploy;
            }
        );
    }
}
