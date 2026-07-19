#!/usr/bin/env bash
# frontend(:5173)・backend(:8000)・mcp(:8090)が起動済みであることを前提に、
# Caddy(:8080)でパスベースに/flow-office配下へまとめ、ngrokでインターネットに公開する。
#
# 事前に一度だけ: ngrok config add-authtoken <自分のngrokアカウントのトークン>
#
# 使い方:
#   .devcontainer/tunnel/start-tunnel.sh
# 表示された公開URLをもとに、別ターミナルでfrontendを起動し直す
# (下記に表示されるコマンドをそのまま使う)。
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

cleanup() {
  [ -n "${CADDY_PID:-}" ] && kill "$CADDY_PID" 2>/dev/null || true
  [ -n "${NGROK_PID:-}" ] && kill "$NGROK_PID" 2>/dev/null || true
}
trap cleanup EXIT

echo "Caddy (ローカルリバースプロキシ, :8080) を起動します..."
caddy run --config "$SCRIPT_DIR/Caddyfile" --adapter caddyfile &
CADDY_PID=$!
sleep 1

echo "ngrok tunnel (http :8080) を起動します..."
ngrok http 8080 --log=stdout > /tmp/ngrok.log 2>&1 &
NGROK_PID=$!

echo "ngrokがトンネルURLを割り当てるまで待機します..."
PUBLIC_URL=""
for _ in $(seq 1 30); do
  PUBLIC_URL=$(curl -s http://127.0.0.1:4040/api/tunnels 2>/dev/null | php -r '
    $d = json_decode(file_get_contents("php://stdin"), true);
    foreach (($d["tunnels"] ?? []) as $t) {
      if (($t["proto"] ?? "") === "https") { echo $t["public_url"]; break; }
    }
  ' 2>/dev/null || true)
  [ -n "$PUBLIC_URL" ] && break
  sleep 1
done

if [ -z "$PUBLIC_URL" ]; then
  echo "ngrokのURLを取得できませんでした。'ngrok config add-authtoken <token>' を実行済みか、" >&2
  echo "/tmp/ngrok.log を確認してください。" >&2
  exit 1
fi

cat <<EOF

公開URL:            $PUBLIC_URL/flow-office/
backend API:        $PUBLIC_URL/flow-office/api/...
mcp (MCPクライアント登録用): $PUBLIC_URL/flow-office/mcp

frontendとmcpは、それぞれ以下の環境変数を付けて起動し直してください
(既存のnpm run dev / php artisan serveは一旦止める)。
mcpはAPP_URLを上書きしないと、OAuth2メタデータ(issuer等)が/flow-office/mcpを
含まない誤ったURLを返し、外部のMCPクライアントからの接続が壊れる。

  cd frontend
  VITE_BASE_PATH=/flow-office/ VITE_API_BASE_URL=$PUBLIC_URL/flow-office/api \\
    npm run dev -- --host 0.0.0.0

  cd mcp
  APP_URL=$PUBLIC_URL/flow-office/mcp \\
    php artisan serve --host=0.0.0.0 --port=8090

Ctrl+Cで終了します。
EOF

wait -n "$CADDY_PID" "$NGROK_PID"
