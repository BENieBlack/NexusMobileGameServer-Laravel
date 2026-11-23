<?php

namespace App\Repositories\Sys;

use App\Models\Sys\SysDeploy;
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
     * 最新のダウンロード可能なデプロイを取得（Redis キャッシュ付き）
     */
    public function selectLatestDownloadable(): ?SysDeploy
    {
        $cacheKey = $this->buildCacheKey('latest_downloadable');

        return Cache::store($this->cacheDriver)->remember(
            $cacheKey,
            $this->cacheTtl,
            function () {
                $sysDeploy = $this->buildLatestDownloadableQuery()->first();

                // メモリキャッシュにも保存
                if ($sysDeploy !== null) {
                    $this->setModel($sysDeploy);
                }

                return $sysDeploy;
            }
        );
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
