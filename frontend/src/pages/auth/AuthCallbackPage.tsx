import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'

/**
 * UC-001: Microsoft SSOでログインする。
 * バックエンドのコールバックから渡されるワンタイムコードをSanctumトークンに交換する。
 */
export function AuthCallbackPage() {
  const [searchParams] = useSearchParams()
  const { completeLogin } = useAuth()
  const navigate = useNavigate()
  const [error, setError] = useState<Error | null>(null)
  const hasStarted = useRef(false)

  useEffect(() => {
    const code = searchParams.get('code')

    if (!code) {
      setError(new Error('ログインコードが見つかりませんでした。'))
      return
    }

    if (hasStarted.current) return
    hasStarted.current = true

    completeLogin(code)
      .then(() => navigate('/', { replace: true }))
      .catch(() => setError(new Error('ログインに失敗しました。もう一度お試しください。')))
  }, [searchParams, completeLogin, navigate])

  if (error) {
    return (
      <main className="flex min-h-screen flex-col items-center justify-center gap-2 p-10 text-center">
        <div className="w-full max-w-sm">
          <ErrorMessage error={error} />
        </div>
        <a href="/login" className="text-sm text-primary underline-offset-4 hover:underline">
          ログイン画面に戻る
        </a>
      </main>
    )
  }

  return (
    <main className="flex min-h-screen items-center justify-center p-10">
      <div className="w-full max-w-sm">
        <LoadingState label="ログイン処理中..." />
      </div>
    </main>
  )
}
