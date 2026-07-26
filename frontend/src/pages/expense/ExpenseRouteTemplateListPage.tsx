import { Link } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useExpenseCategories } from '../../hooks/useExpenseCategories'
import { useExpenseRouteTemplates } from '../../hooks/useExpenseRouteTemplates'

/**
 * UC-X003: 管理者(経理)が全社共有の移動区間テンプレートを一覧・管理する。
 */
export function ExpenseRouteTemplateListPage() {
  const { data: templates, isLoading: isTemplatesLoading, error: templatesError } = useExpenseRouteTemplates()
  const { data: categories, isLoading: isCategoriesLoading, error: categoriesError } = useExpenseCategories()

  if (isTemplatesLoading || isCategoriesLoading) return <LoadingState />
  if (templatesError) return <ErrorMessage error={templatesError} fallback="移動区間テンプレートの取得に失敗しました。" />
  if (categoriesError) return <ErrorMessage error={categoriesError} fallback="経費区分の取得に失敗しました。" />

  const companyTemplates = (templates ?? []).filter((template) => template.scope === 'company')
  const categoryNameById = new Map((categories ?? []).map((category) => [category.id, category.name]))

  return (
    <Card
      title="移動区間テンプレート一覧(全社共有)"
      actions={
        <Button asChild>
          <Link to="/admin/expense-route-templates/new">新規作成</Link>
        </Button>
      }
    >
      {companyTemplates.length === 0 ? (
        <p className="text-sm text-muted-foreground">全社共有の移動区間テンプレートはまだありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>名称</TableHead>
              <TableHead>出発地→到着地</TableHead>
              <TableHead>交通手段</TableHead>
              <TableHead>金額</TableHead>
              <TableHead>経費区分</TableHead>
              <TableHead>状態</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {companyTemplates.map((template) => (
              <TableRow key={template.id}>
                <TableCell>
                  <Link
                    to={`/admin/expense-route-templates/${template.id}`}
                    className="font-medium text-foreground hover:text-primary hover:underline"
                  >
                    {template.name}
                  </Link>
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {template.origin}→{template.destination}
                </TableCell>
                <TableCell className="text-muted-foreground">{template.transport_type}</TableCell>
                <TableCell className="text-muted-foreground">{template.amount}円</TableCell>
                <TableCell className="text-muted-foreground">
                  {categoryNameById.get(template.category_id) ?? '未設定'}
                </TableCell>
                <TableCell>
                  <Badge tone={template.is_active ? 'success' : 'neutral'}>
                    {template.is_active ? '有効' : '無効'}
                  </Badge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </Card>
  )
}
