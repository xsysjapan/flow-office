import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'

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
      <main className="flex min-h-screen flex-col items-center justify-center gap-2 p-10 text-center">
        <p className="text-sm text-destructive">{error}</p>
        <a href="/login" className="text-sm text-primary underline-offset-4 hover:underline">
          ログイン画面に戻る
        </a>
      </main>
    )
  }

  return (
    <p className="p-10 text-center text-sm text-muted-foreground" role="status">
      ログイン処理中...
    </p>
  )
}
