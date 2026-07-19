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

php artisan migrate --seed
php artisan l5-swagger:generate

php artisan serve --host=0.0.0.0 --port=8000 &

cd /workspaces/flow-office/frontend

if [ ! -f .env ]; then
  cp .env.example .env
fi

if [ ! -d node_modules ]; then
  npm install
fi

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

php artisan serve --host=0.0.0.0 --port=8090 &

wait -n
