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
