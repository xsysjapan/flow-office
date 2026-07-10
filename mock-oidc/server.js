'use strict'

/**
 * ローカル開発用のMicrosoft Entra ID (Azure AD) モックサーバー。
 *
 * backend の Socialite "azure" ドライバ (socialiteproviders/microsoft-azure) が実際に
 * 呼び出すエンドポイントのみを最小実装している:
 *   GET  /oauth2/v2.0/authorize  … 認可エンドポイント(ユーザー選択画面を表示)
 *   POST /oauth2/v2.0/token      … トークンエンドポイント(codeをaccess_tokenに交換)
 *   GET  /v1.0/me                … Microsoft Graph /me 相当(access_tokenからユーザー情報を返す)
 *
 * 本物のOAuth2/OIDCとは異なりPKCE検証やclient_secret検証は行わない(開発専用)。
 */

const http = require('node:http')
const crypto = require('node:crypto')

const PORT = process.env.MOCK_OIDC_PORT || 9000
const CODE_TTL_MS = 60 * 1000
const TOKEN_TTL_MS = 5 * 60 * 1000

const USERS = [
  {
    id: 'mock-entra-user-001',
    displayName: '山田 太郎',
    userPrincipalName: 'taro.yamada@example.com',
    mail: 'taro.yamada@example.com',
  },
  {
    id: 'mock-entra-user-002',
    displayName: '佐藤 花子',
    userPrincipalName: 'hanako.sato@example.com',
    mail: 'hanako.sato@example.com',
  },
  {
    id: 'mock-entra-user-003',
    displayName: '鈴木 一郎',
    userPrincipalName: 'ichiro.suzuki@example.com',
    mail: 'ichiro.suzuki@example.com',
  },
]

const authCodes = new Map()
const accessTokens = new Map()

function randomToken() {
  return crypto.randomBytes(24).toString('hex')
}

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

function sendHtml(res, status, html) {
  res.writeHead(status, { 'Content-Type': 'text/html; charset=utf-8' })
  res.end(html)
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ))
}

function renderLoginPage(params) {
  const hidden = (name, value) => `<input type="hidden" name="${escapeHtml(name)}" value="${escapeHtml(value)}">`

  const userButtons = USERS.map((user) => `
    <form method="POST" action="/oauth2/v2.0/authorize" style="margin-bottom:10px;">
      ${hidden('client_id', params.client_id)}
      ${hidden('redirect_uri', params.redirect_uri)}
      ${hidden('state', params.state)}
      ${hidden('response_type', params.response_type)}
      ${hidden('scope', params.scope)}
      ${hidden('user_id', user.id)}
      <button type="submit" style="width:100%;padding:12px;text-align:left;cursor:pointer;">
        <strong>${escapeHtml(user.displayName)}</strong><br>
        <small>${escapeHtml(user.userPrincipalName)}</small>
      </button>
    </form>
  `).join('')

  return `<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>Mock Entra ID ログイン</title>
</head>
<body style="font-family:system-ui,sans-serif;max-width:480px;margin:60px auto;padding:0 16px;">
  <h2>Mock Entra ID(ローカル開発用)</h2>
  <p>これは開発環境用のOIDCモックです。ログインするユーザーを選択してください。</p>
  ${userButtons}
</body>
</html>`
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`)

  if (req.method === 'GET' && url.pathname === '/oauth2/v2.0/authorize') {
    const params = Object.fromEntries(url.searchParams)
    return sendHtml(res, 200, renderLoginPage(params))
  }

  if (req.method === 'POST' && url.pathname === '/oauth2/v2.0/authorize') {
    const body = await readBody(req)
    const params = Object.fromEntries(new URLSearchParams(body))
    const user = USERS.find((u) => u.id === params.user_id)

    if (!user || !params.redirect_uri) {
      return sendHtml(res, 400, '<h1>Bad Request</h1><p>user_id または redirect_uri がありません。</p>')
    }

    const code = randomToken()
    authCodes.set(code, { userId: user.id, expiresAt: Date.now() + CODE_TTL_MS })

    const redirectUrl = new URL(params.redirect_uri)
    redirectUrl.searchParams.set('code', code)
    if (params.state) redirectUrl.searchParams.set('state', params.state)

    res.writeHead(302, { Location: redirectUrl.toString() })
    return res.end()
  }

  if (req.method === 'POST' && url.pathname === '/oauth2/v2.0/token') {
    const body = await readBody(req)
    const params = Object.fromEntries(new URLSearchParams(body))
    const entry = authCodes.get(params.code)

    if (!entry || entry.expiresAt < Date.now()) {
      return sendJson(res, 400, { error: 'invalid_grant', error_description: 'code is invalid or expired' })
    }
    authCodes.delete(params.code)

    const accessToken = randomToken()
    accessTokens.set(accessToken, { userId: entry.userId, expiresAt: Date.now() + TOKEN_TTL_MS })

    return sendJson(res, 200, {
      token_type: 'Bearer',
      access_token: accessToken,
      expires_in: TOKEN_TTL_MS / 1000,
      scope: params.scope || 'User.Read',
    })
  }

  if (req.method === 'GET' && url.pathname === '/v1.0/me') {
    const authHeader = req.headers.authorization || ''
    const token = authHeader.startsWith('Bearer ') ? authHeader.slice(7) : null
    const entry = token ? accessTokens.get(token) : null

    if (!entry || entry.expiresAt < Date.now()) {
      return sendJson(res, 401, {
        error: { code: 'InvalidAuthenticationToken', message: 'Access token is invalid or expired.' },
      })
    }

    const user = USERS.find((u) => u.id === entry.userId)
    return sendJson(res, 200, {
      id: user.id,
      displayName: user.displayName,
      userPrincipalName: user.userPrincipalName,
      mail: user.mail,
    })
  }

  sendJson(res, 404, { error: 'not_found' })
})

server.listen(PORT, () => {
  console.log(`Mock Entra ID OIDC server listening on port ${PORT}`)
})
