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
import { useRoles } from '../hooks/useRoles'
import { useUpdateUserHireDate, useUpdateUserRoles, useUser } from '../hooks/useUsers'

/**
 * UC-M001: ユーザーに付与する権限(ロール)を編集する。
 * UC-P002: 有給の自動付与に使う入社日を設定する。
 */
export function UserRoleEditPage() {
  const { id } = useParams<{ id: string }>()
  const userId = Number(id)
  const { data: user, isLoading: isLoadingUser, error: userError } = useUser(userId)
  const { data: roles, isLoading: isLoadingRoles, error: rolesError } = useRoles()

  const updateRoles = useUpdateUserRoles()
  const updateHireDate = useUpdateUserHireDate()

  const [selectedCodes, setSelectedCodes] = useState<string[]>([])
  const [hireDate, setHireDate] = useState('')
  const [isInitialized, setIsInitialized] = useState(false)

  useEffect(() => {
    if (user && !isInitialized) {
      setSelectedCodes(user.roles ?? [])
      setHireDate(user.hire_date ?? '')
      setIsInitialized(true)
    }
  }, [user, isInitialized])

  if (isLoadingUser || isLoadingRoles) return <LoadingState />
  if (userError) return <ErrorMessage error={userError} fallback="ユーザーの取得に失敗しました。" />
  if (rolesError) return <ErrorMessage error={rolesError} fallback="権限一覧の取得に失敗しました。" />
  if (!user) return null

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
        {updateHireDate.isSuccess && <Badge tone="success">保存しました</Badge>}

        <FormField label="入社日(有給の自動付与に使用)" htmlFor="user-role-edit-hire-date">
          <Input
            id="user-role-edit-hire-date"
            type="date"
            value={hireDate}
            onChange={(e) => setHireDate(e.target.value)}
          />
        </FormField>

        <Button
          variant="secondary"
          isLoading={updateHireDate.isPending}
          disabled={!hireDate}
          onClick={() => updateHireDate.mutate({ id: userId, hireDate })}
        >
          入社日を保存する
        </Button>
      </div>
    </Card>
  )
}
