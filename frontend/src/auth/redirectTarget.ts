/**
 * ログイン後に元のURLへ戻すための共通ユーティリティ(Laravelの`intended URL`相当をSPA側で実装)。
 * `redirect`は外部から改ざん可能な入力のため、自サイト内の相対パスのみ許可する
 * (Open Redirect対策)。
 */

export const POST_LOGIN_REDIRECT_STORAGE_KEY = 'flow-office:post-login-redirect'

const DISALLOWED_PREFIXES = ['/login', '/auth/callback']

/**
 * `value`が「ログイン後に安全に遷移してよい自サイト内の相対パス」であれば返し、
 * そうでなければ`null`を返す。
 */
export function getSafeRedirectTarget(value: string | null | undefined): string | null {
  if (!value) return null

  // 先頭が`/`単体であることを要求する。`//evil.example`や`/\evil.example`のような
  // プロトコル相対URL・絶対URLはブラウザが外部オリジンへの遷移として解釈するため拒否する。
  if (!value.startsWith('/') || value.startsWith('//') || value.startsWith('/\\')) {
    return null
  }

  // `javascript:`等のスキームが紛れ込んでいないかを、URLとしての解釈結果でも確認する。
  let resolved: URL
  try {
    resolved = new URL(value, window.location.origin)
  } catch {
    return null
  }
  if (resolved.origin !== window.location.origin) {
    return null
  }

  const path = resolved.pathname
  if (DISALLOWED_PREFIXES.some((prefix) => path === prefix || path.startsWith(`${prefix}/`))) {
    return null
  }

  return resolved.pathname + resolved.search + resolved.hash
}
