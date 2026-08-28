'use strict'

/**
 * E2E検証用のfreeeモックサーバー。
 *
 * backend の OAuth2Strategy / ExternalApiPublisher (backend/app/Domain/Export/Services/
 * ExternalAuth/OAuth2Strategy.php, Publishers/ExternalApiPublisher.php) が実際に呼び出す
 * エンドポイントのみを最小実装している:
 *   POST /public_api/token                                                       … トークンリフレッシュ
 *   PUT  /hr/api/v1/employees/:employee_id/work_record_summaries/:year/:month    … 勤怠月次サマリ更新
 *
 * 本物のfreee APIとは異なり、クライアント資格情報の検証やスキーマ検証は行わない
 * (E2E/ローカル開発専用)。受け取ったリクエスト(パス・ヘッダー・ボディ)は
 * `GET /_debug/last-request`で参照でき、`POST /_debug/reset`でリセットできる
 * (E2Eからモックの受信内容を検証するための機構。mock-oidcには無いためここで新設する)。
 */

const http = require('node:http')

const PORT = process.env.MOCK_FREEE_PORT || 9001

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

  if (req.method === 'POST' && url.pathname === '/public_api/token') {
    // OAuth2のリフレッシュトークン交換(application/x-www-form-urlencoded)を模擬する。
    return sendJson(res, 200, {
      access_token: 'mock-freee-access-token',
      refresh_token: 'mock-freee-refresh-token',
      token_type: 'bearer',
      expires_in: 3600,
      scope: 'read write',
    })
  }

  const workRecordSummaryMatch = url.pathname.match(
    /^\/hr\/api\/v1\/employees\/([^/]+)\/work_record_summaries\/(\d{4})\/(\d{1,2})$/,
  )
  if (req.method === 'PUT' && workRecordSummaryMatch) {
    const [, employeeId, year, month] = workRecordSummaryMatch
    return sendJson(res, 200, {
      employee_id: employeeId,
      year: Number(year),
      month: Number(month),
    })
  }

  sendJson(res, 404, { error: 'not_found', path: url.pathname })
})

server.listen(PORT, () => {
  console.log(`Mock freee API server listening on port ${PORT}`)
})
