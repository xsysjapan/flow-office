import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useRoles } from '../hooks/useRoles'
import { useUpdateUserRoles, useUser } from '../hooks/useUsers'
import './UserRoleEditPage.css'

/**
 * UC-M001: ユーザーに付与する権限(ロール)を編集する。
 */
export function UserRoleEditPage() {
  const { id } = useParams<{ id: string }>()
  const userId = Number(id)
  const { data: user, isLoading: isLoadingUser, error: userError } = useUser(userId)
  const { data: roles, isLoading: isLoadingRoles, error: rolesError } = useRoles()

  const updateRoles = useUpdateUserRoles()

  const [selectedCodes, setSelectedCodes] = useState<string[]>([])
  const [isInitialized, setIsInitialized] = useState(false)

  useEffect(() => {
    if (user && !isInitialized) {
      setSelectedCodes(user.roles ?? [])
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

      <dl className="user-role-edit__meta">
        <dt>メールアドレス</dt>
        <dd>{user.email}</dd>
        <dt>部署</dt>
        <dd>{user.department ?? '-'}</dd>
        <dt>役職</dt>
        <dd>{user.job_title ?? '-'}</dd>
      </dl>

      <ul className="user-role-edit__roles">
        {roles?.map((role) => (
          <li key={role.code}>
            <label>
              <input
                type="checkbox"
                checked={selectedCodes.includes(role.code)}
                onChange={() => toggleRole(role.code)}
              />
              {role.name}
            </label>
          </li>
        ))}
      </ul>

      <div className="user-role-edit__actions">
        <Button
          isLoading={updateRoles.isPending}
          onClick={() => updateRoles.mutate({ id: userId, roleCodes: selectedCodes })}
        >
          保存する
        </Button>
      </div>
    </Card>
  )
}
