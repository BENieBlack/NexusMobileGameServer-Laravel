# Nexus

モバイルゲーム向けのLaravelベースのバックエンドフレームワーク。APIサーバーと運営ツールで構成されています。

## プロジェクト構成

```
NexusMobileGameServer-Laravel/
├── api/                    # ゲームAPIサーバー（Laravel）
├── tool/                   # 運営ツール（api とは別の Laravel アプリ。マスターデータの build・import・切り替えもここ）
│   └── app/Console/Commands/   # master:* コマンド（マスター配信の CLI）
├── packages/               # 機能ごとのパッケージ（nexus-*）
├── docker/                 # Docker設定ファイル
├── command/                # 開発用スクリプト
│   ├── setup.sh           # 環境構築スクリプト（現状は使えない。「クイックスタート」参照）
│   ├── deploy.sh          # デプロイスクリプト（git pull → 反映 → migration → 起動確認）
│   └── sail               # Laravel Sailラッパー
├── docs/                   # 機能ごとの設計・実装メモ（docs/design/ は Nexus の設計に対応するサーバー側の設計）
├── docker-compose.yml      # Docker Compose設定
├── .env                    # 環境変数（api と tool で共通。`.env.example` から作る）
└── .claude/                # プロジェクトドキュメント
    ├── development.md      # 開発環境・テスト・静的解析
    ├── database.md         # データベース設計
    ├── api.md              # API仕様
    └── tool.md             # Tool仕様
```

要件・全体設計・進め方は、別リポジトリの Nexus（司令塔）にあります。
このリポジトリには、サーバー内部の設計と実装だけを置きます。

## 必要なもの

| ソフトウェア | 必須 | 備考 |
|---|---|---|
| Docker / Docker Compose | ✅ | Compose v2（`docker compose`）を推奨。v1も可 |
| Node.js | 任意 | Tool管理画面のアセットビルド用。APIの開発には不要 |

**ホストにPHP・Composerは不要です。** コンテナ内のPHP 8.5を使います。

## クイックスタート

### 1. 環境構築

`./command/setup.sh`（`make install`）は旧コンテナ名（db-sys など）を前提にしており、現状は使えません。
次の手順で構築します（詳細は [.claude/development.md](./.claude/development.md)）。

```bash
git clone <repository-url>
cd <clone したディレクトリ>

# 環境変数ファイルを作る。APP_KEY を設定する（例: base64:$(openssl rand -base64 32)）
# .env はコンテナを起動する前に書き終えておく
cp .env.example .env

make up
docker compose exec -T api-php composer install
docker compose exec -T tool-php composer install
make migrate
docker compose exec -T tool-php php artisan migrate --path=database/migrations/adm --database=admin --force
docker compose exec -T tool-php php artisan migrate --path=database/migrations/tol --database=tool --force
make seed
docker compose exec -T tool-php php artisan db:seed --class=AdminAccountSeeder --force
```

- DB は MySQL コンテナの初回起動時に `docker/mysql/init/` のスクリプトが作ります（`docker-compose.yml` の `DB_NAMES`）
- `make migrate` は sys・mst（基底 DB）・trx・log を流します。tool の adm / tol は含まれないので、上のとおり別に流します

### 2. アプリケーションへのアクセス

- **API**: http://localhost:8090
- **Tool (運営管理画面)**: http://localhost:8091

## データベース構成

MySQL は役割ごとにコンテナを分けています（定義は `docker-compose.yml`）。
`log_*` は専用のDBを持たず、対応する trx と同じシャードのDBに入ります。

### 非シャーディングDB

| 接続 | コンテナ | ポート | DB名 | 用途 | 使う側 |
|---|---|---|---|---|---|
| `sys` | db-system | 63062 | `{APP_NAME}-{APP_ENV}-system` | 全プレイヤー共通（シャード割当・デプロイ管理・**プレイヤー公開情報**・ギルド・フレンド・チャット） | API |
| `mst` | db-master | 63063 | 基底 DB `{APP_NAME}-{APP_ENV}-master` と、版ごとの DB `{APP_NAME}-{APP_ENV}-master-{deploy_key}-{HASH16}` | マスターデータ（下記） | API・Tool |
| `admin` | db-admin | 63060 | `{APP_NAME}-{APP_ENV}-admin` | 管理者アカウント・権限 | Tool |
| `tool` | db-tool | 63061 | `{APP_NAME}-{APP_ENV}-tool` | 運営ツール機能（`tol_*`。マスター配信の実行記録など） | Tool |

**mst は 2 種類のDBがあります。**

- **基底 DB**: スキーマの雛形で、開発用のシーダー（`Mst*Seeder`）の行き先です。`make migrate` の対象はこれだけです
- **版の DB**: マスターデータをデプロイするたびに tool が作る、その版の中身です。作成後は書き換えません。
  `HASH16` は成果物の hash の先頭16文字です。API は `sys_deploy.start_at` を基準に「いまの版」を選び、リクエストごとに `mst` 接続の向き先を切り替えます

### シャーディングDB

trx と log は同じDBに入り、DB名は全シャード共通です。
シャード数は `.env` の `DB_SHARD_COUNT` で制御し、`.env.example` は **3** です。

| シャード | コンテナ | ポート | DB名 | 中身 |
|---------|--------|--------|------|------|
| 1 | db-shard1 | 63161 | `{APP_NAME}-{APP_ENV}-shard` | `trx_*` と `log_*` |
| 2 | db-shard2 | 63162 | 同上 | 同上 |
| 3 | db-shard3 | 63163 | 同上 | 同上 |

プレイヤーがどのシャードに属するかは sys データベースの
`sys_sharding` / `sys_sharding_node` / `sys_sharding_node_player` で管理します。
シャード追加の手順は [docs/sharding_expansion_guide.md](./docs/sharding_expansion_guide.md) を参照してください。

**sysデータベースの重要な役割**: プレイヤー名、レベル、戦闘力、パーティ編成などの「他プレイヤーから参照される公開情報」を保持します。これにより、ランキング表示やフレンド検索時にシャーディング先までアクセスせずに高速に情報を取得できます。

接続情報:
- ユーザー名: `root`
- パスワード: `root`（`MYSQL_ROOT_PASSWORD`）
- Redis: `redis` コンテナ（ポート 63790）。キャッシュに使います

テスト用のDBは `{APP_NAME}-testing-*` で、同じコンテナに同居します。

詳細は [.claude/database.md](./.claude/database.md) を参照してください。

## マスターデータの配信

マスターデータは、デプロイのたびに**新しい mst DB（版の DB）を作り**、`sys_deploy.start_at`（UTC）で「いまの版」を切り替えます。1つの mst を書き換えません。
作業は運営ツール（tool）で行います。

1. **build**（develop・LD・QA 環境）: Google スプレッドシートを読み、検証・畳みをして、成果物と版の DB を作り、sys に登録します
2. **成果物**: `{deploy_key}_{hash先頭16文字}/` に、行データ・スキーマ・配信用 SQLite・マニフェストを置きます。ローカルでは `tool/public/masterdata/`（`/masterdata` で配信）で、**git には入れません**
3. **import**（review・production・staging 環境）: 上流の成果物を取り込み、自環境の版の DB を作って sys に登録します（`start_at` を指定）。シートは読みません
4. **切り替え**: 登録した版は `start_at` を過ぎると、ゲーム API の「いまの版」になります。キャッシュのキーに版の DB 名が入るので、mst のキャッシュは破棄しません
5. **巻き戻し・予約の取り消し・緊急停止・掃除**: 戻したい版を指す新しい `sys_deploy` 行を作って切り替えます（DB・成果物は作り直さない）。古い版の DB の掃除は手動です

環境ごとの役割は `.env` の `MASTER_DEPLOY_ROLE`（`build` か `import`）で決まります。
`MASTER_DEPLOY_FIXED_KEY` / `MASTER_DEPLOY_KEY`（畳みの上限 T）と `MASTER_ARTIFACT_UPSTREAM_ROOT`（import の上流の置き場）も `.env.example` にあります。

操作は画面（http://localhost:8091/master-import）か、tool-php コンテナの CLI で行います。

```bash
# 例: 検証だけ → build → 上流の成果物を取り込む
docker compose exec -T tool-php php artisan master:build --dry-run
docker compose exec -T tool-php php artisan master:build
docker compose exec -T tool-php php artisan master:import <artifact_dir> --start-at=now
```

CLI は `master:build`・`master:import`・`master:finalize`・`master:verify-sheet`・`master:rollback`・`master:cancel-schedule`・`master:deactivate`・`master:activate`・`master:resolve-unknown`・`master:cleanup` です。
引数・終了コード・各クラスの役割は [.claude/tool.md](./.claude/tool.md) にあります。

開発環境では、`make seed` が基底 DB を1つの版として sys に登録するので、tool を使わなくても API は動きます。

仕様（要件・全体設計・方式の決定）の正本は Nexus リポジトリにあります
（`docs/design/master-distribution/` と `docs/adr/0007-versioned-master-db-timed-switch.md`）。
サーバー側の設計はこのリポジトリの次にあります。

- [docs/design/master-distribution/db.md](./docs/design/master-distribution/db.md) - DB・成果物・登録
- [docs/design/master-distribution/api.md](./docs/design/master-distribution/api.md) - tool の HTTP・CLI・エラー
- [docs/design/master-distribution/sequence.md](./docs/design/master-distribution/sequence.md) - 処理の流れ
- [.claude/api.md](./.claude/api.md) - ゲーム API 側のバージョンチェックと切り替え

## 開発コマンド

### 環境管理

```bash
# コンテナを起動
docker compose up -d

# コンテナを停止
docker compose stop

# コンテナとボリュームを完全に削除する（DB の中身も消える）。作り直しは「クイックスタート」の手順
make clean
```

テスト用の2つ目の mst DB（`{APP_NAME}-testing-master-2026010101-0000000000000000`）は、MySQL のボリュームの初回作成時にしか作られません。
既存の環境では `docker compose down -v` で作り直すか、手で作ります（[.claude/development.md](./.claude/development.md) の「テスト用の 2 つ目の mst DB」）。

### マイグレーション

```bash
# API - sys・mst（基底 DB）・trx1..N・log1..N
make migrate

# API - リセットして再実行（sys / mst の基底 DB / trx / log をすべて作り直す）
# 既存のマイグレーションを書き換えたときはこちら。
# 初期データも消えるので、続けて make seed を流す（無いとサインアップが error_code 605 で失敗する）
make migrate-fresh

# API - 初期データを投入（シャーディング設定・基底 DB のマスターデータ・いまの版の登録など）
make seed
```

`make migrate` と `make migrate-fresh` が対象にする mst は**基底 DB だけ**です。
版の DB は流しません（作り直すときは `master:build` / `master:import`。凍結後に版の DB へマイグレーションを流す `mst:migrate-versions` は未実装です）。

**引数なしの `php artisan migrate` は使わないでください。**
マイグレーションは `Schema::create`（接続名なし）で書かれており、`--database` で接続を指定して流します。
既定接続は `sqlite` のため、引数なしで実行すると
`api/database/database.sqlite` にテーブルが作られて実際のDBには反映されません。

個別に実行する場合は接続とパスを必ず指定します。マイグレーションは
`api/database/migrations/{group}` と `packages/*/database/migrations/{group}` に
分かれて置かれているため、両方を渡す必要があります（`make migrate` が自動で行います）。

```bash
# APIプロジェクト - トランザクションDB（すべてのシャード: trx1, trx2, ...）
docker exec api-php php artisan trx:migrate

# APIプロジェクト - ログDB（すべてのシャード: log1, log2, ...）
docker exec api-php php artisan pitr:migrate

# Toolプロジェクト - 管理者DB（接続名は admin、マイグレーションのパスは adm）
docker exec tool-php php artisan migrate --database=admin --path=database/migrations/adm

# Toolプロジェクト - 運営ツールDB（接続名は tool、マイグレーションのパスは tol）
docker exec tool-php php artisan migrate --database=tool --path=database/migrations/tol
```

**シャーディング対応マイグレーション:**
- `trx:migrate` / `pitr:migrate` - trx / log の全シャードにマイグレーション（trx と log は同じDBなので、`pitr:migrate --fresh` は使わない。作り直しは `make migrate-fresh`）
- `trx:rollback` / `pitr:rollback` - 全シャードでロールバック
- `migrate:shards` - すべてのトランザクションシャード（trx1, trx2, ...）に一括マイグレーション
- `migrate:shards-status` - すべてのシャードのマイグレーション状態を確認
- `migrate:shards-rollback` - すべてのシャードでロールバック

**シャード割り当て:**

プレイヤーがどのシャードを使うかは `sys_sharding_node_player` の割り当てで決まり、
サインアップ時に作られます。割り当てが無いプレイヤーは接続先を解決できずログインに失敗します。

```bash
# 割り当ての無いプレイヤーへ割り当てを作る（まずは --dry-run で確認）
docker exec api-php php artisan sharding:assign-players --dry-run
docker exec api-php php artisan sharding:assign-players
```

既にデータがあるプレイヤーは、そのデータが入っているシャードへ寄せます
（ハッシュで振り直すと今あるデータへ届かなくなるため）。

詳細は [シャーディングマイグレーションシステム](./docs/sharding_migration_system.md) を参照してください。

### Laravel Sailラッパー

```bash
# API（デフォルト）
./command/sail artisan list
./command/sail composer install
./command/sail npm run dev

# Tool
./command/sail --tool artisan list
./command/sail --tool composer install
./command/sail --tool npm run dev
```

### テスト

```bash
# api と packages の全テスト（Dockerを自動起動。結果だけ表示: make test-quiet）
make test

# tool の検査（pint・PHPStan・テスト。tool のテスト用DBも作る）
make tool-check

# CI と同じ検査（脆弱性・フォーマット・静的解析・パッケージ単体・全テスト・tool）。PR の前に回す
make check

# APIのテストを実行（Dockerを自動起動）
./command/sail api test

# Toolのテストを実行（Dockerを自動起動）
./command/sail tool test

# 特定のテストのみ実行
./command/sail api test --filter=UserTest

# より詳細な出力で実行
./command/sail api test --verbose
```

**注意**: `./command/sail` のテストコマンドは自動的にDockerコンテナの状態を確認し、起動していない場合は`docker compose up -d` を実行します。

テストは `{APP_NAME}-testing-*` のDBを使い、マイグレーションはテスト自身が流します。
CI（`.github/workflows/ci.yml`）は、静的解析・フォーマット、パッケージ単体、tool、テスト（api + packages）、個人情報の検査の5ジョブです。

### カバレッジ

カバレッジドライバ（pcov）はapi-phpイメージに同梱済みで、常時有効です。
追加のオプションを渡さなくても計測できます。

```bash
# テキストで表示
make coverage

# HTMLで出力（api/storage/coverage/index.html）
make coverage-html
```

計測対象は `api/phpunit.xml` の `<source>` で指定しています。
パッケージを追加したら、ここにも `../packages/<name>/src` を足してください。

詳細は [.claude/development.md](./.claude/development.md) を参照してください。

## アーキテクチャ

### APIプロジェクト

- **Clean Architecture** ベース
- **DDD (Domain-Driven Design)** の概念を採用
- **Repository パターン** でデータアクセスを抽象化
- **シャーディング対応**: trx1〜trxN（`DB_SHARD_COUNT`）で負荷分散

### Toolプロジェクト

- **MVC アーキテクチャ**
- **DB**: admin（認証）・tool（業務機能）のほか、マスター配信のため sys と mst（基底 DB・版の DB）も参照する
- **セッション認証**（`adm_account`）

## テーブル命名規則

すべてのテーブルには接頭辞が必要です：

- `sys_` - システム管理テーブル
- `mst_` - マスターデータテーブル
- `log_` - ログテーブル
- `trx_` - トランザクションテーブル
- `adm_` - 管理者関連テーブル
- `tol_` - 運営ツールテーブル

例: `mst_item`, `trx_player`, `log_gacha`, `tol_banner`

詳細は [.claude/database.md](./.claude/database.md) を参照してください。

## トラブルシューティング

### セットアップが失敗する

```bash
# 完全にクリーンアップして、「クイックスタート」の手順をやり直す
docker compose down --volumes --remove-orphans
```

### データベースに接続できない

```bash
# MySQLコンテナの状態確認
docker ps | grep db-

# ログ確認
docker logs db-system
docker logs db-master
```

### マイグレーションエラー

```bash
# sys・mst（基底 DB）・trx・log をリセットする（初期データも消えるので、続けて make seed）
make migrate-fresh
```

### APIが全て404・502を返す

`api-php` を作り直した直後に起きることがあります（`make up` がイメージを作り直したときなど）。
コンテナのIPが変わり、nginx（`api-web`）が古いIPへ送ってしまうためです。

nginx は Docker の内部DNSで `api-php` の名前を10秒ごとに引き直す設定にしてあるので
（`docker/api-nginx/api-nginx.conf` の `resolver`）、通常は10秒ほど待てば直ります。
直らない場合は nginx を再起動してください。

```bash
docker compose restart api-web
```

## ドキュメント

### プロジェクト全体
- [アーキテクチャ](./.claude/architecture.md) - レイヤ構成、パッケージ分割の方針
- [コーディング規約](./.claude/coding-standards.md) - 実装ルール、レイヤごとの責務
- [命名規約](./.claude/naming-conventions.md) - クラス・テーブル・カラムの命名
- [開発環境構築ガイド](./.claude/development.md) - Docker、make コマンド、テスト・静的解析、PR の書き方
- [データベース設計](./.claude/database.md) - テーブル構造、命名規則、マイグレーション管理
- [シャーディングマイグレーションシステム](./docs/sharding_migration_system.md) - 動的シャード対応、新規シャード追加手順

### API固有
- [API仕様](./.claude/api.md) - エンドポイント、認証、レスポンス形式
- [API呼び出しフロー](./api/docs/api_flow.md) - 推奨されるAPI呼び出し順序
- [コーディング規約](./api/docs/coding_conventions.md) - Request/Response/Dataクラスの命名規則とディレクトリ構成
- [Repositoryパターン実装ガイド](./api/docs/repository_pattern.md) - データアクセス抽象化とキャッシュ戦略
- [クライアント認証](./docs/client_authentication.md) - 署名検証、デバイス認証

### 実装済み機能
- [ガチャシステム](./docs/gacha_implementation.md) - 通常/ステップアップ/ピックアップガチャ、確率制御、保証機能
- [ギルドシステム](./docs/guild_implementation.md) - ギルド作成、メンバー管理、申請承認、役職制御
- [Wallet管理への切り替え手順](./docs/wallet_managed_item_migration.md) - mst_item.is_wallet、リリース後の残高移行

### 評価レポート
- [プロジェクト評価 2026-11-03](./docs/project_evaluation_2026-11-03.md) - 最新の評価
- [プロジェクト評価 2026-09-01](./docs/project_evaluation_2026-09-01.md) - 品質ゲート整備後の状態、前回指摘16件の追跡
- [プロジェクト評価 2026-08-12](./docs/project_evaluation_2026-08-12.md) - 初回評価

### Tool固有
- [Tool仕様](./.claude/tool.md) - 運営ツールの機能、データベース構成、マスター配信（build・import・CLI）
- [マスター配信の設計](./docs/design/master-distribution/db.md) - 版ごとの mst DB と start_at の切り替え（db.md・api.md・sequence.md）

## ライセンス

このプロジェクトは [MIT License](./LICENSE) の下でライセンスされています。
