#!/bin/sh
set -e

set_env() {
  key="$1"
  value="$2"

  if grep -q "^${key}=" .env; then
    sed -i "s#^${key}=.*#${key}=${value}#" .env
  else
    printf '\n%s=%s\n' "$key" "$value" >> .env
  fi
}

cd /workspaces/flow-office/backend

composer install --no-interaction

if [ ! -f .env ]; then
  cp .env.example .env
fi

set_env APP_URL http://localhost:8000
set_env FRONTEND_URL http://localhost:5173
set_env MICROSOFT_MOCK_ENABLED true
set_env MICROSOFT_MOCK_PUBLIC_BASE_URL http://localhost:9000
set_env MICROSOFT_MOCK_INTERNAL_BASE_URL http://mock-oidc:9000
set_env MICROSOFT_CLIENT_ID mock-client-id
set_env MICROSOFT_CLIENT_SECRET mock-client-secret
set_env MICROSOFT_TENANT_ID mock
set_env MICROSOFT_REDIRECT_URI http://localhost:8000/api/auth/microsoft/callback
set_env L5_SWAGGER_GENERATE_ALWAYS true

php artisan key:generate --force
mkdir -p database
touch database/database.sqlite
php artisan migrate --seed --force
php artisan l5-swagger:generate

cd /workspaces/flow-office/frontend

if [ ! -f .env ]; then
  cp .env.example .env
fi

npm install