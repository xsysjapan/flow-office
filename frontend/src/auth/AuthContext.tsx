import { createContext, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { exchangeCodeForToken, fetchCurrentUser, fetchMicrosoftRedirectUrl, logout as logoutRequest } from '../api/auth'
import { ApiError, clearToken, getToken, setToken } from '../api/client'
import type { User } from '../api/types'

type AuthStatus = 'loading' | 'authenticated' | 'unauthenticated'

export interface AuthContextValue {
  user: User | null
  status: AuthStatus
  /** UC-001手順1〜2: MicrosoftのログインURLへブラウザを遷移させる。 */
  login: () => Promise<void>
  /** UC-001手順4〜6: コールバックのワンタイムコードをSanctumトークンに交換する。 */
  completeLogin: (code: string) => Promise<void>
  /**
   * 発行済みのSanctumトークン+ユーザーでログイン状態にする。初回オンボーディング完了時
   * (docs/06-usecases-auth.md)、実際のSSO往復を待たずにそのままログイン済みにするために使う。
   */
  applySession: (token: string, user: User) => void
  logout: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [status, setStatus] = useState<AuthStatus>('loading')

  useEffect(() => {
    if (!getToken()) {
      setStatus('unauthenticated')
      return
    }

    fetchCurrentUser()
      .then((currentUser) => {
        setUser(currentUser)
        setStatus('authenticated')
      })
      .catch((error: unknown) => {
        // トークン自体が無効(401/419)な場合のみ破棄する。ページ遷移によってこの取得中の
        // fetchがキャンセルされた場合(TypeError等、ApiErrorではない失敗)は、有効な
        // トークンをlocalStorageから消してしまわないようにする。消してしまうと、直後に
        // 読み込まれる次のページが「未ログイン」からやり直すことになってしまう。
        if (error instanceof ApiError && (error.status === 401 || error.status === 419)) {
          clearToken()
        }
        setStatus('unauthenticated')
      })
  }, [])

  const login = useCallback(async () => {
    const { url } = await fetchMicrosoftRedirectUrl()
    window.location.href = url
  }, [])

  const applySession = useCallback((token: string, loggedInUser: User) => {
    setToken(token)
    setUser(loggedInUser)
    setStatus('authenticated')
  }, [])

  const completeLogin = useCallback(
    async (code: string) => {
      const { token, user: loggedInUser } = await exchangeCodeForToken(code)
      applySession(token, loggedInUser)
    },
    [applySession],
  )

  const logout = useCallback(async () => {
    try {
      await logoutRequest()
    } finally {
      clearToken()
      setUser(null)
      setStatus('unauthenticated')
    }
  }, [])

  const value = useMemo(
    () => ({ user, status, login, completeLogin, applySession, logout }),
    [user, status, login, completeLogin, applySession, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
