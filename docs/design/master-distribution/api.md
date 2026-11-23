# マスター配信の API 設計（版ごとの mst DB と start_at の切り替え。S2 のサーバー設計）

運営ツール（`tool/`）の操作（build・import・QA 完了・巻き戻し・予約の取り消し・掃除・target）の入力・出力・エラーと、ゲーム API（`api/`）の内部の変更を書く。**設計だけで、実装はしない。** コードは書かない。

**この文書は ADR 0005 前提の旧版（採番・`deploy`・`execute`・`export`・全破棄・補償）を、ADR 0007 に合わせて書き直したもの。** 旧版の `POST /master-import/deploy`（シート → mst 反映 → SQLite → 登録 → 全破棄を 1 回で行う）・採番・`inconsistent`・補償・`clear-cache` の全破棄は、この文書では無効。

- DB・成果物・登録・版の DB・キャッシュの規則: [db.md](db.md)（**土台**。この文書は db.md を複製せず、節番号で参照する）。シーケンス・トランザクション境界: [sequence.md](sequence.md)（この PR で db.md・api.md・sequence.md を同時に ADR 0007 に揃える。食い違ったら **db.md が正本**）。db.md は最終形（レビュー反映済み）で、この文書はその最終形に従う
- 全体設計（**仕様の正本**。複製しない）: Nexus の `docs/design/master-distribution/api.md`（version 応答の形を変えない契約）・`overview.md`、Nexus の ADR 0007（`docs/adr/0007-versioned-master-db-timed-switch.md`）、課題表 `docs/progress/master-distribution/issues.md`（R 番号）
- 凡例: **決定**（人の決定。ADR 0007・overview の D・db.md が「決定」とするもの）／**仮置き**（AI の提案。人の確認で変わりうる。名前・URL・エラーコード・HTTP ステータスは特記なき限りすべて仮置き）／**未確認**（確かめていない）／**未決**（決まっていない。勝手に決めない）／**提案**（Nexus が「提案」としているもの。人の確認待ち）。仮置き・未確認・未決の一覧は 14 節

## 1. 変更の要約

| 対象 | 変更 |
| --- | --- |
| 運営ツールの操作 | 旧 `POST /master-import/deploy`（1 操作）を、**build**（シート → 成果物と版の DB）と **import**（成果物 → 版の DB）の 2 つに分ける。**QA 完了**（`finalize`。**提案**）・**巻き戻し**（`rollback`）・**予約の取り消し**（`cancel-schedule`）・**緊急停止**（`deactivate`・再開 `activate`。`is_active` を落とす・戻す。**仮置き**。R39 の決定）・**掃除**（`cleanup`）を足す。`target` は版の一覧・予約・警告を返す形に直す。`execute`・`export` は廃止のまま（S3-16） |
| T（畳みの上限） | **リクエストで渡さない**。環境設定で明示する（7.2。決定: D10）。日付による採番はしない（決定。LD で確定したキー V を持ち回る） |
| `start_at` | build は任意（省略 = 現在）、import は入力欄が必須で、省略（「すぐ」）は現在（db.md 3.6） |
| キャッシュ | `clear-cache` は mst を触らず、**schedule キーの破棄だけ**に縮める（9 節の判断） |
| 排他 | 旧どおり `MasterDeployLock`（`GET_LOCK`）。build・import・finalize・rollback・cancel-schedule・deactivate・activate・cleanup が同じロックで直列になる |
| CLI | **tool に `app/Console/Commands/` は無く、`master:*` は 1 つも実装されていない**（CLI は新規。台帳の S3-14 は保留中）。`master:build`・`master:import`・`master:finalize`・`master:verify-sheet`・`master:rollback`・`master:cancel-schedule`・`master:deactivate`・`master:activate`（緊急停止・再開。**仮置き**）・`master:cleanup`・`master:resolve-unknown`（tool）、`mst:migrate-versions`（api）を作る（10 節）。**廃止するのは HTTP の `POST /master-import/deploy` ルートだけ**（旧 `master:deploy` コマンドは存在しない） |
| ゲーム API | 公開エンドポイント・Post・Response の項目は**変えない**（Nexus 決定 D4・F8・F9）。内部だけ変わる: 「いまの版」の選び方・リクエスト冒頭での版の固定と接続の差し替え・schedule キャッシュ・mst のキャッシュキー・`CheckMasterHash`（12 節） |

## 2. 運営ツールの共通事項
- ルートは `tool/routes/web.php` の `master-import` グループ（セッション認証。ログイン済みなら全画面に入れる現状のまま）。URL は**仮置き**（既存の `/master-import` 配下に揃えた）。
- JSON のキーはスネークケース。**配列キーは `_list` サフィックス**（`error_list`・`table_list`・`warning_list`・`scheduled_list`・`version_list`）。既存の `sheets`・`tables`（表名をキーにした連想配列）はそのまま。
- 成功: `{ "status": "success", "message": "...", ... }`。
- 失敗: `{ "status": "error", "code": "...", "message": "...", "error_list": [...], "run_id": 12 }`。**HTTP ステータスはエラーごとに変える**（13 節の表）。画面（`MasterImport/Index.vue`）は `message` と `error_list` を表示する。`run_id` は実行記録を書く前に失敗したときは null。
- 入力の形式の検査（型・必須）に失敗したときは、Laravel 標準の 422（`message`・`errors`。今の `deploy` の `dry_run` と同じ）。上の形式のエラーとは別（画面は両方を表示する）。
- `warning_list` の要素は `{ "code": "...", "message": "..." }`（止めない注意。`start_at_too_soon`・`start_at_shadowed` など）。
- **日時は UTC の `'Y-m-d H:i:s'` 文字列で受け取り・返す**（規約）。画面・CLI は **JST を併記して表示**する（表示だけ。保存は UTC。db.md 3.6）。
- **操作の前の共通の検査（ロックの前。db.md 6.6 の「環境設定の検査」）**: ① `role`（7.2）と操作の食い違い（`role_forbidden`。全操作）② 本番ガード（`fixed_key_forbidden`・`fixed_key_invalid`。build）③ `base_database_mismatch`・`mst_database_name_too_long`。**③ の対象は build・import だけ**（db.md 6.6。版の DB を新しく作る操作。rollback の復元・cleanup・finalize・cancel-schedule・resolve-unknown は ③ を検査しない。設定の誤りは build・import で先に止まる）。`target` は読み取りだけで、検査の結果を `blocked` に載せる。
- **操作と role の対応**（誤操作の防止。**仮置き**）:

| 操作 | build の環境（develop・LD・QA） | import の環境（review・production・staging） |
| --- | --- | --- |
| build・finalize・verify-sheet・`all-sheets`・`spreadsheets`・`sheets`・`preview`（シートを読む・見る操作） | 可 | **拒否**（`role_forbidden`。シートを持たない） |
| import | **拒否**（`role_forbidden`） | 可 |
| artifact-list | 可（`source=own`。QA の finalize の入力の選択） | 可（`source=upstream`。**import の主な入力**） |
| rollback・cancel-schedule・**deactivate・activate**（緊急停止・再開。**仮置き**）・cleanup・target・clear-cache | 可 | 可 |

## 3. エンドポイントの一覧

| ルート | 用途 | 状態 |
| --- | --- | --- |
| `GET /master-import` | 画面。props に `target`（4 節）を渡す | 維持 |
| `GET /master-import/all-sheets`・`/spreadsheets`・`/sheets`・`/preview` | シートの一覧・内容の確認 | 維持（下記） |
| `GET /master-import/target` | 対象・版の一覧・予約・警告（4 節） | 作り直し |
| `GET /master-import/artifact-list` | 成果物の一覧（import の入力・finalize の入力の選択用。5 節） | 新 |
| `POST /master-import/build` | build（6 節） | 新（旧 `deploy` の置き換え） |
| `POST /master-import/import` | import（7 節） | 新 |
| `POST /master-import/finalize` | QA 完了（8 節。**提案**） | 新 |
| `POST /master-import/rollback` | 巻き戻し（9.1 節） | 新 |
| `POST /master-import/cancel-schedule` | 予約の取り消し（9.2 節） | 新 |
| `POST /master-import/deactivate`・`/activate` | 緊急停止・再開（9.6 節。`is_active`。**仮置き**） | 新 |
| `POST /master-import/cleanup` | 版の DB の掃除（9.3 節） | 新 |
| `POST /master-import/clear-cache` | schedule キーの破棄（9.4 節） | 縮める |
| `POST /master-import/deploy` | 旧 1 操作 | **廃止**（404。S3-16。build・import に置き換わる） |
| `POST /master-import/execute`・`/export` | 旧の取り込み・書き出し | **廃止**（404。S3-16。`TRUNCATE` する方式は残さない。`MasterImportService::import()`・`reflect()` も不要。db.md 10.4） |

- `all-sheets`・`spreadsheets`・`sheets`・`preview` は**維持**（一覧・内容の確認。build の環境だけで使う。import の環境では `role_forbidden`）。`preview` は列に `deploy_key`・`__deleted` が含まれたらそのまま表示する。`is_mst` は「build が読むタブか」の表示に使う（db.md 6.1）。
- 旧 URL（`deploy`）を build に残す（互換）案は採らない（画面とテストだけが呼ぶ内部の口なので、残す意味が無い。**仮置き**）。

## 4. GET /master-import/target
画面を開いたとき・操作の前に、**何が起きるか**を出す。DB・設定・保存済みの実行記録を読むだけで、**シートは読まない**。ロックは取らない（`running` は `IS_FREE_LOCK`）。

### Response
| 項目 | 型 | null | 意味 |
| --- | --- | --- | --- |
| app_env | string | 不可 | `APP_ENV` |
| role | string | 不可 | `build` か `import`（7.2 `MASTER_DEPLOY_ROLE`）。未設定は `blocked` に出す |
| mode | string | 可 | build の環境のみ。`fixed`（固定キー）か `explicit`（明示キー）。import は null（db.md 5.2 の `mode` と同じ） |
| deploy_key | int | 可 | build の環境の **T**（`fixed_key` か `explicit_key`）。import は null（成果物のキーを使う） |
| blocked | object | 可 | **いま操作できない理由**。`{ code, message }`。コードは `role_not_configured`・`fixed_key_forbidden`・`fixed_key_invalid`・`deploy_key_not_configured`・`reserved_key_forbidden`（`explicit_key` が予約帯）・`base_database_mismatch`・`mst_database_name_too_long`・`upstream_not_configured`（import で上流の disk が無い）。無ければ null。blocked でも rollback・cancel-schedule・deactivate・activate・target は使える（**仮置き**: 設定の誤りは build・import だけを止める） |
| spreadsheet_dir_configured | bool | 不可 | `TOL_GOOGLE_SPREADSHEET_DIR` と認証情報があるか。import の環境は常に false でよい（読まない） |
| base_database | object | 不可 | `{ name, schema_fingerprint }`。基底 DB（雛形。db.md 4.1）の名前と現行のスキーマ版（db.md 4.4）。**スキーマ版は短い TTL（60 秒）でキャッシュした値**（db.md 4.4。tool の既定のキャッシュ。キー `tool:master:schema_fingerprint:{基底 DB 名}`）なので、画面を開くたびに全表を読み直さない |
| running | bool | 不可 | いま他の操作が実行中か（ロックが取れないか） |
| now | string | 不可 | サーバーの現在時刻（UTC）。画面が `start_at` の過去・近すぎを事前に示すため |
| current_version | object | 可 | いまの版（db.md 3.3 と同じ決め方）。`sys_deploy_id`・`sys_deploy_master_id`・`deploy_key`・`hash`・`artifact_dir`・`mst_database`・`start_at`・**`is_active`**（いまの版は止められていない行なので常に true。**`is_active` が false の行は最新の選択から外れる**。db.md 3.3）。無ければ null（`no_current_version` を `warning_list` に） |
| scheduled_list | array | 不可 | **予約済み**（`start_at` が現在より後の `sys_deploy` 行。**止めた行（`is_active = false`）も含める**。巻き戻しの「予約済み」の判定に数えるため。db.md 3.6）。`sys_deploy_id`・`sys_deploy_master_id`・`deploy_key`・`hash`・`start_at`・**`is_active`**（false なら、緊急停止で止めた行。画面は「止めた行」と表示し、「再開」（9.6）を出す）。`start_at` の昇順 |
| inactive_list | array | 不可 | **止めた行**（`is_active = false` の `sys_deploy` 行。到達済み・未到達を問わない）。`sys_deploy_id`・`sys_deploy_master_id`・`deploy_key`・`hash`・`start_at`。緊急停止の履歴の確認用（**仮置き**） |
| version_list | array | 不可 | 版（`sys_deploy_master`）の一覧。新しい順。`sys_deploy_master_id`・`deploy_key`・`hash`・`artifact_dir`・`mst_database`・`source_kind`・`schema_fingerprint`・`schema_matches`（現行の基底 DB と一致するか）・`mst_dropped_at`・`last_start_at`（その版を指す `sys_deploy` 行の `start_at <= 現在` の最大。無ければ null）・`is_current`（いまの版か）・`rollback_state`（`ok`・`needs_restore`（DB 掃除済み。成果物から復元できる）・`unavailable`（復元もできない）・`schema_mismatch`・`blocked_by_scheduled`。**`blocked_by_scheduled` は、その版を戻し先とした場合の判定**: `start_at > 現在` の行のうち、**`start_at <= 現在 + `start_at_past_grace`（60 秒）かつ戻し先と同じ master を指す行は数えない**（db.md 3.6。巻き戻し直後のやり直しを止めない。戻し先と別の版を指す近い予約は数える）。**`is_current` とは独立**: いまの版は `is_current = true` で、画面は戻すボタンを出さない。API は拒否せず警告で通す。9.1）。巻き戻し先の候補を画面が選べるようにする |
| last_run | object | 可 | 直近の `tol_master_deploy_run`（`id`・`kind`・`status`・`phase`・`deploy_key`・`artifact_dir`・`started_at`・`finished_at`・`error_code`・`error_message`）。無ければ null |
| follow_check | object | 可 | **追従検出の直近の結果**（R28。**提案**）。`status`（`not_checked`・`match`・`changed`・`unverified`。db.md 6.7）・`checked_at`・`artifact_dir`・`changed_deploy_key_list`・`reason`（`unverified` のとき。シートを読めなかった理由。例 `sheet_not_configured`・`sheet_read_failed`）・`allow_unverified`（`--allow-unverified` で通した run か）。**画面を開くたびにシートは読まない**。出所は**直近の `kind = finalize` の実行記録の `summary.follow_check`**（`dry_run` は実行記録を書かない（db.md 5.1）ので、`dry_run`・`master:verify-sheet` の結果は出ない。それは操作の応答で直接見る）。finalize の run が無ければ `not_checked`。QA の build 環境だけ。それ以外は null |
| warning_list | array | 不可 | 画面に出す注意（下） |

`warning_list` の `code`（**仮置き**）:

| code | 条件 |
| --- | --- |
| `no_current_version` | いまの版が無い（API は 299 を返す状態）。本番で予約帯しか無い場合を含む |
| `incomplete_run` | 直近の run が `aborted`、または `failed`（`register_unknown` を除く）で `mst_database` が残っている（孤児の候補。db.md 4.7）。「cleanup（既定は dry_run）で確認できます」 |
| `register_unknown` | `error_code = register_unknown` **かつ `summary.resolved` が無い** run がある（`status = failed`）。**sys の COMMIT の成否が不明で、sys を読めず、DB を `DROP` していない**。**`master:resolve-unknown {run_id}`（9.5）で人が片付ける**。**放置すると、その run は掃除（cleanup）の対象外のまま、DB が残り続ける**（この警告も出続ける。db.md 4.7） |
| `orphan_database` | 孤児の候補の件数が 1 以上（`created_database_list` から。cleanup が消せる） |
| `schema_stale_version` | 保持している版のうち `schema_matches = false` のもの（巻き戻し・復元は `schema_mismatch` で拒否される。db.md 4.8） |
| `cache_not_invalidated` | 直近の成功した run の `cache_invalidated = false` で、終了から `schedule_ttl`（60 秒）以内（過ぎたら自然に直る。db.md 8.3） |
| `sheet_changed_since_build` | `follow_check.status = changed`（R28。QA の build 環境だけ） |
| `follow_check_unverified` | `follow_check.status = unverified`（シートを読めずに `--allow-unverified` で finalize した。**追従を確かめていない**。実行記録に残っている）。**`follow_check_unavailable` は target の警告にしない**（target はシートを読まないので、読めるかを知らない。finalize の操作時の拒否だけ。13.1） |
| `scheduled_exists` | `scheduled_list` が 1 件以上（情報。巻き戻しは取り消しが先） |
| `inactive_exists` | `inactive_list` が 1 件以上（情報。**止めたままの行がある**ことを忘れないため。再開は 9.6。**仮置き**） |

エラー: ログインしていなければ今までどおりのリダイレクト。

## 5. GET /master-import/artifact-list
成果物（`{deploy_key}_{hash}/`）の一覧。画面の import・finalize の入力の選択肢で、CLI の補助にも使う。ストレージを読むだけ（ロックなし）。

### Post（クエリ）
| 項目 | 型 | 必須 | 検証 | 意味 |
| --- | --- | --- | --- | --- |
| source | string | 任意（既定 `upstream`） | `in:upstream,own` | `upstream` = 上流の disk（`master_artifact_upstream`。import の環境の既定）、`own` = 自環境の disk（`master_artifact`。QA の build の成果物の確認・finalize の選択） |

### Response
| 項目 | 型 | null | 意味 |
| --- | --- | --- | --- |
| status | string | 不可 | `success` |
| artifact_list | array | 不可 | `manifest.json` があるものだけ（完成の印。db.md 6.5.3。無いディレクトリは未完成として載せない）。新しい順（`built_at` 降順）。要素: `artifact_dir`・`deploy_key`・`hash`・`schema_fingerprint`・`built_env`・`built_at`（UTC）・`source_kind`・`finalized`（`final.json` があり、`deploy_key`・`hash` がマニフェストと一致するか。8 節）・`imported`（自環境の sys に `(deploy_key, hash)` の master が既にあるか）・`schema_matches`（成果物の `schema_fingerprint` が自環境の基底 DB と一致するか） |

エラー: `upstream_not_configured`（`source=upstream` で上流の disk が無い。500）・`pull_failed`（ストレージを読めない。502。db.md 3.6 の仮称）。

## 6. POST /master-import/build
build の環境（develop・LD・QA）で、**シートの読み込み → 検証 → T 以下を畳む → 作業用 DB → 成果物 → 版の DB → 成果物の配置 → sys に登録**を 1 回で行う（db.md 6.6）。import の環境では `role_forbidden`。

### Post
| 項目 | 型 | 必須 | 検証 | 意味 |
| --- | --- | --- | --- | --- |
| dry_run | bool | 任意（既定 false） | `boolean` | true なら、読み込み・検証・畳み・棚卸し・警告だけを行い、**DB（作業用 DB を含む）・ストレージ・sys・キャッシュを変えず、`tol_master_deploy_run` にも記録せず、ロックも取らない**。**DB に投入しないので、成功しても `load_failed` になりうる**（db.md 6.3）。`start_at` の検査と警告は行う |
| start_at | string | 任意（既定 null） | `nullable\|date_format:Y-m-d H:i:s` | `sys_deploy.start_at`（UTC）。**省略（null）= 登録の直前の現在時刻**（db.md 3.6 の「すぐ」）。build の既定は現在。未来を指定してもよい |

**T・シート・テーブルは受け取らない**（環境設定とディレクトリで決まる。db.md 6.1・7.2）。

### Response（成功）
| 項目 | 型 | null | 意味 |
| --- | --- | --- | --- |
| status | string | 不可 | `success` |
| message | string | 不可 | 結果の説明（新しい版を登録 / 同じ内容の版を再利用して新しい切り替え行を足した / dry_run） |
| dry_run | bool | 不可 | 入力の `dry_run` |
| run_id | int | 可 | `tol_master_deploy_run.id`。dry_run は null |
| deploy.mode | string | 不可 | `fixed` / `explicit` |
| deploy.deploy_key | int | 不可 | T（成果物の `deploy_key`） |
| deploy.hash | string | 可 | 成果物の完全な hash（64 文字。ディレクトリ名 `artifact_dir`・DB 名には先頭 16 文字を使う。db.md 4.2）。dry_run は畳み後の内容から計算できないので null |
| deploy.artifact_dir | string | 可 | `{deploy_key}_{hash}`。dry_run は null |
| deploy.mst_database | string | 可 | 版の DB 名（4.2 の命名）。dry_run は null |
| deploy.sys_deploy_master_id | int | 可 | 登録または再利用した master。dry_run は null |
| deploy.sys_deploy_id | int | 可 | 足した `sys_deploy.id`（**build のたびに新しい値**。同じ内容の再 build でも新しい行になる。db.md 3.6 の R1。クライアントは `sys_deploy.id` の変化で更新に気づくが、表ごとの hash が同じなら取り直さない）。dry_run は null |
| deploy.start_at | string | 不可 | 登録した（する）`start_at`（UTC）。「すぐ」は登録直前の現在時刻。dry_run は入力どおり（null なら現在の見込み） |
| deploy.is_new | bool | 可 | 新しい `(deploy_key, hash)` を登録したか（R1 なら false）。dry_run は null |
| deploy.database_reused | bool | 可 | 既存の版の DB を使ったか（db.md 6.6 の `version_db` の 2）。新しく作った・復元したら false。dry_run は null |
| build.table_list | array | 不可 | 表ごと: `table`・`input_rows`（読んだ行）・`ignored_inactive_rows`・`adopted_rows`（畳み後）・`empty`（シートに行が無く空の表にしたか。db.md 6.1） |
| build.ignored_tab_list | array | 不可 | 読まなかったタブ（`sheet`・`reason`: `not_mst_tab` / `empty`） |
| build.ignored_column_list | array | 不可 | mst に無いため無視した列（`table`・`column`） |
| build.row_digest_list | array | 不可 | 畳み後の行ダイジェスト（`deploy_key`・`digest`。R28。**提案**）。成果物のマニフェストと実行記録にも残る |
| build.warning_list | array | 不可 | 警告。`dry_run` のときだけ、T より大きい行・畳みで負ける古い行の内容違反 V10・V11（止めずに警告。db.md 6.3）と、T より大きい `deploy_key` の行数。`start_at_too_soon`・`start_at_shadowed`・`already_registered`（同じ master・同じ `start_at` の行が既にあり、新しい行を作らず既存を返した。`deploy.is_new = false`。db.md 3.6 の二重登録の防止）・`schedule_cache_not_invalidated`・`register_confirmed_after_error`（COMMIT 処理中のエラーの後、読み直したら登録済みだった。run は `succeeded`。db.md 4.7）は、dry_run かどうかに依らず（該当すれば）出す |
| artifact | object | 可 | `hash`・`artifact_dir`・`file_size`・`table_count`・`file_count`・`reused`（同じ `{deploy_key}_{hash}` が既にあり、ファイルを作り直さなかったか。db.md 6.5.2）・`tables`（表名 → `{hash, file_name, file_size, public_url}`。`public_url` は `/masterdata/{artifact_dir}/{file_name}`）。dry_run は null |
| cache.invalidated | bool | 可 | schedule キーの破棄に成功したか（db.md 3.6。**mst のキャッシュは破棄しない**）。dry_run は null。`false` のときは `warning_list` に `schedule_cache_not_invalidated`（登録は成功のまま。「再試行する、または `clear-cache`」の案内。9.4） |
| inventory | object | 可 | **dry_run のときだけ**。9 桁の棚卸し（db.md 9.3。`digit_distribution`・`legacy_row_list`・`conversion_conflict_list`・`order_change_key_count`） |

```json
{
  "status": "success",
  "message": "新しい版を登録しました（deploy_key=2026120101）。",
  "dry_run": false,
  "run_id": 12,
  "deploy": { "mode": "explicit", "deploy_key": 2026120101, "hash": "0f3c...", "artifact_dir": "2026120101_0f3c...", "mst_database": "nexus-qa-master-2026120101-0f3c1a2b3c4d5e6f", "sys_deploy_master_id": 27, "sys_deploy_id": 31, "start_at": "2026-12-01 15:00:00", "is_new": true, "database_reused": false },
  "build": {
    "table_list": [
      { "table": "mst_config", "input_rows": 9, "ignored_inactive_rows": 0, "adopted_rows": 6, "empty": false }
    ],
    "ignored_tab_list": [{ "sheet": "メモ/メモ1", "reason": "not_mst_tab" }],
    "ignored_column_list": [],
    "row_digest_list": [{ "deploy_key": 2026010101, "digest": "ab12..." }],
    "warning_list": []
  },
  "artifact": { "hash": "0f3c...", "artifact_dir": "2026120101_0f3c...", "file_size": 40960, "table_count": 120, "file_count": 80, "reused": false, "tables": { "mst_config": { "hash": "ab12...", "file_name": "mst_config_ab12....sqlite", "file_size": 4096, "public_url": "/masterdata/2026120101_0f3c.../mst_config_ab12....sqlite" } } },
  "cache": { "invalidated": true }
}
```
JSON 例はこの設計から組み立てた例で、実行出力ではない（`start_at` の 15:00:00 UTC は JST の翌 0 時）。

### エラー
共通の表（13 節）のうち build に当たるもの。**状態（変更したもの）**は 13 節の列を参照。build 固有の補足:
- 検査の順序（db.md 6.6）: `role_forbidden` → `fixed_key_forbidden`・`fixed_key_invalid`・`deploy_key_not_configured`・`reserved_key_forbidden`（`explicit_key` の形・予約帯）→ `base_database_mismatch`・`mst_database_name_too_long`（ここまでロックの前）→ **ロック**（`deploy_locked`）→ 実行記録（`running`・phase `lock`）→ **I6 `start_at` の検査（`start_at_past`。DB を作る前）** → 読む（`sheet_not_configured`・`sheet_read_failed`）→ 検証（`sheet_invalid`）→ 畳み → 作業用 DB（`load_failed`）→ 成果物（`export_failed`）→ `version_db`（`load_failed`・`mst_database_collision`）→ 配置（`store_failed`）→ 登録（`register_failed`・`register_unknown`）。
- `start_at_past`（I6）は DB を作る前に判定する（明示された過去の `start_at` は production 以外では許される: `reject_past_start_at`。許容幅 `start_at_past_grace` 以内は現在に丸めて通す。db.md 3.6・7.2）。失敗しても DB・成果物は作られていない。
- `sheet_invalid` は `error_list` に `{ sheet, table, row, column, code, message }` を最大 20 件、`error_total`・`error_count_by_code` を添える（旧と同じ）。内訳の `code` は db.md 6.3 の V01〜V12（`is_active_column_missing`・`unknown_table`・`deploy_key_column_missing`・`deploy_key_empty`・`deploy_key_not_number`・`deploy_key_legacy_9digits`・`deploy_key_legacy_reserved`・`deploy_key_out_of_range`・`id_empty`・`id_whitespace`・`l10n_parent_empty`・`l10n_language_empty`・`deleted_flag_invalid`・`duplicate_row`・`id_case_variant`・`no_fold_key`・`rarity_invalid`・`id_too_long`・`id_rule_mismatch`・`id_colon`・`unique_group_duplicate`）。
- 失敗したとき、この run が**新規に作った**作業用 DB・版の DB だけを `DROP` する（ベストエフォート。db.md 4.7）。置いた成果物のディレクトリは害が無いのでそのまま。**sys の COMMIT の成否が不明のときは `DROP` しない**（読み直して登録済みなら成功＝`register_confirmed_after_error`。行が無ければ `register_failed` で閉じて DB は孤児掃除に回す＝既定。sys を読めないときだけ `register_unknown`。db.md 4.7）（登録済みの版の DB を消さないため。db.md 4.7）。**sys は 1 トランザクションなので、2 つの DB にまたがる不整合（旧 `inconsistent`）は起きない**。

### 更新リソース
作業用 DB（作って捨てる）、版の DB（新規または復元）、ストレージ（`master_artifact` の `{deploy_key}_{hash}/`）、`sys_deploy_master`（新規または復元時の `mst_database`・`mst_dropped_at`）・`sys_deploy_master_table`・`sys_deploy_asset`・`sys_deploy`、`tol_master_deploy_run`、Redis の `sys:sys_deploy:schedule`（破棄）。**mst のキャッシュ・基底 DB・他の版の DB は変えない**。

### 冪等・排他
- **同じシート・同じ T の再実行**: 畳みが同じ → NDJSON が同じ → 同じ `(deploy_key, hash)` → 成果物と版の DB は再利用し（`database_reused = true`、DB が使える状態を db.md 6.6 の `version_db` で確かめた上で）、`sys_deploy` の行だけを足す（新しい `id`）。**キーを消費しない・成果物を作り直さない**。二重クリックは、後ろの 1 つが `deploy_locked` で止まる。
- 排他は 11 節。

## 7. POST /master-import/import
import の環境（review・production・staging）で、**上流の成果物を pull → 検証 → 版の DB → 登録前の検査 → 自環境のストレージに配置 → sys に登録（`start_at` を指定）**を行う（db.md 6.6・6.7）。**シートを読まず、畳まない**。build の環境では `role_forbidden`。

### Post
| 項目 | 型 | 必須 | 検証 | 意味 |
| --- | --- | --- | --- | --- |
| artifact_dir | string | 必須 | `regex:/^[0-9]{10}_[0-9a-f]{64}$/` | 取り込む成果物（`{deploy_key}_{hash}`）。`artifact-list` から選ぶ。パスの区切り・`..` を許さない形にしている（ストレージのパスに使うため） |
| start_at | string\|null | 必須（キーを必ず送る。値は null 可） | `present\|nullable\|date_format:Y-m-d H:i:s` | `sys_deploy.start_at`（UTC）。**null（画面の「すぐ」）= 登録の直前の現在時刻**。ADR 0007 の「取り込む成果物と `start_at` を指定」に沿って、**指定を省略できない形**にした（誤って即時にしないため。**仮置き**）。production は例えば 0 時 UTC、staging は production と同じ値（取り込み時点で既に過ぎていてもよい） |
| dry_run | bool | 任意（既定 false） | `boolean` | true なら、pull・検証（I1〜I3）・DB を要しない検査（I4・I6）・一時の DB を作っての `version_db` と I5 までを行い、**この run が作った一時の DB と一時の場所を必ず片付け、ストレージ・sys・キャッシュを変えない**（db.md 6.6）。ロックは取る（一時の DB と一時の場所を作るため。**build の dry_run と違う**）。実行記録は書かない |

### Response（成功）
| 項目 | 型 | null | 意味 |
| --- | --- | --- | --- |
| status・message・dry_run・run_id | | | build と同じ |
| deploy.deploy_key・hash・artifact_dir・mst_database・sys_deploy_master_id・sys_deploy_id・start_at・is_new・database_reused | | | build と同じ（成果物から決まる。dry_run のとき `mst_database` は null、他の「登録した」系は null） |
| import.table_list | array | 不可 | 表ごと: `table`・`row_count`（マニフェスト）・`loaded`（投入した行。既存の DB を再利用したときは 0） |
| import.check_list | array | 不可 | 登録前の検査の結果: `check`（`I1`〜`I6`）・`result`（`passed`・`skipped`・`warned`）・`detail`。既存の master を再利用する（R1）ときは I4 の「前進」と I5 は `skipped`（db.md 6.6 の import 6） |
| import.reference | object | 可 | 参照版 A（`enforce_confirmed_rows` の環境のみ）: `sys_deploy_master_id`・`deploy_key`・`restored`（A の DB を成果物から復元して比べたか） |
| import.warning_list | array | 不可 | `start_at_too_soon`・`start_at_shadowed` |
| artifact | object | 可 | `hash`・`artifact_dir`・`file_count`・`tables`（build と同じ形）。dry_run は null |
| cache.invalidated | bool | 可 | build と同じ |

### エラー
13 節の表のうち import に当たるもの。補足:
- 検査の順序（db.md 6.6・6.7 の「検査の順序」）: `role_forbidden` → `upstream_not_configured`・`base_database_mismatch`・`mst_database_name_too_long`（ロックの前）→ **ロック** → 実行記録（phase `lock`）→ **pull**（`pull`。`artifact_not_found`・`pull_failed`）→ **検証**（`verify`。I1〜I3: `artifact_invalid`・`not_finalized`・`reserved_key_forbidden`・`schema_mismatch`）→ **DB を要しない検査**（`check`。I4・I6: `deploy_key_regression`・`confirmed_key_modified`（R3。同じ `deploy_key` の別 hash）・`start_at_past`）→ **`version_db`**（`load_failed`・`mst_database_collision`）→ **行の不変性**（`check_rows`。I5: `confirmed_key_modified`・`reference_unavailable`。production）→ 配置（`store`。`store_failed`）→ 登録（`register`。`register_failed`・`register_unknown`）。**I4・I6 の失敗は DB を作る前なので、`DROP` するものは無い。I5 以降の失敗は、この run が作った DB だけを `DROP` する**。
- `not_finalized`（**提案**。8 節）: `require_finalized` の環境で、成果物に `final.json` が無い、または `final.json` の `deploy_key`・`hash` がマニフェストと違う（db.md 6.5.1・I1）。
- `confirmed_key_modified` は `error_list` に `{ table, id }` を最大 20 件、`error_total` を添える（I5 の差分。db.md 6.7）。同じ `deploy_key` で別 hash の master が既にあるとき（R3。**全 import 環境**で効く。I4 の段階。`error_list` は空）も同じコード。

### 更新リソース
build と同じ（作業用 DB は使わず、`data/*.ndjson` を直接投入）。加えて、pull の一時の場所（片付ける）。**`reference` が復元を要したときは、参照版 A の版の DB と `sys_deploy_master.mst_database`・`mst_dropped_at` も更新する**（db.md 6.7 の I5）。

### 冪等・排他
- **同じ成果物・同じ `start_at` の再実行**: `(deploy_key, hash)` が既にあれば R1（再利用）で、`sys_deploy` の行を足す。**同じ `sys_deploy_master`・同じ `start_at` の行が既にあるときは、新しい行を作らず既存の行を返す**（`is_new = false`・警告 `already_registered`。db.md 3.6。sys の COMMIT の成否が不明だったあとの再実行などで行が増えない）。`start_at` が違えば別の予約になる（二重の予約は 9.2 の取り消しで直す）。`start_at` が null（「すぐ」）の登録は毎回違う時刻になるので、二重クリックは**行が 2 つ増える**（ロックで直列になる）。画面は実行中はボタンを無効にして防ぐ。
- 排他は 11 節。

## 8. POST /master-import/finalize（QA 完了。**提案**）
**R28 は人の確認待ちの提案**。人の決定は「QA 完了時の成果物が最終版」「LD の承認は hash と結びつけない」まで。以下は、決定を実現する操作の**提案**で、`role = build` の QA の環境で使う（予約キーの成果物は `reserved_key_forbidden`。下のエラー）。

- 目的: ① 成果物がシートの現在の内容に**追従している**ことを確かめ（追従検出）、② QA 完了の印（**最終版の確定**）を成果物に付ける。③ 印の無い成果物（古い build）を、review 以降が import できないようにする（ゲートは QA 側。シートを読めるのは QA の環境だけだから）。
- **追従検出**（db.md 6.7）: 現在のシートを、成果物の `deploy_key`（= この環境の T）で畳み直し、`row_digest`（`deploy_key` ごとの畳み後の行ダイジェスト）を成果物のものと比べる。結果は `match`・`changed`・`unverified`。**読み取りだけ**。`master:verify-sheet` と `finalize --dry-run` は**同じ処理**で、どちらも `dry_run` なので実行記録を書かない。
- **finalize は追従検出の一致を前提にする**（db.md 6.5.1）: `changed`（読めて違う）は拒否（`sheet_changed_since_build`。**`allow_unverified` では通せない**）。
- **シートを読めない・認証情報が無い場合**: build と違い、`sheet_not_configured`・`sheet_read_failed` ではなく **`follow_check_unavailable` に読み替えて拒否する**（読めない場合だけ `allow_unverified` で通せるようにするため。db.md 6.5.1）。`allow_unverified = true`（CLI `--allow-unverified`）のときは、拒否せず警告 `follow_check_unverified` で通す。**通したことは実行記録に残す**（`summary.follow_check = {status: unverified, reason}`・`summary.allow_unverified = true`・`adm_account_id`。監査のため）。`final.json` の中身は変えない（`unverified` の印は成果物に付けない）。**`allow_unverified` を画面の API・ボタンでも許すかは未決**（仮置き: POST では受けるが、画面はボタンを出さず CLI だけにする案）。
- **印（`final.json`。db.md 6.5.1。提案。R28）**: 成果物のディレクトリに `final.json` = `{deploy_key, hash, finalized_at (UTC), finalized_by_env}` を、`manifest.json` の**後**に別に書く。`hash` に含めない。**QA の `finalize`（`master:finalize {deploy_key}_{hash}`）だけが書き、書いた後は上書きしない**。pull は `final.json` ごと写す（review → production → staging と印が引き継がれる）。設定 `require_finalized`（7.2）が true の環境の import は、`final.json` が無い、または `deploy_key`・`hash` がマニフェストと違う成果物を拒否する（`not_finalized`。db.md I1）。**誰が行ったかは実行記録の `adm_account_id`**（`final.json` には入れない）。
- **`final.json` が既にあるとき**（db.md 6.5.1。**仮置き**）: 中身の `deploy_key`・`hash` が今回の成果物と**一致する**なら、**何もせず成功コード `already_finalized`**（冪等）。**一致しない**（別の成果物の印がある、または壊れている）なら **`finalize_conflict` で拒否**（上書きしない）。**この確認を、シートの読み込み（追従検出）より先に行う**（既にある場合にシートを読み直さない。仮置き）。

### Post
| 項目 | 型 | 必須 | 検証 | 意味 |
| --- | --- | --- | --- | --- |
| artifact_dir | string | 必須 | 7 節と同じ regex | QA 完了にする成果物。**自環境の disk（`master_artifact`）にあるもの**で、`deploy_key` が環境の T と一致すること |
| dry_run | bool | 任意（既定 false） | `boolean` | true なら追従検出だけ（印を付けない。ロックを取らない。実行記録も書かない） |
| allow_unverified | bool | 任意（既定 false） | `boolean` | true なら、シートを**読めないときだけ**、拒否せず警告で通す（上）。読めて違うときは通せない |

### Response（成功）
| 項目 | 型 | null | 意味 |
| --- | --- | --- | --- |
| status・message・dry_run・run_id | | | 旧と同じ。**`dry_run` は実行記録を書かない**（`run_id` は null）。実行する finalize は `kind = finalize`（phase `lock`・`check`・`register`・`done`。`check` = 追従検出、`register` = `final.json` の書き込み。db.md 5.2）で記録し、追従検出の結果を `summary.follow_check` に残す |
| code | string | 可 | **成功コード**: `finalized`（印を書いた）・`already_finalized`（すでに一致する印がある。冪等。何も書かない）。dry_run は null |
| follow_check | object | 可 | `status`（`match`・`changed`・`unverified`）・`artifact_dir`・`changed_deploy_key_list`（ダイジェストが違った `deploy_key`）・`checked_at`・`reason`（`unverified` のとき）。`already_finalized` のときは追従検出を行わないので null |
| warning_list | array | 不可 | `follow_check_unverified`（`allow_unverified` で通した） |

### エラー
`role_forbidden`・`deploy_locked`・`reserved_key_forbidden`（**予約帯の成果物**。`fixed_key` の環境（develop・LD 仮）の成果物は予約キーなので finalize の対象にならない。`fixed_key_forbidden` は db.md 7.2 のとおり「本番・ステージングで `fixed_key` が設定されている」環境設定の誤りの専用で、ここでは使わない）・`artifact_not_found`（404）・`artifact_invalid`・`deploy_key_mismatch`（成果物の `deploy_key` が環境の T と違う。422）・`finalize_conflict`（409。`final.json` が既にあり、今回の成果物と違う。上書きしない）・`follow_check_unavailable`（422。シートを読めない・認証情報が無い。**この操作では `sheet_not_configured`・`sheet_read_failed` をこのコードに読み替える**。`allow_unverified` で通せる）・`sheet_invalid`（畳み直しで V01〜V12 に当たった。読めて違う側。追従検出ができない）・`sheet_changed_since_build`（`changed`。409。`error_list` に `deploy_key` ごとの違い。印は付けない。**build をやり直して再度 QA する運用**。`allow_unverified` で通せない）・`store_failed`（`final.json` を書けない）・`internal_error`。

### 更新リソース
成果物ディレクトリの `final.json`（`dry_run` と `already_finalized` は書かない）、`tol_master_deploy_run`（`kind = finalize`。`dry_run` は書かない）。他は変えない。

## 9. 巻き戻し・予約の取り消し・掃除・clear-cache

### 9.1 POST /master-import/rollback（巻き戻し。db.md 3.6）
戻したい版を指す**新しい `sys_deploy` 行を、`start_at = max(現在, 直前の最新行の start_at) + 1 秒` で足す**（db.md 3.6・R43。**直前の最新行 = 巻き戻しの時点でいまの版**。同じ秒だと同点の規則（`deploy_key` が大きい方）で戻し先が負けて黙って無効になりうるので、必ず直前の最新行より後にする）。DB・成果物は（復元の場合を除き）作り直さない。**予約済みの未来の行があれば拒否**し、先に 9.2 を要求する。

- **「予約済み」の判定から除く行**（db.md 3.6。**仮置き**）: `start_at > 現在` の行のうち、**`start_at <= 現在 + start_at_past_grace`（60 秒）かつ戻し先と同じ `sys_deploy_master` を指す行**は予約済みに数えない（巻き戻しの `start_at` は現在より約 1 秒先になりうる。その行が約 1 秒間「予約済み」に見えて、直後のやり直しが拒否されるのを避ける）。**戻し先と別の版を指す、近い（60 秒以内）予約は除かない**（到達して巻き戻しが黙って無効になる問題が戻るため）。
- **復元か拒否かの判定**（db.md 3.6・P53）: 戻し先の版の DB が使えるなら、その DB のスキーマ版が現行と一致すること（4.8）。**DB が使えず成果物から復元するときは、戻し先の成果物の `schema_fingerprint` が自環境の基底 DB のスキーマ版と一致すれば復元、不一致は `schema_mismatch` で拒否**（import の I3 と同じ）。復元できなければ `rollback_target_unavailable`。

#### Post
| 項目 | 型 | 必須 | 検証 | 意味 |
| --- | --- | --- | --- | --- |
| sys_deploy_master_id | int | 必須 | `integer\|min:1` | 戻し先の版（`target.version_list` から選ぶ）。存在しなければ `rollback_target_not_found` |
| dry_run | bool | 任意（既定 false） | `boolean` | true なら全検査を行い、`needs_restore`・予約の一覧・足す行の `start_at` を返す。何も変えない（ロックは取らない。実行記録も書かない） |

`start_at` は受け取らない（上の式で決まる）。

#### Response（成功）
| 項目 | 型 | null | 意味 |
| --- | --- | --- | --- |
| status・message・dry_run・run_id | | | 旧と同じ（記録する run は `kind = rollback`。**phase は `lock`・`check`・`version_db`（戻し先の DB を復元するときだけ）・`register`・`done`**。db.md 3.6・5.2。dry_run は記録しない） |
| deploy | object | 不可 | `sys_deploy_master_id`・`sys_deploy_id`（足した行。dry_run は null）・`deploy_key`・`hash`・`mst_database`・`start_at`（`max(現在, 直前の最新行の start_at) + 1 秒`。**現在より最大 1 秒ほど先になりうる**）・`is_new`（同じ master・同じ `start_at` の行が既にあれば false。db.md 3.6 の二重登録の防止）・`restored`（DB を成果物から復元したか。復元したら `mst_database` は付け直した名前）・`previous_sys_deploy_id`（戻す前のいまの版） |
| warning_list | array | 不可 | `rollback_target_is_current`（戻し先がすでにいまの版。**止めない**。**仮置き**）・`already_registered`（上の `is_new = false`）・`schedule_cache_not_invalidated`（`forget` の失敗。登録は成功のまま）・`register_confirmed_after_error`（COMMIT 処理中のエラーの後、読み直したら登録済みだった。run は `succeeded`。db.md 4.7） |
| cache.invalidated | bool | 可 | schedule キーの破棄 |

#### エラー
- `rollback_blocked_by_scheduled`（409）: **`start_at` が現在より後の行がある**（ただし上の「除く行」= 現在 + 60 秒以内で戻し先と同じ master を指す行は数えない）。`error_list` に予約の一覧（`sys_deploy_id`・`deploy_key`・`start_at`）。メッセージは 9.2 の取り消しを案内する。**`is_active` を見るかの決定（R39）に依存しない**（db.md 3.6）。**`start_at` が現在 + 2 × `schedule_ttl`（120 秒）以内の予約は取り消せない**（`schedule_cancel_too_soon`。9.2。db.md の P47: 最大 120 秒の窓。緊急の手段は R39 待ち）ので、その予約が `start_at` に到達する（現在でなくなる）まで待ってから巻き戻す（到達後は通常の巻き戻しになる）。メッセージにもそう書く。
- `rollback_target_not_found`（404）・`rollback_target_unavailable`（422。DB が使えず、成果物からの復元もできない）・`schema_mismatch`（上の判定）・`reserved_key_forbidden`（本番で予約帯の版）・`mst_database_collision`・`load_failed`（復元で往復検査が合わない）・`register_failed`・`register_unknown`・`deploy_locked`・`internal_error`。

更新リソース: `sys_deploy`（1 行追加）、復元のとき版の DB・`sys_deploy_master.mst_database`・`mst_dropped_at`、`tol_master_deploy_run`（`kind = rollback`）、`sys:sys_deploy:schedule`。冪等: **同じ戻し先への 2 回目は、`start_at` が「直前の最新行（1 回目の行を含みうる）+ 1 秒」になるので、既存の行と一致せず（`already_registered` にならず）行がもう 1 つ増える**（同じ版を指すだけで害は無い。db.md 3.6 が許容）。画面は実行中はボタンを無効にし、`rollback_target_is_current` の警告を出す。

### 9.2 POST /master-import/cancel-schedule（予約の取り消し。db.md 3.6）
`start_at` が**到達していない** `sys_deploy` 行を**削除する**（`DELETE ... WHERE id = :id AND start_at > :now`）。`sys_deploy_master`・版の DB・成果物は残す。**「まだ配信されていないので履歴の欠落が無い」という理由づけは使わない**（tool と API の時計のずれで「到達していない」の判定が崩れうる。db.md 3.6）。代わりに、次の保護で範囲を絞る。

- **直前の取り消しを拒否する**（`schedule_cancel_too_soon`）: `start_at` が**現在 + 2 × `schedule_ttl`（既定 120 秒）以内**の行は取り消さない。API は切り替え予定の一覧を TTL（60 秒）でキャッシュし、取り消し後の `forget` は失敗しうる（tool と API が同じ Redis かは未確認）ので、直前の取り消しは API のキャッシュに取り消した行が残ったまま `start_at` に到達し、取り消した版が最大 TTL の間配信されるため。**保護の範囲外（`start_at` が十分先）なら、`forget` が失敗しても TTL が `start_at` より前に切れる**ので安全。
- **取り消し後の `forget` の失敗は成功扱いにしない**: 取り消し自体は成功（HTTP 200）だが、`cache.invalidated = false` と警告 `schedule_cache_not_invalidated` を返し、画面・CLI は「**再試行する、または `clear-cache`（9.4）を実行する**」を案内する。実行記録の `summary.cache_invalidated` にも残す。

判定の順序: ① 行が無い → `schedule_not_found` ② `start_at <= 現在`（到達済み）→ `schedule_already_started` ③ `現在 < start_at <= 現在 + 2 × schedule_ttl` → `schedule_cancel_too_soon` ④ `DELETE` の影響行数が 0（②③ の判定の直後に到達した）→ `schedule_already_started`。

| 項目 | 型 | 必須 | 検証 | 意味 |
| --- | --- | --- | --- | --- |
| sys_deploy_id | int | 必須 | `integer\|min:1` | 取り消す `sys_deploy.id`（`target.scheduled_list` から選ぶ） |

Response: `status`・`message`・`run_id`・`cancelled`（`sys_deploy_id`・`sys_deploy_master_id`・`deploy_key`・`start_at`）・`remaining_scheduled_list`・`cache.invalidated`・`warning_list`（`schedule_cache_not_invalidated`）。

エラー: `schedule_not_found`（404。**二重実行の 2 回目**もこれ）・`schedule_already_started`（409。巻き戻しの操作からやり直す）・`schedule_cancel_too_soon`（409。13.1）・`deploy_locked`・`internal_error`。更新リソース: `sys_deploy`（1 行削除）、`tol_master_deploy_run`（`kind = cancel_schedule`。phase は `lock`・`check`・`register`（= `DELETE`）・`done`）、`sys:sys_deploy:schedule`（破棄）。`is_active` を 0 にする方式は採らない（db.md 3.6）。

### 9.3 POST /master-import/cleanup（版の DB・孤児・一時の場所の掃除。db.md 4.6・4.7）
**手動**（定期実行は作らない）。**既定は dry_run**（db.md 4.6）。

| 項目 | 型 | 必須 | 検証 | 意味 |
| --- | --- | --- | --- | --- |
| dry_run | bool | 任意（**既定 true**） | `boolean` | true なら対象の一覧だけ（ロックを取らず、実行記録も書かない）。false で `DROP`・削除を行う。画面は dry_run の結果を見せてから実行のボタンを出す |

保持数 N（`keep_version_count` 既定 3）・猶予（`orphan_grace_hours` 既定 24）は環境設定（db.md 7.2）。リクエストでは渡さない（誤って全部消さないため）。

**dry_run の一覧は参考**で、実行（`dry_run = false`）は**ロックを取った後に対象を再計算する**（dry_run のあとに版が切り替わったり、新しい run ができたりしうる。実行が消すのは、実行時に再計算した一覧だけ。`drop_list` は再計算の結果を返す）。

**対象の種類**（`drop_list[].kind`。`DROP` の保護は db.md 4.6 のとおり、自環境の接頭辞 `{APP_NAME}-{env}-master-` の名前だけ）:

| kind | 対象 | 条件 |
| --- | --- | --- |
| `version` | 版の DB | いまの版・予約済みの版・直近 N 版（`sys_deploy_master` の重複排除後で数える。db.md 4.6）・基底 DB の外にあるもの。**消し方の順序**: **`DROP DATABASE IF EXISTS` を先、`sys_deploy_master.mst_dropped_at` の更新を後**にする（途中で止まっても、再実行で冪等に完了する。`IF EXISTS` なので 2 回目の DROP は何もせず更新が済む。逆順だと DB が残ったまま「消した」ことになり掃除から漏れる）。成果物は残す |
| `orphan` | 孤児の DB | 実行記録で `failed`・`aborted` の run の `mst_database`・`created_database_list` のうち、`sys_deploy_master` に無く、**その run が新規に作ったもの**で、猶予を過ぎたもの（sys を再確認してから消す）。**除外**: **`error_code = register_unknown` かつ `summary.resolved` が無い run**（人が確認する。9.5）。**`master:resolve-unknown` で解決した run（`summary.resolved` がある）は除外から外れる**（`dropped` で DB が既に消えていれば対象も無い） |
| `work` | 作業用 DB | 接頭辞 `{APP_NAME}-{env}-master-work-` で、実行記録が無い（dry run の異常終了で残った）もので、名前の時刻が猶予を過ぎたもの。**接頭辞に限る**（サーバー全体の走査はしない） |
| `tmp` | 一時の場所 | `master_artifact` の disk の `.tmp/{UTC の時刻}-{run の id}/` で、名前の時刻が猶予を過ぎたもの（DB ではないのでパスで示す） |

Response: `status`・`message`・`dry_run`・`run_id`（dry_run は null）・`keep_list`（残す版。`sys_deploy_master_id`・`mst_database`・`reason`: `current`・`scheduled`・`recent`）・`drop_list`（消す: `kind`・`name`（DB 名または一時の場所のパス）・`sys_deploy_master_id`（`version` のみ。他は null）・`reason`）・`dropped_list`（実際に消したもの。`kind`・`name`）・`failed_list`（消せなかったもの。`kind`・`name`・`message`）。

エラー: `deploy_locked`・`drop_forbidden`（403。保護の規則に当たる名前が対象に入っていた。実行しない。db.md 4.6）・`cleanup_failed`（500。`failed_list` が 1 件以上。**ベストエフォートなので、消せたものは消えたまま**で `dropped_list` を返す）・`internal_error`。更新リソース: 版・孤児・作業用の DB（`DROP`）、一時の場所（削除）、`sys_deploy_master.mst_dropped_at`、`tol_master_deploy_run`（`kind = cleanup`。phase は `lock`・`check`・`drop`・`done`。作った・消した名前を `summary` に残す。db.md 5.1）。**成果物（`.tmp` 以外）・基底 DB・他の環境の DB は消さない**。冪等: 消したものは対象外（2 回目は `dropped_list` が空）。**サーバーを `SHOW DATABASES` で走査して消すことはしない**（db.md 4.7）。

### 9.4 POST /master-import/clear-cache（残して縮める）
**判断（仮置き。db.md 10.4 に従う）: 廃止せず、schedule キーの破棄だけに縮めて残す。** 理由: mst のキャッシュキーに版（DB 名）が入り、版の DB は書き換えないので、mst のキャッシュの破棄は**原則不要**（db.md 8.4）。残る破棄は `sys:sys_deploy:schedule` だけで、登録後の `forget` が失敗したときの手動のやり直し口になる（ただし TTL 60 秒で自然に直るので、緊急性は低い）。基底 DB を指す開発のシーダーのキャッシュ（db.md 8.4 の例外）は、このエンドポイントでは破棄しない（TTL かシーダーが破棄する。開発だけの問題）。

- Post: なし。ロックは取らない（読み取りと破棄だけ。冪等）。
- 処理: `sys:sys_deploy:schedule` と、`sys:sys_deploy:id:*`・`sys:sys_deploy:deploy_key:*`・`sys:sys_deploy_master:{id}:tables` を `forget` する（`id`・`deploy_key`・`tables` は登録後に値が変わらないので通常は不要だが、緊急停止の運用で使えるように含める。**仮置き**）。
- Response: `{ status, message, cache: { invalidated, failed_key_list } }`。破棄に失敗したら HTTP 502・`code = cache_clear_failed`。
- 旧の「mst の全表（`SHOW TABLES LIKE 'mst_%'`）の `forget`」は**廃止**（版つきキーのため）。
- **tool と api が同じ Redis・同じ接頭辞か**は**未確認**（Nexus overview F13・R18）。**別なら、この破棄は効かない**（`forget` は成功しても API のキャッシュは残る）。その場合は TTL（60 秒）で切れるのを待つ。
- **旧キーの扱い**（db.md 8.3）: 旧キャッシュキー **`sys:sys_deploy:latest_downloadable`**（`SysDeployRepository` の現行のキー。tool の `MasterCacheInvalidator::SYS_DEPLOY_LATEST_KEY`）は、新キー `sys:sys_deploy:schedule` への置き換えで**廃止**する。旧キーは Redis に最大 3600 秒残るので、**切り替えの初回に `clear-cache` か `forget` で旧キーも消す**（リリース手順に入れる。残っても新コードは読まないので害は無いがメモリを占める）。そのため `clear-cache` の対象に旧キー（切り替えの初回だけ）を含める。
- **運用手順（`schedule_cancel_too_soon` の保護とセット）**: 登録・巻き戻し・取り消しの結果に `schedule_cache_not_invalidated` が出たら、① 同じ操作を再試行するのではなく、まず `clear-cache` を実行する（登録・取り消しは済んでいるため）② それも失敗する、または Redis が別なら、**最大 TTL（60 秒）待つ**。**取り消しは `start_at` が 120 秒より先のものに限られる**（9.2）ので、60 秒待てばキャッシュは `start_at` より前に切れる。直前（120 秒以内）の予約は取り消せない（保護）ので、「`clear-cache` が効かないのに直前の取り消しが通る」状態は作らない。

### 9.5 POST /master-import/resolve-unknown（`register_unknown` の片付け。db.md 4.7）
**sys の COMMIT の成否が不明で、sys を読めなかった run**（`error_code = register_unknown`・`status = failed`）の DB を、人が確認して片付ける操作（**仮置き**。URL・名前は仮）。**新しい run は作らず、対象の既存の run を更新するだけ**（`kind` は足さない。監査は `resolved_by`＝`adm_account_id`。db.md 4.7）。**放置すると、その run は掃除（cleanup）の対象外のまま、DB が残り続ける**（target の警告 `register_unknown` が出続ける）。**ロックの中で行う**。

| 項目 | 型 | 必須 | 検証 | 意味 |
| --- | --- | --- | --- | --- |
| run_id | int | 必須 | `integer\|min:1` | 対象の run（`target.last_run`・警告から選ぶ） |

**処理**（ツールが sys を再確認して決める。人が誤って登録済みの DB を `DROP` しないため。db.md 4.7）:
1. **sys を読めなければ拒否**（`sys_unreadable`。run は `register_unknown` のまま）。
2. **(a) 登録済み**（sys に同じ `mst_database` の行がある）: run を **`succeeded` に直す**（`error_code` は消さず、`summary.resolved = registered`・`resolved_at`・`resolved_by` を残す）。**DB は消さない**。schedule キーを `forget` する（失敗は警告 `schedule_cache_not_invalidated`）。
3. **(b) 未登録**（sys に行が無い）: **「sys に同じ `mst_database` の行が無い」かつ「その run の `created_database_list` にある名前」に限り**、`DROP DATABASE IF EXISTS`（4.6 の保護の規則つき）。run は **`failed` のまま**閉じる（`summary.resolved = dropped`・`resolved_at`・`resolved_by`。`error_code = register_unknown` は残す）。**どちらかの条件を満たさなければ拒否**（`drop_forbidden`。再現: run1 が `register_unknown` → 再実行の run2 が同名の DB を採用して登録 → run1 を「未登録」と判断して DROP すると登録済みの DB が消える。sys の再確認と `created_database_list`（採用した DB は入らない）の条件が防ぐ）。

Response: `status`・`message`・`run_id`・`code`（`resolved`）・`resolved`（`registered` か `dropped`）・`cache.invalidated`（(a) のみ。他は null）・`warning_list`。

エラー: `deploy_locked`・`run_not_found`（404。その run が無い、または `error_code` が `register_unknown` でない）・`sys_unreadable`（503。sys を読めない）・`drop_forbidden`（(b) の条件不一致・保護の規則）・`drop_failed`（500。`DROP` に失敗。run は `register_unknown` のまま。再実行できる）・`internal_error`。更新リソース: 対象の `tol_master_deploy_run`（`summary`・(a) は `status`）、(b) は版の DB（`DROP`）、(a) は `sys:sys_deploy:schedule`。**冪等**: 既に `summary.resolved` がある run への 2 回目は、何もせず成功コード `already_resolved` を返す。

### 9.6 POST /master-import/deactivate・POST /master-import/activate（緊急停止・再開。db.md 3.3・3.6）
**R39 は決定（人。2026-11-23。A）: `is_active` を最新の選択で見る。位置づけは緊急停止専用**。以下の操作の名前・形（URL・CLI・応答）は**仮置き**（決定は「見る」まで）。運営が**明示的に操作したときだけ** `sys_deploy.is_active` を 0（`deactivate`）／1（`activate`）にする。**build・import・rollback・cancel-schedule は `is_active` を自動では変えない**。目的は、予約の `start_at` の直前（現在 + 2 × `schedule_ttl` ＝ 120 秒以内）の「取り消しも巻き戻しもできない窓」（P47）で、悪い版が配信される直前に止められる**唯一の手段**にすること。窓の中でも使える。

| 項目 | 型 | 必須 | 検証 | 意味 |
| --- | --- | --- | --- | --- |
| sys_deploy_id | int | 必須 | `integer\|min:1` | 止める（再開する）`sys_deploy.id`（`target.scheduled_list`・`current_version`・`inactive_list` から選ぶ） |

- **判定の順序**: ① 行が無い → `deploy_not_found`（404）② すでにその状態（deactivate で `is_active = false`、activate で true）→ **更新はせず成功**（`already_in_state`。冪等。**`forget` は行う**＝下。③ ③' は通らない）③ `deactivate` のみ: **止めた後に最新の選択が空になる、または掃除済み（`mst_dropped_at`）の版に落ちる** → `deactivate_no_fallback`（409。配信が 299 になる止め方を防ぐ。選択に影響しない行は検査なしで通す）③' `activate` のみ: **再開で最新の選択になる行の master が掃除済み**（`mst_dropped_at`・DB が無い・不一致）→ **成果物から復元（phase `version_db`。db.md 6.6）してから**有効にする。**復元できなければ** `activate_target_dropped`（409。**仮称**。更新しない。`schema_mismatch` で復元できないときも同じ）。再開しても最新の選択にならない行は検査なしで通す ④ `UPDATE`。
- **`forget`**: 更新の**後**に `schedule` キーを破棄する（db.md 3.3・8.3。止めたあとも、キャッシュに最大 60 秒古い判断が残るため）。**失敗は成功扱いにしない**（取り消しと同じ）: 操作は成功（HTTP 200）で、`cache.invalidated = false` と警告 `schedule_cache_not_invalidated` を返し、「再試行する、または `clear-cache`（9.4）を実行する」と、**最大 60 秒、止めた版が配信されうる**ことを案内する。**`already_in_state` のときも `forget` を行う**（更新は無いが、前回 `forget` に失敗した後に**同じ操作をもう一度実行すると `forget` だけ行われる**。案内の「再試行」はこれ）。
- 止めた行は残り、`scheduled_list` に出続ける（`start_at > 現在` なら巻き戻しは `rollback_blocked_by_scheduled`。先に取り消す、または再開する）。**窓の中（現在 + 2 × `schedule_ttl` 以内）の未来の行を止めたときは、取り消しもできず巻き戻しも拒否されるので、`start_at` の到達を待つ**（到達後は予約済みから外れ、巻き戻せる）。画面・CLI はこの案内を出す。

Response: `status`・`message`・`run_id`・`code`・`deploy`（`sys_deploy_id`・`sys_deploy_master_id`・`deploy_key`・`start_at`・`is_active`）・`current_version_after`（止めた・再開した後の最新の選択。無ければ null）・`restored`（activate が成果物から復元したか。復元したときだけ true。他は false）・`cache.invalidated`・`warning_list`。**`already_in_state` のときの出方**: HTTP 200・`status = success`・**`code = already_in_state`**（通常の成功は `code = null`）・`message` は「すでにその状態」・`deploy.is_active` は現在値・`cache.invalidated`・`warning_list` は**この操作での `forget` の結果**（失敗なら `schedule_cache_not_invalidated`）・`run_id` は新しい run（`kind = deactivate`。`summary.already_in_state = true`）。CLI の終了コードは 0。

エラー: `deploy_not_found`（404）・`deactivate_no_fallback`（409）・`activate_target_dropped`（409。仮称）・`deploy_locked`・`internal_error`（role は両方可なので `role_forbidden` は無い。2 節）。更新リソース: `sys_deploy.is_active`、`tol_master_deploy_run`（`kind = deactivate`。activate も同じ kind で `summary.is_active` に新しい値。phase は `lock`・`check`・`version_db`（activate が復元するときだけ）・`register`（= `UPDATE` と `forget`）・`done`）、`deploy_key` 列は対象行の deploy_key、`sys:sys_deploy:schedule`（破棄）。

## 10. CLI

tool 側（`tool/app/Console/Commands/` は**まだ無い**。CLI は新規で、台帳の S3-14 は保留中。**仮置き**。名前は S3 で決めてよい）。画面と同じ部品（`MasterDeployRunner` など。db.md 10.4）を呼ぶ。`adm_account_id` は null で記録する。

| コマンド | 引数・オプション | 対応する操作 |
| --- | --- | --- |
| `php artisan master:build` | `--dry-run`・`--start-at="Y-m-d H:i:s"`（UTC。省略 = すぐ） | 6 節。T は引数に無い（環境設定だけ） |
| `php artisan master:import {artifact_dir}` | `--start-at="..."`（**必須**。`--start-at=now` で「すぐ」）・`--dry-run` | 7 節 |
| `php artisan master:finalize {artifact_dir}` | `--dry-run`・`--allow-unverified`（シートを**読めないときだけ**。読めて違うときは通せない。8 節） | 8 節（**提案**） |
| `php artisan master:verify-sheet {artifact_dir}` | なし（読み取り専用） | 8 節の追従検出だけ（`finalize --dry-run` と同じ処理）。db.md 6.7 の名前を残す |
| `php artisan master:rollback {sys_deploy_master_id}` | `--dry-run` | 9.1 |
| `php artisan master:cancel-schedule {sys_deploy_id}` | なし | 9.2 |
| `php artisan master:deactivate {sys_deploy_id}`・`master:activate {sys_deploy_id}` | なし | 9.6（緊急停止・再開。**仮置き**。db.md 3.6） |
| `php artisan master:resolve-unknown {run_id}` | なし | 9.5（`register_unknown` の片付け。db.md 4.7） |
| `php artisan master:cleanup` | **`--execute`**（付けないと dry run） | 9.3 |

（旧 `master:deploy` というコマンドは実装に無い。廃止するのは HTTP の `POST /master-import/deploy` ルートで、CLI は `master:build` が代わる。）

api 側:

| コマンド | 内容 |
| --- | --- |
| `php artisan mst:migrate-versions`（`api/`。db.md 4.8。**凍結後に使う**。凍結前は版の DB を作り直すので不要） | `sys_deploy_master` の `mst_dropped_at IS NULL` の全 DB に、`mst` 接続の database を差し替えて同じマイグレーションを流し、`schema_fingerprint` を更新する。`--dry-run`（対象の版と現在の指紋の一覧だけ）。1 つの版で失敗しても**止めず最後まで流し**、失敗した版を一覧で出して終了コード 1 にする（失敗した版は指紋が更新されないので、巻き戻し・復元が `schema_mismatch` で拒否される。**仮置き**）。リリース手順の一部（`make migrate` の後）。**tool の build・import が走っている間に流さない**ため、tool と同じ `MasterDeployLock` の名前（`nexus_master_deploy:{sys の DB 名}`）を取る（**仮置き**。api に同じロックを置く部品が要る） |

終了コード（tool の `master:*` 共通。**仮置き**。旧は成功 0・検証 2・排他 3・それ以外 1 で、**4 を足した**）:

| 終了コード | 意味 | 対応するエラー（13 節） |
| --- | --- | --- |
| 0 | 成功（dry run の成功を含む）。**成功に付く警告・成功コードも 0**: `already_finalized`・`already_resolved`・`register_confirmed_after_error`・`already_registered`・`schedule_cache_not_invalidated`・`follow_check_unverified` | - |
| 1 | 実行中の失敗・想定外 | `load_failed`・`export_failed`・`pull_failed`・`store_failed`・`register_failed`・`register_unknown`（**sys の COMMIT の成否が不明で sys も読めない。DB を `DROP` していない。人が確認する**ので、メッセージで明示し、`master:resolve-unknown` を案内する）・`mst_database_collision`・`sheet_read_failed`・`cleanup_failed`・`drop_failed`・`sys_unreadable`・`cache_clear_failed`・`internal_error` |
| 2 | 入力・シート・成果物の検証の失敗 | `sheet_invalid`・`artifact_invalid`・`not_finalized`・`finalize_conflict`・`schema_mismatch`・`confirmed_key_modified`・`deploy_key_regression`・`reference_unavailable`・`start_at_past`・`sheet_changed_since_build`・`deploy_key_mismatch`・`artifact_not_found`・入力の形式の誤り |
| 3 | 排他（他の操作が実行中） | `deploy_locked` |
| 4 | 環境・状態の前提で拒否 | `role_forbidden`・`fixed_key_forbidden`・`fixed_key_invalid`・`deploy_key_not_configured`・`reserved_key_forbidden`・`base_database_mismatch`・`mst_database_name_too_long`・`upstream_not_configured`・`sheet_not_configured`・`follow_check_unavailable`・`rollback_blocked_by_scheduled`・`rollback_target_not_found`・`rollback_target_unavailable`・`schedule_not_found`・`schedule_already_started`・`schedule_cancel_too_soon`・`run_not_found`・`drop_forbidden` |

出力は結果の表と `error_list` を標準出力に。用途: 録画を撮るローカル（`MASTER_DEPLOY_FIXED_KEY=7777777777`。db.md 11.2。録画の方式は**未決**・R19）、画面を開けない環境、CI 外の運用。

## 11. 排他・失敗時の整合・タイムアウト

### 11.1 排他
- 旧のとおり **MySQL の名前付きロック**（`GET_LOCK`）を `sys` 接続で取る（`MasterDeployLock`。名前は `nexus_master_deploy:{sys の DB 名}`、64 文字を超えたらハッシュで縮める。**タイムアウト 0**。`finally` で `RELEASE_LOCK`）。プロセスが落ちて接続が切れれば自動で外れる。
- **ロックの対象の操作**: build・import・finalize（dry_run を除く）・rollback・cancel-schedule・deactivate・activate・cleanup（dry_run を除く）・`master:resolve-unknown`・`mst:migrate-versions`（db.md 6.6）。**ロックを取らない**: build の `dry_run`・finalize の `dry_run`・verify-sheet・rollback の `dry_run`・cleanup の `dry_run`・target・artifact-list・clear-cache。**import の `dry_run` はロックを取る**（一時の DB を作るため。7 節）。
- ロックを取る順序: **環境設定の検査（role・本番ガード。build・import はさらに `base_database_mismatch`・`mst_database_name_too_long`）→ ロック → `tol_master_deploy_run` の `running` を作る（phase `lock`）→ 本処理**。ロックが取れて `running` の行が残っていれば `aborted` に直す（前のプロセスが落ちた跡）。**`dry_run` は実行記録を書かない**（db.md 5.1）。
- **操作ごとの実行記録の `kind` と phase**（db.md 5.2・12.1）: build = `lock`・`read`・`validate`・`fold`・`work_db`・`artifact`・`version_db`・`store`・`register`・`done`。import = `lock`・`pull`・`verify`・`check`・`version_db`・`check_rows`・`store`・`register`・`done`。**rollback = `lock`・`check`・`version_db`（復元するときだけ）・`register`・`done`**。cancel_schedule・finalize = `lock`・`check`・`register`・`done`。**deactivate（activate も同じ kind）= `lock`・`check`・`version_db`（activate が復元するときだけ）・`register`・`done`**。cleanup = `lock`・`check`・`drop`・`done`。`master:resolve-unknown` は**新しい run を作らず、既存の run を更新する**（`kind` は足さない）。`failed`・`aborted` で `mst_database` が残るものは孤児の候補（**`register_unknown` かつ `summary.resolved` が無い run は除く**。db.md 4.7）。
- **ロックは、build ではシートの読み込みから sys の登録・schedule キーの破棄まで持つ**（読み込みの間に別の操作が入ると、畳みの入力と登録の前提（予約の有無など）がずれるため。db.md 3.6）。
- 同時に動く他の書き込み（開発のシーダー・`make seed`）は排他しない（開発者が避ける。**仮置き**）。
- **登録は sys の 1 トランザクション**（db.md 3.6）。mst の DB への書き込みは登録の前に済んでいて、版の DB は以後書き換えない。旧設計の「mst と sys にまたがる補償・`inconsistent`」は無い。失敗しても孤児の DB が残るだけで配信には影響しない（4.7 のとおり、この run が作ったものだけをベストエフォートで `DROP` する）。

### 11.2 HTTP のタイムアウト（未確認）
build は Google API の呼び出しを全タブ分行い、import は成果物の pull と DB への投入（数万行の想定。**未確認**）を行うので、nginx の `fastcgi_read_timeout`（`docker/tool-nginx/tool-nginx.conf` に指定なし = 既定 60 秒）や PHP の実行時間を超えうる。**タブ数・行数・時間は未確認**。案: build・import・rollback（復元あり）・cleanup のリクエスト処理で `set_time_limit(0)`・`ignore_user_abort(true)` とし、tool-nginx の `fastcgi_read_timeout` を延ばす（既定案）。足りなければ CLI（タイムアウトが無い）に任せる。キューは使わない（ワーカーのコンテナが無い）。S3 で実測して決める。

## 12. ゲーム API（`api/`）の変更

**公開エンドポイント・Post・Response の項目は変えない**（Nexus 決定 D4・F8・F9。Nexus の `api.md` のとおり）。`GET`・`POST /api/auth/version` の入力（`deploy_version` = `sys_deploy.id`）・応答の項目、`X-Master-Hash`・`X-Master-Update-Required` の意味、`needs_update` が **`sys_deploy.id` の厳密一致**であること、`latest_deploy_key` が畳みの上限であること（クライアントは読まない）は変わらない。変わるのは次の内部だけ。

| 場所 | 変更 |
| --- | --- |
| 「いまの版」の選び方（`SysDeployRepository`・`SysDeploy`） | `start_at <= 現在` の行のうち **`start_at` が最新**、同点は **`deploy_key` が大きい方**、それも同じなら **`id` が大きい方**（決定。db.md 3.3）。**`is_active` は見る**（**決定**。R39。緊急停止専用。止めるのは `master:deactivate` だけ。db.md 3.3・3.6。条件は 1 か所にまとめる）。本番（`APP_ENV=production`）は `deploy_key >= 7000000000`（`DeployKeyConst::RESERVED_FLOOR`）を選ばない（決定。Q7）。`SysDeploy::selectLatestDownloadable()`（static）も同じ並び・同じガードにそろえて、テストで固定する（呼び出し元は無いが削除しない。規約）。`selectByDeployKey()` の `orderByDesc('id')`・`parseDeployKey()` の `count` の 2 桁は**実装済み**（S3-1・#31） |
| 版の固定と mst 接続（**仮置き**。R22。db.md 8.1） | ミドルウェア `ResolveMasterVersion`（仮称）が**リクエストの冒頭**で `CurrentMasterVersion`（リクエスト単位の `scoped` サービス）に版を決めさせ、`mst` 接続の `database` を、決めた版の `sys_deploy_master.mst_database` に差し替えて `DB::purge('mst')` する。接続名・モデル・Repository の記述は変えない。**リクエストの途中で `start_at` を過ぎても、そのリクエストは同じ版を使い続ける** |
| 版が無いとき | `start_at` が未来の行しか無い・本番で予約帯しか無い・選んだ版の DB が掃除済み（`mst_dropped_at` が NULL でない）→ **HTTP 299 の `error_code` 99999**（`SystemDataException::deploy()`。`GameErrorCode::INTERNAL_ERROR`。ログに残す）。**基底 DB・古い版にフォールバックしない**。形は変えない |
| `/auth/version`（`VersionService`） | **判定は変えない**。「最新の `sys_deploy.id`」は `CurrentMasterVersion` の版の `id`。応答の `master.hash`・`master.tables`・`sys_deploy_id`・`latest_deploy_key` は、その版の `deployMaster`・`sys_deploy_master_table` から組む（形は同じ）。mst の表は読まないので、このルートでは接続の差し替えは起きない（版は解決する。**mst を読まないルートでミドルウェアを外すか**は db.md 8.1 の**未確認**を引き継ぐ） |
| 切り替え予定の一覧のキャッシュ（db.md 8.3） | キー `sys:sys_deploy:schedule`。値は「`start_at > t0` のすべての行」と「`start_at <= t0` のうち 3.3 の並びで先頭の 1 行」。リクエストでは、一覧から**今回のリクエスト時刻（`ApiSession::getNow()`）**で `start_at <=` の行を絞り、3.3 の並びで先頭を選ぶ（PHP の比較）。**TTL は 60 秒**（`schedule_ttl`）。`start_at` をまたいでも切り替えは遅れない（TTL に依存しない）。登録・巻き戻し・予約の取り消しの後に tool が `forget`（ベストエフォート） |
| `DeployRepositoryAdapter`（`api/app/Repositories/Sys/`。db.md 10.4） | `/auth/version` は `packages/nexus-version` の `VersionService` が `DeployRepositoryInterface::selectLatestDownloadable()` で引き、実装が `DeployRepositoryAdapter`（`CheckMasterHash` とは**別の呼び出し元**）。`packages/` は `App\` を参照できない規約なので、**`CurrentMasterVersion`（App 側）を `DeployRepositoryAdapter` が仲介する**（`VersionService`・インターフェースは変えない）。`start_at` 基準の最新・schedule キャッシュをここで使う |
| 旧キャッシュキーの廃止（db.md 8.3） | 現行の **`sys:sys_deploy:latest_downloadable`**（`SysDeployRepository`。tool の `MasterCacheInvalidator::SYS_DEPLOY_LATEST_KEY`）は、新キー `sys:sys_deploy:schedule` に置き換えて**廃止**する。旧キーは Redis に最大 3600 秒残るので、**切り替えの初回に `clear-cache` か `forget` で消す**（9.4） |
| 表の一覧のキャッシュ | `sys:sys_deploy_master:{id}:tables`（登録後に変わらないので TTL は長くてよい）。`sys:sys_deploy:id:{id}`・`sys:sys_deploy:deploy_key:{key}` は今のまま |
| mst のキャッシュキー（`_BaseMstRepository`。db.md 8.4） | **`mst:{DB名}:{表}:all`**（`DB::connection($this->connection)->getDatabaseName()` から組む）。版の DB は書き換えないので、**mst のキャッシュの破棄は不要**。TTL は 3600 秒のまま。基底 DB（開発のシーダーが書く）だけは例外 |
| `CheckMasterHash` | 今は `$next` の**後**に `selectLatestDownloadable()` を呼び直す。**リクエスト冒頭で固定した `CurrentMasterVersion` の版の `hash` と比べる**ように直す（応答の版と比較の対象がずれない）。`ResolveMasterVersion` より内側に置き、解決が先に済むようにする。比べ方（`hash_equals`）・200 のときだけ付ける・失敗しても本来のレスポンスを壊さない（`catch`）は変えない |
| trx から mst への JOIN | `RewardTrackMilestoneRepositoryAdapter::findByPlayerAndTrack` の DB 名連結をやめ、`findReceivedKeySet` と同じ二段引き（mst で `id` を引き、trx を `whereIn`）にする（決定。db.md 8.5） |
| 成果物の URL | `master.tables[*].url` は `sys_deploy_master_table.public_url`（`/masterdata/{artifact_dir}/{file_name}`）。**相対パスのまま**で、クライアントは `url` をそのまま使う（変更なし）。配信ホストは ADR 0001 のまま |

テスト（実装時。db.md 8.6）: ① 同じ `deploy_key` の行が 2 つあるとき `id` 最大が選ばれる ② `start_at` が同じ行が 2 つあるとき `deploy_key` が大きい方が選ばれる ③ `start_at` が未来の行は選ばれず、到達後は（キャッシュを破棄せずに）選ばれる（時刻は `ClockUtility`）④ 本番設定で予約帯が選ばれない（予約帯しか無ければ 299）⑤ 版なし・掃除済みで 299 ⑥ **ミドルウェアを通した実経路**で、版の切り替えの前後に応答がそれぞれの DB の値になる ⑦ `CheckMasterHash` がリクエスト冒頭の版の hash と比べる（途中で `start_at` を過ぎても）⑧ mst のキャッシュキーが版ごとに分かれる ⑨ **キーを `mst:{DB名}:{表}:all` に変えるので、`api/tests/Feature/Repositories/MasterCacheKeyContractTest.php`（`mst:{表}:all` を固定している）を新しい形に直し、`_BaseMstRepository` の `clearCache()`（キーを組み直す）も追従させる**（db.md 8.4・10.4）。

## 13. エラー表

### 13.1 運営ツール（`code`・HTTP・状態）
**状態**は「そのエラーで変わったもの」。「なし」は何も変えていない。**ロックの前の検査**は `run_id = null`。

| code | HTTP | 終了コード | 操作 | 条件 | 状態（変更したもの） |
| --- | --- | --- | --- | --- | --- |
| `deploy_locked` | 409 | 3 | 全般 | 他の操作が実行中（ロックが取れない） | なし |
| `role_forbidden` | 403 | 4 | 全般 | role と操作が合わない（2 節の表） | なし（ロックの前） |
| `fixed_key_forbidden` | 403 | 4 | build | `fixed_key` が設定されているのに `APP_ENV` が `fixed_key_forbidden_envs`（本番・ステージング。db.md 7.2）。**finalize では使わない**（予約キーの成果物は `reserved_key_forbidden`。8 節） | なし（ロックの前） |
| `fixed_key_invalid` | 500 | 4 | build | `fixed_key` が許可リスト（`7777777777`・`8888888888`・`9999999999`）に無い | なし |
| `deploy_key_not_configured` | 500 | 4 | build | `fixed_key` と `explicit_key` が両方ある、またはどちらも無い（db.md 7.2） | なし |
| `reserved_key_forbidden` | 403 | 4 | build・import・rollback・finalize | build: `explicit_key` が予約帯。import（I2）: production・staging で予約帯の成果物。rollback: 本番で予約帯の版。finalize: 予約キー・予約帯の成果物 | なし（import は DB を作る前） |
| `base_database_mismatch` | 500 | 4 | build・import | tool の `mst` 接続の database が `{APP_NAME}-{APP_ENV}-master` と一致しない（db.md 4.1。db.md 6.6 のとおり build・import だけ） | なし（ロックの前） |
| `mst_database_name_too_long` | 500 | 4 | build・import | `len(APP_NAME) + len(env)` が 28 文字を超える（db.md 4.2。build・import だけ） | なし（ロックの前） |
| `upstream_not_configured` | 500 | 4 | import・artifact-list | 上流の disk が未設定（7.2） | なし |
| `sheet_not_configured` | 422 | 4 | build・finalize | スプレッドシートのディレクトリ・認証情報が未設定 | なし |
| `sheet_read_failed` | 502 | 1 | build・finalize | Google API の読み込みが再試行（3 回）後も失敗 | なし |
| `sheet_invalid` | 422 | 2 | build・finalize | 検証規則（db.md 6.3）に 1 件でも当たる。`error_list` に最大 20 件・`error_total`・`error_count_by_code` | なし（版の DB・成果物・sys を作らない） |
| `start_at_past` | 422 | 2 | build・import | `reject_past_start_at` が true（production）で、明示の `start_at` が許容幅 `start_at_past_grace`（60 秒）を超えて過去（許容幅以内は現在に丸めて通す。db.md 3.6。I6） | なし（**DB を作る前**。ロックの後） |
| `load_failed` | 500 | 1 | build・import・rollback | 作業用 DB・版の DB への投入で DB が拒否（型・NOT NULL・`uk_*`）、不正な UTF-8、往復検査の不一致。メッセージに表名と DB の文言（旧 `apply_failed` の置き換え） | 投入のトランザクションを `ROLLBACK`。この run が作った DB を `DROP`。sys は変えない |
| `export_failed` | 500 | 1 | build | 成果物（NDJSON・SQLite・`schema.json`・マニフェスト）の書き出しに失敗（db.md 3.6 の仮称。旧 `export_failed` と同名で、意味は「成果物の書き出し」に変わる） | この run が作った DB を `DROP`。sys は変えない |
| `mst_database_collision` | 500 | 1 | build・import・rollback | 同じ名前の DB があり、完全な hash（64 文字）が違う・同じ名前の DB が使えない・孤児の中身が合わない（db.md 4.2。**DB 名の HASH は 16 文字で決定**なので、伸ばした別名にはせず止める） | なし（この run が作った DB のみ `DROP`） |
| `artifact_dir_collision` | 500 | 1 | build・import | 同じ `{deploy_key}_{hash16}` のディレクトリが既にあり、`manifest.json` の完全な hash（64 文字）が違う（先頭 16 文字だけが同じ。db.md 6.5.2。**コード名・HTTP・終了コードは仮置き**）。別名にせず止める | なし（既存のディレクトリは触らない。この run が作った DB のみ `DROP`） |
| `artifact_not_found` | 404 | 2 | import・finalize | 指定の `artifact_dir` が上流（または自環境）に無い・`manifest.json` が無い（未完成） | なし |
| `artifact_invalid` | 422 | 2 | import・finalize | I1: `manifest.json` の `hash` の再計算・ディレクトリ名・各ファイルの SHA-256 が合わない | なし（DB を作る前） |
| `not_finalized` | 422 | 2 | import | **提案**（8 節）。`require_finalized` の環境で、`final.json` が無い、または `deploy_key`・`hash` がマニフェストと違う（I1。db.md 6.5.1） | なし（DB を作る前） |
| `schema_mismatch` | 422 | 2 | import・rollback | import（I3）: 成果物の `schema_fingerprint` が自環境の基底 DB と違う。rollback: 戻し先の DB のスキーマ版が現行と違う（db.md 4.8） | なし（import は DB を作る前） |
| `deploy_key_regression` | 409 | 2 | import | I4（`check`。DB を要しない）: production で、成果物の `deploy_key` が参照版 A の `deploy_key` K より小さい新しい master（古い版へ戻すのは巻き戻し） | なし（DB を作る前） |
| `confirmed_key_modified` | 409 | 2 | import | **R3（I4。`check`）**: 同じ `deploy_key` で別 hash の master が既にある（全 import 環境）。**I5（`check_rows`）**: 新しい版の `deploy_key <= K` の行が A と違う（`error_list` に `{ table, id }`） | R3: なし（DB を作る前）。**I5 だけ DB を作った後**: この run が作った DB を `DROP`（再利用した DB・採用した DB は消さない）。sys は変えない |
| `reference_unavailable` | 409 | 2 | import | I5（`check_rows`）で参照版 A の DB が使えず、A の成果物も無い・復元できない | この run が作った DB を `DROP`（検査をしないまま通さない） |
| `deploy_key_mismatch` | 422 | 2 | finalize | 成果物の `deploy_key` が環境の T と違う | なし |
| `sheet_changed_since_build` | 409 | 2 | finalize | 追従検出で畳み後のダイジェストが違う（読めて違う。**`allow_unverified` では通せない**。**提案**。R28） | なし（印を付けない。`kind = finalize` の run は `failed`） |
| `follow_check_unavailable` | 422 | 4 | finalize | シートを読めない・認証情報が無い（この操作では `sheet_not_configured`・`sheet_read_failed` をこのコードに読み替える。`allow_unverified` で警告 `follow_check_unverified` として通せる。db.md 6.5.1） | なし（印を付けない） |
| `finalize_conflict` | 409 | 2 | finalize | `final.json` が既にあり、中身の `deploy_key`・`hash` が今回の成果物と違う（上書きしない。db.md 6.5.1）。一致するときは成功コード `already_finalized` | なし |
| `pull_failed` | 502 | 1 | import・finalize・artifact-list | 上流（または自環境）のストレージの読み込み・pull に失敗（db.md 3.6 の仮称） | なし（DB を作る前） |
| `store_failed` | 500 | 1 | import・build・finalize | 成果物（`manifest.json`・`final.json`）の自環境のストレージへの配置に失敗（db.md 3.6 の仮称） | この run が作った DB を `DROP`。sys は変えない。置きかけの成果物は害が無くそのまま |
| `register_failed` | 500 | 1 | build・import・rollback | sys のトランザクションが失敗。**COMMIT より前のエラーは失敗が確定**。**COMMIT 処理中のエラーで、読み直しても同じ `mst_database` の行が無い**場合も、既定はこのコードで閉じる（db.md 4.7） | COMMIT 前: sys をロールバック済み。この run が作った DB を `DROP`。**COMMIT 処理中で行が無い場合は、その場では `DROP` せず**、DB は孤児掃除（猶予 24 時間後に sys を再確認してから `DROP`）に回す |
| `register_unknown` | 500 | 1 | build・import・rollback | **sys の COMMIT 処理中のエラーで、sys を読めず、登録されたか分からない**（db.md 4.7）。**読めて同じ `mst_database` の行があれば、エラーではなく成功**（run は `succeeded`、警告 `register_confirmed_after_error`、schedule キーの `forget`。終了コード 0。下の成功・警告のコード） | **`DROP` しない**（登録済みの版の DB を消すと `start_at` で `Unknown database` になるため）。実行記録に `status = failed`・`error_code = register_unknown` と DB 名を残し、**掃除（cleanup）の対象外**にする。target が警告 `register_unknown` を出す。人が `master:resolve-unknown {run_id}`（9.5）で片付ける。放置すると掃除対象外のまま残る |
| `rollback_blocked_by_scheduled` | 409 | 4 | rollback | `start_at` が現在より後の行がある。**ただし「現在 + 60 秒以内で、戻し先と同じ master を指す行」は数えない**（db.md 3.6）。予約の一覧を `error_list` に | なし |
| `rollback_target_not_found` | 404 | 4 | rollback | 指定の master が無い | なし |
| `rollback_target_unavailable` | 422 | 4 | rollback | 戻し先の DB が使えず、成果物から復元もできない | なし |
| `schedule_not_found` | 404 | 4 | cancel-schedule | 指定の `sys_deploy` が無い | なし |
| `schedule_already_started` | 409 | 4 | cancel-schedule | `start_at` が到達している（行が `start_at <= 現在`、または `DELETE` が 0 件） | なし |
| `deploy_not_found` | 404 | 4 | deactivate・activate | 指定の `sys_deploy` が無い（**仮称**。db.md 3.6） | なし |
| `activate_target_dropped` | 409 | 4 | activate | 再開で最新の選択になる行の master が掃除済みで、成果物から復元もできない（`schema_mismatch` を含む）（**仮称**。db.md 3.6） | なし（更新しない。復元で作った DB は `DROP`） |
| `deactivate_no_fallback` | 409 | 4 | deactivate | 止めた後の最新の選択が空、または掃除済み（`mst_dropped_at`）の版になる（**仮称**。db.md 3.6） | なし（更新しない） |
| `schedule_cancel_too_soon` | 409 | 4 | cancel-schedule | `start_at` が**現在 + 2 × `schedule_ttl`（120 秒）以内**で、まだ到達していない（db.md 3.6。API のキャッシュに取り消した行が残ったまま到達するのを防ぐ）。メッセージは「到達した後に巻き戻しで戻してください」 | なし（行を削除しない） |
| `drop_forbidden` | 403 | 4 | cleanup・resolve-unknown | `DROP` の保護（接頭辞・基底 DB でない。db.md 4.6）に当たる名前が対象にある。resolve-unknown では、sys に同じ `mst_database` の行がある、または `created_database_list` に無い名前 | なし（実行しない） |
| `run_not_found` | 404 | 4 | resolve-unknown | その run が無い、または `error_code` が `register_unknown` でない（**api.md の仮置き**） | なし |
| `sys_unreadable` | 503 | 1 | resolve-unknown | sys を読めず、登録済みかを再確認できない（**api.md の仮置き**） | なし（run は `register_unknown` のまま） |
| `drop_failed` | 500 | 1 | resolve-unknown | (b) の `DROP` に失敗（**api.md の仮置き**） | run は `register_unknown` のまま。再実行できる |
| `cleanup_failed` | 500 | 1 | cleanup | 消せなかった DB・一時の場所がある | 消せたものは消えたまま。`dropped_list`・`failed_list` を返す |
| `cache_clear_failed` | 502 | 1 | clear-cache | `forget` に失敗 | なし（冪等） |
| `internal_error` | 500 | 1 | 全般 | 想定外の例外。詳細（SQL など）は画面に出さずログに残す。`run_id` を添える（`failed`） | この run が作った DB を `DROP`（ベストエフォート） |

### 13.1b 成功・警告のコード（HTTP 200・終了コード 0）と、13.1 のコード名の注記
| code | 種別 | 操作 | 意味 |
| --- | --- | --- | --- |
| `already_finalized` | 成功コード | finalize | `final.json` が既にあり一致。何もしない（冪等。db.md 6.5.1） |
| `already_resolved` | 成功コード | resolve-unknown | 既に `summary.resolved` がある（冪等。**api.md の仮置き**） |
| `register_confirmed_after_error` | 警告 | build・import・rollback | COMMIT 処理中のエラーの後、読み直したら登録済み（run は `succeeded`。db.md 4.7） |
| `already_registered` | 警告 | build・import・rollback | 同じ master・同じ `start_at` の行が既にあり、既存を返した（`is_new = false`。db.md 3.6） |
| `already_in_state` | 成功コード | deactivate・activate | すでにその状態。更新はしないが **`forget` は行う**（冪等な再試行で `forget` が効く。応答の `code`。9.6。**仮称**。db.md 3.6） |
| `schedule_cache_not_invalidated` | 警告 | 登録・取り消し・緊急停止・再開・resolve-unknown (a) | schedule キーの `forget` の失敗（操作は成功。db.md 3.6） |
| `follow_check_unverified` | 警告 | finalize | シートを読めず `allow_unverified` で通した（**api.md の仮置き**。db.md 6.5.1 は「警告」のみ） |
| `start_at_too_soon`・`start_at_shadowed` | 警告 | build・import | db.md 3.6 |

- 旧の次のコードは**廃止**: `deploy_key_exhausted`（採番が無い）・`deploy_key_conflict`（build は別 hash を許す。import は `confirmed_key_modified`）・`apply_failed`（`load_failed`）・`inconsistent`（食い違いが無い）。**`export_failed` は名前を残し、意味を「成果物の書き出しの失敗」に変える**（旧は SQLite の書き出し・公開の失敗）。
- **エラーコード名は db.md を正本にする**。db.md 3.6・4.6・4.7・6.5.1 にあるコード（`deploy_locked`・`sheet_invalid`・`artifact_invalid`・`not_finalized`・`schema_mismatch`・`confirmed_key_modified`・`deploy_key_regression`・`reserved_key_forbidden`・`start_at_past`・`mst_database_name_too_long`・`mst_database_collision`・`base_database_mismatch`・`load_failed`・`rollback_target_unavailable`・`rollback_blocked_by_scheduled`・`schedule_already_started`・`schedule_cancel_too_soon`・`reference_unavailable`・`drop_forbidden`・`register_failed`・`store_failed`・`pull_failed`・`export_failed`・`register_unknown`・`finalize_conflict`・`follow_check_unavailable`・`already_finalized`・`register_confirmed_after_error`・`deploy_key_not_configured`（db.md 7.2））はすべて上の表にある。**db.md に無く、この文書で足したコード（仮置き）**: `role_forbidden`・`artifact_not_found`・`deploy_key_mismatch`・`sheet_changed_since_build`・`upstream_not_configured`・`rollback_target_not_found`・`schedule_not_found`・`cleanup_failed`・`run_not_found`・`sys_unreadable`・`drop_failed`・`already_resolved`・`follow_check_unverified`・`cache_clear_failed`（旧から）。db.md の既存コードと名前が衝突しないことを `grep` で確かめた（db.md に同名は無い）。警告のコード（`start_at_too_soon`・`start_at_shadowed`・`already_registered`・`schedule_cache_not_invalidated` は db.md。`rollback_target_is_current`・`no_current_version`・`incomplete_run`・`orphan_database`・`schema_stale_version`・`cache_not_invalidated`・`scheduled_exists`・`register_unknown` は api.md の仮置き。`sheet_changed_since_build` はエラー）。

### 13.2 ゲーム API（クライアントから見える形は変えない）
| 場面 | 結果 | 変更 |
| --- | --- | --- |
| 配信可能な版が無い（未来の `start_at` しか無い・本番で予約帯しか無い・掃除済みの版を選んだ） | HTTP 299・`error_code` 99999・`SystemDataException::deploy()` | **変えない**（起きる条件が増えるだけ） |
| `X-Master-Hash` が、固定した版の `hash` と違う | HTTP 200 に `X-Master-Update-Required: true` | **変えない**（比べる相手がリクエスト冒頭の版になるだけ） |
| `deploy_version` が、いまの版の `sys_deploy.id` と違う | `needs_update: true` | **変えない** |
| mst の DB に繋げない・表が無い | 既存どおり（内部エラー。299 / 99999 のどれかは既存の例外の扱いに従う） | 変えない。接続の差し替えに失敗したときのログを足す |

新しい `GameErrorCode` は**足さない**（クライアントの対応が要らないように）。

## 14. 仮置き・未確認・未決・db.md との関係

### 14.1 仮置き（AI の提案。人の確認で変わりうる）
| # | 内容 | 節 |
| --- | --- | --- |
| A1 | URL・エンドポイントの名前（`build`・`import`・`finalize`・`rollback`・`cancel-schedule`・`cleanup`・`artifact-list`）。旧 `deploy` は廃止（互換の口を残さない） | 3 |
| A2 | 操作と role の対応（build 系は build の環境、import は import の環境、それ以外は両方） | 2 |
| A3 | T はリクエストでも CLI でも渡さない（環境設定だけ）。`start_at` の import の「キーは必須・値は null 可」 | 6・7・10 |
| A4 | build の `dry_run` はロックを取らない（import の `dry_run` は db.md 6.6 のとおりロックを取り、実行記録を書かない） | 6・7・11 |
| A5 | cleanup の `dry_run` 既定は true。N・孤児の経過時間は環境設定 | 9.3 |
| A6 | `clear-cache` は schedule キー（と `id`・`deploy_key`・`tables`）だけに縮めて残す | 9.4 |
| A7 | 巻き戻しの戻し先がすでにいまの版でも拒否せず、警告 `rollback_target_is_current` で通す（db.md 3.6 に拒否の規則が無いので） | 9.1 |
| A8 | 終了コード 4（環境・状態の前提で拒否）の追加。`schedule_cancel_too_soon` は 4、`register_unknown` は 1（人の確認が要ることはメッセージで示す） | 10 |
| A9 | 追加のエラーコード・HTTP ステータス（13.1 の末尾） | 13 |
| A10 | `target` は、画面を開くたびにシートを読まない。追従検出は直近の finalize の実行記録の結果を出す。基底 DB の指紋は短い TTL（60 秒）でキャッシュした値を出す（db.md 4.4） | 4 |
| A11 | `mst:migrate-versions` は失敗しても続行し、同じ `MasterDeployLock` の名前を取る | 10 |
| A12 | cleanup の対象の kind（`version`・`orphan`・`work`・`tmp`）の名前と、dry_run の一覧は参考で実行時に再計算すること | 9.3 |
| A13 | `allow_unverified`（POST・CLI）の名前、`follow_check_unverified` の警告、成功コード `already_resolved`、`resolve-unknown` の URL・エラーコード（`run_not_found`・`sys_unreadable`・`drop_failed`）と、(a)(b) をツールが sys の状態で自動判定すること（人が選ぶ入力は持たない） | 8・9.5 |

### 14.2 未確認
- build・import の所要時間とタイムアウト（11.2）。tool と api が同じ Redis・同じ接頭辞か（9.4。F13・R18）。mst を読まないルートで `ResolveMasterVersion` を外すか（db.md 8.1）。サーバー間の時計のずれ（db.md 8.3）。MySQL 8.0.31 以降か（I5 の `EXCEPT`。db.md 14.0）。基底 DB の指紋の計算コスト（`target` が毎回計算する。4 節）。

### 14.3 未決
- **R28**（QA 完了の操作・最終 hash の確定・追従検出のゲートの置き場。8 節は提案）。`final.json` の置き場（成果物のディレクトリか、実行記録・sys か）。
- **R24** 本番のストレージの種類（hash の長さは**決定**: DB 名の HASH もディレクトリ名の hash も完全な hash の先頭 16 文字。完全な hash は `sys_deploy_master.hash`・`manifest.json` などでそのまま使う。db.md 4.2・6.5.2）・本番のストレージの種類（15 節の本番の disk）。（R39 は決定済み: `is_active` を見る。9.6）**R27** pull 方式の可否。**R32** NN の付け方（サーバーは形を検査するだけ）。**R19** 録画の版の DB の作り方（10 節の用途）。**R20** 切り替え瞬間のクライアント（API の形は変えない）。
- 保持数 N と成果物の掃除（R40）。

### 14.4 Nexus との関係
- 決定 D4・F8・F9・ADR 0007 の決定（`start_at` の一本化・版ごとの DB・環境ごとの T・pull 方式は提案）は弱めていない。Nexus の `api.md`（version 応答の形）とは食い違わない。Nexus の `api.md` 5 節の「`start_at` が未来の版しか無いとき 299」は 12 節と 13.2 で同じ。
- Nexus の `api.md` 6 節（build・import の操作は内部設計）の答えがこの文書。

### 14.5 db.md との関係
**db.md の最終形に従う**。前の版で挙げた不備（follow_check の出所＝`summary.follow_check`・巻き戻しの二重登録と予約判定の例外・近い予約の窓（P47）・復元の phase（`version_db`）・`register_confirmed_after_error`・`verify-sheet` と `finalize --dry-run` が同じ処理・基底 DB の指紋のキャッシュ）は db.md に**反映済み**で、この文書は従った（R42・R43 も反映済み）。db.md の決定待ちとして残る論点:
- **近い予約の窓（P47）**: 120 秒以内の予約は取り消しも巻き戻しもできない（最大 120 秒）。**緊急の手段は `is_active` を落とす操作（決定した R39。9.6 の `deactivate`。窓の中でも使える）**。
- **`require_finalized` の既定**（R46。15 節）。
- **`--allow-unverified` を画面の API でも許すか**（8 節。未決）。
- **resolve-unknown の (a)(b) を人が選ぶ入力を持つか**: db.md 4.7 は「人が確認してどちらかにする」。この文書は、ツールが sys を再確認して自動判定する仮置き（誤判断で登録済みの DB を消さないため。A13）。

### 14.6 新しい未決（台帳に足す候補）
- R28 の細部: `final.json` を production 以外の import にも掛けるか・同じ `deploy_key` で複数の成果物を finalize したときの扱い（review が取り込み済みなら R3 で拒否される）。
- 緊急の巻き戻し（近い予約があるとき。db.md P47）。**R39 の決定（`is_active` を見る）により、緊急停止（9.6）で止められる**。巻き戻しそのものは窓の中ではできないまま。
- **R39 の具体化（仮置き。決定は「見る」まで）**: `master:deactivate`／`activate` の名前・形・検査（`deactivate_no_fallback`）・`inactive_list`。db.md P57。
- R46（`require_finalized` の既定）。R44（`register_unknown` の人手の片付け。9.5）・R45（`finalize` は追従検出の一致を前提にする）は db.md の決定に従った。

## 15. 環境設定
db.md 7.2 の `tool/config/master_deploy.php` を使う（キーは db.md のとおり。**キー名・環境変数名は仮置き**）。既存の `TOL_GOOGLE_SPREADSHEET_DIR`・`TOL_GOOGLE_SERVICE_ACCOUNT_JSON(_CONTENT)` は変えない。`.env.example` に、`MASTER_DEPLOY_ROLE`・`MASTER_DEPLOY_FIXED_KEY`・`MASTER_DEPLOY_KEY`・上流の disk の変数と、説明のコメントを足す。

設定のキーは db.md 7.2 のとおり（`require_finalized`・`keep_version_count`・`orphan_grace_hours`・`schedule_ttl`・`start_at_past_grace`・`reject_past_start_at`・`enforce_confirmed_rows` を含む。この文書で足したものは無い）。`require_finalized` は import の環境で true（仮置き）、`keep_version_count` は 3、`orphan_grace_hours` は 24、`schedule_ttl` は 60 秒、`start_at_past_grace` は 60 秒（いずれも db.md の既定）。**`require_finalized` の既定は、仮置きは true だが、R28（最終 hash の確定・ゲートの置き場）が未決の間は false にし、QA 完了の運用が始まるときに true にする案がある（db.md 14.2・R46）**。true だと、`master:finalize` の運用が無い間は import がすべて `not_finalized` で拒否される。

### 15.1 環境ごとの設定
| 環境 | `role` | T | シートのディレクトリ | 上流の disk | 本番ガード類 |
| --- | --- | --- | --- | --- | --- |
| develop | `build` | `fixed_key = 9999999999`（固定） | 開発の別ディレクトリ | なし | |
| LD（仮） | `build` | `fixed_key = 8888888888`（固定） | LD と QA で共通 | なし | |
| LD（確定後） | `build` | `explicit_key = V`（`fixed_key` は空に。LD で確定したキーを持ち回る。NN の付け方は R32） | 同上 | なし | |
| QA（複数） | `build` | `explicit_key`（環境ごと。例 QA1 = `2026120101`、QA2 = `2026120201`）。**QA は V**（決定） | LD と共通（各 QA が直接読む） | なし | `require_finalized` の対象外（finalize を行う側） |
| review | `import` | 成果物のまま | なし | QA の置き場（読み取りだけ） | |
| production | `import` | 成果物のまま | なし | review の置き場 | `reject_past_start_at = true`・`enforce_confirmed_rows = true`・`require_finalized = true` |
| staging | `import` | 成果物のまま | なし | production の置き場（時間差で、同じ `start_at`） | `fixed_key_forbidden_envs` に含む |
| 録画（ローカル） | `build` | `fixed_key = 7777777777` | 開発の別ディレクトリ（**仮置き**） | なし | 録画の版の DB の作り方は**未決**（R19） |

### 15.2 成果物のストレージ（db.md 6.5.3）
- Laravel の disk で抽象化する（決定）。`master_artifact`（自環境。書き込み可）・`master_artifact_upstream`（import の上流。**読み取りだけ**）・`asset_artifact`（アセット。設計は別の機能。R23）。
- **ローカル**: `master_artifact` は `tool/public/masterdata`（`local`。tool-web の `/masterdata` で配る）、アセットは `tool/public/asset`（決定）。`master_artifact_upstream` はローカルでは別のディレクトリ（環境変数で指定。pull = ディレクトリのコピーで再現）。いずれも git に入れない（`.gitignore`。db.md 6.5.4）。
- **本番側**: 専用バケット（review・production・staging）と共通ストレージ（develop・LD・QA）。**種類は未決**（R24）。上流の資格情報は読み取りだけ。
- URL の形: `/masterdata/{deploy_key}_{hash}/{file_name}`（相対パス。決定）。

### 15.3 `start_at` の既定
| 操作 | 省略したとき | 過去の `start_at` |
| --- | --- | --- |
| build | 現在（すぐ有効） | production 以外は許す。`reject_past_start_at` が true なら拒否（許容幅 60 秒は丸める） |
| import | 入力欄は必須（キーを送る）。null（画面の「すぐ」）= 現在 | 同上 |
| rollback | 現在固定（入力なし） | - |

画面・CLI は UTC で受け、JST を併記して表示する（db.md 3.6）。`start_at` が現在 + `2 × schedule_ttl`（120 秒）より近いときは `start_at_too_soon`、新しい版より後の `start_at` の行があるときは `start_at_shadowed` の警告（止めない）。**`start_at` が「近すぎる」かの判定と、予約の取り消しの保護（`schedule_cancel_too_soon`）・「到達したか」の判定は、tool のサーバー時計で行い、版を選ぶのは各 API サーバーの時計（`ApiSession::getNow()`）である**。両者の時計がずれていると（NTP の誤差）、警告・保護の境界が最大でそのずれだけ動き、`start_at` の瞬間の切り替えもサーバーごとに数秒ずれうる（db.md 8.3。**未確認**）。そのため警告・保護の範囲（120 秒）は、時計のずれより十分大きい前提で、`start_at` の直前の操作を避ける運用にする。

## 16. 画面（`resources/js/Pages/MasterImport/Index.vue`）の作り直し（要点）
- **role で画面を切り替える**（`target.role`）。build の環境: 「検証（dry run）」「build」「QA 完了（検証 → 確定）」。import の環境: 上流の成果物の一覧（`artifact-list`）から選び、`start_at`（UTC 入力・JST 併記・「すぐ」のチェック）を入れて「検証（dry run）」「取り込む」。**旧のシートの選択（左右 2 つの一覧と「全て追加」）・「インポート」「エクスポート」のボタンはやめる**（build は全シートを読む。シートの一覧は「読まれるタブ・読まれないタブ」の確認用に残す）。
- 上部に**対象の表示**（`target`）: 環境名・role・T（build）・基底 DB の指紋・いまの版・**予約済みの版（`start_at` を JST 併記）と「取り消し」「止める（緊急停止）」ボタン**（止めた行は「再開」ボタン）・直近の run の状態。`blocked` があれば build・import は押せない。`warning_list` は目立つ色で出す。
- **版の一覧**（`version_list`）: 巻き戻し先の候補の表と「この版に戻す」ボタン。`rollback_state` が `blocked_by_scheduled` のときは、ボタンの代わりに「先に予約を取り消してください」を出す。
- **掃除**: 「掃除（dry run）」で対象を見せ、確認してから実行。
- 確認ダイアログ: 環境名・操作・T（または成果物）・`start_at`（UTC と JST）を再掲する（誤操作の防止）。
- 結果の表示: 表ごとの件数の表、`error_list`（`sheet`・`row`・`code`・`message`。総件数と、20 件で切ったことの表示）、`warning_list`、dry run の `inventory`。
- **画面に書く説明文**（運営向け。旧の③を直す）: ① 古い行は消さず、新しいキーの行を足す。② 削除は `__deleted` を `TRUE` にした行（`id` と `deploy_key` だけでよい）。`is_active` を無効にしても消えない（A 列の `is_active` はシートの行の話で、`sys_deploy.is_active`（緊急停止。9.6）とは別）。③ `deploy_key` は 10 桁必須。**環境の T より大きい `deploy_key` の行は畳みに入らない**（効かせたいなら T を上げて build する）。**時刻による切り替えは `start_at` で行う**（採番が追いつくまで効かせる仕組みは無い）。④ 言語だけを消すことはできない（親ごと消す）。⑤ 「検証（dry run）」が成功しても、型・NOT NULL・`uk_*` の衝突はデプロイで初めて分かり、`load_failed`（版の DB を作らない）になりうる。⑥ **確定済み（production に出た）版の行の変更は、新しい `deploy_key` で入れる**（production の取り込みが検査する）。⑦ シートに行が無い表は、版の DB で空になる。

## 17. 内部の分担（tool のクラス。名前は仮置き。db.md 10.4 と同じ区分）
| クラス | 責務 |
| --- | --- |
| `MasterImportController`（既存を作り直し） | 上の各エンドポイント。入力の検査・`MasterDeployRunException` の HTTP への変換。`execute`・`export`・`deploy` は削除 |
| `MasterDeployTargetService`（作り直し） | `target`（版の一覧・予約・警告・追従検出の直近の結果）・`artifact-list` |
| `MasterDeployRunner`（既存を作り直す。db.md 10.4） | build・import の順序・排他・実行記録（db.md 6.6）。画面・CLI から呼ぶ。**build と import の Runner を分けるか 1 つにするかは実装 PR で決める**（`MasterBuildRunner`・`MasterImportRunner` に分ける案は仮称。台帳は新名を決めていない） |
| `MasterVersionAdmin`（新。仮称） | rollback・cancel-schedule・deactivate・activate・cleanup・finalize・resolve-unknown（短い操作。ロック・run の記録は共通） |
| `MasterDeployService`（作り直し） | 登録（db.md 3.6 の R1〜R3。1 つの sys トランザクション） |
| `MasterVersionDatabase`（新。仮称） | 版の DB の作成・復元・`DROP` の保護・往復検査（db.md 4.2・4.3・4.6・6.6 の `version_db`） |
| `MasterArtifactStore`（新。仮称） | 成果物の書き込み・pull・`manifest.json` を最後に書く（db.md 6.5.3） |
| `MasterCacheInvalidator`（小さく作り直す） | schedule キーの `forget` だけ（9.4） |
| 再利用 | `GoogleSpreadsheetService`・`MasterSheetValidator`・`MasterOverlayFolder`・`MasterDeployLock`・`MasterDeployKeyPolicy`（一部）・`MasterDeployInventory`・`MasterDataExporter`（読み取り元と出力先を変える）・`MasterImportService` の行変換・ID の検査（db.md 10.4） |

api 側の変更の置き場: `SysDeployRepository`・`SysDeploy`・`DeployRepositoryAdapter`・`CurrentMasterVersion`・`ResolveMasterVersion`・`CheckMasterHash`・`_BaseMstRepository`・`RewardTrackMilestoneRepositoryAdapter`（12 節）。実装の順序は db.md 10.5。
