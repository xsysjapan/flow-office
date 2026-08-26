#!/usr/bin/env bash
# サーバー上でSSH経由で実行するリリース有効化スクリプト。
# GitHub Actions (.github/workflows/deploy.yml) がrsyncで
# $BASE_DIR/releases/$RELEASE/{backend,mcp,frontend/dist} を配置した後に呼び出す。
#
# 使い方: activate-release.sh <BASE_DIR> <RELEASE> <PUBLIC_HTML_LINK> <PHP_BIN> [KEEP_RELEASES]
set -euo pipefail

BASE_DIR="$1"
RELEASE="$2"
PUBLIC_HTML_LINK="$3"
PHP_BIN="$4"
KEEP_RELEASES="${5:-5}"

RELEASE_DIR="$BASE_DIR/releases/$RELEASE"

# migrate --force が失敗すると、このスクリプトは`set -e`によりその場で停止し、
# 3.以降の`current`切替まで到達しない(=本番は古いリリースのまま)。MySQLは
# CREATE TABLE/ALTER TABLE等のDDLをトランザクション内でもロールバックせず即コミット
# するため、「テーブル作成自体は成功したが、直後の記録処理でmigrateコマンドが
# 中断し(SSH切断等)、migrationsテーブルへの記録に失敗した」状態が起こり得る。
# この状態になると、次回以降のデプロイも同じマイグレーションで
# 「table already exists」として同一の理由で失敗し続け、`current`が永遠に
# 進まなくなる(2026-08-01に本番で実際に発生。docs/27-release-runbook.md
# 「8. リハーサルで発見した注意点」参照)。
#
# migrate失敗時にその場の診断情報(migrate:statusの全出力)をログに残すことで、
# 次回以降この障害が起きた際に切り分けの往復を発生させず、その場で復旧手順に
# 進めるようにする。
run_migrate() {
  local app_dir="$1"
  if ! (cd "$app_dir" && "$PHP_BIN" artisan migrate --force); then
    {
      echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
      echo "!! migrate --force failed: $app_dir (release $RELEASE)"
      echo "!! 'current' will NOT be switched — production stays on the previous release."
      echo "!!"
      echo "!! If the error is '...already exists', this is likely a migration that was"
      echo "!! partially applied during an earlier failed deploy: MySQL commits DDL"
      echo "!! (CREATE/ALTER TABLE) immediately even if migrate itself later fails or the"
      echo "!! SSH session drops before the migrations table row is written. Every future"
      echo "!! deploy will keep failing identically on the same migration until this is"
      echo "!! fixed manually. Recovery steps (see docs/27-release-runbook.md):"
      echo "!!   1. Confirm the actual table/column matches the migration file"
      echo "!!      (SHOW CREATE TABLE <table>;)"
      echo "!!   2. If it matches, insert the missing row into the migrations table:"
      echo "!!      INSERT INTO migrations (migration, batch)"
      echo "!!      VALUES ('<migration_file_name_without_php>', (SELECT batch FROM (SELECT MAX(batch)+1 AS batch FROM migrations) t));"
      echo "!!"
      echo "!! Current migrate:status ($app_dir):"
      (cd "$app_dir" && "$PHP_BIN" artisan migrate:status) || true
      echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
    } >&2
    exit 1
  fi
}

if [ ! -d "$RELEASE_DIR/backend" ] || [ ! -d "$RELEASE_DIR/mcp" ] || [ ! -d "$RELEASE_DIR/frontend/dist" ]; then
  echo "release directory is incomplete: $RELEASE_DIR" >&2
  exit 1
fi

# .env と storage/ は shared/ に永続化し、リリースディレクトリからシンボリックリンクする。
# .envはCIからは生成せず、初回セットアップ時にサーバー上で手動作成したものをデプロイの
# たびに使い回す(docs/28-github-actions-deploy.md参照)。storageはセッション・ログ・
# 添付ファイル・mcpのOAuth鍵をデプロイのたびに失わないため。
for app in backend mcp; do
  SHARED_DIR="$BASE_DIR/shared/$app"
  SHARED_ENV="$SHARED_DIR/.env"
  SHARED_STORAGE="$SHARED_DIR/storage"

  if [ ! -f "$SHARED_ENV" ]; then
    echo "missing $SHARED_ENV - create it manually before the first deploy (see docs/28-github-actions-deploy.md)" >&2
    exit 1
  fi

  mkdir -p "$SHARED_STORAGE"
  if [ ! -d "$SHARED_STORAGE/framework" ]; then
    # 初回のみ、リリース同梱のstorageスケルトン(framework/cache等の空ディレクトリ構成)をコピーする
    cp -r "$RELEASE_DIR/$app/storage/." "$SHARED_STORAGE/"
  fi
  rm -rf "$RELEASE_DIR/$app/storage"
  ln -sfn "$SHARED_STORAGE" "$RELEASE_DIR/$app/storage"

  rm -f "$RELEASE_DIR/$app/.env"
  ln -sfn "$SHARED_ENV" "$RELEASE_DIR/$app/.env"
done

# frontend/dist内に、backend・mcpのpublic/への相対シンボリックリンクを張る
# (同一リリースディレクトリ内で完結する相対パスなので、リリースをまたいでも壊れない)。
ln -sfn "../../backend/public" "$RELEASE_DIR/frontend/dist/api"
ln -sfn "../../mcp/public" "$RELEASE_DIR/frontend/dist/mcp"

# mcpのOAuth署名鍵は初回のみ生成し、以後はshared/mcp/storageに永続化されたものを使い回す
# (devの鍵を使い回さない。生成のたびにリフレッシュトークンが無効になるため)。
if [ ! -f "$BASE_DIR/shared/mcp/storage/oauth-private.key" ]; then
  (cd "$RELEASE_DIR/mcp" && "$PHP_BIN" artisan mcp:oauth-keys)
fi
chmod 600 "$BASE_DIR/shared/mcp/storage/oauth-private.key" "$BASE_DIR/shared/mcp/storage/oauth-public.key"

echo "== backend: migrate & cache =="
cd "$RELEASE_DIR/backend"
run_migrate "$RELEASE_DIR/backend"
# Feature・PermissionはAccessControlCatalogに追加するだけでよいようにするため、
# seeder全体は実行しない本番デプロイでもこの同期だけは毎回自動実行する
# (追加専用のupdateOrInsertのみで構成されており、既存のRole/Feature割当は変更しない)。
"$PHP_BIN" artisan access-control:sync-catalog
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan storage:link
"$PHP_BIN" artisan l5-swagger:generate

echo "== mcp: migrate & cache =="
cd "$RELEASE_DIR/mcp"
run_migrate "$RELEASE_DIR/mcp"
"$PHP_BIN" artisan config:cache
# route:cacheは実行しない: mcp/はJSON-RPCエンドポイントをアプリのルート('/'、
# mcp/routes/api.php)に持ち、/flow-office/mcpのようなURLサブパスにマウントする
# (basePathが空でなくなる)。この組み合わせでroute:cacheを使うと、Laravel/Symfonyの
# 既知の問題でルート'/'へのリクエストが誤って405になる(docs/27-release-runbook.md
# 「8. リハーサルで発見した注意点」参照)。route:cacheを使わなければ再現しない。
"$PHP_BIN" artisan view:cache

# 3アプリの準備が整ってから一括切替(このシンボリックリンク1本の張替えがアトミック)。
# ロールバックは $BASE_DIR/current を過去のreleases/<timestamp>に戻すだけでよい。
echo "== switching current -> $RELEASE =="
ln -sfn "$RELEASE_DIR" "$BASE_DIR/current"

# 公開ドキュメントルート側のリンクは初回のみ作成し、以後は不変(currentの向き先だけが変わる)。
if [ ! -L "$PUBLIC_HTML_LINK" ]; then
  echo "== creating public_html link (first deploy) =="
  mkdir -p "$(dirname "$PUBLIC_HTML_LINK")"
  ln -sfn "$BASE_DIR/current/frontend/dist" "$PUBLIC_HTML_LINK"
fi

# 直近 KEEP_RELEASES 件を残して古いリリースを削除する
echo "== pruning old releases (keep $KEEP_RELEASES) =="
cd "$BASE_DIR/releases"
ls -1t | tail -n +$((KEEP_RELEASES + 1)) | xargs -r rm -rf

echo "== done: current -> $RELEASE =="
