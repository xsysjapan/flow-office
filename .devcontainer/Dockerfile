FROM mcr.microsoft.com/devcontainers/php:1-8.4-bookworm

# Remove problematic Yarn repository that can cause GPG key errors in some environments.
RUN rm -f /etc/apt/sources.list.d/yarn.sources /etc/apt/sources.list.d/yarn.list

# Laravel development essentials.
RUN apt-get update \
    && apt-get install -y --no-install-recommends sqlite3 libsqlite3-dev libicu-dev libzip-dev \
    && docker-php-ext-install intl zip pdo_mysql pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Node.js (frontend/Vite用)。VS Code devcontainer機能でも入るが、
# `docker compose up` 単体で使う場合はここで入れておく必要がある。
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Caddy (frontend/backend/mcpを/flow-office配下にパスベースでまとめるローカルリバースプロキシ)
# と ngrok CLI (そのプロキシをインターネットに公開するトンネル)。
# .devcontainer/tunnel/ 参照。ngrok自体の認証は各自の`ngrok config add-authtoken <token>`で行う。
RUN apt-get update \
    && apt-get install -y --no-install-recommends debian-keyring debian-archive-keyring apt-transport-https \
    && curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
        | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg \
    && curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
        | tee /etc/apt/sources.list.d/caddy-stable.list \
    && curl -fsSL https://ngrok-agent.s3.amazonaws.com/ngrok.asc \
        | tee /etc/apt/trusted.gpg.d/ngrok.asc >/dev/null \
    && echo "deb https://ngrok-agent.s3.amazonaws.com buster main" \
        | tee /etc/apt/sources.list.d/ngrok.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends caddy ngrok \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
