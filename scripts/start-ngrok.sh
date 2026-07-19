#!/usr/bin/env bash
# ホスト側(Macのターミナル。devcontainer/コンテナの中ではない)で実行するスクリプト。
# Caddy+ngrokをホストで起動し、割り当てられた公開URLを元に環境変数を組み立てたうえで
# `docker compose up`を起動する。backend/frontend/mcpは最初から
# /flow-office配下の公開URL向けの設定で立ち上がるため、
# 従来のように起動後にfrontend/mcpだけ手動で再起動する必要はない。
#
# 事前準備(初回のみ、ホスト側で):
#   brew install caddy ngrok
#   ngrok config add-authtoken <自分のngrokアカウントのトークン>
#
# 使い方:
#   scripts/start-ngrok.sh
#
# Ctrl+Cで docker compose・ngrok・Caddy をまとめて終了する。
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
TUNNEL_DIR="$SCRIPT_DIR/tunnel"

command -v caddy >/dev/null 2>&1 || {
  echo "caddyが見つかりません。'brew install caddy' を実行してください。" >&2
  exit 1
}
command -v ngrok >/dev/null 2>&1 || {
  echo "ngrokが見つかりません。'brew install ngrok' を実行してください。" >&2
  exit 1
}
command -v docker >/dev/null 2>&1 || {
  echo "dockerが見つかりません。Docker Desktopを起動してください。" >&2
  exit 1
}

cleanup() {
  [ -n "${CADDY_PID:-}" ] && kill "$CADDY_PID" 2>/dev/null || true
  [ -n "${NGROK_PID:-}" ] && kill "$NGROK_PID" 2>/dev/null || true
}
trap cleanup EXIT

echo "Caddy (ローカルリバースプロキシ, :8080) をホスト側で起動します..."
caddy run --config "$TUNNEL_DIR/Caddyfile" --adapter caddyfile &
CADDY_PID=$!
sleep 1

echo "ngrok tunnel (http :8080) を起動します..."
ngrok http 8080 --log=stdout > /tmp/ngrok.log 2>&1 &
NGROK_PID=$!

echo "ngrokがトンネルURLを割り当てるまで待機します..."
PUBLIC_URL=""
for _ in $(seq 1 30); do
  PUBLIC_URL=$(curl -s http://127.0.0.1:4040/api/tunnels 2>/dev/null | python3 -c '
import json, sys
try:
    data = json.load(sys.stdin)
    for t in data.get("tunnels", []):
        if t.get("proto") == "https":
            print(t["public_url"])
            break
except Exception:
    pass
' 2>/dev/null || true)
  [ -n "$PUBLIC_URL" ] && break
  sleep 1
done

if [ -z "$PUBLIC_URL" ]; then
  echo "ngrokのURLを取得できませんでした。'ngrok config add-authtoken <token>' を実行済みか、" >&2
  echo "/tmp/ngrok.log を確認してください。" >&2
  exit 1
fi

echo ""
echo "公開URL:                      $PUBLIC_URL/flow-office/"
echo "backend API:                  $PUBLIC_URL/flow-office/api/..."
echo "mcp (MCPクライアント登録用):  $PUBLIC_URL/flow-office/mcp"
echo ""
echo "この設定を環境変数として渡した状態で docker compose up --build を起動します。"
echo "(Ctrl+Cで全体を終了します)"
echo ""

export VITE_BASE_PATH="/flow-office/"
export VITE_API_BASE_URL="$PUBLIC_URL/flow-office/api"
export MCP_PUBLIC_APP_URL="$PUBLIC_URL/flow-office/mcp"

cd "$REPO_ROOT"
docker compose up --build
