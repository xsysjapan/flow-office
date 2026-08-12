import { CheckCircle2, Circle } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { useGenerateCompanyCalendarYearsNow, useWorkCalendars } from '../../hooks/useWorkCalendars'

/**
 * UC-C011「今すぐ生成する」: 会社カレンダー本体は作成済みだが、カレンダー年度をまだ
 * 生成していない状態向けの簡潔なオンボーディング画面。全ての会社カレンダー本体について
 * fiscal_year_start_month/dayから標準のカレンダー年度をまとめて生成する
 * (`GET /company-calendars`・`POST /onboarding/calendar/generate-now`)。
 */
export function OnboardingCalendarPage() {
  const { data: calendars, isLoading, error } = useWorkCalendars()
  const generateNow = useGenerateCompanyCalendarYearsNow()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="カレンダー一覧の取得に失敗しました。" />

  const hasCalendars = (calendars ?? []).length > 0
  const generated = generateNow.data

  const checklist = [
    { label: '会社カレンダー本体を作成済み', done: hasCalendars },
    { label: 'カレンダー年度を生成済み', done: Boolean(generated) },
  ]

  return (
    <div className="flex flex-col gap-6">
      <Card title={generated ? '勤務カレンダーを準備しました' : '勤務カレンダーの初期設定'}>
        {generateNow.error && <ErrorMessage error={generateNow.error} />}

        {!hasCalendars ? (
          <p className="text-sm text-muted-foreground">
            まず会社カレンダー本体を作成してください。作成後にこの画面から標準のカレンダー年度をまとめて生成できます。
          </p>
        ) : (
          <>
            <ul className="mb-4 flex flex-col gap-2">
              {checklist.map((item) => (
                <li key={item.label} className="flex items-center gap-2 text-sm">
                  {item.done ? (
                    <CheckCircle2 className="size-4 text-success" aria-hidden="true" />
                  ) : (
                    <Circle className="size-4 text-muted-foreground" aria-hidden="true" />
                  )}
                  <span className={item.done ? 'text-foreground' : 'text-muted-foreground'}>{item.label}</span>
                </li>
              ))}
            </ul>

            {!generated && (
              <Button isLoading={generateNow.isPending} onClick={() => generateNow.mutate()}>
                今すぐ生成する
              </Button>
            )}

            {generated && (
              <>
                <p className="mb-4 text-sm text-muted-foreground">
                  {generated.generated_company_calendar_year_ids.length}件のカレンダー年度を生成しました。
                </p>
                <div className="flex flex-wrap gap-2">
                  <Button asChild>
                    <Link to="/admin/work-calendars">この設定で開始する</Link>
                  </Button>
                  <Button variant="secondary" asChild>
                    <Link to="/admin/work-calendars">カレンダーを確認する</Link>
                  </Button>
                </div>
              </>
            )}
          </>
        )}
      </Card>
    </div>
  )
}
