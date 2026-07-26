import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { AttachmentPanel } from '../../components/AttachmentPanel/AttachmentPanel'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { ExpenseItemsTable } from '../../components/ExpenseItemsTable/ExpenseItemsTable'
import { ExpenseRouteBuilder } from '../../components/ExpenseRouteBuilder/ExpenseRouteBuilder'
import { ExpenseTemplateBulkGenerator } from '../../components/ExpenseTemplateBulkGenerator/ExpenseTemplateBulkGenerator'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '../../components/ui/tabs'
import type { SaveExpenseItemInput } from '../../api/expenseClaims'
import { useWeek } from '../../hooks/useAttendance'
import {
  useAddExpenseItemsBulk,
  useCreateExpenseClaim,
  useDeleteExpenseItem,
  useExpenseClaim,
  useSubmitExpenseClaim,
  useUpdateExpenseItem,
} from '../../hooks/useExpenseClaims'
import { useExpenseCategories } from '../../hooks/useExpenseCategories'
import { useEditableRows } from '../../hooks/useEditableRows'
import {
  useCreateExpenseRouteTemplate,
  useDeleteExpenseRouteTemplate,
  useExpenseRouteTemplates,
} from '../../hooks/useExpenseRouteTemplates'
import { mondayOf, formatDate } from '../../utils/weekDates'
import { workLocationTypeLabel } from '../../utils/statusLabels'

function emptyItem(categoryId?: number): SaveExpenseItemInput {
  return { category_id: categoryId ?? 0, usage_date: '', amount: 0 }
}

/** UC-X004: 対象日の勤怠実績(出社/客先訪問等)を入力補助として参考表示するのみで、
 *  金額計算・確定判定には使わない(docs/30-usecases-expense.md)。 */
function AttendanceReferenceLookup() {
  const [targetDate, setTargetDate] = useState('')
  const weekStart = targetDate ? formatDate(mondayOf(new Date(`${targetDate}T00:00:00`))) : ''
  const { data: week, isLoading } = useWeek(weekStart || '1970-01-01')
  const day = week?.find((d) => d.work_date === targetDate)

  return (
    <div className="flex flex-col gap-2 rounded-md border border-border p-3">
      <FormField label="対象日の勤怠実績を確認" htmlFor="attendance-reference-date">
        <Input
          id="attendance-reference-date"
          type="date"
          value={targetDate}
          onChange={(e) => setTargetDate(e.target.value)}
        />
      </FormField>
      {targetDate && (
        <p className="text-sm text-muted-foreground">
          {isLoading
            ? '確認中...'
            : day?.work_location_type
              ? `${targetDate}: ${workLocationTypeLabel(day.work_location_type)}と記録されています`
              : `${targetDate}の勤怠実績は見つかりませんでした`}
        </p>
      )}
    </div>
  )
}

/** UC-X002: 本人の個人移動区間テンプレートをこの画面内で登録・削除する。 */
function PersonalRouteTemplateManager({ employeeId }: { employeeId: string }) {
  const { data: templates } = useExpenseRouteTemplates()
  const { data: categories } = useExpenseCategories()
  const createTemplate = useCreateExpenseRouteTemplate()
  const deleteTemplate = useDeleteExpenseRouteTemplate()

  const [name, setName] = useState('')
  const [origin, setOrigin] = useState('')
  const [destination, setDestination] = useState('')
  const [transportType, setTransportType] = useState('')
  const [amount, setAmount] = useState('')
  const [categoryId, setCategoryId] = useState<number | ''>('')

  const personalTemplates = (templates ?? []).filter(
    (t) => t.scope === 'personal' && t.employee_id === employeeId,
  )

  const canCreate = name && origin && destination && amount && categoryId !== ''

  const handleCreate = () => {
    if (!canCreate) return
    createTemplate.mutate(
      {
        scope: 'personal',
        employee_id: employeeId,
        name,
        origin,
        destination,
        transport_type: transportType,
        amount: Number(amount),
        category_id: Number(categoryId),
      },
      {
        onSuccess: () => {
          setName('')
          setOrigin('')
          setDestination('')
          setTransportType('')
          setAmount('')
          setCategoryId('')
        },
      },
    )
  }

  return (
    <div className="flex flex-col gap-3">
      {personalTemplates.length > 0 && (
        <ul className="flex flex-col gap-1">
          {personalTemplates.map((template) => (
            <li key={template.id} className="flex items-center justify-between gap-2 border-b border-border py-1.5 text-sm last:border-b-0">
              <span className="text-foreground">
                {template.name}({template.origin} ⇔ {template.destination}, {template.amount.toLocaleString()}円)
              </span>
              <Button variant="danger" size="sm" onClick={() => deleteTemplate.mutate(template.id)}>
                削除
              </Button>
            </li>
          ))}
        </ul>
      )}

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
        <Input placeholder="テンプレート名" value={name} onChange={(e) => setName(e.target.value)} />
        <Input placeholder="出発地" value={origin} onChange={(e) => setOrigin(e.target.value)} />
        <Input placeholder="到着地" value={destination} onChange={(e) => setDestination(e.target.value)} />
        <Input placeholder="交通手段" value={transportType} onChange={(e) => setTransportType(e.target.value)} />
        <Input type="number" placeholder="金額" value={amount} onChange={(e) => setAmount(e.target.value)} />
        <NativeSelect value={categoryId} onChange={(e) => setCategoryId(e.target.value ? Number(e.target.value) : '')}>
          <option value="">経費区分を選択</option>
          {categories?.map((category) => (
            <option key={category.id} value={category.id}>
              {category.name}
            </option>
          ))}
        </NativeSelect>
      </div>
      <div>
        <Button variant="secondary" disabled={!canCreate} isLoading={createTemplate.isPending} onClick={handleCreate}>
          個人テンプレートを追加
        </Button>
      </div>
    </div>
  )
}

/**
 * UC-X002/X004〜X010: 経費精算の新規作成。対象期間を指定してドラフトを作成した後、
 * 表形式入力(UC-X006)・移動経路入力(UC-X007)・テンプレート一括生成(UC-X008)の
 * いずれかで明細を組み立て、まとめて保存する。保存済みの明細にはUC-X009の定期区間控除・
 * UC-X005のレシート添付を行い、最後に承認者を指定して申請する(UC-X010)。
 */
export function ExpenseClaimNewPage() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const [claimId, setClaimId] = useState<string | undefined>(undefined)
  const [periodFrom, setPeriodFrom] = useState('')
  const [periodTo, setPeriodTo] = useState('')
  const [approverUserId, setApproverUserId] = useState<string | undefined>(undefined)

  const createClaim = useCreateExpenseClaim()
  const { data: claim, isLoading: isLoadingClaim, error: claimError } = useExpenseClaim(claimId)
  const { data: categories, isLoading: isLoadingCategories, error: categoriesError } = useExpenseCategories()
  const { data: templates } = useExpenseRouteTemplates()

  const { rows, addRow, updateRow, removeRow, duplicateRow, moveRow, appendRows, toData, reset } =
    useEditableRows<SaveExpenseItemInput>([])
  const addItemsBulk = useAddExpenseItemsBulk(claimId ?? '')
  const updateItem = useUpdateExpenseItem(claimId ?? '')
  const deleteItem = useDeleteExpenseItem(claimId ?? '')
  const submitClaim = useSubmitExpenseClaim(claimId ?? '')

  if (isLoadingCategories) return <LoadingState />
  if (categoriesError) return <ErrorMessage error={categoriesError} fallback="経費区分の取得に失敗しました。" />

  const handleCreateClaim = async () => {
    const created = await createClaim.mutateAsync({ period_from: periodFrom, period_to: periodTo })
    setClaimId(created.id)
  }

  const handleSaveItems = async () => {
    const items = toData().filter((item) => item.usage_date && item.amount)
    if (items.length === 0 || !claimId) return
    await addItemsBulk.mutateAsync(items)
    reset([])
  }

  const handleSubmit = async () => {
    if (!claimId || !approverUserId) return
    await submitClaim.mutateAsync(approverUserId)
    navigate(`/expenses/${claimId}`)
  }

  if (!claimId) {
    return (
      <Card title="経費精算の新規作成">
        {createClaim.error && <ErrorMessage error={createClaim.error} />}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="対象期間(開始)" htmlFor="period-from" required>
            <Input id="period-from" type="date" value={periodFrom} onChange={(e) => setPeriodFrom(e.target.value)} />
          </FormField>
          <FormField label="対象期間(終了)" htmlFor="period-to" required>
            <Input id="period-to" type="date" value={periodTo} onChange={(e) => setPeriodTo(e.target.value)} />
          </FormField>
        </div>
        <Button
          isLoading={createClaim.isPending}
          disabled={!periodFrom || !periodTo}
          onClick={() => void handleCreateClaim()}
        >
          作成して明細入力へ進む
        </Button>
      </Card>
    )
  }

  if (isLoadingClaim) return <LoadingState />
  if (claimError) return <ErrorMessage error={claimError} fallback="経費精算の取得に失敗しました。" />

  return (
    <div className="flex flex-col gap-6">
      <Card title={`経費精算(${claim?.period_from} 〜 ${claim?.period_to})`}>
        <AttendanceReferenceLookup />

        <Tabs defaultValue="table" className="mt-4">
          <TabsList>
            <TabsTrigger value="table">表形式入力</TabsTrigger>
            <TabsTrigger value="route">移動経路入力</TabsTrigger>
            <TabsTrigger value="template">テンプレートから生成</TabsTrigger>
          </TabsList>

          <TabsContent value="table">
            <ExpenseItemsTable
              rows={rows}
              categories={categories ?? []}
              onAddRow={() => addRow(emptyItem(categories?.[0]?.id))}
              onUpdateRow={updateRow}
              onRemoveRow={removeRow}
              onDuplicateRow={duplicateRow}
              onMoveRow={moveRow}
              onPasteRows={appendRows}
            />
          </TabsContent>

          <TabsContent value="route">
            <ExpenseRouteBuilder
              categories={categories ?? []}
              defaultCategoryId={categories?.[0]?.id}
              onGenerate={appendRows}
            />
          </TabsContent>

          <TabsContent value="template">
            <ExpenseTemplateBulkGenerator templates={templates ?? []} onGenerate={appendRows} />
          </TabsContent>
        </Tabs>

        {rows.length > 0 && (
          <div className="mt-4">
            <Button isLoading={addItemsBulk.isPending} onClick={() => void handleSaveItems()}>
              明細を保存する({rows.length}件)
            </Button>
            {addItemsBulk.error && <ErrorMessage error={addItemsBulk.error} />}
          </div>
        )}
      </Card>

      <Card title="個人の移動区間テンプレート">
        {user && <PersonalRouteTemplateManager employeeId={user.id} />}
      </Card>

      <Card title={`保存済みの明細(${claim?.items.length ?? 0}件)`}>
        {(claim?.items.length ?? 0) === 0 ? (
          <p className="text-sm text-muted-foreground">保存済みの明細はまだありません。上のタブから追加してください。</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>日付</TableHead>
                <TableHead>経費区分</TableHead>
                <TableHead>経路・内容</TableHead>
                <TableHead>金額</TableHead>
                <TableHead>定期区間控除</TableHead>
                <TableHead>領収書</TableHead>
                <TableHead>操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {claim?.items.map((item) => {
                const requiresReceipt = item.evidence_type === 'receipt_required'
                const deductionAmount = item.commuting_deduction_amount ?? 0
                return (
                  <TableRow key={item.id}>
                    <TableCell className="text-muted-foreground">{item.usage_date}</TableCell>
                    <TableCell className="text-muted-foreground">{item.category?.name}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {item.origin && item.destination ? `${item.origin} → ${item.destination}` : item.purpose}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {item.amount.toLocaleString()}円
                      {deductionAmount > 0 && (
                        <span className="block text-xs">
                          会社負担額 {(item.amount - deductionAmount).toLocaleString()}円
                        </span>
                      )}
                    </TableCell>
                    <TableCell>
                      <label className="flex items-center gap-2 text-xs text-foreground">
                        <Checkbox
                          checked={deductionAmount > 0}
                          onCheckedChange={(checked) =>
                            updateItem.mutate({
                              itemId: item.id,
                              input: {
                                category_id: item.category_id,
                                usage_date: item.usage_date,
                                origin: item.origin ?? undefined,
                                destination: item.destination ?? undefined,
                                transport_type: item.transport_type ?? undefined,
                                amount: item.amount,
                                destination_name: item.destination_name ?? undefined,
                                purpose: item.purpose ?? undefined,
                                project_id: item.project_id ?? undefined,
                                commuting_deduction_amount: checked === true ? deductionAmount : 0,
                              },
                            })
                          }
                        />
                        定期区間を含む
                      </label>
                      {deductionAmount > 0 && (
                        <Input
                          className="mt-1 w-24"
                          type="number"
                          aria-label={`${item.usage_date}の定期区間控除額`}
                          value={deductionAmount}
                          onChange={(e) =>
                            updateItem.mutate({
                              itemId: item.id,
                              input: {
                                category_id: item.category_id,
                                usage_date: item.usage_date,
                                origin: item.origin ?? undefined,
                                destination: item.destination ?? undefined,
                                transport_type: item.transport_type ?? undefined,
                                amount: item.amount,
                                destination_name: item.destination_name ?? undefined,
                                purpose: item.purpose ?? undefined,
                                project_id: item.project_id ?? undefined,
                                commuting_deduction_amount: Number(e.target.value),
                              },
                            })
                          }
                        />
                      )}
                    </TableCell>
                    <TableCell>
                      <AttachmentPanel ownerType="expense_item" ownerId={item.id} required={requiresReceipt} compact />
                    </TableCell>
                    <TableCell>
                      <Button variant="danger" size="sm" onClick={() => deleteItem.mutate(item.id)}>
                        削除
                      </Button>
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        )}
      </Card>

      <Card title="申請する">
        {submitClaim.error && <ErrorMessage error={submitClaim.error} />}
        <FormField label="承認者" htmlFor="approver" required>
          <UserPicker id="approver" value={approverUserId} onChange={setApproverUserId} />
        </FormField>
        <div className="flex items-center gap-3">
          <Button
            isLoading={submitClaim.isPending}
            disabled={!approverUserId || (claim?.items.length ?? 0) === 0}
            onClick={() => void handleSubmit()}
          >
            申請する
          </Button>
          <Badge tone="neutral">下書き</Badge>
        </div>
      </Card>
    </div>
  )
}
