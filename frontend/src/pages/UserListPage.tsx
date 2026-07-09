import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useUsers } from '../hooks/useUsers'
import './UserListPage.css'

/**
 * UC-M001: ユーザーを検索し、権限編集画面へ遷移する一覧。
 */
export function UserListPage() {
  const [query, setQuery] = useState('')
  const { data, isLoading, error } = useUsers(query)

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="ユーザー一覧の取得に失敗しました。" />

  const users = data?.data ?? []

  return (
    <Card title="ユーザー一覧">
      <input
        className="user-list__search"
        placeholder="氏名またはメールアドレスで検索"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
      />

      {users.length === 0 ? (
        <p>該当するユーザーはいません。</p>
      ) : (
        <table className="user-list">
          <thead>
            <tr>
              <th>氏名</th>
              <th>メールアドレス</th>
              <th>部署</th>
              <th>役職</th>
              <th>在籍状況</th>
              <th>権限</th>
            </tr>
          </thead>
          <tbody>
            {users.map((user) => (
              <tr key={user.id}>
                <td>
                  <Link to={`/admin/users/${user.id}`}>{user.name}</Link>
                </td>
                <td>{user.email}</td>
                <td>{user.department ?? '-'}</td>
                <td>{user.job_title ?? '-'}</td>
                <td>{user.employment_status}</td>
                <td>
                  {(user.roles ?? []).length === 0 ? (
                    <span className="user-list__no-roles">未設定</span>
                  ) : (
                    <span className="user-list__roles">
                      {user.roles?.map((role) => (
                        <Badge key={role} tone="info">
                          {role}
                        </Badge>
                      ))}
                    </span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Card>
  )
}
