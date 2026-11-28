import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { notify } from '../../lib/notify'
import { FormActions } from '../../components/FormActions/FormActions'
import { FormField } from '../../components/FormField/FormField'
import { FormLayout } from '../../components/FormLayout/FormLayout'
import { FormRow } from '../../components/FormRow/FormRow'
import { PageHeader } from '../../components/PageHeader/PageHeader'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { TimePicker } from '../../components/TimePicker/TimePicker'
import { WorkStyleFormModal, WORK_TIME_SYSTEM_OPTIONS } from '../../components/WorkStyleFormModal/WorkStyleFormModal'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { YearMonthPicker } from '../../components/YearMonthPicker/YearMonthPicker'
import {
  useAssignUserWorkStyleForMonth,
  useUserWorkStyleMonthlyAssignments,
} from '../../hooks/useUserWorkStyleMonthlyAssignments'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { isWorkStyleOnboardingDirty, type WorkStyleOnboardingValues } from './workStyleOnboardingDirty'
import { useGenerateShiftAssignments } from '../../hooks/useEmployeeShiftAssignments'
import { useUser } from '../../hooks/useUsers'
import { useCreateDefaultWorkStyle, useSetDefaultWorkStyle, useWorkStyles } from '../../hooks/useWorkStyles'
import type { WorkStyle } from '../../api/types'
import { isValidPageParam } from '../../utils/searchParamValidators'
import { fieldErrors } from '../../utils/fieldErrors'

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

  // 保存に成功したら比べる元を「送った値」にする(再取得を待つ間に変更ありと誤判定しない)。
  const [baseValues, setBaseValues] = useState<WorkStyleOnboardingValues>({
    name: STANDARD_WORK_STYLE_DEFAULTS.name,
    startTime: STANDARD_WORK_STYLE_DEFAULTS.default_start_time,
    endTime: STANDARD_WORK_STYLE_DEFAULTS.default_end_time,
    breakMinutes: String(STANDARD_WORK_STYLE_DEFAULTS.default_break_minutes),
  })
  const currentValues: WorkStyleOnboardingValues = { name, startTime, endTime, breakMinutes }
  const { confirmDiscard } = useUnsavedChangesGuard(isEditing && isWorkStyleOnboardingDirty(baseValues, currentValues))

  const hasDefault = (workStyles ?? []).some((style) => style.is_default)
  if (hasDefault) return null

  // 内容を変更するときだけ入力欄を描く。「この設定で始める」(入力なし)のエラーは今のまま出す。
  const { of, rest } = fieldErrors(
    isEditing ? createDefault.error : null,
    ['name', 'default_break_minutes', 'default_start_time', 'default_end_time'] as const,
  )

  const handleStart = () => {
    createDefault.mutate({}, { onSuccess: () => notify.success('一般的な勤務設定を作成しました') })
  }

  const handleSaveEdited = () => {
    const sentValues = currentValues
    createDefault.mutate(
      {
        name,
        default_start_time: startTime,
        default_end_time: endTime,
        default_break_minutes: Number(breakMinutes),
      },
      {
        onSuccess: () => {
          setBaseValues(sentValues)
          notify.success('一般的な勤務設定を作成しました')
        },
      },
    )
  }

  return (
    <Card title="一般的な勤務設定を用意しました">
      <FormLayout>
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

          {createDefault.error && <ErrorMessage error={createDefault.error} />}
          <FormActions>
            <Button variant="secondary" onClick={() => setIsEditing(true)}>
              内容を変更する
            </Button>
            <Button isLoading={createDefault.isPending} onClick={handleStart}>
              この設定で始める
            </Button>
          </FormActions>
        </>
      ) : (
        <>
          <div>
            <FormField label="名称" htmlFor="onboarding-work-style-name" required error={of('name')}>
              <Input id="onboarding-work-style-name" value={name} onChange={(e) => setName(e.target.value)} />
            </FormField>

            <FormField
              label="休憩(分)"
              htmlFor="onboarding-work-style-break-minutes"
              required
              error={of('default_break_minutes')}
            >
              <Input
                id="onboarding-work-style-break-minutes"
                type="number"
                min={0}
                value={breakMinutes}
                onChange={(e) => setBreakMinutes(e.target.value)}
              />
            </FormField>

            <FormRow>
              <FormField label="始業時刻" htmlFor="onboarding-work-style-start-time" required error={of('default_start_time')}>
                <TimePicker id="onboarding-work-style-start-time" value={startTime} onChange={(time) => setStartTime(time ?? '')} />
              </FormField>

              <FormField label="終業時刻" htmlFor="onboarding-work-style-end-time" required error={of('default_end_time')}>
                <TimePicker id="onboarding-work-style-end-time" value={endTime} onChange={(time) => setEndTime(time ?? '')} />
              </FormField>
            </FormRow>
          </div>

          {rest != null && <ErrorMessage error={rest} />}
          <FormActions>
            <Button
              variant="secondary"
              onClick={async () => {
                if (await confirmDiscard()) setIsEditing(false)
              }}
            >
              キャンセル
            </Button>
            <Button isLoading={createDefault.isPending} onClick={handleSaveEdited}>
              保存して開始
            </Button>
          </FormActions>
        </>
      )}
      </FormLayout>
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

  const [searchParams, setSearchParams] = useSearchParams()
  const pageParam = searchParams.get('page')
  const page = isValidPageParam(pageParam) ? Number(pageParam) : 1

  useEffect(() => {
    if (pageParam === null || isValidPageParam(pageParam)) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.delete('page')
        return next
      },
      { replace: true },
    )
  }, [pageParam, setSearchParams])

  function setPage(nextPage: number) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (nextPage <= 1) next.delete('page')
        else next.set('page', String(nextPage))
        return next
      },
      { replace: true },
    )
  }
  const [isCreateOpen, setIsCreateOpen] = useState(false)
  const [editingWorkStyle, setEditingWorkStyle] = useState<WorkStyle | null>(null)

  const list = workStyles ?? []
  const lastPage = Math.max(1, Math.ceil(list.length / PAGE_SIZE))
  const currentPage = Math.min(page, lastPage)
  const pageItems = list.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  return (
    <Card
      title="勤務形態の一覧"
      actions={
        <Button onClick={() => setIsCreateOpen(true)}>新規作成</Button>
      }
    >
      {error && <ErrorMessage error={error} fallback="勤務形態の取得に失敗しました。" />}
      {setDefaultWorkStyle.error && <ErrorMessage error={setDefaultWorkStyle.error} />}

      {isLoading ? (
        <LoadingState />
      ) : list.length === 0 ? (
        <EmptyState
          title="勤務形態はまだありません。"
          description="「新規作成」から勤務形態を作成できます。"
          action={<Button onClick={() => setIsCreateOpen(true)}>新規作成</Button>}
        />
      ) : (
        <>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="whitespace-normal min-w-48">名称</TableHead>
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
                  <TableCell className="whitespace-normal min-w-48 font-medium text-foreground">{style.name}</TableCell>
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
                          onClick={() =>
                            setDefaultWorkStyle.mutate(style.id, {
                              onSuccess: () => notify.success(`「${style.name}」をデフォルトに設定しました`),
                            })
                          }
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

  const monthlyFields = ['user_id', 'work_style_id', 'year_month', 'from', 'to'] as const
  const assignErrors = fieldErrors(assignForMonth.error, monthlyFields)
  // 項目の下には割当のエラーだけを出す。生成のエラーは保存のボタンの位置に、前置きの文とともに出す。
  const errorOf = (key: (typeof monthlyFields)[number]) => assignErrors.of(key)

  const currentAssignment = history?.find((assignment) => assignment.year_month === yearMonth)
  const selectedWorkStyle = workStyles?.find((style) => style.id === workStyleId)

  // 生成の結果(エラー)は次の操作で消す。生成中は始めたばかりの結果を捨てないため呼ばない。
  const resetGenerateResult = () => {
    if (!generateShifts.isPending) generateShifts.reset()
  }

  const handleUserChange = (userId: string | undefined) => {
    resetGenerateResult()
    setTargetUserId(userId)
    setIsConfirming(false)
  }

  const handleYearMonthChange = (value: string) => {
    resetGenerateResult()
    setYearMonth(value)
    setIsConfirming(false)
  }

  const handleWorkStyleChange = (value: string) => {
    resetGenerateResult()
    setWorkStyleId(value)
    setIsConfirming(false)
  }

  const handleSave = () => {
    if (!targetUserId || !yearMonth || !workStyleId) return
    resetGenerateResult()
    assignForMonth.mutate(
      { user_id: targetUserId, year_month: yearMonth, work_style_id: workStyleId },
      {
        onSuccess: () => {
          notify.success(`${targetUser?.name ?? '対象社員'}さんの${yearMonth}の働き方を設定しました`)
          if (autoGenerateShifts) {
            const { from, to } = monthBoundaries(yearMonth)
            generateShifts.mutate(
              { user_id: targetUserId, work_style_id: workStyleId, from, to },
              { onSuccess: () => notify.success('勤務予定を生成しました') },
            )
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
      <FormLayout>
      <div>
        <FormField label="働き方の対象社員" htmlFor="monthly-work-style-user" required error={errorOf('user_id')}>
          <UserPicker id="monthly-work-style-user" value={targetUserId} onChange={handleUserChange} />
        </FormField>

        <FormField
          label="対象年月"
          htmlFor="monthly-work-style-year-month"
          required
          error={errorOf('year_month') ?? errorOf('from') ?? errorOf('to')}
        >
          <YearMonthPicker
            id="monthly-work-style-year-month"
            value={yearMonth || undefined}
            onChange={(value) => handleYearMonthChange(value ?? '')}
          />
        </FormField>

        <FormField label="働き方" htmlFor="monthly-work-style-select" required error={errorOf('work_style_id')}>
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

      {generateShifts.isPending && (
        <p className="mb-4 text-sm text-muted-foreground">勤務予定を生成しています。</p>
      )}
      {generateShifts.error != null && (
        <div className="mb-4">
          <p className="mb-2 text-sm text-destructive">働き方は保存しましたが、勤務予定の自動生成に失敗しました。</p>
          <ErrorMessage error={generateShifts.error} />
        </div>
      )}

      {!isConfirming ? (
        <FormActions>
          <Button disabled={!targetUserId || !yearMonth || !workStyleId} onClick={() => setIsConfirming(true)}>
            変更内容を確認する
          </Button>
        </FormActions>
      ) : (
        <>
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

        </div>
        {assignErrors.rest != null && <ErrorMessage error={assignErrors.rest} />}
        <FormActions>
          <Button variant="secondary" onClick={() => setIsConfirming(false)}>
            キャンセル
          </Button>
          <Button isLoading={assignForMonth.isPending || generateShifts.isPending} onClick={handleSave}>
            この内容で保存
          </Button>
        </FormActions>
        </>
      )}
      </FormLayout>

      {targetUserId !== undefined && (
        <div className="mt-5 border-t border-border pt-4">
          <h3 className="mb-2 text-sm font-semibold text-foreground">割当履歴</h3>
          {isLoadingHistory ? (
            <LoadingState />
          ) : (history ?? []).length === 0 ? (
            <EmptyState title="まだ割り当てられていません。" />
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
      <PageHeader description="勤務形態(所定労働時間・労働時間制)を管理し、ユーザーの月ごとの働き方を割り当てます。" />
      <WorkStyleOnboardingCard />
      <WorkStyleListCard />
      <MonthlyWorkStyleAssignmentCard />
    </div>
  )
}
