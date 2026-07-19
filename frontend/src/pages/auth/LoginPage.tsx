import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { localLogin } from '../../api/auth'
import { useAuth } from '../../auth/useAuth'
import { Button } from '../../components/Button/Button'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { Input } from '../../components/ui/input'
import { useOnboardingStatus } from '../../hooks/useOnboarding'

export function LoginPage() {
  const { login, applySession } = useAuth()
  const navigate = useNavigate()
  const { data: status } = useOnboardingStatus()

  const [isRedirecting, setIsRedirecting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [localLoginError, setLocalLoginError] = useState<unknown>(null)
  const [isLoggingIn, setIsLoggingIn] = useState(false)

  useEffect(() => {
    if (status?.needs_onboarding) {
      navigate('/onboarding', { replace: true })
    }
  }, [status, navigate])

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

  const handleLocalLogin = async () => {
    setLocalLoginError(null)
    setIsLoggingIn(true)
    try {
      const { token, user } = await localLogin(email, password)
      applySession(token, user)
      navigate('/', { replace: true })
    } catch (err) {
      setLocalLoginError(err)
      setIsLoggingIn(false)
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center p-4">
      <div className="flex w-full max-w-sm flex-col items-center gap-4 rounded-lg border border-border p-8 text-center">
        <h1 className="text-lg font-semibold">flow-office</h1>

        {status?.sso_configured !== false && (
          <>
            <p className="text-sm text-muted-foreground">社内アカウント(Microsoft)でログインしてください。</p>
            {error && <p className="text-sm text-destructive">{error}</p>}
            <Button className="w-full" onClick={() => void handleLogin()} isLoading={isRedirecting}>
              Microsoftでログイン
            </Button>
          </>
        )}

        {status?.sso_configured === false && (
          <div className="w-full text-left">
            <p className="mb-4 text-center text-sm text-muted-foreground">
              メールアドレスとパスワードでログインしてください。
            </p>
            {localLoginError != null && <ErrorMessage error={localLoginError} fallback="ログインに失敗しました。" />}
            <FormField label="メールアドレス" htmlFor="login-email" required>
              <Input id="login-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
            </FormField>
            <FormField label="パスワード" htmlFor="login-password" required>
              <Input
                id="login-password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </FormField>
            <Button
              className="w-full"
              disabled={!email || !password}
              isLoading={isLoggingIn}
              onClick={() => void handleLocalLogin()}
            >
              ログイン
            </Button>
          </div>
        )}
      </div>
    </main>
  )
}
