<?php

namespace Database\Seeders\Recording\Scenarios;

use Database\Seeders\Recording\RecordingScenarioSeeder;
use Illuminate\Support\Facades\DB;
use Nexus\Core\Models\Mst\_BaseMst;
use Nexus\Core\Utilities\ClockUtility;

/**
 * RecordingResetSeeder
 *
 * 目的: 動画用に入れたものを元に戻す。既定の一覧には入れない。
 * 1. マーカー付きの規約（version が `rec-` で始まる）を削除する
 * 2. マーカー付きのメンテナンス（title が `[REC]` で始まる）を削除する（解除を含む）
 * 3. 録画用のデプロイ（deploy_key = 7777777777 の sys_deploy・sys_deploy_master・sys_deploy_asset・配信ファイルの一覧と、
 *    成果物 `7777777777_*`）を削除する。build で作った行には `[REC]` が付かないので deploy_key で引く（仮置き。db.md 11.2）。
 *    版の mst DB は消さない（db.md 4.6 の掃除に任せる）
 * 4. daily_login を有効に戻す（無効化シナリオの後始末）
 * 5. メンテナンス・デプロイのキャッシュを消す（即時に反映するため）
 *
 * 使うケース（client_design.md 7節）: 「規約なし」を撮り直す前（1・2）、撮影の終わり。
 *
 * 冪等性: 何度流しても同じ結果（対象が無ければ何もしない）。
 * 副作用: マーカーの無い行は消さない。ただし daily_login はマーカーが無いまま is_active=true に更新する（例外）。
 * それ以外のマーカーの無い行は消さない（運営・基本シードのデータは残る）。
 * 同意済みの記録（trx_agreement）は触らない。規約を消した後は孤立した記録が残るが、
 * 次に同じ version を入れ直しても ID が変わるため、新規ユーザーの撮影には影響しない。
 * 本物のデプロイが無い環境では、デプロイを消すと version API が失敗する。sys_deploy を消すと前の版が最新に戻る。
 * 本物のデプロイ（別の deploy_key）の行・成果物・is_active は触らない。
 */
class RecordingResetSeeder extends RecordingScenarioSeeder
{
    protected function apply(): void
    {
        $sys = DB::connection('sys');

        $sys->table('sys_agreement')
            ->where('version', 'like', self::AGREEMENT_VERSION_PREFIX.'%')
            ->delete();

        $sys->table('sys_maintenance')
            ->where('title', 'like', self::MAINTENANCE_TITLE_PREFIX.'%')
            ->delete();

        $deletedDeployIds = $this->deleteRecordingDeploys();

        // 無効化していなくても害は無い（有効のままにするだけ）。行が無ければ何も起きない
        _BaseMst::allowWrites(function (): void {
            DB::connection('mst')->table('mst_login_bonus')
                ->where('id', self::DAILY_LOGIN_BONUS_ID)
                ->update(['is_active' => true, 'updated_at' => ClockUtility::nowToString()]);
        });

        $this->clearApiCaches($deletedDeployIds);

        $this->report('動画用のデータを削除し、daily_login を有効に戻しました。');
    }

    /**
     * 録画用（deploy_key = 7777777777）の sys_deploy・sys_deploy_master・sys_deploy_asset・配信ファイルの一覧と、
     * 成果物 `7777777777_*` を削除する（仮置き。db.md 11.2。build の行には `[REC]` が付かないので deploy_key で引く）。
     * 版の mst DB は消さない（db.md 4.6 の掃除に任せる）。sys_deploy を消すと前の版が最新に戻る。
     * sys_deploy は外部キーが無いので、先に ID を控えてから消す。
     * 別のキーの sys_deploy が指している master / asset は、録画用のキーでも消さない（外部キーが無く、共有されうる）。
     *
     * @return list<int> 消した sys_deploy.id（キャッシュの破棄用）
     */
    private function deleteRecordingDeploys(): array
    {
        $sys = DB::connection('sys');
        $key = RecordingDeploySeeder::DEPLOY_KEY;
        $realDeploys = $sys->table('sys_deploy')->where('deploy_key', '!=', $key);

        $masterIds = $sys->table('sys_deploy_master')->where('deploy_key', $key)
            ->whereNotIn('id', (clone $realDeploys)->select('sys_deploy_master_id'))->pluck('id')->all();
        $assetIds = $sys->table('sys_deploy_asset')->where('deploy_key', $key)
            ->whereNotIn('id', (clone $realDeploys)->select('sys_deploy_asset_id'))->pluck('id')->all();

        // 残した master の成果物は消さない（別キーの版が指しているため）
        $keptArtifactDirs = $sys->table('sys_deploy_master')->where('deploy_key', $key)
            ->whereNotIn('id', $masterIds)->pluck('artifact_dir')->filter()->all();

        $deployQuery = $sys->table('sys_deploy')->where('deploy_key', $key);
        $deletedDeployIds = array_map('intval', (clone $deployQuery)->pluck('id')->all());
        $deployQuery->delete();
        $sys->table('sys_deploy_master_table')->whereIn('sys_deploy_master_id', $masterIds)->delete();
        $sys->table('sys_deploy_master')->whereIn('id', $masterIds)->delete();
        $sys->table('sys_deploy_asset')->whereIn('id', $assetIds)->delete();

        // 成果物。ディレクトリが無い環境では何もしない
        foreach (RecordingDeploySeeder::artifactDirNames() as $dir) {
            if (in_array($dir, $keptArtifactDirs, true)) {
                continue;
            }
            $this->removeDirectory(RecordingDeploySeeder::masterDataDir().'/'.$dir);
        }

        return $deletedDeployIds;
    }

    private function removeDirectory(string $dir): void
    {
        // シンボリックリンクは中身を辿らず、リンクだけ除く
        if (is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
