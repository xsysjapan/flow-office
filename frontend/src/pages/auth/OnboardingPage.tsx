import { useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormActions } from '../../components/FormActions/FormActions'
import { FormField } from '../../components/FormField/FormField'
import { FormLayout } from '../../components/FormLayout/FormLayout'
import { Ms365CredentialsFields, type Ms365CredentialsFieldsValue } from '../../components/Ms365CredentialsFields/Ms365CredentialsFields'
import { Input } from '../../components/ui/input'
import { useCompleteOnboardingLocal, useStartOnboardingSso } from '../../hooks/useOnboarding'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { INITIAL_ONBOARDING_FORM, isOnboardingDirty } from './onboardingDirty'

type OnboardingMode = 'sso' | 'local'

const DEFAULT_MS365_VALUE: Ms365CredentialsFieldsValue = {
  tenantId: '',
  clientId: '',
  clientSecret: '',
  mockEnabled: false,
}

/**
 * 初回オンボーディング(docs/06-usecases-auth.md UC-000): Microsoft 365(Entra ID)連携設定を
 * 登録するSSOモードと、その場でパスワード付き管理者を作成するローカルモードを選べる。
 * SSOモードでは管理者になるユーザーを事前入力しない。設定を保存した直後に実際のEntra ID
 * ログイン画面へ遷移し、そのOIDC認証結果(ユーザーID・メール・表示名)だけを使って
 * 管理者ユーザーを作成・リンクする。
 */
export function OnboardingPage() {
  const { applySession } = useAuth()
  const startSso = useStartOnboardingSso()
  const completeLocal = useCompleteOnboardingLocal()

  const [mode, setMode] = useState<OnboardingMode>('sso')
  const [ms365Value, setMs365Value] = useState<Ms365CredentialsFieldsValue>(DEFAULT_MS365_VALUE)

  const [adminName, setAdminName] = useState('')
  const [adminEmail, setAdminEmail] = useState('')
  const [adminPassword, setAdminPassword] = useState('')
  const [adminPasswordConfirmation, setAdminPasswordConfirmation] = useState('')

  const { navigateAfterSave, leaveWithoutGuard } = useUnsavedChangesGuard(
    isOnboardingDirty(INITIAL_ONBOARDING_FORM, {
      ms365TenantId: ms365Value.tenantId,
      ms365ClientId: ms365Value.clientId,
      ms365ClientSecret: ms365Value.clientSecret,
      ms365MockEnabled: ms365Value.mockEnabled,
      adminName,
      adminEmail,
      adminPassword,
      adminPasswordConfirmation,
    }),
  )

  const isSsoValid = ms365Value.tenantId && ms365Value.clientId && ms365Value.clientSecret
  const isLocalValid =
    adminName &&
    adminEmail &&
    adminPassword.length >= 8 &&
    adminPassword === adminPasswordConfirmation

  const handleSubmitSso = () => {
    startSso.mutate(
      {
        m365_tenant_id: ms365Value.tenantId,
        m365_client_id: ms365Value.clientId,
        m365_client_secret: ms365Value.clientSecret,
        m365_mock_enabled: ms365Value.mockEnabled,
      },
      {
        onSuccess: ({ redirect_url: redirectUrl }) => {
          leaveWithoutGuard(() => {
            window.location.href = redirectUrl
          })
        },
      },
    )
  }

  const handleSubmitLocal = () => {
    completeLocal.mutate(
      { admin_name: adminName, admin_email: adminEmail, admin_password: adminPassword },
      {
        onSuccess: ({ token, user }) => {
          applySession(token, user)
          navigateAfterSave('/', { replace: true })
        },
      },
    )
  }

  return (
    <main className="flex min-h-screen items-center justify-center p-4">
      <div className="w-full max-w-lg">
        <Card title="初回セットアップ">
          <p className="mb-4 text-sm text-muted-foreground">
            Microsoft 365(Entra ID)との連携を設定するか、ローカルパスワードで管理者アカウントを
            作成してください。
          </p>

          <div className="mb-6 flex gap-2">
            <Button
              variant={mode === 'sso' ? 'primary' : 'secondary'}
              aria-pressed={mode === 'sso'}
              onClick={() => setMode('sso')}
            >
              Microsoft 365 SSOを設定する
            </Button>
            <Button
              variant={mode === 'local' ? 'primary' : 'secondary'}
              aria-pressed={mode === 'local'}
              onClick={() => setMode('local')}
            >
              ローカルパスワードで作成する
            </Button>
          </div>

          {mode === 'sso' && (
            <FormLayout>
              <Ms365CredentialsFields idPrefix="onboarding-sso" value={ms365Value} onChange={setMs365Value} required />
              <p className="mb-2 text-xs text-muted-foreground">
                保存すると、そのままMicrosoftのログイン画面へ遷移します。ログインに成功した
                アカウントがそのまま管理者になります(氏名・メールアドレスの事前入力は不要です)。
              </p>
              {!isSsoValid && (
                <p className="mb-2 text-xs text-muted-foreground">
                  テナントID・クライアントID・クライアントシークレットを入力してください。
                </p>
              )}
              {startSso.error != null && <ErrorMessage error={startSso.error} fallback="設定の保存に失敗しました。" />}
              <FormActions>
                <Button disabled={!isSsoValid} isLoading={startSso.isPending} onClick={handleSubmitSso}>
                  保存してMicrosoftにログインする
                </Button>
              </FormActions>
            </FormLayout>
          )}

          {mode === 'local' && (
            <FormLayout>
              <FormField label="氏名" htmlFor="onboarding-local-name" required>
                <Input id="onboarding-local-name" value={adminName} onChange={(e) => setAdminName(e.target.value)} />
              </FormField>
              <FormField label="メールアドレス" htmlFor="onboarding-local-email" required>
                <Input
                  id="onboarding-local-email"
                  type="email"
                  value={adminEmail}
                  onChange={(e) => setAdminEmail(e.target.value)}
                />
              </FormField>
              <FormField label="パスワード(8文字以上)" htmlFor="onboarding-local-password" required>
                <Input
                  id="onboarding-local-password"
                  type="password"
                  value={adminPassword}
                  onChange={(e) => setAdminPassword(e.target.value)}
                />
              </FormField>
              <FormField label="パスワード(確認)" htmlFor="onboarding-local-password-confirmation" required>
                <Input
                  id="onboarding-local-password-confirmation"
                  type="password"
                  value={adminPasswordConfirmation}
                  onChange={(e) => setAdminPasswordConfirmation(e.target.value)}
                />
              </FormField>
              {!isLocalValid && (
                <p className="mb-2 text-xs text-muted-foreground">
                  氏名・メールアドレス・8文字以上のパスワード(確認と一致)を入力してください。
                </p>
              )}
              {completeLocal.error != null && (
                <ErrorMessage error={completeLocal.error} fallback="セットアップに失敗しました。" />
              )}
              <FormActions>
                <Button disabled={!isLocalValid} isLoading={completeLocal.isPending} onClick={handleSubmitLocal}>
                  セットアップを完了する
                </Button>
              </FormActions>
            </FormLayout>
          )}
        </Card>
      </div>
    </main>
  )
}
