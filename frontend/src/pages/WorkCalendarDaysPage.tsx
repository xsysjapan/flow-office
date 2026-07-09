import { useParams } from 'react-router-dom'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useEditableRows } from '../hooks/useEditableRows'
import { usePutWorkCalendarDays, useWorkCalendars } from '../hooks/useWorkCalendars'
import './WorkCalendarDaysPage.css'

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
  const { id } = useParams<{ id: string }>()
  const calendarId = Number(id)
  const { data: calendars, isLoading, error } = useWorkCalendars()
  const putDays = usePutWorkCalendarDays()

  const { rows, addRow, updateRow, toData } = useEditableRows<DayRowData>([])

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="カレンダー一覧の取得に失敗しました。" />

  const calendar = calendars?.find((c) => c.id === calendarId)
  if (!calendar) return <p>カレンダーが見つかりません。</p>

  const handleSave = () => {
    putDays.mutate({
      id: calendarId,
      days: toData().map((row) => ({ ...row, note: row.note || undefined })),
    })
  }

  return (
    <Card title={`${calendar.name} の日別編集`}>
      <dl className="work-calendar-days__meta">
        <dt>年度</dt>
        <dd>{calendar.fiscal_year}</dd>
        <dt>期間</dt>
        <dd>
          {calendar.starts_on}〜{calendar.ends_on}
        </dd>
      </dl>

      {putDays.error && <ErrorMessage error={putDays.error} />}

      <table className="work-calendar-days__table">
        <thead>
          <tr>
            <th>日付</th>
            <th>区分</th>
            <th>稼働日</th>
            <th>法定休日</th>
            <th>所定休日</th>
            <th>メモ</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.rowId}>
              <td>
                <input
                  aria-label="日付"
                  type="date"
                  value={row.date}
                  onChange={(e) => updateRow(row.rowId, { date: e.target.value })}
                />
              </td>
              <td>
                <input
                  aria-label="区分"
                  value={row.day_type}
                  onChange={(e) => updateRow(row.rowId, { day_type: e.target.value })}
                />
              </td>
              <td>
                <input
                  aria-label="稼働日"
                  type="checkbox"
                  checked={row.is_working_day}
                  onChange={(e) => updateRow(row.rowId, { is_working_day: e.target.checked })}
                />
              </td>
              <td>
                <input
                  aria-label="法定休日"
                  type="checkbox"
                  checked={row.is_legal_holiday}
                  onChange={(e) => updateRow(row.rowId, { is_legal_holiday: e.target.checked })}
                />
              </td>
              <td>
                <input
                  aria-label="所定休日"
                  type="checkbox"
                  checked={row.is_company_holiday}
                  onChange={(e) => updateRow(row.rowId, { is_company_holiday: e.target.checked })}
                />
              </td>
              <td>
                <input
                  aria-label="メモ"
                  value={row.note}
                  onChange={(e) => updateRow(row.rowId, { note: e.target.value })}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="work-calendar-days__actions">
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
