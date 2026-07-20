#!/usr/bin/env bash
# GitHub ActionsのランナーからXSERVERへのSSH接続は稀に
# "Connection closed by ..." で切断されることがある(共有ホスティング側の
# 一時的なセッション制限・回線断と見られ、コード側の恒久対応はできない)。
# 各stepの冒頭で `source deploy/scripts/ci-retry.sh` した上で、
# ssh/scp/rsyncの呼び出しを `retry <command...>` でラップし、指数バックオフで再試行する。
retry() {
  local max_attempts=5
  local delay=3
  local attempt=1
  until "$@"; do
    if (( attempt >= max_attempts )); then
      echo "::error::command failed after ${attempt} attempts: $*" >&2
      return 1
    fi
    echo "command failed (attempt ${attempt}/${max_attempts}), retrying in ${delay}s: $*" >&2
    sleep "$delay"
    delay=$(( delay * 2 ))
    attempt=$(( attempt + 1 ))
  done
}
