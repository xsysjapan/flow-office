import { useState } from 'react'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { Input } from '../../components/ui/input'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { useDownloadAttendanceCsv } from '../../hooks/useAttendance'

/**
 * UC-E001: 勤怠CSVを出力する。締め後(UC-A011)の月次勤怠のみが対象。
 */
export function AttendanceExportPage() {
  const [yearMonth, setYearMonth] = useState('')
  const [userId, setUserId] = useState<string | undefined>(undefined)

  const downloadCsv = useDownloadAttendanceCsv()

  return (
    <Card title="勤怠CSV出力">
      <p className="mb-4 text-sm text-muted-foreground">
        締め処理が完了した月次勤怠のみがCSVに含まれます。対象社員を指定しない場合は全社員が対象です。
      </p>

      {downloadCsv.error && <ErrorMessage error={downloadCsv.error} fallback="勤怠CSVの取得に失敗しました。" />}

      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象月" htmlFor="attendance-export-year-month" required>
          <Input
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

      <Button
        isLoading={downloadCsv.isPending}
        disabled={!yearMonth}
        onClick={() => {
          downloadCsv.mutate({ year_month: yearMonth, user_id: userId })
        }}
      >
        CSVダウンロード
      </Button>
    </Card>
  )
}
