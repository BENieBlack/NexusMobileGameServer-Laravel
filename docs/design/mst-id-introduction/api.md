# mst 16 テーブルへの string id 導入 API 設計（S0 のサーバー設計）

**新しい API は無い。** `version` と マスター配信の応答の形は変わらない。変わるのは、配信される SQLite の列と、課金・報酬トラックの 1 項目の型だけ。

- DB・論点: [db.md](db.md)、シーケンス: [sequence.md](sequence.md)
- 全体設計（仕様の正本）: Nexus の `docs/design/master-distribution/api.md`
- 凡例: 「読んだ」= コードを読んだだけ

## 1. 変わらないもの

| 対象 | 変わらないこと |
| --- | --- |
| `POST /api/auth/version`（`version`）の応答 | 項目・型・エラー。`sys_deploy` の選び方（最新の判定）も、この S0 では変えない（deploy_key 上乗せの S3） |
| マスター配信の URL・ファイルの置き方・manifest | `public_url`（相対パス）、グループ単位の SQLite（`{グループ}_{hash}.sqlite`）、manifest hash の作り方 |
| クライアントの読み取り | `mst_player_level`・`mst_login_bonus_content` は SELECT が列を明示しているので壊れない（db.md 9.2） |

## 2. 変わるもの

### 2.1 配信される SQLite の列（クライアントが取得するファイル）

| 変わること | 内容 |
| --- | --- |
| 列が増える | 16 テーブルに `id`（TEXT・主キー）が増える。他の列は変わらない |
| 主キーが変わる | 複合主キーだった 12 表が、単一の `id` になる。`mst_player_level` の主キーは `level` から `id` へ（`level` 列は残る） |
| AUTO_INCREMENT がなくなる | `mst_billing_platform_product`・`mst_in_app_purchase`・`mst_vip_login_bonus_content` の `id` が `INTEGER PRIMARY KEY AUTOINCREMENT` から TEXT の主キーに |
| 参照列の型 | `mst_in_app_purchase_content.mst_in_app_purchase_id`・`mst_in_app_purchase__l10n.mst_in_app_purchase_id` などが INTEGER から TEXT に |
| hash | 16 表を含むグループの SQLite の hash と、manifest hash が変わる。S0 のデプロイの後の最初の `export` で新しい `sys_deploy_master` が登録され、クライアントは通常の更新で取りに行く |
| クライアントの追従 | `mst_vip_login_bonus_content.id` を整数で読んでいる（0 になるが表示は壊れない）。string に直す小さな変更が要る（db.md 9.2。blocking ではない） |

### 2.2 課金・報酬トラックの 1 項目の型（**決定**: db.md 12 節 2。PR ④ で変わる）

| API | 項目 | 現状 | 変更後 | 備考 |
| --- | --- | --- | --- | --- |
| `POST /api/in_app_purchase/buy`（入力） | `mst_in_app_purchase_id` | `required`・`integer`・`min:1` | `required`・`string`・`max:255` | `BuyRequest`。メッセージ・属性名の定義も直す。数値の受け入れ（互換）はしない |
| 報酬トラックの概要（出力。`GetSummaryResponse`） | `mst_in_app_purchase_id`（ラインごと。有料ラインのみ） | 数値（`int`）。無料ラインは `null` | 文字列。無料ラインは `null` のまま | `RewardTrackLineMaster`・`RewardTrackLine` の型も `?string`・`string` に |

- **破壊的変更**: PR ④ の本文に載せる。クライアント側の追従（別リポジトリ。課金 API・報酬トラックの応答を読む側）は波及として残す。
- 影響する呼び出し側: 課金 API・報酬トラックの API を呼ぶクライアントは、Godot のクライアントには見当たらない（`master_data/`・`contexts/`・`core/`・`app/` を `in_app_purchase` で grep して該当なし）。**他のクライアント・スクリプトが無いかは未確認**。
- 値の例: 現在の数値 `1`〜`5`（開発のシーダー）は `iap_diamond_100` などに変わる（db.md 3.3）。
- `.claude/api.md` の課金の記述（`mst_in_app_purchase_id` の列挙）は、型の説明を足す（PR ④ で直す）。

### 2.3 エラー

新しいエラーコードは無い。存在しない・無効な商品 ID は、既存のエラー（`selectActiveById` が `null` を返したときの扱い）のまま。**文字列にしたことで、数値でない入力が `422`（検証エラー）ではなく「商品が見つからない」に変わる**（`min:1` の検証が無くなるため）。この差がテストに現れるので、`BuyEndpointTest` の期待値を確認して直す（PR ④）。
