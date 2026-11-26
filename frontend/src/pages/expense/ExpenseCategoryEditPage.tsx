import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { BackLink } from '../../components/BackLink/BackLink'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormActions } from '../../components/FormActions/FormActions'
import { FormField } from '../../components/FormField/FormField'
import { FormLayout } from '../../components/FormLayout/FormLayout'
import { PageHeader } from '../../components/PageHeader/PageHeader'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { notify } from '../../lib/notify'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Textarea } from '../../components/ui/textarea'
import { useBackTarget, useKeepBackState } from '../../navigation/backState'
import type { ExpenseCategoryEntryMode, ExpenseCategoryFieldDefinition, ExpenseEvidenceType } from '../../api/types'
import {
  useCreateExpenseCategory,
  useExpenseCategories,
  useUpdateExpenseCategory,
} from '../../hooks/useExpenseCategories'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { fieldErrors } from '../../utils/fieldErrors'
import {
  EXPENSE_CATEGORY_INITIAL,
  isExpenseCategoryDirty,
  type ExpenseCategoryFormValues,
} from './expenseCategoryDirty'

const LIST_PATH = '/admin/expense-categories'

/** 項目の下に出す422のキー。`field_definitions.{i}.*`は行の下に出す。 */
const FIELD_ERROR_KEYS = [
  'code',
  'name',
  'description',
  'entry_mode',
  'evidence_type_default',
  'receipt_required_threshold',
  'approval_skip_threshold',
  'field_definitions.*.key',
  'field_definitions.*.label',
  'field_definitions.*.type',
  'field_definitions.*.required',
] as const

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

function RowError({ message }: { message: string | undefined }) {
  return message ? <p className="text-xs text-destructive">{message}</p> : null
}

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
  // パスの変数(区分ID・new)が変わったら入力を作り直す。
  return <ExpenseCategoryEditForm key={id ?? 'new'} />
}

function ExpenseCategoryEditForm() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const backTarget = useBackTarget()
  const keepBackState = useKeepBackState()
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

  const base: ExpenseCategoryFormValues | null = isCreate
    ? EXPENSE_CATEGORY_INITIAL
    : existing
      ? {
          code: existing.code,
          name: existing.name,
          description: existing.description ?? '',
          evidenceTypeDefault: existing.evidence_type_default,
          entryMode: existing.entry_mode,
          fieldDefinitions: existing.field_definitions ?? [],
          receiptRequiredThreshold:
            existing.receipt_required_threshold != null ? String(existing.receipt_required_threshold) : '',
          approvalSkipThreshold:
            existing.approval_skip_threshold != null ? String(existing.approval_skip_threshold) : '',
          isActive: existing.is_active,
        }
      : null
  const isDirty = base
    ? isExpenseCategoryDirty(base, {
        code,
        name,
        description,
        evidenceTypeDefault,
        entryMode,
        fieldDefinitions,
        receiptRequiredThreshold,
        approvalSkipThreshold,
        isActive,
      })
    : false
  const { navigateAfterSave } = useUnsavedChangesGuard(isDirty)

  const top = (
    <>
      <BackLink className="mb-0" />
      <PageHeader description="経費区分の名称・証憑要件・追加入力項目などを入力し、保存します。" />
    </>
  )
  if (!isCreate && isLoading)
    return (
      <div className="flex flex-col gap-6">
        {top}
        <LoadingState />
      </div>
    )
  if (!isCreate && listError)
    return (
      <div className="flex flex-col gap-6">
        {top}
        <ErrorMessage error={listError} fallback="経費区分の取得に失敗しました。" />
      </div>
    )

  const isBusy = createCategory.isPending || updateCategory.isPending
  const { of, ofRow, rest } = fieldErrors(createCategory.error ?? updateCategory.error, FIELD_ERROR_KEYS)

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
      notify.success('経費区分を作成しました')
    } else if (existing) {
      await updateCategory.mutateAsync({ id: existing.id, input })
      notify.success('経費区分を保存しました')
    }

    if (backTarget && backTarget.to.split(/[?#]/)[0] === LIST_PATH) {
      navigateAfterSave(backTarget.to, { state: backTarget.state })
    } else {
      navigateAfterSave(LIST_PATH, { state: keepBackState })
    }
  }

  const handleCancel = () => {
    if (backTarget) {
      navigate(backTarget.to, { state: backTarget.state, replace: true })
    } else {
      navigate(LIST_PATH)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      {top}
      <Card>
        <FormLayout>
          <FormField label="コード" htmlFor="code" required error={of('code')}>
            <Input id="code" value={code} disabled={!isCreate} onChange={(e) => setCode(e.target.value)} />
          </FormField>

          <FormField label="名称" htmlFor="name" required error={of('name')}>
            <Input id="name" value={name} onChange={(e) => setName(e.target.value)} />
          </FormField>

        <FormField label="説明" htmlFor="description" error={of('description')}>
          <Textarea id="description" value={description} onChange={(e) => setDescription(e.target.value)} />
        </FormField>

        <FormField label="証憑タイプ既定" htmlFor="evidence-type-default" required error={of('evidence_type_default')}>
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

        <FormField label="入力方式" htmlFor="entry-mode" required error={of('entry_mode')}>
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
            <div key={row.rowId} className="grid grid-cols-1 gap-2 rounded-md border border-border p-3">
              <Input
                aria-label={`${index + 1}番目の項目のキー`}
                placeholder="キー(例: origin)"
                value={row.key}
                onChange={(e) => updateFieldDefinitionRow(row.rowId, { key: e.target.value })}
              />
              <RowError message={ofRow('field_definitions', index, 'key')} />
              <Input
                aria-label={`${index + 1}番目の項目の表示名`}
                placeholder="表示名(例: 出発地)"
                value={row.label}
                onChange={(e) => updateFieldDefinitionRow(row.rowId, { label: e.target.value })}
              />
              <RowError message={ofRow('field_definitions', index, 'label')} />
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
              <RowError message={ofRow('field_definitions', index, 'type')} />
              <label className="flex items-center gap-2 text-sm text-foreground">
                <Checkbox
                  checked={row.required ?? false}
                  onCheckedChange={(checked) => updateFieldDefinitionRow(row.rowId, { required: checked === true })}
                />
                必須
              </label>
              <RowError message={ofRow('field_definitions', index, 'required')} />
              <Button variant="danger" size="sm" onClick={() => removeFieldDefinitionRow(row.rowId)}>
                削除
              </Button>
            </div>
          ))}
        </div>

          <FormField
            label="レシート必須しきい値(円・任意)"
            htmlFor="receipt-required-threshold"
            error={of('receipt_required_threshold')}
          >
            <Input
              id="receipt-required-threshold"
              type="number"
              min={0}
              value={receiptRequiredThreshold}
              onChange={(e) => setReceiptRequiredThreshold(e.target.value)}
            />
          </FormField>

          <FormField
            label="承認省略しきい値(円・任意)"
            htmlFor="approval-skip-threshold"
            description="この金額以下の明細は承認を1段階省略できます。"
            error={of('approval_skip_threshold')}
          >
            <Input
              id="approval-skip-threshold"
              type="number"
              min={0}
              value={approvalSkipThreshold}
              onChange={(e) => setApprovalSkipThreshold(e.target.value)}
            />
          </FormField>

        <label className="mb-4 flex items-center gap-2 text-sm font-medium text-foreground">
          <Checkbox id="is-active" checked={isActive} onCheckedChange={(checked) => setIsActive(checked === true)} />
          有効
        </label>

        {(!code || !name) && (
          <p className="mb-2 text-xs text-muted-foreground">コードと名称を入力してください。</p>
        )}
        {rest != null && <ErrorMessage error={rest} />}
        <FormActions>
          <Button variant="secondary" onClick={handleCancel}>
            キャンセル
          </Button>
          <Button isLoading={isBusy} disabled={!code || !name} onClick={() => void handleSave().catch(() => undefined)}>
            {isCreate ? '作成' : '保存'}
          </Button>
        </FormActions>
        </FormLayout>
      </Card>
    </div>
  )
}
