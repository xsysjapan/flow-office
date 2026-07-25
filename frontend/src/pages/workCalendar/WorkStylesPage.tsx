import { useState } from 'react'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { TimePicker } from '../../components/TimePicker/TimePicker'
import { WorkStyleFormModal, WORK_TIME_SYSTEM_OPTIONS } from '../../components/WorkStyleFormModal/WorkStyleFormModal'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import {
  useAssignUserWorkStyleForMonth,
  useUserWorkStyleMonthlyAssignments,
} from '../../hooks/useUserWorkStyleMonthlyAssignments'
import { useGenerateShiftAssignments } from '../../hooks/useEmployeeShiftAssignments'
import { useUser } from '../../hooks/useUsers'
import { useCreateDefaultWorkStyle, useSetDefaultWorkStyle, useWorkStyles } from '../../hooks/useWorkStyles'
import type { WorkStyle } from '../../api/types'

/** "YYYY-MM" から、その月の1日と末日(YYYY-MM-DD)を返す。 */
function monthBoundaries(yearMonth: string): { from: string; to: string } {
  const [year, month] = yearMonth.split('-').map(Number)
  const lastDay = new Date(year, month, 0).getDate()
  return { from: `${yearMonth}-01`, to: `${yearMonth}-${String(lastDay).padStart(2, '0')}` }
}

const STANDARD_WORK_STYLE_DEFAULTS = {
  name: '通常勤務',
  default_start_time: '09:00',
  default_end_time: '18:00',
  default_break_minutes: 60,
}

/**
 * 指示書 12.1節: 初回導入時、会社のデフォルト働き方が未設定の間だけ表示するオンボーディング。
 * 「未設定」と「デフォルト適用」を混同しないため、is_defaultの働き方が1件も無いことを
 * 表示条件とする(指示書 2.2節)。
 */
function WorkStyleOnboardingCard() {
  const { data: workStyles } = useWorkStyles()
  const createDefault = useCreateDefaultWorkStyle()
  const [isEditing, setIsEditing] = useState(false)
  const [name, setName] = useState(STANDARD_WORK_STYLE_DEFAULTS.name)
  const [startTime, setStartTime] = useState(STANDARD_WORK_STYLE_DEFAULTS.default_start_time)
  const [endTime, setEndTime] = useState(STANDARD_WORK_STYLE_DEFAULTS.default_end_time)
  const [breakMinutes, setBreakMinutes] = useState(String(STANDARD_WORK_STYLE_DEFAULTS.default_break_minutes))

  const hasDefault = (workStyles ?? []).some((style) => style.is_default)
  if (hasDefault) return null

  const handleStart = () => {
    createDefault.mutate({})
  }

  const handleSaveEdited = () => {
    createDefault.mutate({
      name,
      default_start_time: startTime,
      default_end_time: endTime,
      default_break_minutes: Number(breakMinutes),
    })
  }

  return (
    <Card title="一般的な勤務設定を用意しました">
      {createDefault.error && <ErrorMessage error={createDefault.error} />}

      {!isEditing ? (
        <>
          <ul className="mb-4 text-sm text-foreground">
            <li>{STANDARD_WORK_STYLE_DEFAULTS.name}</li>
            <li>月曜日〜金曜日</li>
            <li>
              {STANDARD_WORK_STYLE_DEFAULTS.default_start_time}〜{STANDARD_WORK_STYLE_DEFAULTS.default_end_time}
            </li>
            <li>休憩12:00〜13:00</li>
            <li>土日祝休み</li>
          </ul>

          <div className="flex flex-wrap gap-3">
            <Button isLoading={createDefault.isPending} onClick={handleStart}>
              この設定で始める
            </Button>
            <Button variant="secondary" onClick={() => setIsEditing(true)}>
              内容を変更する
            </Button>
          </div>
        </>
      ) : (
        <>
          <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label="名称" htmlFor="onboarding-work-style-name" required>
              <Input id="onboarding-work-style-name" value={name} onChange={(e) => setName(e.target.value)} />
            </FormField>

            <FormField label="休憩(分)" htmlFor="onboarding-work-style-break-minutes" required>
              <Input
                id="onboarding-work-style-break-minutes"
                type="number"
                min={0}
                value={breakMinutes}
                onChange={(e) => setBreakMinutes(e.target.value)}
              />
            </FormField>

            <FormField label="始業時刻" htmlFor="onboarding-work-style-start-time" required>
              <TimePicker id="onboarding-work-style-start-time" value={startTime} onChange={(time) => setStartTime(time ?? '')} />
            </FormField>

            <FormField label="終業時刻" htmlFor="onboarding-work-style-end-time" required>
              <TimePicker id="onboarding-work-style-end-time" value={endTime} onChange={(time) => setEndTime(time ?? '')} />
            </FormField>
          </div>

          <div className="flex flex-wrap gap-3">
            <Button isLoading={createDefault.isPending} onClick={handleSaveEdited}>
              保存して開始する
            </Button>
            <Button variant="secondary" onClick={() => setIsEditing(false)}>
              キャンセル
            </Button>
          </div>
        </>
      )}
    </Card>
  )
}

function workTimeSystemLabel(value: string): string {
  return WORK_TIME_SYSTEM_OPTIONS.find((option) => option.value === value)?.label ?? value
}

/** UC-C005: シフト制の勤務形態にのみ適用される法定休日要件の説明。 */
function legalHolidayRuleDescription(style: Pick<WorkStyle, 'legal_holiday_rule' | 'four_week_period_start_date'>): string {
  if (style.legal_holiday_rule === 'four_weeks_four_days') {
    return `法定休日: 4週4日以上(変形休日制、起算日 ${style.four_week_period_start_date ?? '未設定'})`
  }
  return '法定休日: 毎週1日'
}

const PAGE_SIZE = 10

/**
 * UC-C002: 勤務形態の一覧・新規登録・編集。一覧はクライアント側でページングする
 * (勤務形態はマスタデータであり、勤怠計算の基準となる全件をダウンドロップ選択(シフト生成等)
 * でも使うため、フェッチ自体はページ分割しない)。
 */
function WorkStyleListCard() {
  const { data: workStyles, isLoading, error } = useWorkStyles()
  const setDefaultWorkStyle = useSetDefaultWorkStyle()

  const [page, setPage] = useState(1)
  const [isCreateOpen, setIsCreateOpen] = useState(false)
  const [editingWorkStyle, setEditingWorkStyle] = useState<WorkStyle | null>(null)

  const list = workStyles ?? []
  const lastPage = Math.max(1, Math.ceil(list.length / PAGE_SIZE))
  const currentPage = Math.min(page, lastPage)
  const pageItems = list.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  return (
    <Card
      title="勤務形態"
      actions={
        <Button onClick={() => setIsCreateOpen(true)}>新規登録</Button>
      }
    >
      {error && <ErrorMessage error={error} fallback="勤務形態の取得に失敗しました。" />}
      {setDefaultWorkStyle.error && <ErrorMessage error={setDefaultWorkStyle.error} />}

      {isLoading ? (
        <LoadingState />
      ) : list.length === 0 ? (
        <p className="text-sm text-muted-foreground">勤務形態はまだありません。</p>
      ) : (
        <>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>名称</TableHead>
                <TableHead>コード</TableHead>
                <TableHead>労働時間制</TableHead>
                <TableHead>所定労働時間</TableHead>
                <TableHead>区分</TableHead>
                <TableHead>状態</TableHead>
                <TableHead>操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {pageItems.map((style) => (
                <TableRow key={style.id}>
                  <TableCell className="font-medium text-foreground">{style.name}</TableCell>
                  <TableCell className="text-muted-foreground">{style.code}</TableCell>
                  <TableCell className="text-muted-foreground">{workTimeSystemLabel(style.work_time_system)}</TableCell>
                  <TableCell className="text-muted-foreground">{style.prescribed_daily_minutes}分/日</TableCell>
                  <TableCell>
                    <div className="flex flex-wrap gap-1">
                      <Badge tone={style.is_shift_based ? 'info' : 'neutral'}>
                        {style.is_shift_based ? 'シフト制' : '固定制'}
                      </Badge>
                      {style.auto_break_enabled && <Badge tone="info">休憩自動補完</Badge>}
                      {style.is_shift_based && <Badge tone="neutral">{legalHolidayRuleDescription(style)}</Badge>}
                      {style.configuration_warnings.map((warning) => (
                        <Badge key={warning} tone="warning">
                          {warning}
                        </Badge>
                      ))}
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-col gap-1 text-xs text-muted-foreground">
                      {style.is_default && (
                        <span className="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                          デフォルト
                        </span>
                      )}
                      <span>適用社員数 {style.applied_employee_count ?? '-'}名</span>
                      {style.is_shift_based && <span>使用中の勤務シフト {style.active_shift_pattern_count ?? 0}件</span>}
                      {style.updated_at && <span>最終更新 {style.updated_at.slice(0, 10)}</span>}
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-wrap items-start gap-2">
                      <Button variant="secondary" onClick={() => setEditingWorkStyle(style)}>
                        編集
                      </Button>
                      {!style.is_default && (
                        <Button
                          variant="secondary"
                          isLoading={setDefaultWorkStyle.isPending}
                          onClick={() => setDefaultWorkStyle.mutate(style.id)}
                        >
                          デフォルトに設定
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <Pagination currentPage={currentPage} lastPage={lastPage} total={list.length} onPageChange={setPage} />
        </>
      )}

      <WorkStyleFormModal mode="create" open={isCreateOpen} onOpenChange={setIsCreateOpen} />
      {editingWorkStyle && (
        <WorkStyleFormModal
          mode="edit"
          workStyle={editingWorkStyle}
          open={editingWorkStyle !== null}
          onOpenChange={(open) => {
            if (!open) setEditingWorkStyle(null)
          }}
        />
      )}
    </Card>
  )
}

/**
 * 10月までは通常勤務、11月からシフト勤務のように、ユーザーの月次働き方を切り替える。
 * 過去月の割当は変更されず履歴として残る(docs/16-database-schema.md
 * user_work_style_monthly_assignments)。
 */
function MonthlyWorkStyleAssignmentCard() {
  const { data: workStyles } = useWorkStyles()
  const [targetUserId, setTargetUserId] = useState<string | undefined>(undefined)
  const [yearMonth, setYearMonth] = useState('')
  const [workStyleId, setWorkStyleId] = useState('')
  const [isConfirming, setIsConfirming] = useState(false)
  const [autoGenerateShifts, setAutoGenerateShifts] = useState(false)

  const { data: targetUser } = useUser(targetUserId ?? '')
  const { data: history, isLoading: isLoadingHistory } = useUserWorkStyleMonthlyAssignments(targetUserId)
  const assignForMonth = useAssignUserWorkStyleForMonth()
  const generateShifts = useGenerateShiftAssignments()

  const currentAssignment = history?.find((assignment) => assignment.year_month === yearMonth)
  const selectedWorkStyle = workStyles?.find((style) => style.id === workStyleId)

  const handleUserChange = (userId: string | undefined) => {
    setTargetUserId(userId)
    setIsConfirming(false)
  }

  const handleYearMonthChange = (value: string) => {
    setYearMonth(value)
    setIsConfirming(false)
  }

  const handleWorkStyleChange = (value: string) => {
    setWorkStyleId(value)
    setIsConfirming(false)
  }

  const handleSave = () => {
    if (!targetUserId || !yearMonth || !workStyleId) return
    assignForMonth.mutate(
      { user_id: targetUserId, year_month: yearMonth, work_style_id: workStyleId },
      {
        onSuccess: () => {
          if (autoGenerateShifts) {
            const { from, to } = monthBoundaries(yearMonth)
            generateShifts.mutate({ user_id: targetUserId, work_style_id: workStyleId, from, to })
          }
          setYearMonth('')
          setIsConfirming(false)
          setAutoGenerateShifts(false)
        },
      },
    )
  }

  return (
    <Card title="ユーザーの月次働き方">
      <p className="mb-4 text-sm text-muted-foreground">
        働き方が設定されていない月は、システムのデフォルト働き方にフォールバックする。
      </p>
      {assignForMonth.error && <ErrorMessage error={assignForMonth.error} />}
      {generateShifts.error && <ErrorMessage error={generateShifts.error} />}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <FormField label="働き方の対象社員" htmlFor="monthly-work-style-user" required>
          <UserPicker id="monthly-work-style-user" value={targetUserId} onChange={handleUserChange} />
        </FormField>

        <FormField label="対象年月" htmlFor="monthly-work-style-year-month" required>
          <Input
            id="monthly-work-style-year-month"
            type="month"
            value={yearMonth}
            onChange={(e) => handleYearMonthChange(e.target.value)}
          />
        </FormField>

        <FormField label="働き方" htmlFor="monthly-work-style-select" required>
          <NativeSelect
            id="monthly-work-style-select"
            value={workStyleId}
            onChange={(e) => handleWorkStyleChange(e.target.value)}
          >
            <option value="">選択してください</option>
            {workStyles?.map((style) => (
              <option key={style.id} value={style.id}>
                {style.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>
      </div>

      {!isConfirming ? (
        <Button disabled={!targetUserId || !yearMonth || !workStyleId} onClick={() => setIsConfirming(true)}>
          変更内容を確認する
        </Button>
      ) : (
        <div className="mb-4 rounded-md border border-border p-4 text-sm">
          <p className="mb-3 font-semibold text-foreground">変更内容の確認</p>

          <dl className="mb-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
            <dt className="text-muted-foreground">対象社員</dt>
            <dd className="text-foreground">{targetUser?.name ?? `社員ID ${targetUserId}`}</dd>
            <dt className="text-muted-foreground">対象年月</dt>
            <dd className="text-foreground">{yearMonth}</dd>
            <dt className="text-muted-foreground">現在の働き方</dt>
            <dd className="text-foreground">
              {currentAssignment?.work_style?.name ?? '未設定(会社のデフォルトにフォールバック)'}
            </dd>
            <dt className="text-muted-foreground">変更後の働き方</dt>
            <dd className="text-foreground">{selectedWorkStyle?.name ?? '-'}</dd>
          </dl>

          <ul className="mb-3 list-disc pl-5 text-xs text-muted-foreground">
            <li>{yearMonth}より前の月の割当・勤怠には影響しません。</li>
            <li>
              {yearMonth}より後の月は自動的には引き継がれません。別途その月の働き方を割り当てる必要があります。
            </li>
            <li>
              {yearMonth}内で既に打刻・日次編集済みの日の集計(残業・深夜等)は自動的には再計算されません。
              反映するには対象日を日次編集から保存し直してください。
            </li>
          </ul>

          <label className="mb-3 flex items-center gap-2 text-foreground">
            <Checkbox
              checked={autoGenerateShifts}
              onCheckedChange={(checked) => setAutoGenerateShifts(checked === true)}
            />
            この働き方をもとに{yearMonth}の勤務予定を自動生成する(既存の勤務予定は上書きされます)
          </label>

          <div className="flex flex-wrap gap-3">
            <Button isLoading={assignForMonth.isPending || generateShifts.isPending} onClick={handleSave}>
              この内容で保存する
            </Button>
            <Button variant="secondary" onClick={() => setIsConfirming(false)}>
              キャンセル
            </Button>
          </div>
        </div>
      )}

      {targetUserId !== undefined && (
        <div className="mt-5 border-t border-border pt-4">
          <h3 className="mb-2 text-sm font-semibold text-foreground">割当履歴</h3>
          {isLoadingHistory ? (
            <LoadingState />
          ) : (history ?? []).length === 0 ? (
            <p className="text-sm text-muted-foreground">まだ割り当てられていません。</p>
          ) : (
            <ul className="divide-y divide-border">
              {history?.map((assignment) => (
                <li key={assignment.id} className="py-2 text-sm text-foreground">
                  {`${assignment.year_month}: ${assignment.work_style?.name ?? assignment.work_style_id}`}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Card>
  )
}

/**
 * UC-C002: 勤務形態の一覧(ページングあり)・新規登録・編集(いずれもモーダル)。
 * デフォルト働き方が未設定の間は初回オンボーディングを表示する(指示書 12.1節)。
 * シフトパターン・ローテーション・シフト生成は`ShiftsPage`に分離している。
 */
export function WorkStylesPage() {
  return (
    <div className="flex flex-col gap-6">
      <WorkStyleOnboardingCard />
      <WorkStyleListCard />
      <MonthlyWorkStyleAssignmentCard />
    </div>
  )
}
