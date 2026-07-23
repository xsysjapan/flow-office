import { useParams } from 'react-router-dom'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useEditableRows } from '../../hooks/useEditableRows'
import { usePutWorkCalendarDays, useWorkCalendars } from '../../hooks/useWorkCalendars'

interface DayRowData {
  date: string
  day_type: string
  is_working_day: boolean
  is_legal_holiday: boolean
  is_company_holiday: boolean
  note: string
}

const emptyRow: DayRowData = {
  date: '',
  day_type: '',
  is_working_day: true,
  is_legal_holiday: false,
  is_company_holiday: false,
  note: '',
}

/**
 * UC-C001手順2〜3: 年度カレンダーの日別属性(休日区分)を一括登録・更新する。
 * 日単位の取得APIはないため、入力した内容をまとめて `PUT /work-calendars/:id/days` に送る。
 */
export function WorkCalendarDaysPage() {
  const { id: calendarId } = useParams<{ id: string }>()
  const { data: calendars, isLoading, error } = useWorkCalendars()
  const putDays = usePutWorkCalendarDays()

  const { rows, addRow, updateRow, toData } = useEditableRows<DayRowData>([])

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="カレンダー一覧の取得に失敗しました。" />

  const calendar = calendars?.find((c) => c.id === calendarId)
  if (!calendar || !calendarId) return <p className="text-sm text-muted-foreground">カレンダーが見つかりません。</p>

  const handleSave = () => {
    putDays.mutate({
      id: calendarId,
      days: toData().map((row) => ({ ...row, note: row.note || undefined })),
    })
  }

  return (
    <Card title={`${calendar.name} の日別編集`}>
      <dl className="mb-4 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="font-medium text-muted-foreground">年度</dt>
        <dd className="text-foreground">{calendar.fiscal_year}</dd>
        <dt className="font-medium text-muted-foreground">期間</dt>
        <dd className="text-foreground">
          {calendar.starts_on}〜{calendar.ends_on}
        </dd>
      </dl>

      {putDays.error && <ErrorMessage error={putDays.error} />}

      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>日付</TableHead>
            <TableHead>区分</TableHead>
            <TableHead>稼働日</TableHead>
            <TableHead>法定休日</TableHead>
            <TableHead>所定休日</TableHead>
            <TableHead>メモ</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <TableRow key={row.rowId}>
              <TableCell>
                <Input
                  aria-label="日付"
                  type="date"
                  value={row.date}
                  onChange={(e) => updateRow(row.rowId, { date: e.target.value })}
                />
              </TableCell>
              <TableCell>
                <Input
                  aria-label="区分"
                  value={row.day_type}
                  onChange={(e) => updateRow(row.rowId, { day_type: e.target.value })}
                />
              </TableCell>
              <TableCell>
                <Checkbox
                  aria-label="稼働日"
                  checked={row.is_working_day}
                  onCheckedChange={(checked) => updateRow(row.rowId, { is_working_day: checked === true })}
                />
              </TableCell>
              <TableCell>
                <Checkbox
                  aria-label="法定休日"
                  checked={row.is_legal_holiday}
                  onCheckedChange={(checked) => updateRow(row.rowId, { is_legal_holiday: checked === true })}
                />
              </TableCell>
              <TableCell>
                <Checkbox
                  aria-label="所定休日"
                  checked={row.is_company_holiday}
                  onCheckedChange={(checked) => updateRow(row.rowId, { is_company_holiday: checked === true })}
                />
              </TableCell>
              <TableCell>
                <Input
                  aria-label="メモ"
                  value={row.note}
                  onChange={(e) => updateRow(row.rowId, { note: e.target.value })}
                />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>

      <div className="mt-4 flex gap-3">
        <Button variant="secondary" onClick={() => addRow(emptyRow)}>
          行を追加
        </Button>
        <Button isLoading={putDays.isPending} disabled={rows.length === 0} onClick={handleSave}>
          保存する
        </Button>
      </div>
    </Card>
  )
}
