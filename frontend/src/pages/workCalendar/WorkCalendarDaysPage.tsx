import { useParams } from 'react-router-dom'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useEditableRows } from '../../hooks/useEditableRows'
import { usePutWorkCalendarDays } from '../../hooks/useWorkCalendars'

type ScheduleState = 'WORK' | 'OFF'

interface DayRowData {
  date: string
  schedule_state: ScheduleState
  is_public_holiday: boolean
  public_holiday_name: string
  note: string
}

const emptyRow: DayRowData = {
  date: '',
  schedule_state: 'WORK',
  is_public_holiday: false,
  public_holiday_name: '',
  note: '',
}

/** UC-C010: 祝日属性(祝日か否か)と勤務区分(WORK/OFF)を別の入力として扱う。 */
function deriveDayType(row: DayRowData): string {
  if (row.is_public_holiday) return 'public_holiday'
  return row.schedule_state === 'WORK' ? 'weekday' : 'holiday'
}

/**
 * UC-C010: カレンダー年度の日別属性(勤務区分・祝日)を一括登録・更新する。日単位の取得APIは
 * ないため、入力した内容をまとめて `PUT /company-calendar-years/:yearId/days` に送る。
 */
export function WorkCalendarDaysPage() {
  const { yearId } = useParams<{ yearId: string }>()
  const putDays = usePutWorkCalendarDays()

  const { rows, addRow, updateRow, toData } = useEditableRows<DayRowData>([])

  if (!yearId) return <p className="text-sm text-muted-foreground">カレンダー年度が見つかりません。</p>

  const handleSave = () => {
    putDays.mutate({
      id: yearId,
      days: toData().map((row) => ({
        date: row.date,
        day_type: deriveDayType(row),
        schedule_state: row.schedule_state,
        is_public_holiday: row.is_public_holiday,
        public_holiday_name: row.is_public_holiday && row.public_holiday_name ? row.public_holiday_name : undefined,
        note: row.note || undefined,
      })),
    })
  }

  return (
    <Card title="カレンダー年度の日別編集">
      {putDays.error && <ErrorMessage error={putDays.error} />}

      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>日付</TableHead>
            <TableHead>勤務区分</TableHead>
            <TableHead>祝日</TableHead>
            <TableHead>祝日名</TableHead>
            <TableHead>メモ</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <TableRow key={row.rowId}>
              <TableCell>
                <DatePicker
                  aria-label="日付"
                  value={row.date || undefined}
                  onChange={(date) => updateRow(row.rowId, { date: date ?? '' })}
                />
              </TableCell>
              <TableCell>
                <NativeSelect
                  aria-label="勤務区分"
                  value={row.schedule_state}
                  onChange={(e) => updateRow(row.rowId, { schedule_state: e.target.value as ScheduleState })}
                >
                  <option value="WORK">WORK(勤務日)</option>
                  <option value="OFF">OFF(休日)</option>
                </NativeSelect>
              </TableCell>
              <TableCell>
                <Checkbox
                  aria-label="祝日"
                  checked={row.is_public_holiday}
                  onCheckedChange={(checked) => updateRow(row.rowId, { is_public_holiday: checked === true })}
                />
              </TableCell>
              <TableCell>
                <Input
                  aria-label="祝日名"
                  value={row.public_holiday_name}
                  disabled={!row.is_public_holiday}
                  onChange={(e) => updateRow(row.rowId, { public_holiday_name: e.target.value })}
                />
              </TableCell>
              <TableCell>
                <Input aria-label="メモ" value={row.note} onChange={(e) => updateRow(row.rowId, { note: e.target.value })} />
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
