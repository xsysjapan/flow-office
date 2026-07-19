import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { fetchOnboardingStatus } from '../../api/onboarding'
import { useAuth } from '../../auth/useAuth'
import { Button } from '../../components/Button/Button'

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [isRedirecting, setIsRedirecting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetchOnboardingStatus()
      .then(({ needs_onboarding: needsOnboarding }) => {
        if (needsOnboarding) {
          navigate('/onboarding', { replace: true })
        }
      })
      .catch(() => {
        // ステータス取得に失敗しても通常のログイン画面はそのまま表示する。
      })
  }, [navigate])

  const handleLogin = async () => {
    setError(null)
    setIsRedirecting(true)
    try {
      await login()
    } catch {
      setError('ログインURLの取得に失敗しました。時間をおいて再度お試しください。')
      setIsRedirecting(false)
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center p-4">
      <div className="flex w-full max-w-sm flex-col items-center gap-4 rounded-lg border border-border p-8 text-center">
        <h1 className="text-lg font-semibold">flow-office</h1>
        <p className="text-sm text-muted-foreground">社内アカウント(Microsoft)でログインしてください。</p>
        {error && <p className="text-sm text-destructive">{error}</p>}
        <Button className="w-full" onClick={() => void handleLogin()} isLoading={isRedirecting}>
          Microsoftでログイン
        </Button>
      </div>
    </main>
  )
}
