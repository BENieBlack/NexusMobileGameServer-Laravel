# マスター配信の deploy_key 上乗せ API 設計（S2 のサーバー設計）

運営ツール（`tool/`）のデプロイ操作の入力・出力・エラーと、ゲーム API（`api/`）の変更を書く。**設計だけで、実装はしない。**

- DB・取り込みの規則: [db.md](db.md)、シーケンス・トランザクション境界: [sequence.md](sequence.md)
- 全体設計（仕様の正本。複製しない）: Nexus の `docs/design/master-distribution/api.md`（version 応答の形を変えない契約）・`overview.md`
- 凡例: **決定** / **仮置き** / **未確認** は db.md と同じ。仮置きと未確認の一覧は db.md 14 節

## 1. 変更の要約

| 対象 | 変更 |
| --- | --- |
| 運営ツール | 取り込み（`POST /master-import/execute`）と書き出し（`POST /master-import/export`）を**廃止**し、1 操作 `POST /master-import/deploy` に束ねる（Nexus overview 11.2 の「T の渡し方・1 操作に束ねるか」への答え。**決定**ではなく**仮置き**）。`GET /master-import/target`・`POST /master-import/clear-cache` を追加。一覧・プレビュー系は維持 |
| 運営ツール（CLI） | `php artisan master:deploy`（画面と同じ処理。録画用・CI 外の運用向け） |
| T（畳みの上限） | **リクエストで渡さない**。環境設定だけで決まる（db.md 7.2） |
| ゲーム API | 新しいエンドポイント・項目の追加・削除は**なし**（Nexus 決定 D4）。変わるのは「最新」の選び方（同点は `id` 降順・本番は予約帯を除く）と、モデルの `deploy_key` の cast だけ（10 節） |

## 2. 運営ツールの共通事項
- ルートは `tool/routes/web.php` の `master-import` グループ（セッション認証。ログイン済みなら全画面に入れる現状のまま。`canAccessPage` は使われていない）。
- JSON のキーはスネークケース。**新しい配列キーは `_list` サフィックス**（`error_list`・`table_list`・`warning_list`・`ignored_tab_list`）。既存の `sheets`・`tables`（表名をキーにした連想配列）はそのまま。
- 失敗は `{ "status": "error", "code": "...", "message": "...", "error_list": [...], "run_id": 12 }`。**HTTP ステータスはエラーごとに変える**（今は一律 422）。画面（`MasterImport/Index.vue`）は `message` と `error_list` を表示する。
- 成功は `{ "status": "success", "message": "...", ... }`。

## 3. GET /master-import/target
画面を開いたとき・デプロイの前に、**何が起きるか**を出す。DB と設定を読むだけ（シートは読まない）。

### Response
| 項目 | 型 | null | 意味 |
| --- | --- | --- | --- |
| app_env | string | 不可 | `APP_ENV` |
| mode | string | 不可 | `real`（本物のキーを採番）か `fixed`（固定キー） |
| fixed_key | int | 可 | `mode = fixed` のときの T。`real` なら null |
| next_key | int | 可 | `mode = real` のときの次のキー（3.4 の採番の予告。拒否される場合は null） |
| blocked | object | 可 | デプロイできない理由。`{ code, message }`（`fixed_key_forbidden`・`fixed_key_invalid`・`deploy_key_exhausted`・`reserved_key_forbidden`）。無ければ null |
| spreadsheet_dir_configured | bool | 不可 | `TOL_GOOGLE_SPREADSHEET_DIR` と認証情報があるか（今の `is_configured`） |
| running | bool | 不可 | いま他のデプロイが実行中か（ロックが取れないか） |
| last_run | object | 可 | 直近の `tol_master_deploy_run`（`id`・`status`・`phase`・`deploy_key`・`started_at`・`finished_at`・`error_code`・`error_message`）。無ければ null |
| warning_list | array | 不可 | 画面に出す警告。`last_run.status` が `inconsistent`・`aborted` なら「前回の実行が完了していません。再実行すれば直ります（古いキャッシュを今すぐ消すなら clear-cache）」。**最後に phase が commit 以降へ進んだ run** が、`succeeded` で `cache_invalidated = false`、または `failed` なら、「API のキャッシュが古い可能性があります。次のデプロイが全表を破棄します。今すぐなら clear-cache」（db.md 8 節の判定と同じ） |

エラー: ログインしていなければ今までどおりのリダイレクト。

## 4. POST /master-import/deploy
シートの読み込み → 検証 → 畳み → mst 反映 → SQLite 書き出し → `sys_deploy*` 登録 → キャッシュ破棄を 1 回で行う。

### Post
| 項目 | 型 | 必須 | 検証 | 意味 |
| --- | --- | --- | --- | --- |
| dry_run | bool | 任意（既定 false） | `boolean` | true なら、読み込み・検証・畳み・差分の件数・（9 桁の）棚卸しだけを行い（**DB への INSERT は試さないので、成功しても `apply_failed` になりうる**。画面の説明文 7 節）、**mst・sys・ファイル・キャッシュを変えない**。`tol_master_deploy_run` にも記録しない。排他のロックも取らない |

**T・シート・テーブルは受け取らない**（環境設定とディレクトリで決まる。db.md 6.1・7.2）。

### Response（成功）
| 項目 | 型 | null | 意味 |
| --- | --- | --- | --- |
| status | string | 不可 | `success` |
| message | string | 不可 | 結果の説明（新規 / 既存の版を有効に戻した / dry_run） |
| dry_run | bool | 不可 | 入力の `dry_run` |
| run_id | int | 可 | `tol_master_deploy_run.id`。dry_run は null |
| deploy.mode | string | 不可 | `real` / `fixed` |
| deploy.deploy_key | int | 不可 | T（real は採番した、または再利用した版のキー）。dry_run の real は予告の `next_key` |
| deploy.sys_deploy_master_id | int | 可 | 登録または再利用した master。dry_run は null |
| deploy.sys_deploy_id | int | 可 | 有効にした `sys_deploy.id`。dry_run は null |
| deploy.is_new | bool | 可 | 新しい版を登録したか（db.md 3.6 の R1 なら false） |
| import.table_list | array | 不可 | 表ごと: `table`・`input_rows`（読んだ行）・`ignored_inactive_rows`・`adopted_rows`（畳み後）・`inserted`・`updated`・`deleted`・`unchanged`・`replaced`（置き換えの対象か。db.md 6.1） |
| import.ignored_tab_list | array | 不可 | 読まなかったタブ（`sheet`・`reason`: `not_mst_tab` / `empty`） |
| import.ignored_column_list | array | 不可 | mst に無いため無視した列（`table`・`column`） |
| import.warning_list | array | 不可 | 警告。**`dry_run` のときだけ**、T より大きい行・畳みで負ける古い行の内容違反 V10・V11（止めずに警告だけ。本番のデプロイでは出さない。db.md 6.3）と、未来の `deploy_key` の行数を出す |
| export | object | 可 | `hash`・`file_size`・`table_count`・`file_count`・`tables`（今の `export` と同じ形）。dry_run は null |
| cache.invalidated | bool | 可 | 破棄に成功したか。dry_run は null |
| cache.failed_key_list | array | 可 | 失敗したキー |
| inventory | object | 可 | **dry_run のときだけ**。9 桁の棚卸し（db.md 9.3）: `digit_distribution`・`legacy_row_list`・`conversion_conflict_list`・`order_change_key_count` |

```json
{
  "status": "success",
  "message": "新しい版を登録しました（deploy_key=2026112201）。",
  "dry_run": false,
  "run_id": 12,
  "deploy": { "mode": "real", "deploy_key": 2026112201, "sys_deploy_master_id": 27, "sys_deploy_id": 31, "is_new": true },
  "import": {
    "table_list": [
      { "table": "mst_config", "input_rows": 9, "ignored_inactive_rows": 0, "adopted_rows": 6, "inserted": 0, "updated": 1, "deleted": 0, "unchanged": 5, "replaced": true }
    ],
    "ignored_tab_list": [{ "sheet": "メモ/メモ1", "reason": "not_mst_tab" }],
    "ignored_column_list": [],
    "warning_list": []
  },
  "export": { "hash": "0f3c...", "file_size": 40960, "table_count": 120, "file_count": 80, "tables": { "mst_config": { "hash": "ab12...", "file_name": "mst_config_ab12....sqlite", "file_size": 4096, "public_url": "/masterdata/0f3c.../mst_config_ab12....sqlite" } } },
  "cache": { "invalidated": true, "failed_key_list": [] }
}
```
JSON 例はこの設計から組み立てた例で、実行出力ではない。

### エラー
| code | HTTP | 条件 | 状態（変更したもの） |
| --- | --- | --- | --- |
| `deploy_locked` | 409 | 他のデプロイが実行中（ロックが取れない） | 何も変えない |
| `fixed_key_forbidden` | 403 | `fixed_key` が設定されているのに `APP_ENV` が `fixed_key_forbidden_envs`（本番・ステージング） | 何も変えない（ロックの前に判定） |
| `fixed_key_invalid` | 500 | `fixed_key` が許可リスト（`7777777777`・`8888888888`・`9999999999`）に無い | 何も変えない |
| `reserved_key_forbidden` | 403 | 本物のモードで、採番の結果が予約帯 | 何も変えない |
| `deploy_key_exhausted` | 422 | その日の NN が 99 を超える（翌日に回さない） | 何も変えない |
| `deploy_key_conflict` | 409 | 本物のキーが別の `hash` で既にある（db.md 3.6 の R4） | mst と `sys` の両方をロールバック済み |
| `sheet_not_configured` | 422 | スプレッドシートのディレクトリ・認証情報が未設定 | 何も変えない |
| `sheet_read_failed` | 502 | Google API の読み込みが再試行（3 回）後も失敗 | 何も変えない |
| `sheet_invalid` | 422 | 検証規則（db.md 6.3）に 1 件でも当たる。`error_list` に `{ sheet, table, row, column, code, message }` を最大 20 件、`error_total`（総件数）と `error_count_by_code` を添える | 何も変えない |
| `apply_failed` | 500 | mst への反映で DB が拒否（型・NOT NULL・`uk_*`）。メッセージに表名と DB の文言 | mst をロールバック済み |
| `export_failed` | 500 | SQLite の書き出し・公開に失敗 | mst をロールバック済み（`sys` は未登録） |
| `register_failed` | 500 | `sys` への登録に失敗 | `sys` と mst をロールバック済み |
| `inconsistent` | 500 | `sys` は登録できたが mst のコミットが失敗。`compensated`（`sys` を元に戻せたか）を返す | 画面に警告。**再実行で直る**（再実行は mst の全表のキャッシュも破棄する。sequence.md 5.4・5.6） |

- `error_list[].code`（`sheet_invalid` の内訳）は db.md 6.3 の `code` と同じ: `is_active_column_missing`・`unknown_table`・`deploy_key_column_missing`・`deploy_key_empty`・`deploy_key_not_number`・`deploy_key_legacy_9digits`・`deploy_key_legacy_reserved`・`deploy_key_out_of_range`・`id_empty`・`id_whitespace`・`l10n_parent_empty`・`l10n_language_empty`・`deleted_flag_invalid`・`duplicate_row`・`id_case_variant`・`no_fold_key`・`rarity_invalid`・`id_too_long`・`id_rule_mismatch`・`id_colon`・`unique_group_duplicate`。
- 想定外の例外は 500 の `internal_error` とし、`run_id` を添える（`tol_master_deploy_run` の `failed`）。
- **更新リソース**: `mst_*`（置き換えの対象の表）、`sys_deploy_master`・`sys_deploy_master_table`・`sys_deploy_asset`・`sys_deploy`、`tol_master_deploy_run`、`tool/public/masterdata/{hash}/`、Redis のキャッシュ（db.md 8 節）。

### 冪等・排他
- **同じシートの再実行は冪等**: 差分反映（db.md 6.5）で mst は変わらず、書き出す SQLite のバイト列が同じ → `hash` が同じ → db.md 3.6 の R1 で既存の版を有効に戻す（新しいキーを消費しない）。画面の二重クリックも、後ろの 1 つは `deploy_locked` で止まるか、同じ結果になる。
- 排他は 11 節。

## 5. POST /master-import/clear-cache
キャッシュ破棄だけをやり直す口（デプロイ後に `cache.invalidated = false` だったとき用）。

- Post: なし。
- 処理: 最新の `sys_deploy` を引き、db.md 8 節のうち `sys:sys_deploy:*` の 3 種（最新・有効な全行の `deploy_key`・`id`）と、**mst の全表の `mst:{表}:all`**（`SHOW TABLES LIKE 'mst_%'`）を `forget` する。ロックは取らない（読み取りと破棄だけ。冪等）。
- Response: `{ status, message, cache: { invalidated, failed_key_list } }`。破棄に失敗したら HTTP 502・`code = cache_clear_failed`。

## 6. 維持するエンドポイント・廃止するエンドポイント
| ルート | 扱い |
| --- | --- |
| `GET /master-import` | 維持。渡す props に `target`（3 節と同じ内容）を足す。`cached_sheets`・`cached_at` は維持 |
| `GET /master-import/all-sheets`・`/spreadsheets`・`/sheets`・`/preview` | **維持**（一覧・内容の確認用）。`preview` は、列に `deploy_key`・`__deleted` が含まれたら（今も含まれる）そのまま表示する。`is_mst` は「デプロイが読むタブか」の表示に使う（db.md 6.1） |
| `POST /master-import/execute` | **廃止**（404）。シート 1 つずつ・テーブルごとに `TRUNCATE` する今の方式は残さない。`MasterImportService::import()` も廃止し、検証・反映を新しい部品に分ける（12 節の内部の分担） |
| `POST /master-import/export` | **廃止**（404）。`deploy` に含める |

## 7. 画面（`resources/js/Pages/MasterImport/Index.vue`）の変更
- **シートの選択（左右 2 つの一覧と「全て追加」など）をやめる**。デプロイは全シートを読むので選ぶものが無い。一覧は「読まれるタブ・読まれないタブ」の確認用の表示にする（`all-sheets` を使う）。
- 上部に**対象の表示**（`GET /master-import/target`）: 環境名、モード（本物 / 固定）、T または「次のキー」、`blocked` の理由、前回の実行の状態。`warning_list`（`inconsistent`・`aborted`）は目立つ色で出す（`aborted` には「古いキャッシュを今すぐ消すなら clear-cache」の案内も出す）。
- ボタン 2 つ: 「検証（dry run）」と「デプロイ」。デプロイの確認ダイアログに、環境名・モード・T を再掲する（誤操作の防止。固定モードは「この環境の mst を `{T}` で畳み直します」）。`blocked` があればデプロイを押せない。
- 結果の表示: 表ごとの `inserted`/`updated`/`deleted`/`unchanged` の表、`error_list`（`sheet`・`row`・`code`・`message`。総件数と、20 件で切ったことの表示）、`cache` の失敗（`clear-cache` のボタン）、`dry_run` の `inventory`。
- **画面に書く説明文**（運営向け）: ① 古い行は消さず、新しいキーの行を足す。② 削除は `__deleted` を `TRUE` にした行（`id` と `deploy_key` だけでよい）。`is_active` を無効にしても消えない。③ `deploy_key` は 10 桁必須。未来のキー（次の採番より先）の行は、採番が追いつくまで効かない。④ 言語だけを消すことはできない（親ごと消す）。⑤ 「検証（dry run）」が成功しても、型・NOT NULL・`uk_*` の衝突・照合順序による主キー衝突はデプロイで初めて分かり、`apply_failed`（元の mst のまま）になりうる。

## 8. CLI: `php artisan master:deploy`
- `tool/app/Console/Commands/MasterDeployCommand.php`（**仮置き**。名前は S3 で決めてよい）。画面と同じ `MasterDeployRunner` を呼ぶ。`--dry-run` あり。T は引数に無い（環境設定だけ）。
- 出力は結果の表と `error_list` を標準出力に。終了コード: 成功 0、`sheet_invalid` など検証の失敗 2、排他 3、それ以外の失敗 1。
- 用途: 録画を撮るローカル（`MASTER_DEPLOY_FIXED_KEY=7777777777`。db.md 11.2）、画面を開けない環境。`adm_account_id` は null で記録する。

## 9. 環境設定
db.md 7.2 の `config/master_deploy.php`（`MASTER_DEPLOY_FIXED_KEY`）。既存の `TOL_GOOGLE_SPREADSHEET_DIR`・`TOL_GOOGLE_SERVICE_ACCOUNT_JSON(_CONTENT)` は変えない。`.env.example` に `MASTER_DEPLOY_FIXED_KEY=`（空）と説明のコメントを足す。

| 環境 | `MASTER_DEPLOY_FIXED_KEY` | シートのディレクトリ |
| --- | --- | --- |
| 本番・ステージング | 未設定 | 本番用（**有無は未確認**） |
| QA | 未設定 | LD と共通 |
| レベルデザイン | `8888888888` | QA と共通 |
| 開発 | `9999999999` | 開発の別ディレクトリ |
| 録画（ローカル） | `7777777777` | 開発の別ディレクトリ（**仮置き**） |

## 10. ゲーム API（`api/`）の変更
**エンドポイント・Post・Response の項目は変わらない**（`GET`・`POST /api/auth/version`、`X-Master-Hash`・`X-Master-Update-Required`。Nexus の `api.md` のとおり）。変わるのは次の内部だけ。

| 場所 | 変更 |
| --- | --- |
| `SysDeployRepository::selectLatestDownloadable()` | 並びを `deploy_key` 降順 → `id` 降順。`APP_ENV=production` のときは `deploy_key < 7000000000` を条件に足す（決定。Q7）。Redis キャッシュは今のまま（キーは `sys:sys_deploy:latest_downloadable`） |
| `SysDeploy::selectLatestDownloadable()`（static） | 同じ並び・同じガード（db.md 3.3） |
| `SysDeployRepository::selectByDeployKey()` | `orderByDesc('id')` を足す（同じキーの行が複数ありうる） |
| `SysDeploy` モデルの `$casts` | `'deploy_key' => 'integer'`。`parseDeployKey()` の `count` を 2 桁に |
| `latest_deploy_key`（`CheckResponse`） | 型は int のまま。**意味が「畳みの上限（T）」になる**。値が 32 ビットを超える（`9999999999`）。JSON は 2^53 未満で安全。クライアントは使わない |
| `VersionService` の判定 | **変えない**（送られた `sys_deploy.id` と最新の `id` の厳密な一致） |
| 本番に予約帯の `sys_deploy` しか無い | 「無い」と同じ扱いで `SystemDataException::deploy()`（299 / 99999）（変えない。Nexus api.md 5 節） |
| 定数 | `DeployKeyConst::RESERVED_FLOOR = 7000000000`（`packages/nexus-version/src/Constants/`。tool の設定と同じ値。db.md 3.3） |

テスト（S3）: ① 同じ `deploy_key` の行が 2 つあるとき `id` 最大が最新、② 本番設定で予約帯が最新に選ばれない（予約帯しか無ければ 299）、③ 本番以外は予約帯が選ばれる、④ キャッシュが破棄されると新しい最新を返す。

## 11. 排他・失敗時の整合（Nexus overview 4.1 の宿題への答え）

### 11.1 排他
- **MySQL の名前付きロック**（`GET_LOCK`）を `sys` 接続で取る。名前は `nexus_master_deploy:{sys の DB 名}`（環境ごとに別の DB なので他の環境と衝突しない）。**タイムアウト 0**（待たずに `deploy_locked` を返す）。`finally` で `RELEASE_LOCK`。
- なぜ名前付きロックか: ① プロセスが落ちても接続が切れれば自動で外れる（古いロックが残らない）。② tool は Redis を排他に使いたくない（落ちたときの TTL 待ちが無い）。③ `database` ストアの `Cache::lock` は期限切れまで残る。同じ `sys` の DB を使う運営ツールが複数あっても効く。
- ロックを取る順序: **環境設定の検査（本番ガード）→ ロック → `tol_master_deploy_run` の `running` を作る → 本処理**。ロックが取れて `running` の行が残っていれば `aborted` に直す（前のプロセスが落ちた跡）。
- ロックは**シートの読み込みから最後のキャッシュ破棄まで**持つ（読み込みの間に別のデプロイが入ると、T の採番と畳みの入力がずれるため）。`dry_run` はロックを取らない（変更しないので。結果は取得時点のもの）。
- **同時に動く他の書き込み**（開発のシーダー・`make seed`）は排他しない（開発者が自分で避ける。**仮置き**）。

### 11.2 HTTP のタイムアウト（未確認）
読み込みは Google API の呼び出しを全タブ分行う（`withRetry` で最大 3 回）ので、タブ数が多いと nginx の `fastcgi_read_timeout`（`docker/tool-nginx/tool-nginx.conf` に指定なし = 既定 60 秒）や PHP の実行時間を超えうる。**タブ数・読み込み時間は未確認**。対策の案:
1. `deploy` のリクエスト処理で `set_time_limit(0)` とし、tool-nginx の `fastcgi_read_timeout` を延ばす（既定案）。
2. それでも足りなければ、読み込みだけを `master:deploy` の CLI に任せる運用にする（CLI にはタイムアウトが無い）。
キューは使わない（ワーカーのコンテナが無い。`.claude/tool.md`）。S3 で実測して決める。

## 12. 内部の分担（tool のクラス。名前は仮置き）
| クラス | 責務 |
| --- | --- |
| `GoogleSpreadsheetService`（既存を拡張） | 全 `mst_` タブを読む `readAllMasterTabs()`。`getSheetData` が行番号・`deploy_key`・`__deleted` を保つ（db.md 6.2） |
| `MasterSheetValidator`（新） | 構造・内容・畳み結果の検証（V01〜V12）。エラーを集める |
| `MasterOverlayFolder`（新） | 畳み（db.md 6.4） |
| `MasterImportService`（既存を作り替え） | 差分反映（db.md 6.5）。`ID_RULES`・`UNIQUE_COLUMN_GROUPS`・`normalizeRarity` は残す |
| `MasterDataExporter`（既存を直す） | 主キー昇順・トランザクション内の接続で読む（db.md 6.6） |
| `MasterDeployService`（既存を直す） | 採番（db.md 3.4）・登録（R1〜R4。db.md 3.6）・補償 |
| `MasterCacheInvalidator`（新） | キャッシュの破棄（db.md 8 節） |
| `MasterDeployRunner`（新） | 排他・実行記録・上の呼び出し順・トランザクション（sequence.md）。画面・CLI から呼ぶ |
