import { useEffect, useState } from 'react'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Ms365CredentialsFields, type Ms365CredentialsFieldsValue } from '../../components/Ms365CredentialsFields/Ms365CredentialsFields'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { useSystemSettings, useUpdateSystemSettings } from '../../hooks/useSystemSettings'

/**
 * UC-003: システム設定(default_timezone等)を管理する。
 * 新規作成されるユーザーの既定タイムゾーンを変更する(既存ユーザーには影響しない)。
 * Microsoft 365連携設定(Entra ID資格情報)は初回オンボーディングで登録済みだが、
 * この画面から後で更新することもできる。
 */
export function SystemSettingsPage() {
  const { data, isLoading, error } = useSystemSettings()
  const updateSettings = useUpdateSystemSettings()
  const [defaultTimezone, setDefaultTimezone] = useState('')
  const [submissionDeadlineDay, setSubmissionDeadlineDay] = useState('')
  const [monthCloseDeadlineDay, setMonthCloseDeadlineDay] = useState('')
  const [defaultWorkStyleId, setDefaultWorkStyleId] = useState<number | null>(null)
  const [ms365Value, setMs365Value] = useState<Ms365CredentialsFieldsValue>({
    tenantId: '',
    clientId: '',
    clientSecret: '',
    mockEnabled: false,
  })
  const [notificationMailEnabled, setNotificationMailEnabled] = useState(false)
  const [notificationMailSenderAddress, setNotificationMailSenderAddress] = useState('')
  const [notificationMailSenderName, setNotificationMailSenderName] = useState('')
  const [savedMessage, setSavedMessage] = useState(false)

  useEffect(() => {
    if (!data) return
    setDefaultTimezone(data.default_timezone)
    setSubmissionDeadlineDay(String(data.attendance_submission_deadline_day))
    setMonthCloseDeadlineDay(String(data.attendance_month_close_deadline_day))
    setDefaultWorkStyleId(data.default_work_style_id)
    setMs365Value({
      tenantId: data.m365_tenant_id ?? '',
      clientId: data.m365_client_id ?? '',
      clientSecret: '',
      mockEnabled: data.m365_mock_enabled,
    })
    setNotificationMailEnabled(data.notification_mail_enabled)
    setNotificationMailSenderAddress(data.notification_mail_sender_address ?? '')
    setNotificationMailSenderName(data.notification_mail_sender_name ?? '')
  }, [data])

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="システム設定の取得に失敗しました。" />

  const handleSave = () => {
    setSavedMessage(false)
    updateSettings.mutate(
      {
        default_timezone: defaultTimezone,
        attendance_submission_deadline_day: Number(submissionDeadlineDay),
        attendance_month_close_deadline_day: Number(monthCloseDeadlineDay),
        // 既定の働き方は管理メニューの勤務形態画面(デフォルトに設定)で変更する。
        // ここでは読み込んだ値をそのまま送り返し、上書きしないようにする。
        default_work_style_id: defaultWorkStyleId,
        m365_tenant_id: ms365Value.tenantId || null,
        m365_client_id: ms365Value.clientId || null,
        // 空欄のままなら送らない(既存のシークレットを変更しない)。
        ...(ms365Value.clientSecret ? { m365_client_secret: ms365Value.clientSecret } : {}),
        m365_mock_enabled: ms365Value.mockEnabled,
        notification_mail_enabled: notificationMailEnabled,
        notification_mail_sender_address: notificationMailSenderAddress || null,
        notification_mail_sender_name: notificationMailSenderName || null,
      },
      {
        onSuccess: () => {
          setSavedMessage(true)
          setMs365Value((current) => ({ ...current, clientSecret: '' }))
        },
      },
    )
  }

  return (
    <Card title="システム設定">
      <p className="mb-4 text-sm text-muted-foreground">
        変更後に新規作成されるユーザーはこのタイムゾーンで作成されます(既存ユーザーの
        タイムゾーンは変更されません)。
      </p>

      {updateSettings.error && <ErrorMessage error={updateSettings.error} />}
      {savedMessage && <p className="mb-3 text-sm text-success">保存しました。</p>}

      <FormField label="既定タイムゾーン" htmlFor="system-settings-default-timezone" required>
        <Input
          id="system-settings-default-timezone"
          placeholder="Asia/Tokyo"
          value={defaultTimezone}
          onChange={(e) => {
            setDefaultTimezone(e.target.value)
            setSavedMessage(false)
          }}
        />
      </FormField>

      <FormField
        label="勤怠未提出の警告基準日(当月の何日)"
        htmlFor="system-settings-submission-deadline-day"
        required
      >
        <Input
          id="system-settings-submission-deadline-day"
          type="number"
          min={1}
          max={31}
          value={submissionDeadlineDay}
          onChange={(e) => {
            setSubmissionDeadlineDay(e.target.value)
            setSavedMessage(false)
          }}
        />
        <p className="mt-1 text-xs text-muted-foreground">
          この日を過ぎても前月分の勤怠が未提出の在籍社員に、解消するまで毎日通知する。
        </p>
      </FormField>

      <FormField
        label="月次締め前警告の基準日(当月の何日)"
        htmlFor="system-settings-month-close-deadline-day"
        required
      >
        <Input
          id="system-settings-month-close-deadline-day"
          type="number"
          min={1}
          max={31}
          value={monthCloseDeadlineDay}
          onChange={(e) => {
            setMonthCloseDeadlineDay(e.target.value)
            setSavedMessage(false)
          }}
        />
        <p className="mt-1 text-xs text-muted-foreground">
          この日の3日前になっても前月分の月次勤怠が締められていない場合に通知する。
        </p>
      </FormField>

      <h3 className="mb-3 mt-6 text-sm font-semibold text-foreground">Microsoft 365連携設定</h3>
      <p className="mb-4 text-sm text-muted-foreground">
        SSOログイン・MS365ユーザー同期・メール通知(Graph API <code>sendMail</code>)で共有する
        Entra IDアプリ登録の資格情報。初回オンボーディングで登録済みだが、ここから変更できる。
      </p>

      <Ms365CredentialsFields
        idPrefix="system-settings-m365"
        value={ms365Value}
        onChange={(value) => {
          setMs365Value(value)
          setSavedMessage(false)
        }}
        clientSecretConfigured={data?.m365_client_secret_configured}
      />

      <h3 className="mb-3 mt-6 text-sm font-semibold text-foreground">メール通知設定</h3>
      <p className="mb-4 text-sm text-muted-foreground">
        通知はMicrosoft Graph API(<code>sendMail</code>)経由で送信する。上記のMicrosoft 365
        連携設定が未設定、または「メール通知を有効にする」がオフの場合、通知メールは送信されない
        (ログにのみ記録される)。
      </p>

      <label className="mb-4 flex items-center gap-2 text-sm text-foreground">
        <Checkbox
          checked={notificationMailEnabled}
          onCheckedChange={(checked) => {
            setNotificationMailEnabled(checked === true)
            setSavedMessage(false)
          }}
        />
        メール通知を有効にする
      </label>

      <FormField label="送信元メールアドレス" htmlFor="system-settings-mail-sender-address">
        <Input
          id="system-settings-mail-sender-address"
          type="email"
          value={notificationMailSenderAddress}
          onChange={(e) => {
            setNotificationMailSenderAddress(e.target.value)
            setSavedMessage(false)
          }}
        />
      </FormField>

      <FormField label="送信元表示名" htmlFor="system-settings-mail-sender-name">
        <Input
          id="system-settings-mail-sender-name"
          value={notificationMailSenderName}
          onChange={(e) => {
            setNotificationMailSenderName(e.target.value)
            setSavedMessage(false)
          }}
        />
      </FormField>

      <Button
        isLoading={updateSettings.isPending}
        disabled={!defaultTimezone || !submissionDeadlineDay || !monthCloseDeadlineDay}
        onClick={handleSave}
      >
        保存する
      </Button>
    </Card>
  )
}
