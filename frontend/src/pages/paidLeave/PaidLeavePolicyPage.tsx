import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useForwardState } from '../../navigation/backState'
import { Badge } from '../../components/Badge/Badge'
import { BulkActionDialog, type BulkActionResult } from '../../components/BulkActionDialog/BulkActionDialog'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { GrantTargetPicker, type GrantTargetMode } from '../../components/GrantTargetPicker/GrantTargetPicker'
import { LeaveUsageList } from '../../components/LeaveUsageList/LeaveUsageList'
import { PageHeader } from '../../components/PageHeader/PageHeader'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PaidLeaveGrantPolicyMatrix } from '../../components/PaidLeaveGrantPolicyMatrix/PaidLeaveGrantPolicyMatrix'
import { PaidLeaveGrantCycleTypeField } from '../../components/PaidLeaveGrantCycleTypeField/PaidLeaveGrantCycleTypeField'
import { PaidLeaveGrantRulePreview } from '../../components/PaidLeaveGrantRulePreview/PaidLeaveGrantRulePreview'
import { PaidLeaveGrantStepsEditor } from '../../components/PaidLeaveGrantStepsEditor/PaidLeaveGrantStepsEditor'
import { ChangeGrantDaysButton } from '../../components/ChangeGrantDaysButton/ChangeGrantDaysButton'
import { RevokeGrantButton } from '../../components/RevokeGrantButton/RevokeGrantButton'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '../../components/ui/sheet'
import { useQueryClient } from '@tanstack/react-query'
import {
  useAdminCancelPaidLeaveRequest,
  useCreatePaidLeaveGrantPolicyVersion,
  useCreatePaidLeaveGrantRule,
  useCreatePaidLeaveProportionalGrantPolicyVersion,
  useDeletePaidLeaveGrantRule,
  useBulkGrantPaidLeave,
  usePaidLeaveGrantPolicies,
  usePaidLeaveGrantRules,
  usePaidLeaveGrantRuleTargetUsers,
  usePaidLeaveGrantsForUser,
  usePaidLeaveScheduleEntries,
  usePaidLeaveUsagesForUser,
  useChangePaidLeaveGrantedDays,
  useRevokePaidLeaveGrant,
} from '../../hooks/usePaidLeave'
import { useUpdatePaidLeaveAutoGrantEnabled } from '../../hooks/useUsers'
import { useWorkCalendars } from '../../hooks/useWorkCalendars'
import type {
  PaidLeaveGrantCycleType,
  PaidLeaveGrantPolicyStep,
  PaidLeaveGrantRuleStep,
  PaidLeaveProportionalGrantPolicyStep,
} from '../../api/types'
import {
  GRANT_CYCLE_TYPE_LABELS,
  DEFAULT_FISCAL_YEAR_START,
  pickDefaultFiscalYearStart,
  statutoryGrantSteps,
} from '../../lib/paidLeaveGrantRule'
import { notify } from '../../lib/notify'
import { fieldErrors } from '../../utils/fieldErrors'
import { isValidUuidParam } from '../../utils/searchParamValidators'

const STATUTORY_POLICY_WARNING =
  'この表の変更は法令に基づく設定です。保存前に社労士等の専門家に確認してください。'

const WEEKLY_CATEGORY_OPTIONS: Array<{ value: '4' | '3' | '2' | '1'; label: string }> = [
  { value: '4', label: '週4日' },
  { value: '3', label: '週3日' },
  { value: '2', label: '週2日' },
  { value: '1', label: '週1日' },
]

/**
 * 法定通常付与表(継続勤務月数→付与日数)の新バージョン作成フォーム(spec.md Feature 5)。
 * 現行versionの行で初期化し、行の追加・削除・編集ができる。クライアント側バリデーションは
 * バックエンドと同じ規則を簡易チェックするが、最終的な正はバックエンド側。
 */
/** 付与表の新バージョン作成(`rows.{i}.*`は行の項目の下、`rows`は行の一覧の下に出す)の422のキー。 */
const NORMAL_POLICY_ERROR_KEYS = ['rows', 'rows.*', 'rows.*.continuous_service_months', 'rows.*.grant_days'] as const
const PROPORTIONAL_POLICY_ERROR_KEYS = [
  'rows',
  'rows.*',
  'rows.*.weekly_scheduled_days_category',
  'rows.*.continuous_service_months',
  'rows.*.grant_days',
] as const

/** 付与ルールの作成で項目の下に出す422のキー。`steps.{i}.*`は付与日数の表の行の下に出す。 */
const RULE_ERROR_KEYS = [
  'name',
  'min_attendance_rate',
  'first_grant_after_months',
  'grant_cycle_months',
  'grant_cycle_type',
  'is_active',
  'steps.*.grant_category',
  'steps.*.continuous_service_months',
  'steps.*.grant_days',
] as const
const RULE_STEP_ROW_KEYS = ['grant_category', 'continuous_service_months', 'grant_days'] as const

function PaidLeaveGrantPolicyEditSheet({
  open,
  onOpenChange,
  initialRows,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  initialRows: PaidLeaveGrantPolicyStep[]
}) {
  const [rows, setRows] = useState<PaidLeaveGrantPolicyStep[]>(initialRows)
  const createVersion = useCreatePaidLeaveGrantPolicyVersion()
  const { ofRow, ofAny, rest } = fieldErrors(createVersion.error, NORMAL_POLICY_ERROR_KEYS)

  useEffect(() => {
    if (open) {
      setRows(initialRows)
      createVersion.reset()
    }
    // initialRowsは開くたびに再同期すれば十分なため、depsには含めない。
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const months = rows.map((row) => row.continuous_service_months)
  const hasDuplicateMonths = new Set(months).size !== months.length
  const hasInvalidRow = rows.some(
    (row) =>
      row.continuous_service_months === undefined ||
      row.continuous_service_months === null ||
      row.continuous_service_months < 0 ||
      row.grant_days === undefined ||
      row.grant_days === null ||
      row.grant_days < 0,
  )
  const canSave = rows.length > 0 && !hasDuplicateMonths && !hasInvalidRow

  const updateRow = (index: number, patch: Partial<PaidLeaveGrantPolicyStep>) => {
    setRows((prev) => prev.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  }

  const handleAddRow = () => {
    setRows((prev) => [...prev, { continuous_service_months: 0, grant_days: 0 }])
  }

  const handleRemoveRow = (index: number) => {
    setRows((prev) => prev.filter((_, i) => i !== index))
  }

  const handleSave = () => {
    createVersion.mutate(rows, {
      onSuccess: () => {
        notify.success('通常付与表の新しいバージョンを保存しました')
        onOpenChange(false)
      },
    })
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="w-full max-w-md sm:max-w-lg">
        <SheetHeader>
          <SheetTitle>通常付与表の新しいバージョンを作成</SheetTitle>
        </SheetHeader>

        <div className="mt-4 rounded-md border border-warning/40 bg-warning/10 p-3 text-sm text-foreground">
          {STATUTORY_POLICY_WARNING}
        </div>

        {rest != null && <ErrorMessage error={rest} fallback="保存に失敗しました。" />}
        {hasDuplicateMonths && <p className="mt-2 text-xs text-destructive">継続勤務月数が重複しています。</p>}

        <ul className="mt-4 flex flex-col gap-3">
          {rows.map((row, index) => (
            <li key={index} className="flex flex-wrap items-end gap-3">
              <FormField
                label="継続勤務(か月)"
                htmlFor={`normal-policy-months-${index}`}
                error={ofRow('rows', index, 'continuous_service_months')}
              >
                <Input
                  id={`normal-policy-months-${index}`}
                  type="number"
                  value={row.continuous_service_months}
                  onChange={(e) => updateRow(index, { continuous_service_months: Number(e.target.value) })}
                />
              </FormField>
              <FormField label="付与日数" htmlFor={`normal-policy-days-${index}`} error={ofRow('rows', index, 'grant_days')}>
                <Input
                  id={`normal-policy-days-${index}`}
                  type="number"
                  value={row.grant_days}
                  onChange={(e) => updateRow(index, { grant_days: Number(e.target.value) })}
                />
              </FormField>
              <Button variant="danger" size="sm" onClick={() => handleRemoveRow(index)}>
                削除
              </Button>
            </li>
          ))}
        </ul>

        {ofAny('rows').map((message) => (
          <p key={message} className="mt-2 text-xs text-destructive">
            {message}
          </p>
        ))}

        <Button variant="secondary" size="sm" className="mt-3" onClick={handleAddRow}>
          行を追加
        </Button>

        <div className="mt-6 flex items-center gap-3">
          <Button isLoading={createVersion.isPending} disabled={!canSave} onClick={handleSave}>
            新しいバージョンを保存
          </Button>
        </div>
      </SheetContent>
    </Sheet>
  )
}

/**
 * 法定比例付与表(週所定労働日数区分×継続勤務月数→付与日数)の新バージョン作成フォーム
 * (spec.md Feature 5)。
 */
function PaidLeaveProportionalGrantPolicyEditSheet({
  open,
  onOpenChange,
  initialRows,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  initialRows: PaidLeaveProportionalGrantPolicyStep[]
}) {
  const [rows, setRows] = useState<PaidLeaveProportionalGrantPolicyStep[]>(initialRows)
  const createVersion = useCreatePaidLeaveProportionalGrantPolicyVersion()
  const { ofRow, ofAny, rest } = fieldErrors(createVersion.error, PROPORTIONAL_POLICY_ERROR_KEYS)

  useEffect(() => {
    if (open) {
      setRows(initialRows)
      createVersion.reset()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const keys = rows.map((row) => `${row.weekly_scheduled_days_category}-${row.continuous_service_months}`)
  const hasDuplicateKeys = new Set(keys).size !== keys.length
  const hasInvalidRow = rows.some(
    (row) =>
      !['1', '2', '3', '4'].includes(row.weekly_scheduled_days_category) ||
      row.continuous_service_months === undefined ||
      row.continuous_service_months === null ||
      row.continuous_service_months < 0 ||
      row.grant_days === undefined ||
      row.grant_days === null ||
      row.grant_days < 0,
  )
  const canSave = rows.length > 0 && !hasDuplicateKeys && !hasInvalidRow

  const updateRow = (index: number, patch: Partial<PaidLeaveProportionalGrantPolicyStep>) => {
    setRows((prev) => prev.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  }

  const handleAddRow = () => {
    setRows((prev) => [...prev, { weekly_scheduled_days_category: '4', continuous_service_months: 0, grant_days: 0 }])
  }

  const handleRemoveRow = (index: number) => {
    setRows((prev) => prev.filter((_, i) => i !== index))
  }

  const handleSave = () => {
    createVersion.mutate(rows, {
      onSuccess: () => {
        notify.success('比例付与表の新しいバージョンを保存しました')
        onOpenChange(false)
      },
    })
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="w-full max-w-md sm:max-w-lg">
        <SheetHeader>
          <SheetTitle>比例付与表の新しいバージョンを作成</SheetTitle>
        </SheetHeader>

        <div className="mt-4 rounded-md border border-warning/40 bg-warning/10 p-3 text-sm text-foreground">
          {STATUTORY_POLICY_WARNING}
        </div>

        {rest != null && <ErrorMessage error={rest} fallback="保存に失敗しました。" />}
        {hasDuplicateKeys && (
          <p className="mt-2 text-xs text-destructive">週所定労働日数区分と継続勤務月数の組み合わせが重複しています。</p>
        )}

        <ul className="mt-4 flex flex-col gap-3">
          {rows.map((row, index) => (
            <li key={index} className="flex flex-wrap items-end gap-3">
              <FormField
                label="週所定労働日数"
                htmlFor={`proportional-policy-category-${index}`}
                error={ofRow('rows', index, 'weekly_scheduled_days_category')}
              >
                <NativeSelect
                  id={`proportional-policy-category-${index}`}
                  value={row.weekly_scheduled_days_category}
                  onChange={(e) =>
                    updateRow(index, { weekly_scheduled_days_category: e.target.value as '4' | '3' | '2' | '1' })
                  }
                >
                  {WEEKLY_CATEGORY_OPTIONS.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </NativeSelect>
              </FormField>
              <FormField
                label="継続勤務(か月)"
                htmlFor={`proportional-policy-months-${index}`}
                error={ofRow('rows', index, 'continuous_service_months')}
              >
                <Input
                  id={`proportional-policy-months-${index}`}
                  type="number"
                  value={row.continuous_service_months}
                  onChange={(e) => updateRow(index, { continuous_service_months: Number(e.target.value) })}
                />
              </FormField>
              <FormField
                label="付与日数"
                htmlFor={`proportional-policy-days-${index}`}
                error={ofRow('rows', index, 'grant_days')}
              >
                <Input
                  id={`proportional-policy-days-${index}`}
                  type="number"
                  value={row.grant_days}
                  onChange={(e) => updateRow(index, { grant_days: Number(e.target.value) })}
                />
              </FormField>
              <Button variant="danger" size="sm" onClick={() => handleRemoveRow(index)}>
                削除
              </Button>
            </li>
          ))}
        </ul>

        {ofAny('rows').map((message) => (
          <p key={message} className="mt-2 text-xs text-destructive">
            {message}
          </p>
        ))}

        <Button variant="secondary" size="sm" className="mt-3" onClick={handleAddRow}>
          行を追加
        </Button>

        <div className="mt-6 flex items-center gap-3">
          <Button isLoading={createVersion.isPending} disabled={!canSave} onClick={handleSave}>
            新しいバージョンを保存
          </Button>
        </div>
      </SheetContent>
    </Sheet>
  )
}

/** 付与ルールの対象条件にマッチする社員一覧(展開時のみ取得)。名前で絞り込み・ON/OFF即時反映。 */
function PaidLeaveGrantRuleTargetUsersSection({ ruleId }: { ruleId: number }) {
  const { data: targetUsers, isLoading, error } = usePaidLeaveGrantRuleTargetUsers(ruleId)
  const updateAutoGrantEnabled = useUpdatePaidLeaveAutoGrantEnabled()
  const queryClient = useQueryClient()
  const [nameFilter, setNameFilter] = useState('')

  const filteredUsers = (targetUsers ?? []).filter((user) => user.name.includes(nameFilter))

  const handleToggle = (user: { id: string; name: string }, enabled: boolean) => {
    updateAutoGrantEnabled.mutate(
      { id: user.id, enabled },
      {
        onSuccess: () => {
          notify.success(`${user.name}の自動付与を${enabled ? '有効' : '無効'}にしました`)
          void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'grant-rules', ruleId, 'target-users'] })
        },
      },
    )
  }

  return (
    <div className="mt-3 rounded-md border border-border p-3">
      {error && <ErrorMessage error={error} fallback="対象社員の取得に失敗しました。" />}

      <FormField label="社員名で絞り込み" htmlFor={`paid-leave-rule-${ruleId}-target-user-filter`}>
        <Input
          id={`paid-leave-rule-${ruleId}-target-user-filter`}
          value={nameFilter}
          onChange={(e) => setNameFilter(e.target.value)}
        />
      </FormField>

      {isLoading ? (
        <LoadingState />
      ) : filteredUsers.length === 0 ? (
        <EmptyState title="対象社員はいません。" />
      ) : (
        <table className="mt-3 w-full text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs font-medium text-muted-foreground">
              <th className="py-1 pr-3">氏名</th>
              <th className="py-1 pr-3">働き方</th>
              <th className="py-1 pr-3">自動付与</th>
            </tr>
          </thead>
          <tbody>
            {filteredUsers.map((user) => (
              <tr key={user.id} className="border-b border-border last:border-0">
                <td className="py-1.5 pr-3 text-foreground">{user.name}</td>
                <td className="py-1.5 pr-3 text-muted-foreground">{user.work_style ?? '-'}</td>
                <td className="py-1.5 pr-3">
                  <label className="flex items-center gap-2">
                    <Checkbox
                      checked={user.paid_leave_auto_grant_enabled}
                      onCheckedChange={(checked) => handleToggle(user, checked === true)}
                      aria-label={`${user.name}の有給自動付与`}
                    />
                    {!user.paid_leave_auto_grant_enabled && <Badge tone="warning">自動付与:無効</Badge>}
                  </label>
                  {updateAutoGrantEnabled.error && updateAutoGrantEnabled.variables?.id === user.id && (
                    <div className="mt-1">
                      <ErrorMessage error={updateAutoGrantEnabled.error} />
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}

/** 無効化済みルールのみ「削除」できる(バックエンドが強制)。有効なルールはボタンを無効化し理由を示す。 */
function DeleteRuleButton({ ruleId, ruleName, isActive }: { ruleId: number; ruleName: string; isActive: boolean }) {
  const deleteRule = useDeletePaidLeaveGrantRule()

  if (isActive) {
    return (
      <div className="flex flex-col items-start gap-1">
        <Button variant="danger" size="sm" disabled>
          削除
        </Button>
        <p className="text-xs text-muted-foreground">有効なルールは削除できません。先に無効化してください。</p>
      </div>
    )
  }

  return (
    <ConfirmActionDialog
      triggerLabel="削除"
      title={`${ruleName}を削除しますか?`}
      description="この操作は元に戻せません。"
      confirmLabel="削除する"
      isPending={deleteRule.isPending}
      error={deleteRule.error}
      onConfirm={async () => {
        await deleteRule.mutateAsync(ruleId)
        notify.success(`${ruleName}を削除しました`)
      }}
    />
  )
}

function PaidLeaveGrantRulesCard() {
  const forwardState = useForwardState()
  const { data: rules, isLoading, error } = usePaidLeaveGrantRules()
  const { data: policies, isLoading: isLoadingPolicies, error: policiesError } = usePaidLeaveGrantPolicies()
  const createRule = useCreatePaidLeaveGrantRule()
  const [expandedRuleIds, setExpandedRuleIds] = useState<Set<number>>(new Set())
  const [isNormalPolicySheetOpen, setIsNormalPolicySheetOpen] = useState(false)
  const [isProportionalPolicySheetOpen, setIsProportionalPolicySheetOpen] = useState(false)

  const toggleExpanded = (ruleId: number) => {
    setExpandedRuleIds((prev) => {
      const next = new Set(prev)
      if (next.has(ruleId)) {
        next.delete(ruleId)
      } else {
        next.add(ruleId)
      }
      return next
    })
  }

  const { data: calendars, isLoading: isLoadingCalendars } = useWorkCalendars()
  const defaultFiscalYearStart = isLoadingCalendars ? undefined : pickDefaultFiscalYearStart(calendars)
  const previewFiscalYearStart = defaultFiscalYearStart ?? DEFAULT_FISCAL_YEAR_START

  const [ruleName, setRuleName] = useState('')
  const [minAttendanceRate, setMinAttendanceRate] = useState('')
  const [firstGrantAfterMonths, setFirstGrantAfterMonths] = useState('')
  const [grantCycleMonths, setGrantCycleMonths] = useState('')
  const [grantCycleType, setGrantCycleType] = useState<PaidLeaveGrantCycleType>('mass_grant_month')
  const [isActive, setIsActive] = useState(true)
  /** 付与日数の表。未編集のあいだは法定表の写し(全区分)を使い、編集したら`stepsOverride`で持つ。 */
  const [stepsOverride, setStepsOverride] = useState<PaidLeaveGrantRuleStep[] | null>(null)
  const steps = stepsOverride ?? (policies ? statutoryGrantSteps(policies) : [])

  const handleCreateRule = () => {
    createRule.mutate(
      {
        name: ruleName,
        min_attendance_rate: minAttendanceRate ? Number(minAttendanceRate) : undefined,
        first_grant_after_months: firstGrantAfterMonths ? Number(firstGrantAfterMonths) : undefined,
        grant_cycle_months: grantCycleMonths ? Number(grantCycleMonths) : undefined,
        grant_cycle_type: grantCycleType,
        is_active: isActive,
        steps: steps.length > 0 ? steps : undefined,
      },
      {
        onSuccess: () => {
          notify.success(`${ruleName}を作成しました`)
          setRuleName('')
          setMinAttendanceRate('')
          setFirstGrantAfterMonths('')
          setGrantCycleMonths('')
          setGrantCycleType('mass_grant_month')
          setIsActive(true)
          setStepsOverride(null)
        },
      },
    )
  }

  const { of, ofRow, rest: createRest } = fieldErrors(createRule.error, RULE_ERROR_KEYS)

  return (
    <Card title="付与ルール">
      {error && <ErrorMessage error={error} fallback="付与ルールの取得に失敗しました。" />}
      {createRest != null && <ErrorMessage error={createRest} />}

      {isLoading ? (
        <LoadingState />
      ) : (rules ?? []).length === 0 ? (
        <EmptyState title="付与ルールはまだありません。" description="ルールを作成すると、対象社員へ自動的に有給が付与されます。" />
      ) : (
        <ul className="mb-5 flex flex-col gap-4">
          {(rules ?? []).map((rule) => (
            <li key={rule.id} className="rounded-md border border-border p-3">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <strong className="text-sm font-semibold text-foreground">{rule.name}</strong>
                  <span className="text-sm text-muted-foreground">{rule.is_active ? '有効' : '無効'}</span>
                  <span className="text-sm text-muted-foreground">{GRANT_CYCLE_TYPE_LABELS[rule.grant_cycle_type]}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Button asChild variant="secondary" size="sm">
                    <Link to={`/admin/paid-leave/rules/${rule.id}/edit`} state={forwardState}>
                      編集
                    </Link>
                  </Button>
                  <DeleteRuleButton ruleId={rule.id} ruleName={rule.name} isActive={rule.is_active} />
                </div>
              </div>

              <div className="mt-2">
                <PaidLeaveGrantRulePreview
                  grantCycleType={rule.grant_cycle_type}
                  fiscalYearStart={previewFiscalYearStart}
                  firstGrantAfterMonths={rule.first_grant_after_months}
                  grantCycleMonths={rule.grant_cycle_months}
                  minAttendanceRate={rule.min_attendance_rate}
                  steps={rule.steps ?? []}
                />
              </div>

              <Button variant="secondary" size="sm" className="mt-3" onClick={() => toggleExpanded(rule.id)}>
                {expandedRuleIds.has(rule.id) ? '対象社員を閉じる' : '対象社員'}
              </Button>
              {expandedRuleIds.has(rule.id) && <PaidLeaveGrantRuleTargetUsersSection ruleId={rule.id} />}
            </li>
          ))}
        </ul>
      )}

      <h3 className="mb-3 text-sm font-semibold text-foreground">新しい付与ルールを作成</h3>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="ルール名" htmlFor="rule-name" required error={of('name')}>
          <Input id="rule-name" value={ruleName} onChange={(e) => setRuleName(e.target.value)} />
        </FormField>

        <FormField label="最低出勤率" htmlFor="rule-min-attendance-rate" error={of('min_attendance_rate')}>
          <Input
            id="rule-min-attendance-rate"
            type="number"
            value={minAttendanceRate}
            onChange={(e) => setMinAttendanceRate(e.target.value)}
          />
        </FormField>

        <FormField
          label="初回付与までの月数"
          htmlFor="rule-first-grant-after-months"
          error={of('first_grant_after_months')}
        >
          <Input
            id="rule-first-grant-after-months"
            type="number"
            value={firstGrantAfterMonths}
            onChange={(e) => setFirstGrantAfterMonths(e.target.value)}
          />
        </FormField>

        <FormField label="付与サイクル(月数)" htmlFor="rule-grant-cycle-months" error={of('grant_cycle_months')}>
          <Input
            id="rule-grant-cycle-months"
            type="number"
            value={grantCycleMonths}
            onChange={(e) => setGrantCycleMonths(e.target.value)}
          />
        </FormField>
      </div>

      <PaidLeaveGrantCycleTypeField
        value={grantCycleType}
        onChange={setGrantCycleType}
        defaultFiscalYearStart={defaultFiscalYearStart}
      />
      {of('grant_cycle_type') && <p className="-mt-2 mb-4 text-xs text-destructive">{of('grant_cycle_type')}</p>}

      <label className="mb-4 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox checked={isActive} onCheckedChange={(checked) => setIsActive(checked === true)} />
        有効
      </label>
      {of('is_active') && <p className="-mt-2 mb-4 text-xs text-destructive">{of('is_active')}</p>}

      <PaidLeaveGrantStepsEditor
        steps={steps}
        onChange={setStepsOverride}
        statutory={policies}
        rowErrors={(index) =>
          RULE_STEP_ROW_KEYS.map((key) => ofRow('steps', index, key)).filter((message): message is string => Boolean(message))
        }
      />

      <div className="flex flex-col items-start gap-1">
        <Button isLoading={createRule.isPending} disabled={!ruleName} onClick={handleCreateRule}>
          ルールを作成
        </Button>
        {!ruleName && <p className="text-xs text-muted-foreground">ルール名を入力してください。</p>}
      </div>

      <div className="mt-8 border-t border-border pt-5">
        <h3 className="mb-3 text-sm font-semibold text-foreground">法定付与日数(参考・自動適用)</h3>
        {policiesError && <ErrorMessage error={policiesError} fallback="法定付与表の取得に失敗しました。" />}
        {isLoadingPolicies ? (
          <LoadingState />
        ) : (
          policies && (
            <PaidLeaveGrantPolicyMatrix
              policies={policies}
              normalAction={
                <Button variant="secondary" size="sm" onClick={() => setIsNormalPolicySheetOpen(true)}>
                  新しいバージョンを作成
                </Button>
              }
              proportionalAction={
                <Button variant="secondary" size="sm" onClick={() => setIsProportionalPolicySheetOpen(true)}>
                  新しいバージョンを作成
                </Button>
              }
            />
          )
        )}
      </div>

      {policies && (
        <PaidLeaveGrantPolicyEditSheet
          open={isNormalPolicySheetOpen}
          onOpenChange={setIsNormalPolicySheetOpen}
          initialRows={policies.normal}
        />
      )}
      {policies && (
        <PaidLeaveProportionalGrantPolicyEditSheet
          open={isProportionalPolicySheetOpen}
          onOpenChange={setIsProportionalPolicySheetOpen}
          initialRows={policies.proportional}
        />
      )}
    </Card>
  )
}

function ManualGrantCard() {
  const [targetIds, setTargetIds] = useState<string[]>([])
  const [targetMode, setTargetMode] = useState<GrantTargetMode>('individual')
  const [targetGroupId, setTargetGroupId] = useState<string | undefined>(undefined)
  const [targetLabels, setTargetLabels] = useState<Record<string, string>>({})
  const [grantedOn, setGrantedOn] = useState('')
  const [expiresOn, setExpiresOn] = useState('')
  const [grantedDays, setGrantedDays] = useState('')
  const [grantReason, setGrantReason] = useState('')
  const [dialogOpen, setDialogOpen] = useState(false)
  // 再送を二重付与にしない識別子。確認ダイアログを開くたびに作り、同じダイアログでの再実行(通信エラー後など)では同じ値を送る。
  const [requestId, setRequestId] = useState(() => crypto.randomUUID())

  const bulkGrant = useBulkGrantPaidLeave()
  const revokeGrant = useRevokePaidLeaveGrant()
  const changeGrantedDays = useChangePaidLeaveGrantedDays()

  const singleTargetUserId = targetMode === 'individual' && targetIds.length === 1 ? targetIds[0] : undefined
  const { data: userGrants, isLoading: isLoadingUserGrants } = usePaidLeaveGrantsForUser(singleTargetUserId ?? '')

  const isGroupTarget = targetMode === 'group'
  const hasTarget = isGroupTarget ? targetGroupId !== undefined && targetIds.length > 0 : targetIds.length > 0
  const targetSummary = isGroupTarget ? 'グループの所属メンバー' : `${targetIds.length}名`

  const openGrantDialog = () => {
    setRequestId(crypto.randomUUID())
    setDialogOpen(true)
  }

  /** 全部か無しか: 一括のAPIを1回呼ぶ。1人でも付与できないと422で何も付与されず、ダイアログが失敗の一覧を出して選択を残す。 */
  async function executeGrant(): Promise<BulkActionResult> {
    const response = await bulkGrant.mutateAsync({
      request_id: requestId,
      ...(isGroupTarget && targetGroupId !== undefined ? { group_id: targetGroupId } : { user_ids: targetIds }),
      granted_on: grantedOn,
      expires_on: expiresOn,
      granted_days: Number(grantedDays),
      grant_reason: grantReason || undefined,
    })
    return { succeededIds: response.data.map((item) => item.user_id), failures: [] }
  }

  const clearForm = () => {
    setGrantedOn('')
    setExpiresOn('')
    setGrantedDays('')
    setGrantReason('')
  }

  return (
    <Card title="手動付与">
      <FormField label="付与対象" htmlFor="grant-target-users" required>
        <GrantTargetPicker
          idPrefix="grant-target"
          onResolvedChange={(ids, mode, labels, groupId) => {
            setTargetMode(mode)
            setTargetIds(ids)
            setTargetLabels(labels)
            setTargetGroupId(groupId)
          }}
        />
      </FormField>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="付与日" htmlFor="grant-granted-on" required>
          <DatePicker id="grant-granted-on" value={grantedOn || undefined} onChange={(date) => setGrantedOn(date ?? '')} />
        </FormField>

        <FormField label="失効日" htmlFor="grant-expires-on" required>
          <DatePicker
            id="grant-expires-on"
            value={expiresOn || undefined}
            defaultDate={grantedOn || undefined}
            onChange={(date) => setExpiresOn(date ?? '')}
          />
        </FormField>

        <FormField label="付与日数" htmlFor="grant-granted-days" required>
          <Input
            id="grant-granted-days"
            type="number"
            value={grantedDays}
            onChange={(e) => setGrantedDays(e.target.value)}
          />
        </FormField>

        <FormField label="付与理由" htmlFor="grant-reason">
          <Input id="grant-reason" value={grantReason} onChange={(e) => setGrantReason(e.target.value)} />
        </FormField>
      </div>

      <div className="mt-4 flex flex-col items-start gap-1">
        <Button disabled={!hasTarget || !grantedOn || !expiresOn || !grantedDays} onClick={openGrantDialog}>
          {isGroupTarget ? 'グループの所属メンバーに付与する' : `${targetIds.length}名に付与する`}
        </Button>
        {!hasTarget ? (
          <p className="text-xs text-muted-foreground">付与対象を選択してください。</p>
        ) : !grantedOn ? (
          <p className="text-xs text-muted-foreground">付与日を選択してください。</p>
        ) : !expiresOn ? (
          <p className="text-xs text-muted-foreground">失効日を選択してください。</p>
        ) : !grantedDays ? (
          <p className="text-xs text-muted-foreground">付与日数を入力してください。</p>
        ) : null}
      </div>

      <BulkActionDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        title={`${targetSummary}に有給を付与しますか?`}
        summary={`${targetSummary}に、付与日${grantedOn}・失効日${expiresOn}で${grantedDays}日の有給を付与します。`}
        description={
          isGroupTarget
            ? '付与は、実行した時点のグループの直接の所属メンバー(退職者を除く)に行います。1人でも付与できないと、誰にも付与しません。'
            : '1人でも付与できないと、誰にも付与しません。'
        }
        confirmLabel="付与する"
        resultTitle="付与の結果"
        resultMode="allOrNothing"
        itemLabels={targetLabels}
        onExecute={executeGrant}
        onSucceeded={clearForm}
        successMessage={(count) => `${count}名に有給を付与しました`}
      />

      {singleTargetUserId !== undefined && (
        <div className="mt-6">
          <h3 className="mb-2 text-sm font-semibold text-foreground">対象社員の有給付与状況</h3>
          {isLoadingUserGrants ? (
            <LoadingState />
          ) : (userGrants ?? []).length === 0 ? (
            <EmptyState title="有給の付与はまだありません。" description="上のフォームから付与すると、ここに一覧が表示されます。" />
          ) : (
            <ul className="divide-y divide-border">
              {(userGrants ?? []).map((grant) => (
                <li key={grant.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm text-foreground">
                  <span>
                    {grant.granted_on} 〜 {grant.expires_on} / 残{grant.remaining_days}日
                    {grant.status === 'revoked' && (
                      <Badge tone="neutral">取消済み{grant.revoke_reason ? `(${grant.revoke_reason})` : ''}</Badge>
                    )}
                  </span>
                  {grant.status === 'active' && (
                    <div className="flex items-center gap-2">
                    {/* 変更・取消できるのは最新の非取消付与だけ(APIの is_latest。集約の判定と同じ) */}
                    <ChangeGrantDaysButton
                      id={`change-days-${grant.id}`}
                      grantedOn={grant.granted_on}
                      currentDays={grant.granted_days}
                      usedDays={grant.used_days}
                      isPending={changeGrantedDays.isPending}
                      error={changeGrantedDays.error}
                      onChange={(days, reason) =>
                        changeGrantedDays.mutateAsync({ grantId: grant.id, grantedDays: days, reason })
                      }
                      disabled={!grant.is_latest}
                      disabledReason="最新の付与のみ変更できます。"
                    />
                    <RevokeGrantButton
                      id={`revoke-reason-${grant.id}`}
                      grantedOn={grant.granted_on}
                      title="有給付与を取り消しますか?"
                      description={`${grant.granted_on}付与分(${grant.granted_days}日)を取り消します。この操作は元に戻せません。${
                        grant.used_days > 0
                          ? `消化済みの${grant.used_days}日分は他の付与へ付け替えます。付け替え先が足りない分は残高不足(未充当)になります。`
                          : ''
                      }`}
                      isPending={revokeGrant.isPending}
                      error={revokeGrant.error}
                      onRevoke={(reason) => revokeGrant.mutateAsync({ grantId: grant.id, reason })}
                      disabled={!grant.is_latest}
                      disabledReason="最新の付与のみ取り消せます。"
                    />
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Card>
  )
}

function PaidLeaveUsageCard() {
  const [searchParams, setSearchParams] = useSearchParams()
  const userIdParam = searchParams.get('userId')
  const userId = isValidUuidParam(userIdParam) ? (userIdParam ?? undefined) : undefined
  const { data, isLoading, error } = usePaidLeaveUsagesForUser(userId ?? '')
  const adminCancel = useAdminCancelPaidLeaveRequest(userId ?? '')

  useEffect(() => {
    if (userIdParam === null || isValidUuidParam(userIdParam)) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.delete('userId')
        return next
      },
      { replace: true },
    )
  }, [userIdParam, setSearchParams])

  const handleUserChange = (value: string | undefined) => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (value) {
          next.set('userId', value)
        } else {
          next.delete('userId')
        }
        return next
      },
      { replace: true },
    )
  }

  const isEmpty = userId !== undefined && !isLoading && !error && (data?.length ?? 0) === 0

  return (
    <Card title="使用状況">
      <div className="max-w-sm">
        <FormField label="対象社員" htmlFor="paid-leave-usage-user">
          <UserPicker id="paid-leave-usage-user" value={userId} onChange={handleUserChange} />
        </FormField>
      </div>

      {userId === undefined ? (
        <EmptyState title="対象社員を選択してください。" description="社員を選ぶと、その社員の有給使用状況を確認できます。" />
      ) : isEmpty ? (
        <EmptyState
          title="有給の使用状況はまだありません。"
          description="対象社員が有給を消化すると、ここに消化記録が表示されます。"
          action={
            <Button variant="secondary" onClick={() => handleUserChange(undefined)}>
              社員選択をクリア
            </Button>
          }
        />
      ) : (
        <LeaveUsageList
          usages={data?.map((usage) => ({
            id: usage.id,
            usedOn: usage.used_on,
            usedDays: usage.used_days,
            usedMinutes: usage.used_minutes,
            usageType: usage.usage_type,
            requestStatus: usage.request_status,
            requestId: usage.paid_leave_request_id,
          }))}
          isLoading={isLoading}
          error={error}
          errorFallback="有給の使用状況の取得に失敗しました。"
          onCancelRequest={async (requestId) => {
            await adminCancel.mutateAsync(requestId)
            notify.success('有給申請を取り消しました')
          }}
          isCancelling={adminCancel.isPending}
          cancelError={adminCancel.error}
        />
      )}
    </Card>
  )
}

/**
 * 「付与予定を確認」導線(spec.md論点1・8)。付与ルールカードのactionsに置き、付与予定画面
 * (`/admin/paid-leave/schedule`)へ遷移する。付与予定日を過ぎて未付与の件数(overdue)が
 * 1件以上あれば件数のバッジを併記し、アクセシブルネームにも件数を含める。
 * 件数の取得に失敗してもリンクは出す(導線を止めない)。
 */
function PaidLeaveScheduleLink() {
  const forwardState = useForwardState()
  const { data: overdueEntries } = usePaidLeaveScheduleEntries({ filter: 'overdue' })
  const overdueCount = overdueEntries?.length ?? 0

  return (
    <Button asChild variant="secondary">
      <Link
        to="/admin/paid-leave/schedule"
        state={forwardState}
        aria-label={overdueCount > 0 ? `付与予定を確認(付与予定日超過 ${overdueCount}件)` : undefined}
      >
        付与予定を確認
        {overdueCount > 0 && <Badge tone="warning">{overdueCount}件</Badge>}
      </Link>
    </Button>
  )
}

/**
 * UC-P002 / UC-P007: 有給付与ルールの設定・手動付与・対象社員の使用状況確認・
 * 付与取消/申請取消を1画面にまとめて管理者・人事向けに提供する有給休暇画面。
 * 付与予定(UC-P013〜P015)は独立ナビ項目を持たず、画面見出しの「付与予定を確認」
 * ボタンから付与予定画面へ遷移する。
 */
export function PaidLeavePolicyPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        description="有給の付与ルールを設定し、手動付与や使用状況の確認ができます。"
        actions={<PaidLeaveScheduleLink />}
      />
      <PaidLeaveGrantRulesCard />
      <ManualGrantCard />
      <PaidLeaveUsageCard />
    </div>
  )
}
