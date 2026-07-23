#!/usr/bin/env bash
# 本番カットオーバー移行(docs/30-legacy-data-migration.md)のローカルリハーサル一式。
#
# 前提:
# - ローカルにMySQL(8.0で代用可。本番は5.7想定。docs/30の「MySQL 5.7 vs 8.0」参照)が
#   起動しており、以下の2つのDB・ユーザーが作成済みであること:
#     flow_office_legacy (旧main相当のスキーマ+データを入れる)
#     flow_office_new    (このリポジトリの現在のスキーマを入れる、移行先)
# - backend/.env.legacy-rehearsal に、上記2DBへの接続情報(DB_*とLEGACY_DB_*)を
#   書いた状態であること(.env.exampleをコピーして値を差し替える)。
# - flow_office_legacy 側に、main branchのmigrate --seed + ScenarioSeeder等で
#   本番相当のサンプルデータが投入済みであること。
#
# このスクリプトは backend/.env を一時的に .env.legacy-rehearsal の内容へ差し替えて実行し、
# 終了時に元の .env へ戻す(bootstrap/app.phpが起動直後に無条件で.envを読み込むため、
# `--env=`オプションだけでは接続先を切り替えられないことに注意。docs/30参照)。

set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../backend" && pwd)"
SNAPSHOT_DIR="${SNAPSHOT_DIR:-/tmp/flow-office-legacy-migration-snapshot}"

cd "${BACKEND_DIR}"

if [ ! -f .env.legacy-rehearsal ]; then
  echo "backend/.env.legacy-rehearsal が見つかりません。作成してから再実行してください。" >&2
  exit 1
fi

cp .env .env.rehearsal-backup
trap 'mv .env.rehearsal-backup .env' EXIT

cp .env.legacy-rehearsal .env

echo "== 1. 旧DBの現在の行をスナップショットへ書き出す =="
php artisan legacy:export --connection=legacy --path="${SNAPSHOT_DIR}"

echo "== 2. 新スキーマを作り直す(移行先DBを空にする) =="
php artisan migrate:fresh --force

echo "== 3. スナップショットをイベントへ変換してstored_eventsへ書き込む =="
php artisan legacy:convert --path="${SNAPSHOT_DIR}" --map="${SNAPSHOT_DIR}/uuid-map.json" --force

echo "== 4. 全Projectionを再生成する =="
echo "yes" | php artisan event-sourcing:replay

echo "== 完了 =="
echo "移行先DB(flow_office_new相当)を確認してください。UUID対応表: ${SNAPSHOT_DIR}/uuid-map.json"
