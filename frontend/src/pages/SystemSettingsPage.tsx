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
  const [submissionDeadlineDay, setSubmissionDeadlineDay] = useState('')
  const [monthCloseDeadlineDay, setMonthCloseDeadlineDay] = useState('')
  const [defaultWorkStyleId, setDefaultWorkStyleId] = useState<number | null>(null)
  const [savedMessage, setSavedMessage] = useState(false)

  useEffect(() => {
    if (!data) return
    setDefaultTimezone(data.default_timezone)
    setSubmissionDeadlineDay(String(data.attendance_submission_deadline_day))
    setMonthCloseDeadlineDay(String(data.attendance_month_close_deadline_day))
    setDefaultWorkStyleId(data.default_work_style_id)
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
      },
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
