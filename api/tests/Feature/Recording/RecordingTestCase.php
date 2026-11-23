<?php

namespace Tests\Feature\Recording;

use App\Models\Mst\MstPlayerLevel;
use Database\Seeders\Recording\RecordingMasterExporter;
use Database\Seeders\Recording\RecordingSeeder;
use Database\Seeders\Recording\Scenarios\RecordingDeploySeeder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\RefreshMultipleDatabases;
use Tests\TestCase;

/**
 * 動画撮影用シーダーのテストの共通の土台。
 *
 * テスト全体をトランザクションで包み、終わると巻き戻す（既定の動作）。
 * テスト用 DB にある既存の行を壊さないため、件数の検査は「マーカー付きの行」か
 * 「前後の差」で行う。
 */
abstract class RecordingTestCase extends TestCase
{
    use RefreshMultipleDatabases;

    /** 配信ファイル（SQLite）の出力先。テストごとの一時ディレクトリで、実環境の tool/public/masterdata は触らない */
    protected string $masterDataDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masterDataDir = sys_get_temp_dir().'/recording_masterdata_'.uniqid('', true);
        mkdir($this->masterDataDir, 0755, true);
        config(['recording.masterdata_dir' => $this->masterDataDir]);
    }

    /** @var array<string, true> */
    private array $recordingDatabases = [];

    protected function tearDown(): void
    {
        foreach (array_keys($this->recordingDatabases) as $name) {
            $this->ddlConnection()->statement('DROP DATABASE IF EXISTS `'.$name.'`');
        }
        $this->removeDirectory($this->masterDataDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir($dir.'/'.$entry) ? $this->removeDirectory($dir.'/'.$entry) : unlink($dir.'/'.$entry);
        }
        rmdir($dir);
    }

    /**
     * mst_player_level と mst_config に最低 1 行入れる（空のテスト DB 向け）。
     * 空だと RecordingDeploySeeder が truncate するシーダーを呼び、暗黙コミットでテストの巻き戻しが効かなくなるため。
     */
    protected function ensureMasterRows(): void
    {
        $mst = DB::connection('mst');
        if ($mst->table('mst_player_level')->doesntExist()) {
            $mst->table('mst_player_level')->insert([
                'id' => MstPlayerLevel::makeId(1), 'level' => 1, 'deploy_key' => 2026010101, 'required_exp' => 0, 'max_stamina' => 50,
            ]);
        }
        if ($mst->table('mst_config')->doesntExist()) {
            $mst->table('mst_config')->insert(['deploy_key' => 2026010101, 'id' => 'test', 'value' => '1', 'value_type' => 'int', 'description' => 't']);
        }
    }

    /**
     * シーダーを流す。db:seed 経由なので command が付く（出力は捨てる）。
     *
     * @param  class-string  $seeder
     */
    protected function runSeeder(string $seeder): void
    {
        // RecordingSeeder（全シナリオ）も、中で RecordingDeploySeeder を流す
        if (in_array($seeder, [RecordingDeploySeeder::class, RecordingSeeder::class], true)) {
            $this->prepareRecordingDatabase();
        }

        $this->artisan('db:seed', ['--class' => $seeder, '--force' => true])->assertExitCode(0);
    }

    /**
     * DDL 用の接続（mst と同じ設定の別接続）。mst はテストのトランザクションの中で、CREATE / DROP DATABASE の
     * 暗黙コミットで巻き戻しが効かなくなるため、別の接続で流す
     */
    private function ddlConnection(): Connection
    {
        config(['database.connections.mst_ddl' => config('database.connections.mst')]);

        return DB::connection('mst_ddl');
    }

    /**
     * RecordingDeploySeeder が登録しようとする版の mst DB を、流す前に実在させる
     *
     * シーダーは版の DB が実在しないと何も書かずに中止する（R19 未決）。同じ mst から同じ hash の名前を
     * 一時ディレクトリへの書き出しで求め、空の DB を作る（テストの終わりに消す）。
     * 空の DB で足りるのは、シーダーが存在確認しか見ないため。
     */
    protected function prepareRecordingDatabase(): void
    {
        $seeder = new RecordingDeploySeeder;
        $tmp = $this->masterDataDir.'/_probe';
        mkdir($tmp, 0755, true);
        $hash = (new RecordingMasterExporter($tmp))->export($seeder->masterGroups())['hash'];
        $this->removeDirectory($tmp);

        $name = RecordingDeploySeeder::mstDatabaseName($hash);
        $this->ddlConnection()->statement('CREATE DATABASE IF NOT EXISTS `'.$name.'`');
        $this->recordingDatabases[$name] = true;
    }

    protected function countAgreements(bool $markedOnly): int
    {
        $query = DB::connection('sys')->table('sys_agreement');

        return $markedOnly ? $query->where('version', 'like', 'rec-%')->count() : $query->count();
    }

    protected function countMaintenance(bool $markedOnly): int
    {
        $query = DB::connection('sys')->table('sys_maintenance');

        return $markedOnly ? $query->where('title', 'like', '[REC]%')->count() : $query->count();
    }
}
