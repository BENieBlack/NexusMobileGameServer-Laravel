# mst 16 テーブルへの string id 導入 シーケンス設計（S0 のサーバー設計）

移行（マイグレーションの流れ）と、運営シートの取り込みから配信までの流れのうち、`id` が効く箇所だけを書く。短くてよい範囲。

- DB の設計・論点: [db.md](db.md)、API の変化: [api.md](api.md)
- 全体の流れ（畳み・採番・配信）は Nexus の `docs/design/master-distribution/overview.md` 5 節の図が正本。ここに写さない
- 凡例: 「読んだ」= コードを読んだだけ。実行はしていない

## 1. 移行の流れ（PR 1 本あたり。書き換え方式。db.md 6.1）

```mermaid
sequenceDiagram
    autonumber
    participant OP as 運営（シート）
    participant DEV as 開発者
    participant CI as CI / make check
    participant DB as mst DB（db-master）
    participant T as tool（取り込み・配信）

    OP->>OP: 対象の表に id 列を足す（規則は db.md 3 節）
    DEV->>DEV: create_*_tables.php を書き換え、Model・Repository・シーダー・テストを直す
    DEV->>DEV: PENDING_VIOLATIONS から直した表を消す（db.md 10 節）
    DEV->>CI: make check（Architecture テストが id の有無・AUTO_INCREMENT・消し忘れを検査）
    CI-->>DEV: 通過
    Note over DB: 環境の更新（手元は make migrate-fresh → make seed。<br/>テスト DB は指紋の変化で自動再作成）
    DEV->>DB: migrate-fresh（mst は作り直し。trx・log も同時に作り直す環境のみ）
    DEV->>DB: キャッシュ破棄（Redis mst:{table}:all。形が変わるため）
    OP->>T: シートを取り込む（id 付き）
    T->>DB: TRUNCATE → INSERT（id あり）
    OP->>T: export（配信）
    T->>T: SQLite 出力（id 列つき）・hash 更新・sys_deploy 登録
```

- **シートの `id` 列が先**。DB を新しい形にしてから `id` の無いシートを取り込むと、INSERT が失敗する。TRUNCATE が先に走っているので、その表は空のまま残る（db.md 8.1 の既存の穴。事前チェックで塞ぐ案がdb.md 12 節 4（決定））。
- 作り直せない環境は無い（db.md 12 節 1）ので、追加のマイグレーションは使わない（db.md 6.3 は不採用の代替案）。trx の購入履歴は `migrate-fresh` で消える。
- 手順 7 のキャッシュ破棄を忘れると、`queryOrMemory` が古い形（元の複合キーの鍵）の配列を TTL まで返す。

## 2. 運営シートの取り込みから配信まで（`id` が効く箇所）

```mermaid
sequenceDiagram
    autonumber
    participant OP as 運営
    participant C as MasterImportController
    participant G as GoogleSpreadsheetService
    participant I as MasterImportService
    participant DB as mst DB
    participant X as MasterDataExporter
    participant CL as クライアント

    OP->>C: POST /master-import/execute（spreadsheet_id, sheet_title）
    C->>G: getSheetData（1 行目 = テーブル名、2 行目 = 列名）
    G-->>C: テーブルごとの headers・rows（id 列もそのまま列として入る）
    loop テーブルごと
        C->>I: import(table, headers, rows)
        I->>I: SHOW COLUMNS でテーブルの列と照合（シートの id 列が列として残る）
        Note over I: 【S0 で足す】id 列がテーブルにあるのにシートに無いなら、TRUNCATE の前に中断。<br/>id の空・重複・規則違反の行を拒否（db.md 8.2）
        I->>DB: TRUNCATE
        I->>DB: INSERT（500 行ずつ。id は PK、自然キーは unique）
    end
    OP->>C: POST /master-import/export
    C->>X: export()
    X->>DB: SELECT *（id 列を含む全列）
    X->>X: SQLite 出力（単一 PK = id のとき PRIMARY KEY。複合 PK だった表は単一 PK に）
    X-->>C: hash・ファイル一覧（16 表を含むグループの hash が変わる）
    CL->>CL: master_data の Local リポジトリが SELECT（列を明示。id を読むのは VIP の報酬行だけ。db.md 9.2）
```

- **`id` が効く箇所**: ①シートの 1 列目以降に `id` 列があること（`getSheetData` が列として拾う）②取り込みの検証（空・重複・規則違反）③DB の PK = `id`・自然キー = unique で、**行の一意が 2 重に守られる**（INSERT 時の重複は MySQL が拒否し、その表の取り込み全体が失敗する。TRUNCATE 後なので表は空になる。ここも既存の穴）④配信 SQLite の PK ⑤deploy_key 上乗せの畳み（`id` 単位で最大の `deploy_key` の行を採る）。⑤は deploy_key 上乗せの S2/S3 の範囲で、S0 は **畳みの鍵が存在する状態にする**ところまで。
- **S0 の後も `deploy_key` の扱いは変わらない**: 取り込みは今までどおり TRUNCATE + INSERT（畳みは S3）。S0 は列と PK だけを変える。
- 取り込みの拒否の粒度（テーブル単位で止める）と、`created_at`・`updated_at` の自動設定は現状のまま。

## 3. 買い切り・パスの購入で `id` が効く箇所（PR ④ の後）

`mst_in_app_purchase.id` が文字列になるので、購入の流れの識別子が変わる（API の入力は [api.md](api.md)）。

```mermaid
sequenceDiagram
    autonumber
    participant CL as クライアント
    participant API as InAppPurchaseController / BuyRequest
    participant M as MstInAppPurchaseRepository
    participant U as BuyDiamond / BuyPack / BuyPass UseCase
    participant TRX as trx（シャード）
    participant LOG as log（同じシャード）

    CL->>API: POST /api/in_app_purchase/buy（mst_in_app_purchase_id: 文字列）
    API->>API: 検証（string・max 255）
    API->>M: selectActiveById(string)
    M-->>API: 商品 or null（無い・無効は既存のエラー）
    API->>U: 実行
    U->>TRX: trx_in_app_purchase（PK に商品 id）を読む・更新
    U->>TRX: パスなら trx_in_app_purchase_effect（商品 id 列は文字列）を追加
    U->>LOG: log_action_in_app_purchase（商品 id は元から文字列）
```

- 購入回数の制限（`trx_in_app_purchase`）は PK の `mst_in_app_purchase_id` で引く。**列は元から文字列**で、コードが `int` で比べていた（`===`）のを、文字列同士の比較に直す。直し忘れると、型の違いで「見つからない」→ 回数制限が効かなくなるので、PR ④ のテストで固定する（二重購入・上限・リセットの既存テストを文字列 id で通す）。
- 冪等キー（`_BaseBuyUseCase::generateUniqueRequestId` の `{sysPlayerId}_{id}_{transaction}`）は、`id` が文字列になって区切りの `_` を含みうる（`iap_diamond_100`）。連結の曖昧さが、同じプレイヤー・同じトランザクションで実害になるか（長さの上限も含めて）は**未確認**。PR ④ で確認する。
