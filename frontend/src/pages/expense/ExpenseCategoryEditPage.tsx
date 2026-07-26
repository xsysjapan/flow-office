import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Textarea } from '../../components/ui/textarea'
import type { ExpenseEvidenceType } from '../../api/types'
import {
  useCreateExpenseCategory,
  useExpenseCategories,
  useUpdateExpenseCategory,
} from '../../hooks/useExpenseCategories'

const evidenceTypeOptions: { value: ExpenseEvidenceType; label: string }[] = [
  { value: 'fact_reference_available', label: '実績参照のみ' },
  { value: 'receipt_required', label: 'レシート必須' },
  { value: 'receipt_optional', label: 'レシート任意' },
]

/**
 * UC-X001: 管理者が経費区分マスタを作成・編集する。
 */
export function ExpenseCategoryEditPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const isCreate = !id || id === 'new'

  const { data: categories, isLoading, error: listError } = useExpenseCategories(true)
  const existing = useMemo(
    () => (isCreate ? undefined : categories?.find((category) => category.id === Number(id))),
    [categories, id, isCreate],
  )

  const createCategory = useCreateExpenseCategory()
  const updateCategory = useUpdateExpenseCategory()

  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [evidenceTypeDefault, setEvidenceTypeDefault] = useState<ExpenseEvidenceType>('fact_reference_available')
  const [receiptRequiredThreshold, setReceiptRequiredThreshold] = useState('')
  const [approvalSkipThreshold, setApprovalSkipThreshold] = useState('')
  const [isActive, setIsActive] = useState(true)

  useEffect(() => {
    if (!existing) return
    setCode(existing.code)
    setName(existing.name)
    setDescription(existing.description ?? '')
    setEvidenceTypeDefault(existing.evidence_type_default)
    setReceiptRequiredThreshold(
      existing.receipt_required_threshold != null ? String(existing.receipt_required_threshold) : '',
    )
    setApprovalSkipThreshold(
      existing.approval_skip_threshold != null ? String(existing.approval_skip_threshold) : '',
    )
    setIsActive(existing.is_active)
  }, [existing])

  if (!isCreate && isLoading) return <LoadingState />
  if (!isCreate && listError) return <ErrorMessage error={listError} fallback="経費区分の取得に失敗しました。" />

  const isBusy = createCategory.isPending || updateCategory.isPending
  const error = createCategory.error ?? updateCategory.error

  const handleSave = async () => {
    const input = {
      code,
      name,
      description: description || undefined,
      evidence_type_default: evidenceTypeDefault,
      receipt_required_threshold: receiptRequiredThreshold ? Number(receiptRequiredThreshold) : undefined,
      approval_skip_threshold: approvalSkipThreshold ? Number(approvalSkipThreshold) : undefined,
      is_active: isActive,
    }

    if (isCreate) {
      await createCategory.mutateAsync(input)
    } else if (existing) {
      await updateCategory.mutateAsync({ id: existing.id, input })
    }

    navigate('/admin/expense-categories')
  }

  return (
    <Card title={isCreate ? '経費区分の新規作成' : '経費区分の編集'}>
      {error && <ErrorMessage error={error} />}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="コード" htmlFor="code" required>
          <Input id="code" value={code} disabled={!isCreate} onChange={(e) => setCode(e.target.value)} />
        </FormField>

        <FormField label="名称" htmlFor="name" required>
          <Input id="name" value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>
      </div>

      <FormField label="説明" htmlFor="description">
        <Textarea id="description" value={description} onChange={(e) => setDescription(e.target.value)} />
      </FormField>

      <FormField label="証憑タイプ既定" htmlFor="evidence-type-default" required>
        <NativeSelect
          id="evidence-type-default"
          value={evidenceTypeDefault}
          onChange={(e) => setEvidenceTypeDefault(e.target.value as ExpenseEvidenceType)}
        >
          {evidenceTypeOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </NativeSelect>
      </FormField>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="レシート必須しきい値(円・任意)" htmlFor="receipt-required-threshold">
          <Input
            id="receipt-required-threshold"
            type="number"
            min={0}
            value={receiptRequiredThreshold}
            onChange={(e) => setReceiptRequiredThreshold(e.target.value)}
          />
        </FormField>

        <FormField label="承認省略しきい値(円・任意)" htmlFor="approval-skip-threshold">
          <Input
            id="approval-skip-threshold"
            type="number"
            min={0}
            value={approvalSkipThreshold}
            onChange={(e) => setApprovalSkipThreshold(e.target.value)}
          />
          <p className="mt-1 text-xs text-muted-foreground">
            この金額以下の明細は承認を1段階省略できます
          </p>
        </FormField>
      </div>

      <label className="mb-4 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox id="is-active" checked={isActive} onCheckedChange={(checked) => setIsActive(checked === true)} />
        有効
      </label>

      <div className="mt-5">
        <Button isLoading={isBusy} disabled={!code || !name} onClick={() => void handleSave()}>
          保存する
        </Button>
      </div>
    </Card>
  )
}
