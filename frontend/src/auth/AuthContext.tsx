import { createContext, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { exchangeCodeForToken, fetchCurrentUser, fetchMicrosoftRedirectUrl, logout as logoutRequest } from '../api/auth'
import { clearToken, getToken, setToken } from '../api/client'
import type { User } from '../api/types'

type AuthStatus = 'loading' | 'authenticated' | 'unauthenticated'

export interface AuthContextValue {
  user: User | null
  status: AuthStatus
  /** UC-001手順1〜2: MicrosoftのログインURLへブラウザを遷移させる。 */
  login: () => Promise<void>
  /** UC-001手順4〜6: コールバックのワンタイムコードをSanctumトークンに交換する。 */
  completeLogin: (code: string) => Promise<void>
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
      .catch(() => {
        clearToken()
        setStatus('unauthenticated')
      })
  }, [])

  const login = useCallback(async () => {
    const { url } = await fetchMicrosoftRedirectUrl()
    window.location.href = url
  }, [])

  const completeLogin = useCallback(async (code: string) => {
    const { token, user: loggedInUser } = await exchangeCodeForToken(code)
    setToken(token)
    setUser(loggedInUser)
    setStatus('authenticated')
  }, [])

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
    () => ({ user, status, login, completeLogin, logout }),
    [user, status, login, completeLogin, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
