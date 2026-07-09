import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useRequestTypes } from '../hooks/useRequestTypes'
import './RequestTypeListPage.css'

/**
 * UC-M002 / UC-W001: 管理者が申請種別を一覧・管理する。
 */
export function RequestTypeListPage() {
  const { data: requestTypes, isLoading, error } = useRequestTypes(true)

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="申請種別の取得に失敗しました。" />

  const types = requestTypes ?? []

  return (
    <Card
      title="申請種別一覧"
      actions={
        <Link className="fo-button fo-button--primary request-type-list__new" to="/admin/request-types/new">
          新規作成
        </Link>
      }
    >
      {types.length === 0 ? (
        <p>申請種別はまだありません。</p>
      ) : (
        <ul className="request-type-list">
          {types.map((type) => (
            <li key={type.id}>
              <div>
                <Link to={`/admin/request-types/${type.id}`}>{type.name}</Link>
                <span className="request-type-list__code">{type.code}</span>
              </div>
              <div className="request-type-list__meta">
                {type.requires_backoffice_task && (
                  <span className="request-type-list__task-type">
                    バックオフィス: {type.backoffice_task_type ?? '未設定'}
                  </span>
                )}
                <Badge tone={type.is_active ? 'success' : 'neutral'}>{type.is_active ? '有効' : '無効'}</Badge>
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
