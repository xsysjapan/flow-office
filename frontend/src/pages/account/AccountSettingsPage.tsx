import { useState } from 'react'
import { fetchMicrosoftLinkRedirectUrl } from '../../api/auth'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'

/**
 * UC-004: ローカルパスワードでログイン中のユーザーが、任意のタイミングで自分のアカウントに
 * Microsoft 365(Entra ID)アカウントを紐づける。紐づけ後もローカルパスワードでのログインは
 * 引き続き使える。
 */
export function AccountSettingsPage() {
  const { user } = useAuth()
  const [error, setError] = useState<Error | null>(null)
  const [isRedirecting, setIsRedirecting] = useState(false)

  const handleLink = () => {
    setError(null)
    setIsRedirecting(true)

    fetchMicrosoftLinkRedirectUrl()
      .then(({ url }) => {
        window.location.href = url
      })
      .catch((err: Error) => {
        setError(err)
        setIsRedirecting(false)
      })
  }

  return (
    <Card title="アカウント設定">
      {error && <ErrorMessage error={error} fallback="連携用のログインURLの取得に失敗しました。" />}

      <div className="flex items-center justify-between gap-4 rounded-md border border-border p-4">
        <div>
          <p className="text-sm font-medium text-foreground">Microsoft 365 連携</p>
          <p className="mt-1 text-sm text-muted-foreground">
            {user?.sso_linked
              ? 'このアカウントはMicrosoft 365(Entra ID)アカウントと連携済みです。'
              : '連携すると、このアカウントでMicrosoftログインも使えるようになります。連携後もパスワードでのログインは引き続き使えます。'}
          </p>
        </div>
        {user?.sso_linked ? (
          <Badge tone="success">連携済み</Badge>
        ) : (
          <Button isLoading={isRedirecting} onClick={handleLink}>
            Microsoft 365 と連携する
          </Button>
        )}
      </div>
    </Card>
  )
}
