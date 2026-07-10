# flow-office

汎用勤怠・申請・バックオフィス処理システム。

- `backend/` — Laravel API (バックエンド)
- `frontend/` — Vite + React + TypeScript (フロントエンド、Storybook導入済み)
- `docs/` — 設計ドキュメント(目次は [docs/README.md](docs/README.md))

開発の始め方・設計原則は [CLAUDE.md](CLAUDE.md) を参照。

## ローカル環境

VSCode Dev Containers または docker compose でローカル環境を構築できる。

- VSCode: リポジトリを開き「Reopen in Container」を実行する
  (`.devcontainer/devcontainer.json`)。backend・frontendの依存関係インストールと
  モックOIDCサーバーの起動まで自動で行われる。
- docker compose のみを使う場合: `docker compose up -d mock-oidc` でモックOIDCサーバー
  (`http://localhost:9000`) だけを起動し、backend/frontendは [CLAUDE.md](CLAUDE.md) の手順で
  ホスト上に直接セットアップする。

### Entra ID SSOのローカルモック (`mock-oidc/`)

実際のMicrosoft Entra IDの代わりにログインできる開発用モックOIDCサーバーを同梱している
(`mock-oidc/server.js`)。backend の `.env` で以下を設定すると有効になる
(devcontainerでは自動設定済み)。

```
MICROSOFT_MOCK_ENABLED=true
MICROSOFT_CLIENT_ID=mock-client-id
MICROSOFT_CLIENT_SECRET=mock-client-secret
MICROSOFT_TENANT_ID=mock
```

有効にすると `GET /api/auth/microsoft/redirect` が実際のEntra IDではなく
`mock-oidc/` のログイン画面(`http://localhost:9000`)を指すようになり、画面上でダミー
ユーザーを選択するだけでSSOログインの一連の流れ(初回ログイン時のユーザー作成含む)を
確認できる。本番・検証環境では `MICROSOFT_MOCK_ENABLED` を設定しないこと。
