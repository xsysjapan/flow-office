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
import { useExpenseCategories } from '../../hooks/useExpenseCategories'
import {
  useCreateExpenseRouteTemplate,
  useExpenseRouteTemplates,
  useUpdateExpenseRouteTemplate,
} from '../../hooks/useExpenseRouteTemplates'

const transportTypeOptions = [
  { value: 'train', label: '電車' },
  { value: 'bus', label: 'バス' },
  { value: 'taxi', label: 'タクシー' },
  { value: 'car', label: '自家用車' },
  { value: 'airplane', label: '飛行機' },
  { value: 'other', label: 'その他' },
]

/**
 * UC-X003: 管理者(経理)が全社共有の移動区間テンプレートを作成・編集する。
 */
export function ExpenseRouteTemplateEditPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const isCreate = !id || id === 'new'

  const { data: templates, isLoading, error: listError } = useExpenseRouteTemplates()
  const { data: categories, isLoading: isCategoriesLoading, error: categoriesError } = useExpenseCategories()
  const existing = useMemo(
    () =>
      isCreate
        ? undefined
        : templates?.find((template) => template.scope === 'company' && template.id === Number(id)),
    [templates, id, isCreate],
  )

  const createTemplate = useCreateExpenseRouteTemplate()
  const updateTemplate = useUpdateExpenseRouteTemplate()

  const [name, setName] = useState('')
  const [origin, setOrigin] = useState('')
  const [destination, setDestination] = useState('')
  const [transportType, setTransportType] = useState(transportTypeOptions[0].value)
  const [amount, setAmount] = useState('')
  const [categoryId, setCategoryId] = useState('')
  const [isActive, setIsActive] = useState(true)

  useEffect(() => {
    if (!existing) return
    setName(existing.name)
    setOrigin(existing.origin)
    setDestination(existing.destination)
    setTransportType(existing.transport_type)
    setAmount(String(existing.amount))
    setCategoryId(String(existing.category_id))
    setIsActive(existing.is_active)
  }, [existing])

  useEffect(() => {
    if (!categoryId && categories && categories.length > 0) {
      setCategoryId(String(categories[0].id))
    }
  }, [categories, categoryId])

  if (!isCreate && (isLoading || isCategoriesLoading)) return <LoadingState />
  if (!isCreate && listError) return <ErrorMessage error={listError} fallback="移動区間テンプレートの取得に失敗しました。" />
  if (categoriesError) return <ErrorMessage error={categoriesError} fallback="経費区分の取得に失敗しました。" />

  const isBusy = createTemplate.isPending || updateTemplate.isPending
  const error = createTemplate.error ?? updateTemplate.error

  const handleSave = async () => {
    const input = {
      scope: 'company' as const,
      name,
      origin,
      destination,
      transport_type: transportType,
      amount: Number(amount),
      category_id: Number(categoryId),
      is_active: isActive,
    }

    if (isCreate) {
      await createTemplate.mutateAsync(input)
    } else if (existing) {
      await updateTemplate.mutateAsync({ id: existing.id, input })
    }

    navigate('/admin/expense-route-templates')
  }

  return (
    <Card title={isCreate ? '移動区間テンプレートの新規作成' : '移動区間テンプレートの編集'}>
      {error && <ErrorMessage error={error} />}

      <FormField label="名称" htmlFor="name" required>
        <Input id="name" value={name} onChange={(e) => setName(e.target.value)} />
      </FormField>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="出発地" htmlFor="origin" required>
          <Input id="origin" value={origin} onChange={(e) => setOrigin(e.target.value)} />
        </FormField>

        <FormField label="到着地" htmlFor="destination" required>
          <Input id="destination" value={destination} onChange={(e) => setDestination(e.target.value)} />
        </FormField>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="交通手段" htmlFor="transport-type" required>
          <NativeSelect id="transport-type" value={transportType} onChange={(e) => setTransportType(e.target.value)}>
            {transportTypeOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        <FormField label="金額" htmlFor="amount" required>
          <Input id="amount" type="number" min={0} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
      </div>

      <FormField label="対象経費区分" htmlFor="category-id" required>
        <NativeSelect id="category-id" value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
          {(categories ?? []).map((category) => (
            <option key={category.id} value={category.id}>
              {category.name}
            </option>
          ))}
        </NativeSelect>
      </FormField>

      <label className="mb-4 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox id="is-active" checked={isActive} onCheckedChange={(checked) => setIsActive(checked === true)} />
        有効
      </label>

      <div className="mt-5">
        <Button
          isLoading={isBusy}
          disabled={!name || !origin || !destination || !amount || !categoryId}
          onClick={() => void handleSave()}
        >
          保存する
        </Button>
      </div>
    </Card>
  )
}
