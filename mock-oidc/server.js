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

// backend(Laravel)のDB上にまだ存在しない「初回ログイン」シナリオ確認用の固定エントリ。
// 既にDBに存在するユーザー(admin, ScenarioSeederが作成する004〜009など)は
// MOCK_USERS_API_URL 経由でbackendから動的に取得するため、ここには含めない。
const FALLBACK_NEW_USERS = [
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

// DB接続やbackendが未起動の場合のフォールバック(MOCK_USERS_API_URL未設定時も含む)。
// docker-compose未使用でmock-oidcだけ単体起動した場合などに、従来通りログインできるようにする。
const STATIC_USERS = [
  {
    id: 'mock-entra-admin',
    displayName: 'Test Admin',
    userPrincipalName: 'admin@example.com',
    mail: 'admin@example.com',
  },
  ...FALLBACK_NEW_USERS,
]

const authCodes = new Map()
const accessTokens = new Map()

/**
 * backendのdev専用エンドポイント(MockOidcUserController)からDB上のユーザー一覧を取得する。
 * 取得できない場合は STATIC_USERS にフォールバックする。
 */
async function fetchUsers() {
  const apiUrl = process.env.MOCK_USERS_API_URL

  if (!apiUrl) {
    return STATIC_USERS
  }

  try {
    const response = await fetch(apiUrl, { signal: AbortSignal.timeout(3000) })
    if (!response.ok) {
      return STATIC_USERS
    }
    const dbUsers = await response.json()
    const dbIds = new Set(dbUsers.map((user) => user.id))
    return [...dbUsers, ...FALLBACK_NEW_USERS.filter((user) => !dbIds.has(user.id))]
  } catch (error) {
    console.error('MOCK_USERS_API_URL からのユーザー一覧取得に失敗しました。静的な一覧にフォールバックします。', error)
    return STATIC_USERS
  }
}

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

function renderLoginPage(params, users) {
  const hidden = (name, value) => `<input type="hidden" name="${escapeHtml(name)}" value="${escapeHtml(value)}">`

  const userButtons = users.map((user) => `
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
        ${(user.department || user.jobTitle) ? `<br><small>${escapeHtml([user.department, user.jobTitle].filter(Boolean).join(' / '))}</small>` : ''}
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
    const users = await fetchUsers()
    return sendHtml(res, 200, renderLoginPage(params, users))
  }

  if (req.method === 'POST' && url.pathname === '/oauth2/v2.0/authorize') {
    const body = await readBody(req)
    const params = Object.fromEntries(new URLSearchParams(body))
    const users = await fetchUsers()
    const user = users.find((u) => u.id === params.user_id)

    if (!user || !params.redirect_uri) {
      return sendHtml(res, 400, '<h1>Bad Request</h1><p>user_id または redirect_uri がありません。</p>')
    }

    const code = randomToken()
    authCodes.set(code, { user, expiresAt: Date.now() + CODE_TTL_MS })

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
    accessTokens.set(accessToken, { user: entry.user, expiresAt: Date.now() + TOKEN_TTL_MS })

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

    const user = entry.user
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
