import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Input } from '../components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../components/ui/table'
import { useUsers } from '../hooks/useUsers'

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
      <Input
        className="mb-4 max-w-xs"
        placeholder="氏名またはメールアドレスで検索"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
      />

      {users.length === 0 ? (
        <p className="text-sm text-muted-foreground">該当するユーザーはいません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>氏名</TableHead>
              <TableHead>メールアドレス</TableHead>
              <TableHead>部署</TableHead>
              <TableHead>役職</TableHead>
              <TableHead>在籍状況</TableHead>
              <TableHead>権限</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {users.map((user) => (
              <TableRow key={user.id}>
                <TableCell>
                  <Link
                    to={`/admin/users/${user.id}`}
                    className="font-medium text-foreground hover:text-primary hover:underline"
                  >
                    {user.name}
                  </Link>
                </TableCell>
                <TableCell className="text-muted-foreground">{user.email}</TableCell>
                <TableCell className="text-muted-foreground">{user.department ?? '-'}</TableCell>
                <TableCell className="text-muted-foreground">{user.job_title ?? '-'}</TableCell>
                <TableCell className="text-muted-foreground">{user.employment_status}</TableCell>
                <TableCell>
                  {(user.roles ?? []).length === 0 ? (
                    <span className="text-sm text-muted-foreground">未設定</span>
                  ) : (
                    <span className="flex flex-wrap gap-1">
                      {user.roles?.map((role) => (
                        <Badge key={role} tone="info">
                          {role}
                        </Badge>
                      ))}
                    </span>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </Card>
  )
}
