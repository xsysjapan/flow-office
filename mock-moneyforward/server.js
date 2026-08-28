'use strict'

/**
 * E2E検証用のマネーフォワードクラウド経費APIモックサーバー。
 *
 * backend の ApiKeyStrategy / MoneyForwardExpensePublisher (backend/app/Domain/Export/Services/
 * ExternalAuth/ApiKeyStrategy.php, Publishers/MoneyForwardExpensePublisher.php) が実際に
 * 呼び出すエンドポイントのみを最小実装している:
 *   POST /api/external/v1/offices/:office_id/office_members/:office_member_id/ex_transactions
 *     … 経費明細(ex_transaction)の作成
 *   POST /api/external/v1/offices/:office_id/office_members/:office_member_id/upload_receipt
 *     … 領収書アップロード(領収書添付がある経費のみ呼ばれる。現行のE2Eシナリオでは未使用)
 *
 * ApiKeyStrategyはOAuth2のトークンエンドポイントを呼ばないため、/oauth/tokenは実際には
 * 呼ばれない想定だが、config/external_integrations.php の moneyforward.token_endpoint に
 * 対応する値として一応用意しておく。
 *
 * 受け取ったリクエスト(パス・ヘッダー・ボディ)は`GET /_debug/last-request`で参照でき、
 * `POST /_debug/reset`でリセットできる(mock-freeeと同じ機構)。
 */

const http = require('node:http')

const PORT = process.env.MOCK_MONEYFORWARD_PORT || 9002

let lastRequest = null

function readBody(req) {
  return new Promise((resolve, reject) => {
    let data = ''
    req.on('data', (chunk) => {
      data += chunk
    })
    req.on('end', () => resolve(data))
    req.on('error', reject)
  })
}

function sendJson(res, status, body) {
  const json = JSON.stringify(body)
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(json),
  })
  res.end(json)
}

function parseJsonBody(raw) {
  if (!raw) return null
  try {
    return JSON.parse(raw)
  } catch {
    return raw
  }
}

function recordRequest(req, url, rawBody) {
  lastRequest = {
    method: req.method,
    path: url.pathname,
    query: Object.fromEntries(url.searchParams),
    headers: req.headers,
    body: parseJsonBody(rawBody),
    receivedAt: new Date().toISOString(),
  }
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`)
  const rawBody = await readBody(req)

  if (req.method === 'GET' && url.pathname === '/_debug/last-request') {
    return sendJson(res, 200, { lastRequest })
  }

  if (req.method === 'POST' && url.pathname === '/_debug/reset') {
    lastRequest = null
    return sendJson(res, 200, { ok: true })
  }

  recordRequest(req, url, rawBody)

  if (req.method === 'POST' && url.pathname === '/oauth/token') {
    return sendJson(res, 200, {
      access_token: 'mock-moneyforward-access-token',
      token_type: 'bearer',
      expires_in: 3600,
    })
  }

  const exTransactionsMatch = url.pathname.match(
    /^\/api\/external\/v1\/offices\/([^/]+)\/office_members\/([^/]+)\/ex_transactions$/,
  )
  if (req.method === 'POST' && exTransactionsMatch) {
    return sendJson(res, 200, { id: 'mock-tx-1' })
  }

  const uploadReceiptMatch = url.pathname.match(
    /^\/api\/external\/v1\/offices\/([^/]+)\/office_members\/([^/]+)\/upload_receipt$/,
  )
  if (req.method === 'POST' && uploadReceiptMatch) {
    return sendJson(res, 200, { id: 'mock-receipt-1' })
  }

  sendJson(res, 404, { error: 'not_found', path: url.pathname })
})

server.listen(PORT, () => {
  console.log(`Mock MoneyForward API server listening on port ${PORT}`)
})
