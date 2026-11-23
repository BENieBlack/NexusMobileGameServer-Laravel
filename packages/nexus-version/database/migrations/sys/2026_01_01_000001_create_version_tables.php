<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ========================================
        // sys_deploy_master: マスターデータデプロイ
        // ========================================
        Schema::create('sys_deploy_master', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('deploy_key')->comment('デプロイキー（YYYYMMDDNN 形式。予約キーは 7000000000 以上）');
            $table->string('hash', 64)->comment('成果物のSHA-256ハッシュ（完全な64文字。データ＋スキーマ版から作る。DB名・ディレクトリ名には先頭16文字を使う）');
            $table->date('deploy_date')->comment('デプロイ日');
            $table->unsignedBigInteger('deploy_count')->comment('その日の何回目のデプロイか');
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'failed', 'rolled_back'])
                ->default('scheduled')
                ->comment('デプロイステータス');
            $table->string('deployed_by')->nullable()->comment('デプロイ実行者');
            $table->dateTime('deployed_at')->nullable()->comment('実際のデプロイ実行日時');
            $table->text('description')->nullable()->comment('デプロイ内容の説明');
            $table->string('mst_database', 64)->comment('この版のmst DB名（APIが接続先を決める唯一の根拠）');
            $table->string('artifact_dir', 128)->comment('成果物のディレクトリ名（{deploy_key}_{hash}。導出せず保存する）');
            $table->string('schema_fingerprint', 64)->comment('この版DBの現在のスキーマ版');
            $table->string('source_kind', 16)->comment('登録の元 (build = 自環境で build, import = 上流から取り込み)');
            $table->dateTime('mst_dropped_at')->nullable()->comment('掃除で版のDBを消した日時（NULLでなければ切り替え不可）');
            $table->dateTime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('作成日時');
            $table->dateTime('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->comment('更新日時');

            // インデックス（(deploy_key, hash) の UNIQUE が deploy_key の前方一致検索を兼ねる）
            $table->unique(['deploy_key', 'hash'], 'uk_deploy_master_deploy_key_hash');
            $table->unique('mst_database', 'uk_deploy_master_mst_database');
            $table->index('deploy_date');
            $table->index('hash');
            $table->index('status');
            $table->index(['deploy_date', 'deploy_count']);
        });

        // ========================================
        // sys_deploy_asset: アセットデプロイ
        // ========================================
        Schema::create('sys_deploy_asset', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('deploy_key')->comment('デプロイキー（YYYYMMDDNN 形式。予約キーは 7000000000 以上）');
            $table->string('hash', 64)->comment('ファイル全体のSHA-256ハッシュ（バージョン識別用）');
            $table->date('deploy_date')->comment('デプロイ日');
            $table->unsignedBigInteger('deploy_count')->comment('その日の何回目のデプロイか');
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'failed', 'rolled_back'])
                ->default('scheduled')
                ->comment('デプロイステータス');
            $table->string('s3_bucket')->nullable()->comment('S3バケット名');
            $table->string('s3_path')->nullable()->comment('S3パス');
            $table->string('asset_version')->nullable()->comment('アセットバージョン');
            $table->bigInteger('total_size')->nullable()->comment('総アセットサイズ（バイト）');
            $table->integer('file_count')->nullable()->comment('アセットファイル数');
            $table->string('deployed_by')->nullable()->comment('デプロイ実行者');
            $table->dateTime('deployed_at')->nullable()->comment('実際のデプロイ実行日時');
            $table->text('description')->nullable()->comment('デプロイ内容の説明');
            $table->dateTime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('作成日時');
            $table->dateTime('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->comment('更新日時');

            // インデックス（UNIQUE は sys_deploy_master と同じ (deploy_key, hash)）
            $table->unique(['deploy_key', 'hash'], 'uk_deploy_asset_deploy_key_hash');
            $table->index('deploy_date');
            $table->index('hash');
            $table->index('status');
            $table->index(['deploy_date', 'deploy_count']);
            $table->index('asset_version');
        });

        // ========================================
        // sys_deploy: デプロイ統合テーブル
        // ========================================
        Schema::create('sys_deploy', function (Blueprint $table) {
            $table->id()->comment('デプロイID');
            $table->bigInteger('deploy_key')->comment('デプロイキー（畳みの上限Tとラベル。同じ値の行が複数ありうる。最新の選択には使わない）');
            $table->dateTime('start_at')->comment('いまの版になる日時（UTC。切り替えの唯一の時刻）');
            $table->unsignedBigInteger('sys_deploy_master_id')->comment('DLできるsys_deploy_masterのID');
            $table->unsignedBigInteger('sys_deploy_asset_id')->comment('DLできるsys_deploy_assetのID');
            $table->boolean('is_active')->default(true)->comment('有効フラグ（最新の選択で見る。緊急停止専用）');
            $table->dateTime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('作成日時');
            $table->dateTime('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->comment('更新日時');

            // インデックス
            $table->index('deploy_key');
            $table->index('start_at');
            $table->index('sys_deploy_master_id');
            $table->index('sys_deploy_asset_id');
            $table->index('is_active');
            $table->index(['is_active', 'start_at']);
        });

        Schema::create('sys_deploy_master_table', function (Blueprint $table) {
            $table->unsignedBigInteger('sys_deploy_master_id')->comment('sys_deploy_masterテーブルのID');
            $table->string('table_name', 128)->comment('SQLiteに含まれるテーブルグループ名');
            $table->string('hash', 64)->comment('テーブルグループSQLiteのSHA-256ハッシュ');
            $table->unsignedBigInteger('file_size')->comment('SQLiteファイルサイズ（バイト）');
            $table->string('file_name', 255)->comment('ダウンロード対象ファイル名');
            $table->string('public_url', 512)->comment('ダウンロードURL');
            $table->primary(['sys_deploy_master_id', 'table_name'], 'pk_deploy_master_table');
            $table->index('hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sys_deploy_master_table');
        Schema::dropIfExists('sys_deploy');
        Schema::dropIfExists('sys_deploy_asset');
        Schema::dropIfExists('sys_deploy_master');
    }
};
