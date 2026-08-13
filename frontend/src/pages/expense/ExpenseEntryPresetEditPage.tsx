import { useEffect, useState } from 'react'
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
  ExpenseCategory,
  ExpenseEntryPresetDefinitionItem,
  ExpenseEntryPresetType,
  ExpenseEntryPresetVisibility,
  ExpensePaymentBearer,
} from '../../api/types'
import { useExpenseCategories } from '../../hooks/useExpenseCategories'
import {
  useCreateExpenseEntryPreset,
  useExpenseEntryPreset,
  useUpdateExpenseEntryPreset,
} from '../../hooks/useExpenseEntryPresets'
import { paymentBearerLabel } from '../../utils/statusLabels'
import {
  buildExpenseItemDescription,
  composeRouteDescription,
  fieldSetForCategory,
} from '../../utils/expenseItemFieldSet'

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

type DefinitionRow = ExpenseEntryPresetDefinitionItem & { rowId: number }

let itemRowSeq = 0
function newDefinitionRow(): DefinitionRow {
  itemRowSeq += 1
  return { rowId: itemRowSeq, category_id: 0, amount: null, payment_bearer: null }
}

/**
 * 「経費精算機能 設計・実装指示書」10.1〜10.4: プリセットの作成・編集。ユーザーにJSONを
 * 直接編集させず、明細1件ごとの初期値をGUIで設定する。任意のコード・SQL・外部API呼び出しは
 * 持たせない。
 *
 * 入力欄は選んだ経費区分に応じて、実際の入力画面(SingleExpenseItemForm)と同じ入力補助欄
 * (交通費なら出発地/到着地、会食なら取引先・参加者情報など)を出す。これによりプリセットを
 * 適用したときに内容だけでなく入力補助欄まで初期値が入る。保存時は表示用・交通費のまとめ
 * 入力(表形式)用にdescriptionも同じ整形ルールで自動生成して一緒に保存する。
 */
export function ExpenseEntryPresetEditPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const isCreate = !id || id === 'new'

  const { data: existing, isLoading, error: loadError } = useExpenseEntryPreset(isCreate ? undefined : Number(id))
  const { data: categories, isLoading: isCategoriesLoading, error: categoriesError } = useExpenseCategories()

  const createPreset = useCreateExpenseEntryPreset()
  const updatePreset = useUpdateExpenseEntryPreset()

  const [visibility, setVisibility] = useState<ExpenseEntryPresetVisibility>('personal')
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [presetType, setPresetType] = useState<ExpenseEntryPresetType>('single_item')
  const [items, setItems] = useState<DefinitionRow[]>([newDefinitionRow()])
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
  if (!isCreate && loadError) return <ErrorMessage error={loadError} fallback="プリセットの取得に失敗しました。" />
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
      definition: items.map((item) => {
        const category = categories?.find((c) => c.id === item.category_id)
        const fieldSet = category ? fieldSetForCategory(category) : 'generic'
        // 交通費は出発地/到着地から、それ以外は取引先+内容からdescriptionを組み立て、
        // 表示・交通費のまとめ入力(表形式)でそのまま使えるようにする。
        const content =
          fieldSet === 'transport'
            ? composeRouteDescription(item.departure ?? '', item.destination ?? '')
            : (item.content ?? '')
        return {
          category_id: item.category_id,
          description:
            buildExpenseItemDescription(fieldSet, {
              payee: item.payee ?? '',
              content,
              participants: item.participants ?? '',
              participantCount: item.participant_count != null ? String(item.participant_count) : '',
            }) || undefined,
          amount: item.amount || undefined,
          payment_bearer: item.payment_bearer || undefined,
          payee: item.payee || undefined,
          content: content || undefined,
          participants: item.participants || undefined,
          participant_count: item.participant_count ?? undefined,
          departure: item.departure || undefined,
          destination: item.destination || undefined,
        }
      }),
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
        <p className="text-xs text-muted-foreground">
          ここで入力した値は、経費精算の入力画面でプリセットを選んだときの初期値になります。利用日は
          使うたびに変わるためプリセットには持たせません。
        </p>
        {items.map((row, index) => (
          <PresetDefinitionRowFields
            key={row.rowId}
            row={row}
            index={index}
            categories={categories ?? []}
            canRemove={items.length > 1}
            onChange={(patch) => updateItemRow(row.rowId, patch)}
            onRemove={() => removeItemRow(row.rowId)}
          />
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

/** 明細1件分の初期値入力欄。選んだ経費区分のfieldSetに応じて、実際の入力画面と同じ
 *  入力補助欄を出す(区分未選択のうちは共通項目だけを出す)。 */
function PresetDefinitionRowFields({
  row,
  index,
  categories,
  canRemove,
  onChange,
  onRemove,
}: {
  row: DefinitionRow
  index: number
  categories: ExpenseCategory[]
  canRemove: boolean
  onChange: (patch: Partial<ExpenseEntryPresetDefinitionItem>) => void
  onRemove: () => void
}) {
  const category = categories.find((c) => c.id === row.category_id)
  const fieldSet = category ? fieldSetForCategory(category) : undefined
  const label = `${index + 1}番目の明細`

  return (
    <div className="flex flex-col gap-3 rounded-md border border-border p-3">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <FormField label="経費区分" htmlFor={`preset-item-${row.rowId}-category`} required>
          <NativeSelect
            id={`preset-item-${row.rowId}-category`}
            aria-label={`${label}の経費区分`}
            value={row.category_id || ''}
            onChange={(e) => onChange({ category_id: Number(e.target.value) })}
          >
            <option value="">経費区分を選択</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        <FormField label="金額(初期値・任意)" htmlFor={`preset-item-${row.rowId}-amount`}>
          <Input
            id={`preset-item-${row.rowId}-amount`}
            aria-label={`${label}の金額の初期値`}
            type="number"
            min={0}
            value={row.amount ?? ''}
            onChange={(e) => onChange({ amount: e.target.value ? Number(e.target.value) : null })}
          />
        </FormField>

        <FormField label="支払方法(初期値・任意)" htmlFor={`preset-item-${row.rowId}-payment-bearer`}>
          <NativeSelect
            id={`preset-item-${row.rowId}-payment-bearer`}
            aria-label={`${label}の支払方法の初期値`}
            value={row.payment_bearer ?? ''}
            onChange={(e) => onChange({ payment_bearer: (e.target.value || null) as ExpensePaymentBearer | null })}
          >
            <option value="">支払方法(初期値・任意)</option>
            {paymentBearers.map((bearer) => (
              <option key={bearer} value={bearer}>
                {paymentBearerLabel(bearer)}
              </option>
            ))}
          </NativeSelect>
        </FormField>
      </div>

      {fieldSet === 'transport' && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <FormField label="出発地(初期値・任意)" htmlFor={`preset-item-${row.rowId}-departure`}>
            <Input
              id={`preset-item-${row.rowId}-departure`}
              aria-label={`${label}の出発地の初期値`}
              value={row.departure ?? ''}
              onChange={(e) => onChange({ departure: e.target.value })}
            />
          </FormField>
          <FormField label="到着地(初期値・任意)" htmlFor={`preset-item-${row.rowId}-destination`}>
            <Input
              id={`preset-item-${row.rowId}-destination`}
              aria-label={`${label}の到着地の初期値`}
              value={row.destination ?? ''}
              onChange={(e) => onChange({ destination: e.target.value })}
            />
          </FormField>
        </div>
      )}

      {fieldSet && fieldSet !== 'transport' && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <FormField
            label={fieldSet === 'lodging' ? '宿泊先名(初期値・任意)' : '取引先(初期値・任意)'}
            htmlFor={`preset-item-${row.rowId}-payee`}
          >
            <Input
              id={`preset-item-${row.rowId}-payee`}
              aria-label={`${label}の${fieldSet === 'lodging' ? '宿泊先名' : '取引先'}の初期値`}
              value={row.payee ?? ''}
              onChange={(e) => onChange({ payee: e.target.value })}
            />
          </FormField>
          <FormField label="内容(初期値・任意)" htmlFor={`preset-item-${row.rowId}-content`}>
            <Input
              id={`preset-item-${row.rowId}-content`}
              aria-label={`${label}の内容の初期値`}
              value={row.content ?? ''}
              onChange={(e) => onChange({ content: e.target.value })}
            />
          </FormField>
        </div>
      )}

      {fieldSet === 'meal' && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <FormField label="参加者氏名(初期値・任意)" htmlFor={`preset-item-${row.rowId}-participants`}>
            <Input
              id={`preset-item-${row.rowId}-participants`}
              aria-label={`${label}の参加者氏名の初期値`}
              value={row.participants ?? ''}
              onChange={(e) => onChange({ participants: e.target.value })}
            />
          </FormField>
          <FormField label="参加人数(初期値・任意)" htmlFor={`preset-item-${row.rowId}-participant-count`}>
            <Input
              id={`preset-item-${row.rowId}-participant-count`}
              aria-label={`${label}の参加人数の初期値`}
              type="number"
              min={0}
              value={row.participant_count ?? ''}
              onChange={(e) => onChange({ participant_count: e.target.value ? Number(e.target.value) : null })}
            />
          </FormField>
        </div>
      )}

      <div>
        <Button variant="danger" size="sm" disabled={!canRemove} onClick={onRemove}>
          削除
        </Button>
      </div>
    </div>
  )
}
