# 動画撮影用シード

クライアントの動作確認（タイトル〜ホーム。`NexusMobileGameClient-Godot/docs/design/title-to-home/client_design.md` の 7 節）を動画に撮るために、サーバーの状態を整えるシーダー。

**このフォルダに動画撮影用のものをすべて置く。** 撮影が増えるたびに、このフォルダの中にシナリオを足していく。

## フォルダの構成

| パス | 役割 |
| --- | --- |
| `README.md` | このドキュメント |
| `RecordingSeeder.php` | 入口。既定で流すシナリオの一覧（`defaultScenarios()`）を持つ |
| `RecordingScenarioSeeder.php` | 基底クラス。環境ガード、マーカーの定数、共通の出力 |
| `Scenarios/` | シナリオ（1 クラス 1 場面）。下の一覧を参照 |
| `api/tests/Feature/Recording/` | テスト（フォルダの外。テストの置き場の規約による） |

フォルダの外にあるのは次の 3 つだけ（置き場所を変えられない）: `Makefile` のターゲット、サーバーの `CLAUDE.md` のコマンド表の 1 行、上のテスト。シーダーは composer の PSR-4（`Database\Seeders\`）の都合で `database/seeders/` の下に置く必要があり、その配下の `Recording/` を専用フォルダにしている。

## 前提

- `make up` でサーバーが動いていること
- **`make seed`（基本のシード）が済んでいること**（`make migrate-fresh` の後は `make seed` を流す）
- **録画用の版（`7777777777`）が build で作ってあること**（下の「録画用の版の作り方」）。無いと `RecordingDeploySeeder` は何も書かずに中止し、理由を表示する（他のシナリオは流れる。CI・`make seed` は録画用の版を作らない）
- `make seed` は `mst_item__l10n`（アイテムの表示名。`free_diamond`・`gold`・`stamina_50` など `ja`）も入れる。録画用のシナリオは名前を重ねて入れない（`RecordingLoginBonusSeeder` の 5 日目は `item` / `stamina_50` / 1）
- `make migrate-fresh && make seed` で DB を作り直した後は、**もう一度 `make seed-recording` が必要**（動画用の行も消えるため。ケース 10 の注記）。録画用の版の DB・成果物は残るので、`RecordingDeploySeeder` が sys に登録し直す（build し直さなくてよい）
- 実行環境が `local`（開発）または `testing`。それ以外の環境（production など）では、どのシナリオも例外で止まる

## 使い方

| 目的 | コマンド |
| --- | --- |
| 既定のシナリオをまとめて流す | `make seed-recording` |
| シナリオを 1 つだけ流す | `make seed-recording-one SEEDER=<クラス名>`（例: `SEEDER=RecordingMaintenanceOnSeeder`） |
| 動画用のものを消して元に戻す | `make seed-recording-reset` |
| キャッシュを全消去する（開発専用） | `make seed-recording-clear-cache` |

Docker を使わない直接の形は `php artisan db:seed --class='Database\Seeders\Recording\Scenarios\<クラス名>' --force`（実際は `docker compose exec -T api-php` 経由で流す）。

## シナリオ一覧

ケース番号は client_design.md 7-2 の表の `#`。

| クラス（`Scenarios/`） | 内容 | 使うケース | 既定に入るか |
| --- | --- | --- | --- |
| `RecordingDeploySeeder` | **build で作った録画用の版**（`deploy_key = 7777777777`）を sys に登録する（`sys_deploy_master`・`sys_deploy_master_table`・`sys_deploy_asset`・`sys_deploy`。`start_at` は現在）。版の DB も成果物も作らない。成果物（`7777777777_{hash16}/manifest.json`）が無い、または版の DB が実在しないときは、何も書かずに理由を表示して中止する。`start_at` が未来の `sys_deploy`（予約済みの版）があるときも何もしない。詳細は下の「録画用の版と配信ファイル」 | 全ケースの前提、14 | 入る |
| `RecordingAgreementSeeder` | 規約 3 件: 公開済みの必須（`rec-required`）・公開済みの任意（`rec-optional`）・公開日が未来の必須（`rec-future`） | 3, 4, 12, 13, 15 | 入る |
| `RecordingAgreementMultiSeeder` | 必須規約を 2 件足す（`rec-required-2`・`rec-required-3`。公開済み）。`rec-required` と合わせて必須が 3 件になる。`make seed-recording` の後に流す | 規約が複数件 | 入らない |
| `RecordingLoginBonusSeeder` | 通常ログインボーナス `daily_login`（7 日サイクル）を、有効・期間なしで保証する。他の行は消さない | 1, 2, 3, 5, 13 | 入る |
| `RecordingLoginBonusDisabledSeeder` | `daily_login` を無効にする（ログインボーナスなしの画面用） | ログインボーナスなし | 入らない |
| `RecordingLoginBonusProgressSeeder` | **既存の 1 人のプレイヤー**のログインボーナスの受け取り状況（`trx_login_bonus`・`trx_vip_login_bonus`・`sys_player` の `last_login_at`・`vip_point`）を、番号で選んだシナリオ（1〜8・F2・G）の状態にする。`RECORDING_PLAYER_MY_ID` が無いと何もしない。下の「ログインボーナスのカレンダー」を参照 | ログインボーナスのカレンダー表示（login-bonus-calendar） | 入らない |
| `RecordingMaintenanceOnSeeder` | メンテナンスを有効にする（API が 503 になる） | 8 | 入らない |
| `RecordingMaintenanceOffSeeder` | 動画用のメンテナンスを解除する | 8（解除して再試行） | 入らない |
| `RecordingResetSeeder` | マーカー付きの行と、録画用のデプロイ（`deploy_key = 7777777777` の行と成果物 `7777777777_*`。版の DB は残す）を削除し、メンテナンスを解除し、`daily_login` を有効に戻す | 規約なしに戻す・撮影の終わり | 入らない |

### 既定に入れない理由

API を止める（メンテナンス）、画面を変える（ログインボーナス無効・規約を複数件にする）、データを消す（リセット）ものは、撮影の途中で意図せず流れると他の撮影を壊す。必要なときだけ `seed-recording-one` で流す。

## client_design.md 7 節のケースとシナリオ

サーバーの状態に関するケースだけを載せる（クライアントの保存・停止・429 などはサーバーのシードでは作らない）。

| ケース | サーバーの状態 | 流すシナリオ |
| --- | --- | --- |
| 1 | 規約なし、ログインボーナスあり | `seed-recording-reset` の後に `RecordingLoginBonusSeeder`（規約は入らない）。デプロイが空なら `RecordingDeploySeeder` も（リセットは録画用の `sys_deploy` を消すので、流し直す） |
| 2 | 同上（同じ端末の 2 回目） | 1 と同じ |
| 3, 4 | 必須規約が 1 件 | `make seed-recording`（`RecordingAgreementSeeder` を含む） |
| 5 | ログインボーナス 2 日目 | `RecordingLoginBonusSeeder`。`sys_player.last_login_at` の調整は別途（シード対象外） |
| 8 | メンテナンス 503 | `RecordingMaintenanceOnSeeder` → 撮影 → `RecordingMaintenanceOffSeeder` |
| 12, 13 | 規約が 1 件ある | `make seed-recording` |
| 15 | 規約が 1 件ある | `make seed-recording` |
| 規約が複数件（7-2 の表に専用の番号は無い。規約画面の複数件表示の確認用） | 必須規約が 3 件（`rec-required`・`rec-required-2`・`rec-required-3`） | `make seed-recording` → `make seed-recording-one SEEDER=RecordingAgreementMultiSeeder`。1 件に戻すときは `seed-recording-reset` の後に `make seed-recording` |
| ログインボーナスなし | `daily_login` が無効 | `RecordingLoginBonusDisabledSeeder` |
| 14 | 未取得の版の配信ファイル（mst の全テーブル）がある。クライアントは同梱の版のまま取りに行く | `make seed-recording`（`RecordingDeploySeeder` を含む） |
| 6, 7, 9, 10, 11 | サーバーのシードでは作らない（停止・応答の差し替え・保存データの操作・配信ホストの不通） | なし |

## 録画用の版と配信ファイル（`RecordingDeploySeeder`）

home-player-info の動画（スタミナ最大値と回復間隔のマスター差分）を撮るため、録画用の版は mst の全テーブルの配信ファイルを持つ。設計は `docs/design/master-distribution/db.md` 11.2・3.5、`sequence.md` 7 節（R19。人の決定。2026-11-24）。

- **録画用の版は運営ツールの build で作る**（シーダーは作らない）: `MASTER_DEPLOY_FIXED_KEY=7777777777` を設定して `master:build`（または画面）を実行する。版の DB は `{APP_NAME}-{env}-master-7777777777-{hash 先頭 16 文字}`、成果物は `tool/public/masterdata/7777777777_{hash16}/`（`manifest.json`・配信 SQLite）。T が `7777777777` なので、開発・LD の行は畳みに入らず、本物の行 + 録画用の行だけの版になる。build は `start_at` 省略で sys にも登録するので、通常はそれだけで録画用が最新になる。旧方式（シーダーが基底 DB の現在の行から版を作る `RecordingMasterExporter`）は廃止した。
- **シーダーの出番**: `make migrate-fresh` などで sys だけが消え、版の DB と成果物が残ったときの登録のやり直し。`7777777777_*` の成果物が複数あるときは `manifest.json` の `built_at` が最新のものを使う（仮置き）。同じ `(deploy_key, hash)` の `sys_deploy_master` があれば再利用し、`sys_deploy` を有効にして `start_at` を現在にする。他のキーの行は触らない。
- **中止（何も書かない）する条件**: ① 成果物が無い ② 最新の成果物の版の DB が実在しない（古い版にはフォールバックしない）③ `start_at` が未来の `sys_deploy`（予約済みの版）がある（仮置き。予約の版が `start_at` に達すると録画用が黙って無効になるため）。①② はシートを使える環境で build が要る旨を表示する。例外にはしない（CI・`make seed` を失敗にしない）。
- **本番ガード**: `APP_ENV=production` では何もせず例外で止まる（予約帯。`DeployKeyConst::RESERVED_FLOOR` 以上のキーを使うため）。
- **手順**（Nexus の `docs/workflow/recording.md` と同じ）: シートを使える環境で、**本物の版の登録の後に**録画用を build する → `make seed-recording`。**本物の build（`start_at` = 現在）があとから走ると、録画用は黙って無効になる**（build に予約を見る保護は無い）。そのときは録画用を build し直すか `make seed-recording-one SEEDER=RecordingDeploySeeder`（`start_at` を現在にし直す）。
- **最新になる条件**: version API は `start_at` が現在以前の有効なデプロイのうち `start_at` が最新のものを選ぶ（`deploy_key` の大小は効かない。db.md 3.3）。`start_at = 現在` で登録するので、本物があっても録画用が最新になる。録画用が最新の間は、本物のデプロイの配信ファイルは `master.tables` に出ない。元に戻すには `make seed-recording-reset`（下の「冪等性」参照）。
- **置き場**: `config('recording.masterdata_dir')`（env `RECORDING_MASTERDATA_DIR`、既定 `/var/www/tool-masterdata`）。`docker-compose.yml` で `./tool/public/masterdata` を api-php にマウントしてあり、シーダーはここの成果物を読むだけ（書かない）。クライアントは tool-web（:8091）から取得する。マウント元が無いまま `make up` すると Docker が root 所有で作り、運営ツールが書けなくなるので、先に `mkdir -p tool/public/masterdata` する。
- **リセット**（`RecordingResetSeeder`。仮置き。db.md 11.2）: build の行には `[REC]` が付かないので、`deploy_key = 7777777777` の `sys_deploy`・`sys_deploy_master`（と `sys_deploy_master_table`）・`sys_deploy_asset` と、成果物 `7777777777_*` を消す。別のキーの `sys_deploy` が指す master / asset は残す。**版の mst DB は消さない**（db.md 4.6 の掃除に任せる）。`sys_deploy` を消すと前の版が最新に戻る。
- キャッシュ: 登録・リセットの後に、API が読む `sys_deploy` の schedule キー（`SysDeployRepository::scheduleCacheKey()`。廃止した旧キーも併せて）・`deploy_key`・`id` のキャッシュを消す。
- 再利用する master の `mst_dropped_at` が入っている、または `mst_database` が命名から導いた名前と違うときは、実在を確認した版の DB に行を揃える（tool の R1 と同じ）。
- リセットは、残した master（別キーの `sys_deploy` が指すもの）の成果物ディレクトリと、シンボリックリンクの `7777777777_*` の中身は消さない。

## ログインボーナスのカレンダー（`RecordingLoginBonusProgressSeeder`）

カレンダー表示（login-bonus-calendar）の撮影では、「N 日目まで受け取り済み」「VIP の再受け取り」など、**特定のプレイヤーの状態**が要る。他のシナリオと違って、**既存プレイヤーの trx・`sys_player` を書き換える**（trx にはマーカー列が無いので `RecordingResetSeeder` では戻らない）。対象は環境変数で明示した 1 人だけ。設計は Nexus の `docs/design/login-bonus-calendar/`（サーバー側 `docs/design/login-bonus-calendar/db.md` 6 節）。

クライアントの `--e2e-reset` は毎回新しいプレイヤーを作るので、次の手順で使う。

1. クライアントを `--e2e-reset` で 1 回起動し、プレイヤーを作る（sign_up まで進めばよい）。画面などから `my_id` を控える（`sys_player.my_id`）
2. 撮りたい状態の番号を選んで流す。`make seed-recording` と `RecordingLoginBonusSeeder`（`daily_login` が有効）が済んでいること。
   ```
   make seed-recording-one SEEDER=RecordingLoginBonusProgressSeeder \
     RECORDING_PLAYER_MY_ID=<my_id> RECORDING_LOGIN_BONUS_SCENARIO=2
   ```
3. クライアントでログイン（ホームへ進む）して撮る。別の状態を撮るなら、番号だけ替えて 2 から繰り返す（何度流しても同じ結果で、上書きする）
4. 新しい `--e2e-reset`（新しいプレイヤー）で撮り直すときは、1 からやり直す（新しい `my_id` を控える）

| 番号 | 状態 | 撮れるもの |
| --- | --- | --- |
| `1` | 初回（行なし・`last_login_at = null`） | 通常・VIP とも 1 日目が配られ、スタンプを再生する |
| `2` | 3 日目まで受け取り済みの翌日 | 4 日目が配られる |
| `3` | 同じ日の 2 回目（4 日目を今日配布済み） | 配られず、スタンプは再生しない（配布済みの表示） |
| `4` | 6 日目まで受け取り済みの翌日 | 7 日目が配られる |
| `5` | 7 日目まで受け取り済みの翌日 | 1 日目に戻る |
| `6` | VIP の段なし（今のレベルの VIP マスターを無効化） | VIP の段が出ない（通常だけ） |
| `7` | VIP だけ今日の段でない（通常は今日配布済み） | VIP の段が「今日の分は未配布」 |
| `8` | VIP の再受け取り（レベルが上がった状態。**`last_login_at` は今日**） | 再受け取りのボタンが出て、押すと配られる。`last_login_at` を今日にするのは、ログインで新しい日の分が配られて「今日の段」の前提（再受け取りの条件）が崩れないようにするため |
| `F2` | 通常のマスターが無効で VIP だけある（`daily_login` を無効化） | 通常の段が出ない・VIP の段は出る |
| `G` | 通常・VIP の両段が今日の段でない（`last_login_at` は今日） | 両段とも「今日の分は未配布」 |

- `6`・`F2` はマスターを変える。戻すには別の番号（`1` など。`daily_login`・今のレベルの VIP マスターを有効に戻す）か、`RecordingLoginBonusSeeder`・`seed-recording-reset` を流す
- `RECORDING_PLAYER_MY_ID` が無い・該当プレイヤーがいない・番号が不明のときは、何も書かずに警告を出して終わる
- 「今日」は、その時のサーバーの時刻と `DAY_START_TIME` によるゲーム日。日をまたいで撮ると、`3`・`7`・`8`・`G` は「前日」の状態になるので、撮影の直前に流す

## 動画撮影の標準手順の例

### 規約が複数件（必須 3 件）→ 同意 → ホーム

1. `make seed-recording`（`rec-required` を含む既定のシナリオ）
2. `make seed-recording-one SEEDER=RecordingAgreementMultiSeeder`（必須を 3 件にする。何度流しても 2 件足すだけ）
3. クライアントを `--e2e-reset` で起動し、規約が 3 件出る画面を撮る
4. 撮り終えたら `make seed-recording-reset`（マーカー付きの 5 件がすべて消える）

### 新規ユーザーで規約あり → ログインボーナス → ホーム（ケース 3）

1. `make seed-recording`（デプロイ・規約・ログインボーナスを入れる）
2. クライアントを `--e2e-reset` で起動して撮影する
3. 撮り終えたら `make seed-recording-reset`

### メンテナンス 503 → 解除（ケース 8）

1. `make seed-recording-one SEEDER=RecordingMaintenanceOnSeeder`
2. クライアントを起動して、メンテナンス表示と「再試行」を撮る
3. `make seed-recording-one SEEDER=RecordingMaintenanceOffSeeder`
4. 再試行してホームまで進むところを撮る

## シナリオの足し方

1. `Scenarios/` にクラスを作る。`RecordingScenarioSeeder` を継承し、`apply()` だけを書く（`run()` は環境ガード付きで固定）。クラスの冒頭に、目的・使うケース・冪等性・副作用を日本語で書く
2. 既定で流したいものだけ `RecordingSeeder::defaultScenarios()` に足す（入れないものは `seed-recording-one` で流す）
3. 動画用の行にはマーカー（下）を付け、`RecordingResetSeeder` がその行を消せるようにする。新しい種類のテーブルを使うなら、Reset の削除にも足す
4. このドキュメントの「シナリオ一覧」と、必要なら「ケースとシナリオ」の表に足す
5. `api/tests/Feature/Recording/` にテストを足す（2 回流して行数が変わらない、マーカー行だけを作る・消す、既定の一覧に入るか）

## マーカーの規則

動画用の行は目印で見分け、**目印のある行だけを作る・消す**。定数は `RecordingScenarioSeeder` にある。

| テーブル | 目印 |
| --- | --- |
| `sys_agreement` | `version` が `rec-` で始まる（`rec-required` `rec-optional` `rec-future` `rec-required-2` `rec-required-3`。varchar(20) 以内） |
| `sys_maintenance` | `title` が `[REC]` で始まる |
| `sys_deploy` / `sys_deploy_master` / `sys_deploy_asset` | 目印は付けず、`deploy_key = 7777777777`（build の行に `[REC]` は付かない）で引く。`sys_deploy_master_table` はその master を指す行。成果物は `7777777777_*` のディレクトリ |
| `mst_login_bonus` | 目印なし。`daily_login` の有効フラグだけを切り替える（行は消さない） |
| `trx_login_bonus` / `trx_vip_login_bonus` / `sys_player` | 目印なし。`RecordingLoginBonusProgressSeeder` が、`RECORDING_PLAYER_MY_ID` で指した 1 人の行だけを書き換える（消さない・他のプレイヤーは触らない） |

## 規約の HTML

規約の `url` は `api/public/legal/` のサンプル HTML への相対パス（クライアントが API の origin に付けて取得する）。運用は [docs/terms_html.md](../../../../docs/terms_html.md)。

| version（`sys_agreement`） | url | ファイル |
| --- | --- | --- |
| `rec-required` | `/legal/terms-of-service-1.0.0.html` | 利用規約（条文 10 条） |
| `rec-optional` | `/legal/privacy-policy-1.0.0.html` | プライバシーポリシー |
| `rec-future` | `/legal/rec-sample-future.html` | 未公開の規約 |
| `rec-required-2` | `/legal/rec-sample-2.html` | 複数件用 |
| `rec-required-3` | `/legal/rec-sample-3.html` | 複数件用 |

シーダーは `url` も更新するので、古い値（以前のダミー URL）の行も流し直せば直る。テストは `api/tests/Feature/Recording/RecordingTermsHtmlTest.php`。

## 冪等性と安全性

- **追加専用・冪等**: 何度流しても同じ結果。既存のシーダー（`truncate` で作り直す）と違い、既存データを消さない。キーで `updateOrInsert` する
- **環境ガード**: `local` / `testing` 以外では例外で止まる。本番に誤って流さないため。シナリオの入口（`run()`）で毎回検査する
- 例外: `RecordingResetSeeder` は、マーカーの無い `daily_login` の `is_active` を true に更新する
- 例外: `RecordingLoginBonusProgressSeeder` は、**環境変数で指した 1 人の既存プレイヤーの trx・`sys_player` を上書きする**（行は消さず、他のプレイヤーは触らない。シナリオ 6・F2 は `mst_login_bonus` / `mst_vip_login_bonus` の有効フラグも切り替える）。冪等だが「既存データを書き換えない」ではないので、既定のシナリオに入れていない
- マスター（`mst_*`）への書き込みは `_BaseMst::allowWrites()` の中だけで行う
- キャッシュ: 503 のミドルウェアは `MaintenanceService` のキャッシュ（既定 60 秒）、version API は Redis のキャッシュ（メンテナンス・切り替え予定〔schedule キー〕とも 3600 秒）を先に見る。`RecordingMaintenanceOnSeeder` / `RecordingMaintenanceOffSeeder` / `RecordingResetSeeder` / `RecordingDeploySeeder` は末尾でこれらを消すので、流した直後に反映される（ケース 8 は On → 撮影 → Off → 再試行で撮れる）。それ以外の手段（SQL など）で DB を直接変えたときは、TTL を待つか `make seed-recording-clear-cache` で消す
- 回復手段（レート制限）: sign_up は IP あたり 10 回/時間・device あたり 3 回/時間で、成否にかかわらず数える。撮影で使い切ったら `make seed-recording-clear-cache`（`cache:clear`）でカウンターごと消せる。**キャッシュを全部消すので local 限定**
- 注意（規約）: 動画用ではない必須規約が既にあると、それも pending に出る。ケース 1・2 の撮影前に `seed-recording-reset` を流し、他の規約が無いことを確かめる
- 注意（任意規約）: pending は必須規約だけを返すので、`rec-optional` は規約画面に出ない（任意の扱いの確認用の行）
