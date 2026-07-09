import { useState } from 'react'
import { useAuth } from '../auth/useAuth'
import './LoginPage.css'

export function LoginPage() {
  const { login } = useAuth()
  const [isRedirecting, setIsRedirecting] = useState(false)
  const [error, setError] = useState<string | null>(null)

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
    <main className="login-page">
      <div className="login-card">
        <h1>flow-office</h1>
        <p>社内アカウント(Microsoft)でログインしてください。</p>
        {error && <p className="login-error">{error}</p>}
        <button type="button" onClick={handleLogin} disabled={isRedirecting}>
          {isRedirecting ? '遷移中...' : 'Microsoftでログイン'}
        </button>
      </div>
    </main>
  )
}
