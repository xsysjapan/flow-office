import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'

/**
 * UC-001: Microsoft SSOでログインする。
 * バックエンドのコールバックから渡されるワンタイムコードをSanctumトークンに交換する。
 */
export function AuthCallbackPage() {
  const [searchParams] = useSearchParams()
  const { completeLogin } = useAuth()
  const navigate = useNavigate()
  const [error, setError] = useState<string | null>(null)
  const hasStarted = useRef(false)

  useEffect(() => {
    const code = searchParams.get('code')

    if (!code) {
      setError('ログインコードが見つかりませんでした。')
      return
    }

    if (hasStarted.current) return
    hasStarted.current = true

    completeLogin(code)
      .then(() => navigate('/', { replace: true }))
      .catch(() => setError('ログインに失敗しました。もう一度お試しください。'))
  }, [searchParams, completeLogin, navigate])

  if (error) {
    return (
      <main className="page-loading">
        <p>{error}</p>
        <a href="/login">ログイン画面に戻る</a>
      </main>
    )
  }

  return <p className="page-loading">ログイン処理中...</p>
}
