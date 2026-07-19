import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { API_BASE_URL } from '../../api/client'
import { submitOnboarding } from '../../api/onboarding'
import { useAuth } from '../../auth/useAuth'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'

/**
 * 初回オンボーディング(docs/06-usecases-auth.md): Microsoft 365連携設定(Entra ID
 * アプリ登録の資格情報)を登録し、最初の管理者ユーザーを作成する。認証はEntra ID SSOのみ
 * のため、この設定自体が空の間は誰もログインできない。完了すると作成した管理者で
 * そのままログイン済みになる(実際のSSO往復は待たない)。
 */
export function OnboardingPage() {
  const { applySession } = useAuth()
  const navigate = useNavigate()

  const [adminName, setAdminName] = useState('')
  const [adminEmail, setAdminEmail] = useState('')
  const [tenantId, setTenantId] = useState('')
  const [clientId, setClientId] = useState('')
  const [clientSecret, setClientSecret] = useState('')
  const [redirectUri, setRedirectUri] = useState(`${API_BASE_URL.replace(/\/$/, '')}/auth/microsoft/callback`)
  const [mockEnabled, setMockEnabled] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const isValid = adminName && adminEmail && tenantId && clientId && clientSecret && redirectUri

  const handleSubmit = async () => {
    setError(null)
    setIsSubmitting(true)
    try {
      const { token, user } = await submitOnboarding({
        admin_name: adminName,
        admin_email: adminEmail,
        m365_tenant_id: tenantId,
        m365_client_id: clientId,
        m365_client_secret: clientSecret,
        m365_redirect_uri: redirectUri,
        m365_mock_enabled: mockEnabled,
      })
      applySession(token, user)
      navigate('/', { replace: true })
    } catch (err) {
      setError(err)
      setIsSubmitting(false)
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center p-4">
      <div className="w-full max-w-lg">
        <Card title="初回セットアップ">
          <p className="mb-4 text-sm text-muted-foreground">
            Microsoft 365(Entra ID)との連携設定と、最初の管理者アカウントを登録します。
          </p>

          {error != null && <ErrorMessage error={error} fallback="セットアップに失敗しました。" />}

          <h3 className="mb-3 text-sm font-semibold text-foreground">管理者アカウント</h3>
          <FormField label="氏名" htmlFor="onboarding-admin-name" required>
            <Input id="onboarding-admin-name" value={adminName} onChange={(e) => setAdminName(e.target.value)} />
          </FormField>
          <FormField label="メールアドレス" htmlFor="onboarding-admin-email" required>
            <Input
              id="onboarding-admin-email"
              type="email"
              value={adminEmail}
              onChange={(e) => setAdminEmail(e.target.value)}
            />
          </FormField>

          <h3 className="mb-3 mt-6 text-sm font-semibold text-foreground">Microsoft 365連携設定</h3>
          <FormField label="テナントID" htmlFor="onboarding-tenant-id" required>
            <Input id="onboarding-tenant-id" value={tenantId} onChange={(e) => setTenantId(e.target.value)} />
          </FormField>
          <FormField label="クライアントID" htmlFor="onboarding-client-id" required>
            <Input id="onboarding-client-id" value={clientId} onChange={(e) => setClientId(e.target.value)} />
          </FormField>
          <FormField label="クライアントシークレット" htmlFor="onboarding-client-secret" required>
            <Input
              id="onboarding-client-secret"
              type="password"
              value={clientSecret}
              onChange={(e) => setClientSecret(e.target.value)}
            />
          </FormField>
          <FormField label="リダイレクトURI" htmlFor="onboarding-redirect-uri" required>
            <Input id="onboarding-redirect-uri" value={redirectUri} onChange={(e) => setRedirectUri(e.target.value)} />
          </FormField>

          <label className="mb-4 flex items-center gap-2 text-sm text-foreground">
            <Checkbox checked={mockEnabled} onCheckedChange={(checked) => setMockEnabled(checked === true)} />
            ローカル開発用モックOIDC(mock-oidc)を使う
          </label>

          <Button className="w-full" disabled={!isValid} isLoading={isSubmitting} onClick={() => void handleSubmit()}>
            セットアップを完了する
          </Button>
        </Card>
      </div>
    </main>
  )
}
