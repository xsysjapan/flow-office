# mcp/

`backend/`とは完全に独立したLaravelアプリ(別の`composer.json`・別のDB)。ClaudeなどのAI
アプリへ勤怠操作ツールをMCP(JSON-RPC 2.0, Streamable HTTP)で提供する。認可はOAuth2
(Dynamic Client Registration対応)。詳細な設計は `mcp/README.md` と
`docs/25-usecases-integrations-mcp.md` を参照(このファイルでは要約のみ)。

**勤怠計算ロジック・労働時間計算・休日判定・締め判定・承認ルールはここに実装しない**。
すべて`backend/`のAPIをHTTP経由で呼び出すクライアントであり、`backend/`のDBには一切
アクセスしない(設計原則11)。

## セットアップ

```
cd mcp
composer install
cp .env.example .env && php artisan key:generate
php artisan mcp:oauth-keys      # OAuth2アクセストークン署名用のRSA鍵ペアを生成する
touch database/database.sqlite
php artisan migrate
php artisan serve --port=8090
php artisan test
```

## ディレクトリ構成

```
app/
├── Mcp/Tools/          MCPツール本体(1ツール1クラス)。中身はbackend/ APIへのHTTP呼び出し
├── Mcp/OAuth/           DCR・認可コード+PKCE・アクセストークン(JWT)発行の実装
├── Mcp/Contracts/       ツール・OAuthの共通インターフェース
├── Mcp/Support/         横断的なユーティリティ
├── Http/Controllers/    /oauth/* エンドポイント、MCPエンドポイント(/)
└── Models/              mcp/自身のDB用モデル(oauth_*, mcp_users, mcp_user_backend_tokens,
                          attendance_import_sessions等。docs/26参照)

routes/api.php   MCP/OAuthエンドポイント定義
database/        mcp/自身のDBのmigrations(backend/のDBとは無関係)
```

## 効率的なコード参照

- 新しいMCPツールを追加/修正する場合は `app/Mcp/Tools/` の中の対応する1ファイルと、
  それが呼び出す `backend/routes/api.php` 側のエンドポイントだけを見れば足りる。
  `backend/app/Domain/` の実装詳細まで読み込む必要はない(mcp/はAPIクライアントに徹する)。
- OAuth周りの調査は `app/Mcp/OAuth/` に閉じる。ツール実装(`Mcp/Tools/`)を一緒に読む必要はない。

## 開発でよく使うパターン

`backend/`側のドメインロジック追加は`backend/CLAUDE.md`・`.claude/skills/`を参照。
mcp/固有のスキルは現状なし。
