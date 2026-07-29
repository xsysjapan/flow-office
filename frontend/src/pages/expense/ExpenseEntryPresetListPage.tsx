import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmDialog } from '../../components/ConfirmDialog/ConfirmDialog'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { ExpenseEntryPreset, ExpenseEntryPresetVisibility } from '../../api/types'
import { useDeleteExpenseEntryPreset, useExpenseEntryPresets } from '../../hooks/useExpenseEntryPresets'
import { hasAnyRole, ROLE } from '../../utils/roles'

const visibilityLabel: Record<ExpenseEntryPresetVisibility, string> = {
  personal: '個人用',
  company: '全社共有',
  system: 'システム標準',
}

/**
 * 「経費精算機能 設計・実装指示書」9〜10: 入力プリセット一覧。個人用は本人のみ編集でき、
 * 全社共有・システム標準は経理・管理者のみ編集できる(書き込みはAPI側でも検証する)。
 */
export function ExpenseEntryPresetListPage() {
  const { user } = useAuth()
  const { data: presets, isLoading, error } = useExpenseEntryPresets()
  const deletePreset = useDeleteExpenseEntryPreset()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="プリセットの取得に失敗しました。" />

  const canManageShared = hasAnyRole(user?.roles, [ROLE.ACCOUNTING_STAFF, ROLE.ADMIN])
  const canEdit = (preset: ExpenseEntryPreset) =>
    preset.visibility === 'personal' ? preset.owner_user_id === user?.id : canManageShared

  const list = presets ?? []

  return (
    <Card
      title="入力プリセット"
      actions={
        <Button asChild>
          <Link to="/expenses/presets/new">新規作成</Link>
        </Button>
      }
    >
      {deletePreset.error && <ErrorMessage error={deletePreset.error} />}

      {list.length === 0 ? (
        <p className="text-sm text-muted-foreground">プリセットはまだありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>名称</TableHead>
              <TableHead>公開範囲</TableHead>
              <TableHead>明細件数</TableHead>
              <TableHead>利用回数</TableHead>
              <TableHead>状態</TableHead>
              <TableHead>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.map((preset) => (
              <TableRow key={preset.id}>
                <TableCell>
                  {canEdit(preset) ? (
                    <Link
                      to={`/expenses/presets/${preset.id}`}
                      className="font-medium text-foreground hover:text-primary hover:underline"
                    >
                      {preset.name}
                    </Link>
                  ) : (
                    <span className="font-medium text-foreground">{preset.name}</span>
                  )}
                </TableCell>
                <TableCell className="text-muted-foreground">{visibilityLabel[preset.visibility]}</TableCell>
                <TableCell className="text-muted-foreground">{preset.definition.length}</TableCell>
                <TableCell className="text-muted-foreground">{preset.usage_count}</TableCell>
                <TableCell>
                  <Badge tone={preset.is_active ? 'success' : 'neutral'}>{preset.is_active ? '有効' : '無効'}</Badge>
                </TableCell>
                <TableCell>
                  {canEdit(preset) && (
                    <ConfirmDialog
                      trigger={
                        <Button variant="danger" size="sm">
                          削除
                        </Button>
                      }
                      title="このプリセットを削除しますか?"
                      description="削除すると元に戻せません。"
                      isConfirming={deletePreset.isPending && deletePreset.variables === preset.id}
                      onConfirm={() => deletePreset.mutate(preset.id)}
                    />
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
