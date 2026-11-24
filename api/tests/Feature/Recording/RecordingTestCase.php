<?php

namespace Tests\Feature\Recording;

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

    /** 成果物の置き場。テストごとの一時ディレクトリで、実環境の tool/public/masterdata は触らない */
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
     * シーダーを流す。db:seed 経由なので command が付く（出力は捨てる）。
     *
     * @param  class-string  $seeder
     */
    protected function runSeeder(string $seeder): void
    {
        // RecordingSeeder（全シナリオ）も、中で RecordingDeploySeeder を流す。録画用の版が無ければ用意する
        if (in_array($seeder, [RecordingDeploySeeder::class, RecordingSeeder::class], true) && RecordingDeploySeeder::findLatestArtifact() === null) {
            $this->prepareRecordingVersion();
        }

        $this->artisan('db:seed', ['--class' => $seeder, '--force' => true])->assertExitCode(0);
    }

    /**
     * DDL 用の接続（mst と同じ設定の別接続）。mst はテストのトランザクションの中で、CREATE / DROP DATABASE の
     * 暗黙コミットで巻き戻しが効かなくなるため、別の接続で流す
     */
    protected function ddlConnection(): Connection
    {
        config(['database.connections.mst_ddl' => config('database.connections.mst')]);

        return DB::connection('mst_ddl');
    }

    /**
     * build が置いた録画用の版を模して用意する: 成果物 `7777777777_{hash16}`（manifest.json と配信 SQLite の代わりのファイル）を
     * 一時ディレクトリに置き、版の mst DB（空）を作る（テストの終わりに消す）。
     * シーダーは成果物の manifest.json と DB の実在しか見ないので、DB の中身は空でよい。
     *
     * @param  string|null  $hash  64 文字の 16 進。省略すると毎回別の値（実 DB 名の衝突を避ける）
     * @param  bool  $withDatabase  false なら成果物だけ置く（版の DB が無い状態）
     * @return array{hash: string, dir: string, database: string}
     */
    protected function prepareRecordingVersion(?string $hash = null, string $builtAt = '2026-11-24 00:00:00', bool $withDatabase = true): array
    {
        $hash ??= bin2hex(random_bytes(32));
        $dir = RecordingDeploySeeder::DEPLOY_KEY.'_'.substr($hash, 0, 16);
        mkdir($this->masterDataDir.'/'.$dir, 0755, true);
        $groups = [];
        foreach (['mst_config', 'mst_player_level'] as $group) {
            $fileName = $group.'_'.substr(hash('sha256', $hash.$group), 0, 16).'.sqlite';
            file_put_contents($this->masterDataDir.'/'.$dir.'/'.$fileName, 'x');
            $groups[] = ['group' => $group, 'file_name' => $fileName, 'sqlite_sha256' => hash('sha256', $hash.$group), 'file_size' => 1];
        }
        file_put_contents($this->masterDataDir.'/'.$dir.'/manifest.json', json_encode([
            'format_version' => 1,
            'deploy_key' => RecordingDeploySeeder::DEPLOY_KEY,
            'hash' => $hash,
            'schema_fingerprint' => hash('sha256', 'schema'),
            'group_list' => $groups,
            'built_env' => 'testing',
            'built_at' => $builtAt,
            'source_kind' => 'build',
        ], JSON_PRETTY_PRINT));

        $name = RecordingDeploySeeder::mstDatabaseName($hash);
        if ($withDatabase) {
            $this->ddlConnection()->statement('CREATE DATABASE IF NOT EXISTS `'.$name.'`');
            $this->recordingDatabases[$name] = true;
        }

        return ['hash' => $hash, 'dir' => $dir, 'database' => $name];
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
