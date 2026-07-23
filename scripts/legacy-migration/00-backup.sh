#!/usr/bin/env bash
# 本番カットオーバー移行(docs/30-legacy-data-migration.md)手順0: 移行前のフルバックアップ。
#
# 使い方:
#   MYSQL_HOST=127.0.0.1 MYSQL_USER=root MYSQL_PASSWORD=xxx MYSQL_DATABASE=flow_office \
#     ./00-backup.sh /path/to/backup-dir
#
# 「最悪消し飛んでも構わない」という前提であっても、このダンプが復元可能な唯一の手段になる。
# 必ず移行対象DB以外の場所(ローカルにダウンロード、別ストレージ等)にもコピーしておくこと。

set -euo pipefail

BACKUP_DIR="${1:?使い方: $0 <backup-dir>}"
MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:?MYSQL_USERを設定してください}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:?MYSQL_PASSWORDを設定してください}"
MYSQL_DATABASE="${MYSQL_DATABASE:?MYSQL_DATABASEを設定してください}"

mkdir -p "${BACKUP_DIR}"
TIMESTAMP=$(date -u +%Y%m%dT%H%M%SZ)
OUT_FILE="${BACKUP_DIR}/${MYSQL_DATABASE}-${TIMESTAMP}.sql.gz"

echo "[backup] ${MYSQL_DATABASE}@${MYSQL_HOST}:${MYSQL_PORT} -> ${OUT_FILE}"

mysqldump \
  --host="${MYSQL_HOST}" \
  --port="${MYSQL_PORT}" \
  --user="${MYSQL_USER}" \
  --password="${MYSQL_PASSWORD}" \
  --single-transaction \
  --routines \
  --triggers \
  --hex-blob \
  "${MYSQL_DATABASE}" | gzip > "${OUT_FILE}"

echo "[backup] 完了: ${OUT_FILE} ($(du -h "${OUT_FILE}" | cut -f1))"
echo "[backup] 復元する場合: gunzip -c ${OUT_FILE} | mysql --host=... --user=... --password=... ${MYSQL_DATABASE}"
