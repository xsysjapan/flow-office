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
import type { ExpenseCategoryEntryMode, ExpenseCategoryFieldDefinition, ExpenseEvidenceType } from '../../api/types'
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

const entryModeOptions: { value: ExpenseCategoryEntryMode; label: string }[] = [
  { value: 'batch', label: 'まとめ入力(交通費専用: 表形式・移動経路・テンプレート)' },
  { value: 'single', label: '1件入力(区分専用フォームで続けて何度でも入力できる)' },
]

const fieldTypeOptions: { value: ExpenseCategoryFieldDefinition['type']; label: string }[] = [
  { value: 'text', label: 'テキスト' },
  { value: 'number', label: '数値' },
  { value: 'date', label: '日付' },
  { value: 'select', label: '選択肢' },
  { value: 'boolean', label: 'はい/いいえ' },
]

let fieldRowSeq = 0
function newFieldRow(): ExpenseCategoryFieldDefinition & { rowId: number } {
  fieldRowSeq += 1
  return { rowId: fieldRowSeq, key: '', label: '', type: 'text', required: false }
}

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
  const [entryMode, setEntryMode] = useState<ExpenseCategoryEntryMode>('single')
  const [fieldDefinitions, setFieldDefinitions] = useState<Array<ExpenseCategoryFieldDefinition & { rowId: number }>>(
    [],
  )
  const [receiptRequiredThreshold, setReceiptRequiredThreshold] = useState('')
  const [approvalSkipThreshold, setApprovalSkipThreshold] = useState('')
  const [isActive, setIsActive] = useState(true)

  useEffect(() => {
    if (!existing) return
    setCode(existing.code)
    setName(existing.name)
    setDescription(existing.description ?? '')
    setEvidenceTypeDefault(existing.evidence_type_default)
    setEntryMode(existing.entry_mode)
    setFieldDefinitions((existing.field_definitions ?? []).map((field) => ({ ...field, rowId: (fieldRowSeq += 1) })))
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

  const addFieldDefinitionRow = () => setFieldDefinitions((rows) => [...rows, newFieldRow()])
  const removeFieldDefinitionRow = (rowId: number) =>
    setFieldDefinitions((rows) => rows.filter((row) => row.rowId !== rowId))
  const updateFieldDefinitionRow = (rowId: number, patch: Partial<ExpenseCategoryFieldDefinition>) =>
    setFieldDefinitions((rows) => rows.map((row) => (row.rowId === rowId ? { ...row, ...patch } : row)))

  const handleSave = async () => {
    const definedFields = fieldDefinitions
      .filter((row) => row.key && row.label)
      .map(({ rowId: _rowId, ...field }) => field)

    const input = {
      code,
      name,
      description: description || undefined,
      evidence_type_default: evidenceTypeDefault,
      entry_mode: entryMode,
      field_definitions: definedFields.length > 0 ? definedFields : null,
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

      <FormField label="入力方式" htmlFor="entry-mode" required>
        <NativeSelect
          id="entry-mode"
          value={entryMode}
          onChange={(e) => setEntryMode(e.target.value as ExpenseCategoryEntryMode)}
        >
          {entryModeOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </NativeSelect>
      </FormField>

      <div className="mb-4 flex flex-col gap-2">
        <div className="flex items-center justify-between">
          <span className="text-sm font-medium text-foreground">追加入力項目(任意)</span>
          <Button variant="secondary" size="sm" onClick={addFieldDefinitionRow}>
            項目を追加
          </Button>
        </div>
        <p className="text-xs text-muted-foreground">
          この区分で明細に追加入力させたい項目を定義します。ここで定義したキーだけが保存できます。
        </p>
        {fieldDefinitions.map((row, index) => (
          <div key={row.rowId} className="grid grid-cols-1 gap-2 rounded-md border border-border p-3 sm:grid-cols-5">
            <Input
              aria-label={`${index + 1}番目の項目のキー`}
              placeholder="キー(例: origin)"
              value={row.key}
              onChange={(e) => updateFieldDefinitionRow(row.rowId, { key: e.target.value })}
            />
            <Input
              aria-label={`${index + 1}番目の項目の表示名`}
              placeholder="表示名(例: 出発地)"
              value={row.label}
              onChange={(e) => updateFieldDefinitionRow(row.rowId, { label: e.target.value })}
            />
            <NativeSelect
              aria-label={`${index + 1}番目の項目の種類`}
              value={row.type}
              onChange={(e) =>
                updateFieldDefinitionRow(row.rowId, { type: e.target.value as ExpenseCategoryFieldDefinition['type'] })
              }
            >
              {fieldTypeOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </NativeSelect>
            <label className="flex items-center gap-2 text-sm text-foreground">
              <Checkbox
                checked={row.required ?? false}
                onCheckedChange={(checked) => updateFieldDefinitionRow(row.rowId, { required: checked === true })}
              />
              必須
            </label>
            <Button variant="danger" size="sm" onClick={() => removeFieldDefinitionRow(row.rowId)}>
              削除
            </Button>
          </div>
        ))}
      </div>

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

      <div className="mt-5 flex flex-col gap-1.5">
        <div className="flex items-center gap-2">
          <Button variant="secondary" onClick={() => navigate('/admin/expense-categories')}>
            キャンセル
          </Button>
          <Button isLoading={isBusy} disabled={!code || !name} onClick={() => void handleSave()}>
            {isCreate ? '作成' : '保存'}
          </Button>
        </div>
        {(!code || !name) && (
          <p className="text-xs text-muted-foreground">コードと名称を入力してください</p>
        )}
      </div>
    </Card>
  )
}
