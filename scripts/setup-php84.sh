#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

SUDO=""
if [ "$(id -u)" != "0" ]; then
  SUDO=sudo
fi

PACKAGES=(
  php8.4-cli
  php8.4-sqlite3
  php8.4-mbstring
  php8.4-xml
  php8.4-curl
  php8.4-zip
  php8.4-intl
  php8.4-bcmath
  php8.4-gd
)

# PHP 8.4(CI・本番と同じ)をインストールして既定にする
if [ -x /usr/bin/php8.4 ] && dpkg -s "${PACKAGES[@]}" >/dev/null 2>&1; then
  echo "php8.4 と拡張は導入済みのためapt処理をスキップします"
else
  export DEBIAN_FRONTEND=noninteractive

  # add-apt-repositoryはpython3のバージョン差異で動かない環境があるため、PPAを手動で登録する
  $SUDO install -d -m 0755 /etc/apt/keyrings

  if ! command -v gpg >/dev/null 2>&1 || ! command -v curl >/dev/null 2>&1; then
    $SUDO apt-get update
    $SUDO apt-get install -y gnupg curl
  fi

  if [ ! -f /etc/apt/keyrings/ondrej-php.gpg ]; then
    curl -fsSL "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C" \
      | $SUDO gpg --dearmor -o /etc/apt/keyrings/ondrej-php.gpg
  fi

  . /etc/os-release
  CODENAME="${VERSION_CODENAME}"
  echo "deb [signed-by=/etc/apt/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu ${CODENAME} main" \
    | $SUDO tee /etc/apt/sources.list.d/ondrej-php.list >/dev/null

  $SUDO apt-get update
  $SUDO apt-get install -y "${PACKAGES[@]}"
fi

$SUDO update-alternatives --set php /usr/bin/php8.4
php -v | head -n1

# backendの依存をインストール
cd "$REPO_ROOT/backend"
composer install --no-interaction --no-progress
