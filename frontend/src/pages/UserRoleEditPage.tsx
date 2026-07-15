import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Checkbox } from '../components/ui/checkbox'
import { Input } from '../components/ui/input'
import { NativeSelect } from '../components/ui/native-select'
import { useRoles } from '../hooks/useRoles'
import {
  useAssignUserWorkStyleForMonth,
  useRemoveUserWorkStyleMonthlyAssignment,
  useUserWorkStyleMonthlyAssignments,
} from '../hooks/useUserWorkStyleMonthlyAssignments'
import { useUpdateUserHireDate, useUpdateUserRoles, useUpdateUserTerminationDate, useUser } from '../hooks/useUsers'
import { useWorkStyles } from '../hooks/useWorkStyles'
import { formatDate } from '../utils/weekDates'

type WorkStyleMode = 'default' | 'specify'

/**
 * UC-M001: ユーザーに付与する権限(ロール)を編集する。
 * UC-P002: 有給の自動付与に使う入社日を設定する。
 * 指示書 13章: 会社のデフォルトを使用するか、別の働き方を指定するかを選択する。
 */
export function UserRoleEditPage() {
  const { id } = useParams<{ id: string }>()
  const userId = Number(id)
  const { data: user, isLoading: isLoadingUser, error: userError } = useUser(userId)
  const { data: roles, isLoading: isLoadingRoles, error: rolesError } = useRoles()
  const { data: workStyles } = useWorkStyles()
  const { data: workStyleHistory } = useUserWorkStyleMonthlyAssignments(userId)

  const updateRoles = useUpdateUserRoles()
  const updateHireDate = useUpdateUserHireDate()
  const updateTerminationDate = useUpdateUserTerminationDate()
  const assignWorkStyleForMonth = useAssignUserWorkStyleForMonth()
  const removeWorkStyleAssignment = useRemoveUserWorkStyleMonthlyAssignment()

  const [selectedCodes, setSelectedCodes] = useState<string[]>([])
  const [hireDate, setHireDate] = useState('')
  const [terminationDate, setTerminationDate] = useState('')
  const [isInitialized, setIsInitialized] = useState(false)

  const currentYearMonth = formatDate(new Date()).slice(0, 7)
  const currentAssignment = workStyleHistory?.find((assignment) => assignment.year_month === currentYearMonth)
  const defaultWorkStyle = workStyles?.find((style) => style.is_default)

  const [workStyleMode, setWorkStyleMode] = useState<WorkStyleMode>('default')
  const [selectedWorkStyleId, setSelectedWorkStyleId] = useState('')

  useEffect(() => {
    if (user && !isInitialized) {
      setSelectedCodes(user.roles ?? [])
      setHireDate(user.hire_date ?? '')
      setTerminationDate(user.termination_date ?? '')
      setIsInitialized(true)
    }
  }, [user, isInitialized])

  useEffect(() => {
    if (currentAssignment) {
      setWorkStyleMode('specify')
      setSelectedWorkStyleId(String(currentAssignment.work_style_id))
    } else {
      setWorkStyleMode('default')
      setSelectedWorkStyleId('')
    }
  }, [currentAssignment])

  if (isLoadingUser || isLoadingRoles) return <LoadingState />
  if (userError) return <ErrorMessage error={userError} fallback="ユーザーの取得に失敗しました。" />
  if (rolesError) return <ErrorMessage error={rolesError} fallback="権限一覧の取得に失敗しました。" />
  if (!user) return null

  const handleSaveWorkStyle = () => {
    if (workStyleMode === 'default') {
      if (currentAssignment) {
        removeWorkStyleAssignment.mutate({ id: currentAssignment.id, userId })
      }
      return
    }
    if (!selectedWorkStyleId) return
    assignWorkStyleForMonth.mutate({
      user_id: userId,
      year_month: currentYearMonth,
      work_style_id: Number(selectedWorkStyleId),
    })
  }

  const toggleRole = (code: string) => {
    setSelectedCodes((prev) => (prev.includes(code) ? prev.filter((c) => c !== code) : [...prev, code]))
  }

  return (
    <Card title={`${user.name}の権限設定`}>
      {updateRoles.error && <ErrorMessage error={updateRoles.error} />}
      {updateRoles.isSuccess && <Badge tone="success">保存しました</Badge>}

      <dl className="mb-4 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="font-medium text-muted-foreground">メールアドレス</dt>
        <dd className="text-foreground">{user.email}</dd>
        <dt className="font-medium text-muted-foreground">部署</dt>
        <dd className="text-foreground">{user.department ?? '-'}</dd>
        <dt className="font-medium text-muted-foreground">役職</dt>
        <dd className="text-foreground">{user.job_title ?? '-'}</dd>
      </dl>

      <ul className="mb-4 divide-y divide-border">
        {roles?.map((role) => (
          <li key={role.code} className="py-2">
            <label className="flex items-center gap-2 text-sm text-foreground">
              <Checkbox checked={selectedCodes.includes(role.code)} onCheckedChange={() => toggleRole(role.code)} />
              {role.name}
            </label>
          </li>
        ))}
      </ul>

      <div className="flex gap-3">
        <Button
          isLoading={updateRoles.isPending}
          onClick={() => updateRoles.mutate({ id: userId, roleCodes: selectedCodes })}
        >
          保存する
        </Button>
      </div>

      <div className="mt-6 border-t border-border pt-4">
        {updateHireDate.error && <ErrorMessage error={updateHireDate.error} />}
        {updateTerminationDate.error && <ErrorMessage error={updateTerminationDate.error} />}
        {updateHireDate.isSuccess && <Badge tone="success">保存しました</Badge>}
        {updateTerminationDate.isSuccess && <Badge tone="success">保存しました</Badge>}

        <div className="grid gap-3 sm:grid-cols-2">
          <FormField label="入社日(有給の自動付与に使用)" htmlFor="user-role-edit-hire-date">
            <Input
              id="user-role-edit-hire-date"
              type="date"
              value={hireDate}
              onChange={(e) => setHireDate(e.target.value)}
            />
          </FormField>
          <FormField label="退社日(未設定なら在籍中)" htmlFor="user-role-edit-termination-date">
            <Input
              id="user-role-edit-termination-date"
              type="date"
              min={hireDate || undefined}
              value={terminationDate}
              onChange={(e) => setTerminationDate(e.target.value)}
            />
          </FormField>
        </div>

        <div className="flex gap-2">
          <Button
            variant="secondary"
            isLoading={updateHireDate.isPending}
            disabled={!hireDate}
            onClick={() => updateHireDate.mutate({ id: userId, hireDate })}
          >
            入社日を保存する
          </Button>
          <Button
            variant="secondary"
            isLoading={updateTerminationDate.isPending}
            onClick={() => updateTerminationDate.mutate({ id: userId, terminationDate: terminationDate || null })}
          >
            退社日を保存する
          </Button>
        </div>
      </div>

      <div className="mt-6 border-t border-border pt-4">
        {assignWorkStyleForMonth.error && <ErrorMessage error={assignWorkStyleForMonth.error} />}
        {removeWorkStyleAssignment.error && <ErrorMessage error={removeWorkStyleAssignment.error} />}
        {(assignWorkStyleForMonth.isSuccess || removeWorkStyleAssignment.isSuccess) && (
          <Badge tone="success">保存しました</Badge>
        )}

        <h3 className="mb-3 text-sm font-semibold text-foreground">働き方({currentYearMonth})</h3>

        <div className="mb-4 flex flex-col gap-2">
          <label className="flex items-start gap-2 text-sm text-foreground">
            <input
              type="radio"
              name="work-style-mode"
              className="mt-1"
              checked={workStyleMode === 'default'}
              onChange={() => setWorkStyleMode('default')}
            />
            <span>
              会社のデフォルトを使用
              {defaultWorkStyle && (
                <span className="block text-xs text-muted-foreground">
                  {defaultWorkStyle.name}
                  {defaultWorkStyle.default_start_time && defaultWorkStyle.default_end_time
                    ? `(${defaultWorkStyle.default_start_time}〜${defaultWorkStyle.default_end_time})`
                    : ''}
                </span>
              )}
            </span>
          </label>

          <label className="flex items-start gap-2 text-sm text-foreground">
            <input
              type="radio"
              name="work-style-mode"
              className="mt-1"
              checked={workStyleMode === 'specify'}
              onChange={() => setWorkStyleMode('specify')}
            />
            <span>別の働き方を指定</span>
          </label>

          {workStyleMode === 'specify' && (
            <NativeSelect
              aria-label="指定する働き方"
              value={selectedWorkStyleId}
              onChange={(e) => setSelectedWorkStyleId(e.target.value)}
            >
              <option value="">選択してください</option>
              {workStyles?.map((style) => (
                <option key={style.id} value={style.id}>
                  {style.name}
                </option>
              ))}
            </NativeSelect>
          )}
        </div>

        <Button
          variant="secondary"
          isLoading={assignWorkStyleForMonth.isPending || removeWorkStyleAssignment.isPending}
          disabled={workStyleMode === 'specify' && !selectedWorkStyleId}
          onClick={handleSaveWorkStyle}
        >
          働き方を保存する
        </Button>

        {(workStyleHistory ?? []).length > 0 && (
          <div className="mt-4">
            <h4 className="mb-2 text-xs font-semibold text-muted-foreground">適用履歴</h4>
            <ul className="divide-y divide-border text-sm">
              {workStyleHistory?.map((assignment) => (
                <li key={assignment.id} className="py-1 text-foreground">
                  {assignment.year_month}: {assignment.work_style?.name ?? assignment.work_style_id}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Card>
  )
}
