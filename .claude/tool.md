# 運営ツール（tool/）

運営ツールの画面・DB・マスターデータのデプロイに手を入れるときに読む。
コンテナ・テストの回し方は [development.md](./development.md)、マスターデータがクライアントに届くまでの流れは [api.md](./api.md)。

## 構成

- `tool/` は api とは別の Laravel アプリで、composer の依存も別。api のクラスや packages の Repository は使わず、`DB::connection()` で各DBを直接読み書きする
- URL は http://localhost:8091（tool-web / tool-php）。ルートは `tool/routes/web.php` だけ
- 画面は Inertia + Vue（`resources/js/Pages/`）と Blade（`resources/views/`。管理者管理とプレイヤー画面）が混在している
- `app/Console/Commands/` に `master:*` コマンドがある（下の「マスターデータの build・import・切り替え」）
- ログインはセッション認証で、ユーザーモデルは `App\Models\Adm\AdmAccount`（`config/auth.php`）。`AdminAccountSeeder` が `admin@example.com` / `admin` を作る

## DB 接続

`tool/config/database.php`。

| 接続名 | ホスト | 用途 |
|---|---|---|
| `admin` | db-admin（`-admin`） | `adm_*`。既定の接続 |
| `tool` | db-tool（`-tool`） | `tol_*` |
| `sys` | db-system | `sys_player` の参照、`sys_deploy*` への登録・切り替え、`MasterDeployLock`（`GET_LOCK`） |
| `mst` | db-master | **基底 DB**（`{APP_NAME}-{APP_ENV}-master`。版の DB の雛形）。版の DB（`{基底}-{deploy_key}-{hash16}`）・作業用 DB は、この接続のサーバー上に作る |
| `trx` / `trx2` / `trx3` | db-shard1〜3 | プレイヤーのログ参照、ダッシュボードの集計 |

- 既定接続は `TOOL_DB_CONNECTION`（既定 `admin`）。共有の `.env` にある `DB_CONNECTION=sqlite` は読まない
- シャード1の接続名は api 側の `trx1` と違い `trx`
- Eloquent モデルは `app/Models/Adm/` の4つ（AdmAccount, AdmRole, AdmPage, AdmPermission）だけ。tol と他DBは `DB::connection()` で扱う

## マイグレーション

- `tool/database/migrations/adm/` と `tol/`。`Schema::connection('admin')` / `Schema::connection('tool')` で接続を固定する
- `make migrate` には含まれない。次で流す

```bash
docker compose exec -T tool-php php artisan migrate --path=database/migrations/adm --database=admin --force
docker compose exec -T tool-php php artisan migrate --path=database/migrations/tol --database=tool --force
```

| DB | テーブル |
|---|---|
| admin | `adm_account`, `adm_role`, `adm_page`, `adm_permission`, `adm_account_role`, `sessions`, Laravel 標準（cache, cache_locks, jobs, job_batches, failed_jobs） |
| tool | `tol_master_status`, `tol_asset_status`, `tol_banner`, `tol_cache_control`, `tol_maintenance`, `tol_notice`, `tol_dashboard_retention`, `tol_dashboard_access_cache`, Laravel 標準 |

コードから使っている `tol_*` は `tol_dashboard_retention` と `tol_dashboard_access_cache` だけ。他の `tol_*` はテーブルだけあり、画面も処理も無い。

## 主な機能

### マスターデータの build・import・切り替え（`/master-import`）

版ごとの mst DB 設計（ADR 0007）。設計の正本は `docs/design/master-distribution/`（`api.md` が HTTP・CLI・エラー表、`db.md` が DB・成果物・登録）。`MasterImportController` は薄い層で、操作は次のクラスが行う（`app/Services/`）。

| 操作 | クラス | 環境（`MASTER_DEPLOY_ROLE`） |
| --- | --- | --- |
| build（シート → 成果物と版の DB → sys に登録） | `MasterBuildRunner` | build（develop・LD・QA） |
| import（上流の成果物 → 版の DB → sys に登録） | `MasterImportRunner` | import（review・production・staging） |
| finalize（追従検出 → `final.json`） | `MasterFinalizeRunner` | build |
| rollback・cancel-schedule・cleanup・deactivate / activate・resolve-unknown | `MasterRollbackService`・`MasterCancelScheduleService`・`MasterCleanupService`・`MasterActivationService`・`MasterResolveUnknownService`（共通部分は `MasterDeployOperation`） | どちらも可 |
| target・artifact-list（読み取りだけ） | `MasterDeployTargetService` | どちらも可 |

- 順序と排他: 環境設定の検査（role・T・本番ガード）→ `GET_LOCK`（待たない。取れなければ `deploy_locked`）→ 実行記録 `tol_master_deploy_run` → 本処理。登録は sys の 1 トランザクション。失敗したときはその run が新規に作った DB だけ `DROP` する。`dry_run` は実行記録を書かない（import の dry_run だけロックを取る）
- T（畳みの上限）はリクエストにも CLI にも渡さない。`MASTER_DEPLOY_FIXED_KEY`（予約キー）か `MASTER_DEPLOY_KEY`（本物の形）のどちらか 1 つで決める
- 切り替えは `sys_deploy.start_at`（UTC）。ゲーム API は `start_at` 基準で版の DB に切り替わる。`is_active` を落とすのは `deactivate`（緊急停止）だけ
- 成果物は `master_artifact` disk（ローカルは `tool/public/masterdata/{deploy_key}_{hash16}/`、git に入れない）。import の上流は `master_artifact_upstream` disk（`MASTER_ARTIFACT_UPSTREAM_ROOT`）
- 時間のかかる HTTP（build・import など）は nginx の既定 60 秒を超えると 504 になるが、処理は `ignore_user_abort` で最後まで続く。結果は `target` の `last_run`（と実行記録）で見る
- キャッシュ: 操作の後に `sys:sys_deploy:schedule` だけを `forget` する（`MasterCacheInvalidator`）。mst のキャッシュは版つきキーなので破棄しない。失敗しても操作は成功のまま警告 `schedule_cache_not_invalidated`（`clear-cache` で再試行）
- `MasterCacheInvalidator` と各 Runner はコンテナに明示的に登録してある（`AppServiceProvider`）。自動解決に任せると、キャッシュストアが既定のものになる

#### HTTP

| ルート | 内容 |
| --- | --- |
| `GET /master-import` | 画面（Inertia。props に `target`）。**画面（`Index.vue`）はまだ旧 API（`execute`・`export`）を呼んでおり、動かない。作り直しは次の段階** |
| `GET /master-import/target`・`/artifact-list?source=upstream\|own` | 対象・版の一覧・予約・警告 / 成果物の一覧 |
| `POST /master-import/build`・`/import`・`/finalize`・`/rollback` | `dry_run` 可。import は `start_at` のキーが必須（値は null 可） |
| `POST /master-import/cancel-schedule`・`/deactivate`・`/activate` | `sys_deploy_id` |
| `POST /master-import/cleanup` | 既定は `dry_run`（`dry_run=false` で実行） |
| `POST /master-import/clear-cache`・`/resolve-unknown` | schedule キー（と `id`・`deploy_key`・`tables`・旧キー）の破棄 / `register_unknown` の片付け（`run_id`） |
| `GET /master-import/all-sheets`・`/spreadsheets`・`/sheets`・`/preview` | シートの一覧・内容の確認。build の環境だけ（import の環境は `role_forbidden`） |

- 失敗は `{status: error, code, message, error_list, run_id}` を、`api.md` 13.1 の HTTP ステータスで返す（`MasterDeployRunException`）。入力の形式の誤りは Laravel 標準の 422。入力は `app/Http/Requests/MasterImport/`
- `POST /master-import/deploy`・`execute`・`export` は廃止（404）

#### CLI（`php artisan`。tool-php コンテナで実行）

`master:build [--dry-run] [--start-at=]`・`master:import {artifact_dir} --start-at= [--dry-run]`（`--start-at=now` で「すぐ」）・`master:finalize {artifact_dir} [--dry-run] [--allow-unverified]`・`master:verify-sheet {artifact_dir}`・`master:rollback {sys_deploy_master_id} [--dry-run]`・`master:cancel-schedule {sys_deploy_id}`・`master:deactivate {sys_deploy_id}`・`master:activate {sys_deploy_id}`・`master:resolve-unknown {run_id}`・`master:cleanup [--execute]`（付けないと dry run）。

- 終了コード: 0 成功（警告つきを含む）/ 1 実行中の失敗・想定外 / 2 入力・検証の失敗 / 3 排他 / 4 環境・状態の前提で拒否（対応表は `App\Support\MasterDeployFailure::exitCode`）
- 出力は結果の表と `error_list` を標準出力に。日時は UTC に JST を併記する。`adm_account_id` は null で記録される
- `mst:migrate-versions`（api 側。版の DB 全部にマイグレーションを流す）は凍結後に使うもので、未作成

#### テスト

mst を使うテストは実 MySQL（db-master）に、テストごとの使い捨て `APP_NAME`（`nxt` + 乱数）の基底 DB・版の DB を作り、終了時に接頭辞で全部消す（`tests/Feature/MasterVersionedTestCase.php`）。sys・tool は SQLite のメモリ、ロックと schedule キャッシュはモック。

### ダッシュボード（`/dashboard`）

- 売上は `trx` 接続（シャード1）から集計する
- アクセス数と継続率は `tol_dashboard_access_cache` / `tol_dashboard_retention` にキャッシュし、無ければジョブ（`CalculateAccessCacheJob`, `CalculateRetentionJob`）を database キューに積む。キューワーカーのコンテナは無いので、ローカルで集計させるには tool-php で `php artisan queue:work` を動かす

### プレイヤー（`/player/list`, `/player/index`, `/player/log`）

- `list`: `sys_player` を `my_id` / `uuid` の部分一致で検索（最大50件）
- `index`: `sys_player` の基本情報だけを表示する
- `log`: 全シャードの `log_*` テーブルからプレイヤーの履歴を最新100件ずつ表示する。表示するテーブルは `PlayerHistoryController::LOG_TABLES`

### 管理者・ロール・ページ（`/adm/*`）

`AdmManagementController`。アカウント、ロール、ページの一覧・作成・編集。

### ツールに無いもの

メンテナンスの開始・終了は tool ではなく API の `POST /api/admin/maintenance/start|end`（`ADMIN_TOKEN` で認証）。お知らせ・バナーの画面は無い。

## 権限

- `adm_role`（ID は文字列）と `adm_page`（ID は文字列。例 `master.items`）を `adm_permission` で結ぶ。`adm_permission` は**拒否リスト**で、ロールに対して見せないページを登録する（`AdmRole::deniedPages()`）
- アカウントとロールは `adm_account_role` で多対多
- 判定は `AdmAccount::canAccessPage($pageId)`。スーパー管理者（name が `admin` かつ email が `admin@example.com`）は常に可、それ以外は所属ロールのどれかが拒否していれば不可
- 現状 `canAccessPage()` はルートやミドルウェアから呼ばれておらず、ログイン済みなら全画面に入れる
