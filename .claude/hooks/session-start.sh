#!/usr/bin/env bash
set -euo pipefail

# Claude Code クラウドセッション(CLAUDE_CODE_REMOTE=true)でのみ実行する。
# ローカル環境では apt や PPA 追加を走らせないため何もせず終了する。
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

# CLAUDE_PROJECT_DIR が未設定ならフックの配置位置(.claude/hooks/)からリポジトリルートを求める
ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"

# 同期実行(PHPの切り替えが終わる前にテストが走る競合を避ける)
exec "$ROOT/scripts/setup-php84.sh"
