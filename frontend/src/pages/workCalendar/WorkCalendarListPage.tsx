import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { CreateCompanyCalendarModal } from '../../components/CreateCompanyCalendarModal/CreateCompanyCalendarModal'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { useDeleteWorkCalendar, useWorkCalendarsPage } from '../../hooks/useWorkCalendars'

const PER_PAGE = 20

/**
 * UC-C009: 会社カレンダー本体の一覧・作成・削除。デフォルト切替・週起算曜日/年度開始月日の
 * 設定・祝日iCalendar同期は、カレンダー名のリンク先の本体詳細画面(WorkCalendarDetailPage)に
 * まとめてある。カレンダー年度の作成・公開・複製は本体ごとの年度一覧ページ
 * (WorkCalendarYearsPage)で行う。作成はモーダル(CreateCompanyCalendarModal)に切り出し、
 * 週の開始曜日・年度開始月日・曜日ごとの休日設定・祝日iCalendarソースの割当を作成時から
 * 選べるようにする(段階的開示のため曜日ごとの休日設定は既定で折りたたまれている)。
 */
export function WorkCalendarListPage() {
  const [page, setPage] = useState(1)
  const { data, isLoading, error } = useWorkCalendarsPage(page, PER_PAGE)
  const deleteCalendar = useDeleteWorkCalendar()

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false)

  const calendars = data?.data ?? []

  return (
    <div className="flex flex-col gap-6">
      <CreateCompanyCalendarModal open={isCreateModalOpen} onOpenChange={setIsCreateModalOpen} />

      <Card
        title="会社カレンダー一覧"
        actions={<Button onClick={() => setIsCreateModalOpen(true)}>新規作成</Button>}
      >
        {isLoading ? (
          <LoadingState />
        ) : error ? (
          <ErrorMessage error={error} fallback="カレンダー一覧の取得に失敗しました。" />
        ) : calendars.length === 0 ? (
          <EmptyState
            title="カレンダーはまだありません。"
            description="会社カレンダーを作成すると、社員の勤務予定や祝日をカレンダー単位で管理できます。"
            action={<Button onClick={() => setIsCreateModalOpen(true)}>会社カレンダーを作成</Button>}
          />
        ) : (
          <>
            <ul className="divide-y divide-border">
              {calendars.map((calendar) => (
                <li key={calendar.id} className="flex flex-wrap items-center gap-3 py-3">
                  <div className="flex flex-1 flex-col">
                    <Link
                      to={`/admin/work-calendars/${calendar.id}`}
                      className="text-sm font-medium text-foreground hover:text-primary hover:underline"
                    >
                      {calendar.name}
                    </Link>
                    <span className="text-sm text-muted-foreground">
                      週開始: {calendar.week_starts_on} / 年度開始: {calendar.fiscal_year_start_month}月
                      {calendar.fiscal_year_start_day}日
                    </span>
                  </div>
                  <Badge tone={calendar.is_default ? 'success' : 'neutral'}>
                    {calendar.is_default ? 'デフォルト' : '非デフォルト'}
                  </Badge>
                  <ConfirmActionDialog
                    triggerLabel="削除"
                    triggerVariant="danger"
                    title={`「${calendar.name}」を削除しますか?`}
                    description="削除すると元に戻せません。"
                    confirmLabel="削除する"
                    isPending={deleteCalendar.isPending && deleteCalendar.variables === calendar.id}
                    error={deleteCalendar.variables === calendar.id ? deleteCalendar.error : undefined}
                    onConfirm={() => deleteCalendar.mutateAsync(calendar.id)}
                  />
                </li>
              ))}
            </ul>

            {data && (
              <Pagination
                currentPage={data.meta.current_page}
                lastPage={data.meta.last_page}
                total={data.meta.total}
                onPageChange={setPage}
              />
            )}
          </>
        )}
      </Card>
    </div>
  )
}
