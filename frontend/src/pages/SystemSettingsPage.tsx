import { useEffect, useState } from 'react'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Input } from '../components/ui/input'
import { useSystemSettings, useUpdateSystemSettings } from '../hooks/useSystemSettings'

/**
 * UC-003: システム設定(default_timezone)を管理する。
 * 新規作成されるユーザーの既定タイムゾーンを変更する(既存ユーザーには影響しない)。
 */
export function SystemSettingsPage() {
  const { data, isLoading, error } = useSystemSettings()
  const updateSettings = useUpdateSystemSettings()
  const [defaultTimezone, setDefaultTimezone] = useState('')
  const [savedMessage, setSavedMessage] = useState(false)

  useEffect(() => {
    if (data) setDefaultTimezone(data.default_timezone)
  }, [data])

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="システム設定の取得に失敗しました。" />

  const handleSave = () => {
    setSavedMessage(false)
    updateSettings.mutate(
      { default_timezone: defaultTimezone },
      { onSuccess: () => setSavedMessage(true) },
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

      <Button isLoading={updateSettings.isPending} disabled={!defaultTimezone} onClick={handleSave}>
        保存する
      </Button>
    </Card>
  )
}
