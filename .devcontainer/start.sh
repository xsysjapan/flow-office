#!/usr/bin/env bash
# docker-compose の app サービスから起動される開発用エントリポイント。
# backend(Laravel)・frontend(Vite)・mcp(Laravel)の開発サーバーを起動し続ける。
set -e

cd /workspaces/flow-office/backend

if [ ! -f .env ]; then
  cp .env.example .env
  sed -i 's/^MICROSOFT_MOCK_ENABLED=.*/MICROSOFT_MOCK_ENABLED=true/' .env
  sed -i 's#^MICROSOFT_MOCK_INTERNAL_BASE_URL=.*#MICROSOFT_MOCK_INTERNAL_BASE_URL=http://mock-oidc:9000#' .env
  sed -i 's/^MICROSOFT_CLIENT_ID=$/MICROSOFT_CLIENT_ID=mock-client-id/' .env
  sed -i 's/^MICROSOFT_CLIENT_SECRET=$/MICROSOFT_CLIENT_SECRET=mock-client-secret/' .env
  sed -i 's/^MICROSOFT_TENANT_ID=.*/MICROSOFT_TENANT_ID=mock/' .env
fi

if [ ! -d vendor ]; then
  composer install
fi

if ! grep -q '^APP_KEY=base64' .env; then
  php artisan key:generate
fi

if [ ! -f database/database.sqlite ]; then
  touch database/database.sqlite
fi

# scripts/start-ngrok.sh(ホスト側)からngrok公開時のみBACKEND_PUBLIC_APP_URLが渡される。
# 未設定時は書き換えない(APP_API_PREFIX等、他の.env値はcp .env.exampleの既定のまま
# ローカル既定のAPP_URL=http://localhost:8000を使う)。mcpと同様の理由([.env直接書き換え]
# 参照、下記mcpセクションのコメント)でシェル変数ではなく.env自体を書き換える。
if [ -n "${BACKEND_PUBLIC_APP_URL:-}" ]; then
  sed -i "s#^APP_URL=.*#APP_URL=${BACKEND_PUBLIC_APP_URL}#" .env
  sed -i "s#^FRONTEND_URL=.*#FRONTEND_URL=${BACKEND_PUBLIC_APP_URL}#" .env
fi

php artisan migrate --seed
php artisan l5-swagger:generate
php artisan cache:clear
php artisan config:clear
php artisan route:clear

php artisan serve --host=0.0.0.0 --port=8000 &

cd /workspaces/flow-office/frontend

if [ ! -f .env ]; then
  cp .env.example .env
fi

npm install

npm run dev -- --host 0.0.0.0 &

cd /workspaces/flow-office/mcp

if [ ! -f .env ]; then
  cp .env.example .env
fi

if [ ! -d vendor ]; then
  composer install
fi

if ! grep -q '^APP_KEY=base64' .env; then
  php artisan key:generate
fi

if [ ! -f storage/oauth-private.key ]; then
  php artisan mcp:oauth-keys
fi

if [ ! -f database/database.sqlite ]; then
  touch database/database.sqlite
fi

php artisan migrate

if [ ! -d node_modules ]; then
  npm install
fi

# mcpの画面(/link, /oauth/authorize)は変更頻度が低いため、HMR用の常駐devサーバーは
# 立てずビルド済み資産で済ませる(frontendの5173とポートが衝突するのを避ける意図もある)。
if [ ! -f public/build/manifest.json ]; then
  npm run build
fi

# scripts/start-ngrok.sh(ホスト側)からngrok公開時のみMCP_PUBLIC_APP_URLが渡される。
# mcpだけのAPP_URLを上書きしないと、OAuth2メタデータ(issuer等)が/flow-office/mcpを
# 含まない誤ったURLを返し、外部のMCPクライアントからの接続が壊れる。
# シェルの環境変数としてAPP_URLを渡すだけだと、phpdotenvの「既存の環境変数は
# 上書きしない」判定が$_ENV/$_SERVER基準で行われ、getenv()レベルでは値が見えていても
# 反映されないことがある(variables_order次第)。確実に反映させるため、mcp/.envの
# APP_URL行自体を直接書き換えてから起動する(backend側の.envには触れない)。
if [ -n "${MCP_PUBLIC_APP_URL:-}" ]; then
  sed -i "s#^APP_URL=.*#APP_URL=${MCP_PUBLIC_APP_URL}#" .env
else
  sed -i 's#^APP_URL=.*#APP_URL=http://localhost#' .env
fi
php artisan config:clear >/dev/null 2>&1 || true
php artisan serve --host=0.0.0.0 --port=8090 &

wait -n
