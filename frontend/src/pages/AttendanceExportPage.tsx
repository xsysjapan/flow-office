import { useState } from 'react'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { UserPicker } from '../components/UserPicker/UserPicker'
import { downloadAttendanceCsv } from '../api/exports'
import './AttendanceExportPage.css'

/**
 * UC-E001: 勤怠CSVを出力する。締め後(UC-A011)の月次勤怠のみが対象。
 */
export function AttendanceExportPage() {
  const [yearMonth, setYearMonth] = useState('')
  const [userId, setUserId] = useState<number | undefined>(undefined)
  const [isDownloading, setIsDownloading] = useState(false)
  const [error, setError] = useState<Error | undefined>(undefined)

  const handleDownload = async () => {
    setError(undefined)
    setIsDownloading(true)
    try {
      await downloadAttendanceCsv({ year_month: yearMonth, user_id: userId })
    } catch (e) {
      setError(e instanceof Error ? e : new Error('勤怠CSVの取得に失敗しました。'))
    } finally {
      setIsDownloading(false)
    }
  }

  return (
    <Card title="勤怠CSV出力">
      <p className="attendance-export__note">
        締め処理が完了した月次勤怠のみがCSVに含まれます。対象社員を指定しない場合は全社員が対象です。
      </p>

      {error && <ErrorMessage error={error} fallback="勤怠CSVの取得に失敗しました。" />}

      <div className="attendance-export__form">
        <FormField label="対象月" htmlFor="attendance-export-year-month" required>
          <input
            id="attendance-export-year-month"
            type="month"
            value={yearMonth}
            onChange={(e) => setYearMonth(e.target.value)}
          />
        </FormField>
        <FormField label="対象社員(任意)" htmlFor="attendance-export-user">
          <UserPicker id="attendance-export-user" value={userId} onChange={setUserId} />
        </FormField>
      </div>

      <Button isLoading={isDownloading} disabled={!yearMonth} onClick={() => void handleDownload()}>
        CSVダウンロード
      </Button>
    </Card>
  )
}
