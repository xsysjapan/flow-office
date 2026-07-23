#!/usr/bin/env bash
# 本番カットオーバー移行(docs/30-legacy-data-migration.md)の実行スクリプト。
#
# 必ずメンテナンス時間中に実行すること(アプリケーションを止めた状態で行う。書き込みが
# 続いている間にexportすると、export後・migrate:fresh前に発生した書き込みが失われる)。
#
# 前提:
# - backend/.env が現在の本番接続情報(DB_*)を指していること。
# - backend/.env に LEGACY_DB_* を追加設定してあること。カットオーバーは同一DBに対して
#   行うため、通常は LEGACY_DB_* にも DB_* と全く同じ接続情報を設定する
#   (config/database.phpの`legacy`接続は、書き込み前の「今の状態」を読むためだけの別名)。
# - このリポジトリ(新スキーマ)のコードが既にデプロイ済み(docs/27-release-runbook.md通り
#   composer install等は完了しているが、まだ`php artisan migrate --force`はしていない)。
#
# 実行順序:
#   1. mysqldumpでフルバックアップ(00-backup.sh)
#   2. legacy:export で「今の状態」をJSONへ書き出す(まだ何も壊さない)
#   3. migrate:fresh で新スキーマを作り直す(ここで旧データは消える。1のバックアップが頼り)
#   4. legacy:convert でJSONをイベントへ変換してstored_eventsへ書き込む
#   5. event-sourcing:replay で全Projectionを再生成する
#   6. 手動で件数照合・アプリの動作確認をしてから、docs/27-release-runbook.mdの
#      config:cache等の残り手順に進む

set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../backend" && pwd)"
BACKUP_DIR="${1:?使い方: $0 <backup-dir>}"
SNAPSHOT_DIR="${SNAPSHOT_DIR:-/var/tmp/flow-office-legacy-migration-snapshot}"

cd "${BACKEND_DIR}"

read -r -p "本番DBに対してカットオーバー移行を実行します。メンテナンス時間中ですか? (yes/no) " CONFIRM
if [ "${CONFIRM}" != "yes" ]; then
  echo "中止しました。"
  exit 1
fi

echo "== 0. フルバックアップ =="
# .envから接続情報を読み込んでbackupスクリプトへ渡す(直接artisanのDB設定を再利用する)。
MYSQL_HOST=$(php artisan tinker --execute="echo config('database.connections.mysql.host');")
MYSQL_PORT=$(php artisan tinker --execute="echo config('database.connections.mysql.port');")
MYSQL_USER=$(php artisan tinker --execute="echo config('database.connections.mysql.username');")
MYSQL_PASSWORD=$(php artisan tinker --execute="echo config('database.connections.mysql.password');")
MYSQL_DATABASE=$(php artisan tinker --execute="echo config('database.connections.mysql.database');")

MYSQL_HOST="${MYSQL_HOST}" MYSQL_PORT="${MYSQL_PORT}" MYSQL_USER="${MYSQL_USER}" \
  MYSQL_PASSWORD="${MYSQL_PASSWORD}" MYSQL_DATABASE="${MYSQL_DATABASE}" \
  "$(dirname "${BASH_SOURCE[0]}")/00-backup.sh" "${BACKUP_DIR}"

echo "== 1. 旧スキーマ(移行直前の現在の状態)をスナップショットへ書き出す =="
php artisan legacy:export --connection=legacy --path="${SNAPSHOT_DIR}"

read -r -p "スナップショットの件数を確認しましたか? 新スキーマへの切り替えに進みますか? (yes/no) " CONFIRM2
if [ "${CONFIRM2}" != "yes" ]; then
  echo "中止しました(スナップショットは ${SNAPSHOT_DIR} に残っています)。"
  exit 1
fi

echo "== 2. 新スキーマを作り直す(この時点で旧データは失われる。バックアップが頼り) =="
php artisan migrate:fresh --force

echo "== 3. スナップショットをイベントへ変換してstored_eventsへ書き込む =="
php artisan legacy:convert --path="${SNAPSHOT_DIR}" --map="${SNAPSHOT_DIR}/uuid-map.json" --force

echo "== 4. 全Projectionを再生成する =="
echo "yes" | php artisan event-sourcing:replay

echo "== 完了。次のステップ =="
echo "1. 主要テーブルの件数を旧DBのバックアップと照合する。"
echo "2. アプリを一時的に起動して主要画面(ログイン・今日の勤怠・管理画面)を確認する。"
echo "3. 問題なければ docs/27-release-runbook.md の config:cache 等の残り手順へ進む。"
echo "4. 問題があれば ${BACKUP_DIR} のダンプから復元する。"
