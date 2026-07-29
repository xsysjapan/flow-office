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
import type {
  ExpenseEntryPresetDefinitionItem,
  ExpenseEntryPresetType,
  ExpenseEntryPresetVisibility,
  ExpensePaymentBearer,
} from '../../api/types'
import { useExpenseCategories } from '../../hooks/useExpenseCategories'
import {
  useCreateExpenseEntryPreset,
  useExpenseEntryPresets,
  useUpdateExpenseEntryPreset,
} from '../../hooks/useExpenseEntryPresets'
import { paymentBearerLabel } from '../../utils/statusLabels'

const visibilityOptions: { value: ExpenseEntryPresetVisibility; label: string }[] = [
  { value: 'personal', label: '個人用(自分だけが使う)' },
  { value: 'company', label: '全社共有(経理・管理者のみ登録可)' },
  { value: 'system', label: 'システム標準(経理・管理者のみ登録可)' },
]

const presetTypeOptions: { value: ExpenseEntryPresetType; label: string }[] = [
  { value: 'single_item', label: '1件の明細を作る' },
  { value: 'multiple_items', label: '複数件の明細をまとめて作る' },
]

const paymentBearers: ExpensePaymentBearer[] = ['employee', 'corporate_card', 'company', 'customer', 'other']

let itemRowSeq = 0
function newDefinitionRow(): ExpenseEntryPresetDefinitionItem & { rowId: number } {
  itemRowSeq += 1
  return { rowId: itemRowSeq, category_id: 0, description: '', amount: null, payment_bearer: null }
}

/**
 * 「経費精算機能 設計・実装指示書」10.1〜10.4: プリセットの作成・編集。ユーザーにJSONを
 * 直接編集させず、明細1件ごとの初期値(区分・内容・金額・支払方法)をGUIで設定する。
 * 任意のコード・SQL・外部API呼び出しは持たせない。
 */
export function ExpenseEntryPresetEditPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const isCreate = !id || id === 'new'

  const { data: presets, isLoading, error: listError } = useExpenseEntryPresets()
  const { data: categories, isLoading: isCategoriesLoading, error: categoriesError } = useExpenseCategories()
  const existing = useMemo(
    () => (isCreate ? undefined : presets?.find((preset) => preset.id === Number(id))),
    [presets, id, isCreate],
  )

  const createPreset = useCreateExpenseEntryPreset()
  const updatePreset = useUpdateExpenseEntryPreset()

  const [visibility, setVisibility] = useState<ExpenseEntryPresetVisibility>('personal')
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [presetType, setPresetType] = useState<ExpenseEntryPresetType>('single_item')
  const [items, setItems] = useState<Array<ExpenseEntryPresetDefinitionItem & { rowId: number }>>([newDefinitionRow()])
  const [isActive, setIsActive] = useState(true)

  useEffect(() => {
    if (!existing) return
    setVisibility(existing.visibility)
    setName(existing.name)
    setDescription(existing.description ?? '')
    setPresetType(existing.preset_type)
    setItems(
      existing.definition.length > 0
        ? existing.definition.map((item) => ({ ...item, rowId: (itemRowSeq += 1) }))
        : [newDefinitionRow()],
    )
    setIsActive(existing.is_active)
  }, [existing])

  if (!isCreate && isLoading) return <LoadingState />
  if (!isCreate && listError) return <ErrorMessage error={listError} fallback="プリセットの取得に失敗しました。" />
  if (isCategoriesLoading) return <LoadingState />
  if (categoriesError) return <ErrorMessage error={categoriesError} fallback="経費区分の取得に失敗しました。" />

  const isBusy = createPreset.isPending || updatePreset.isPending
  const error = createPreset.error ?? updatePreset.error

  const addItemRow = () => setItems((rows) => [...rows, newDefinitionRow()])
  const removeItemRow = (rowId: number) => setItems((rows) => rows.filter((row) => row.rowId !== rowId))
  const updateItemRow = (rowId: number, patch: Partial<ExpenseEntryPresetDefinitionItem>) =>
    setItems((rows) => rows.map((row) => (row.rowId === rowId ? { ...row, ...patch } : row)))

  const canSave = name.trim() !== '' && items.every((item) => item.category_id > 0)

  const handleSave = async () => {
    const input = {
      visibility,
      name,
      description: description || undefined,
      preset_type: presetType,
      definition: items.map(({ rowId: _rowId, ...item }) => ({
        category_id: item.category_id,
        description: item.description || undefined,
        amount: item.amount || undefined,
        payment_bearer: item.payment_bearer || undefined,
      })),
      is_active: isActive,
    }

    if (isCreate) {
      await createPreset.mutateAsync(input)
    } else if (existing) {
      await updatePreset.mutateAsync({ id: existing.id, input })
    }

    navigate('/expenses/presets')
  }

  return (
    <Card title={isCreate ? 'プリセットの新規作成' : 'プリセットの編集'}>
      {error && <ErrorMessage error={error} />}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="名称" htmlFor="preset-name" required>
          <Input id="preset-name" value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>

        <FormField label="公開範囲" htmlFor="preset-visibility" required>
          <NativeSelect
            id="preset-visibility"
            value={visibility}
            onChange={(e) => setVisibility(e.target.value as ExpenseEntryPresetVisibility)}
          >
            {visibilityOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </NativeSelect>
        </FormField>
      </div>

      <FormField label="説明(任意)" htmlFor="preset-description">
        <Textarea id="preset-description" value={description} onChange={(e) => setDescription(e.target.value)} />
      </FormField>

      <FormField label="種類" htmlFor="preset-type" required>
        <NativeSelect
          id="preset-type"
          value={presetType}
          onChange={(e) => setPresetType(e.target.value as ExpenseEntryPresetType)}
        >
          {presetTypeOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </NativeSelect>
      </FormField>

      <div className="mb-4 flex flex-col gap-2">
        <div className="flex items-center justify-between">
          <span className="text-sm font-medium text-foreground">作成する明細</span>
          <Button variant="secondary" size="sm" onClick={addItemRow}>
            明細を追加
          </Button>
        </div>
        {items.map((row, index) => (
          <div key={row.rowId} className="grid grid-cols-1 gap-2 rounded-md border border-border p-3 sm:grid-cols-5">
            <NativeSelect
              aria-label={`${index + 1}番目の明細の経費区分`}
              value={row.category_id || ''}
              onChange={(e) => updateItemRow(row.rowId, { category_id: Number(e.target.value) })}
            >
              <option value="">経費区分を選択</option>
              {categories?.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </NativeSelect>
            <Input
              aria-label={`${index + 1}番目の明細の内容の初期値`}
              placeholder="内容(初期値・任意)"
              value={row.description ?? ''}
              onChange={(e) => updateItemRow(row.rowId, { description: e.target.value })}
            />
            <Input
              aria-label={`${index + 1}番目の明細の金額の初期値`}
              type="number"
              min={0}
              placeholder="金額(初期値・任意)"
              value={row.amount ?? ''}
              onChange={(e) => updateItemRow(row.rowId, { amount: e.target.value ? Number(e.target.value) : null })}
            />
            <NativeSelect
              aria-label={`${index + 1}番目の明細の支払方法の初期値`}
              value={row.payment_bearer ?? ''}
              onChange={(e) =>
                updateItemRow(row.rowId, { payment_bearer: (e.target.value || null) as ExpensePaymentBearer | null })
              }
            >
              <option value="">支払方法(初期値・任意)</option>
              {paymentBearers.map((bearer) => (
                <option key={bearer} value={bearer}>
                  {paymentBearerLabel(bearer)}
                </option>
              ))}
            </NativeSelect>
            <Button variant="danger" size="sm" disabled={items.length === 1} onClick={() => removeItemRow(row.rowId)}>
              削除
            </Button>
          </div>
        ))}
      </div>

      <label className="mb-4 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox id="preset-is-active" checked={isActive} onCheckedChange={(checked) => setIsActive(checked === true)} />
        有効
      </label>

      <div className="mt-5">
        <Button isLoading={isBusy} disabled={!canSave} onClick={() => void handleSave()}>
          保存する
        </Button>
      </div>
    </Card>
  )
}
