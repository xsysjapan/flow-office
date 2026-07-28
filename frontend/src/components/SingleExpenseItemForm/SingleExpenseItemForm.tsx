import { useState } from 'react'
import type { SaveExpenseItemInput } from '../../api/expenseClaims'
import type { ExpenseCategoryFieldDefinition, ExpensePaymentBearer } from '../../api/types'
import { Button } from '../Button/Button'
import { FormField } from '../FormField/FormField'
import { Checkbox } from '../ui/checkbox'
import { Input } from '../ui/input'
import { NativeSelect } from '../ui/native-select'
import { Textarea } from '../ui/textarea'
import { paymentBearerLabel } from '../../utils/statusLabels'

export type SingleExpenseItemFieldSet = 'meal' | 'lodging' | 'generic'

export interface SingleExpenseItemFormProps {
  /** UC-X004b〜d: 経費区分ごとに入力項目が異なる(会食/宿泊/消耗品・その他)。 */
  fieldSet: SingleExpenseItemFieldSet
  categoryId: number
  /** 「経費精算機能 設計・実装指示書」7.2: 区分固有の追加入力項目。attributesとして保存する。 */
  fieldDefinitions?: ExpenseCategoryFieldDefinition[] | null
  onSubmit: (input: SaveExpenseItemInput) => void
  isSubmitting?: boolean
}

const fieldSetTitle: Record<SingleExpenseItemFieldSet, string> = {
  meal: '会食・接待費を入力',
  lodging: '宿泊費を入力',
  generic: '消耗品・その他の経費を入力',
}

const PAYMENT_BEARERS: ExpensePaymentBearer[] = ['employee', 'corporate_card', 'company', 'customer', 'other']

/** field_definitionsから受け取った値を、typeに応じてJSON保存用の値に変換する。 */
function coerceAttributeValue(field: ExpenseCategoryFieldDefinition, raw: string | boolean): unknown {
  if (field.type === 'number') return raw === '' ? null : Number(raw)
  if (field.type === 'boolean') return Boolean(raw)
  return raw === '' ? null : raw
}

/**
 * UC-X004b〜d: 会食・宿泊・消耗品/その他の単発経費を1件ずつ入力するフォーム。
 * `fieldSet`によって追加入力項目とdescriptionの整形フォーマットのみが切り替わり、
 * 利用日・金額の共通フィールドと「保存後にフォームをリセットして続けて入力できる」
 * 挙動はどのfieldSetでも共通(バックエンドのデータ構造は増やさない、
 * docs/30-usecases-expense.md UC-X004b〜d)。
 * fieldDefinitionsが設定された区分では、その追加項目を動的に表示しattributesへ保存する
 * (「経費精算機能 設計・実装指示書」6.5/7.2)。
 */
export function SingleExpenseItemForm({
  fieldSet,
  categoryId,
  fieldDefinitions,
  onSubmit,
  isSubmitting,
}: SingleExpenseItemFormProps) {
  const [usageDate, setUsageDate] = useState('')
  const [amount, setAmount] = useState('')
  const [payee, setPayee] = useState('') // 取引先(会食)/宿泊先名(宿泊)/取引先(消耗品・その他)
  const [content, setContent] = useState('') // 内容
  const [participants, setParticipants] = useState('') // 参加者氏名(会食のみ)
  const [participantCount, setParticipantCount] = useState('') // 参加人数(会食のみ)
  const [paymentBearer, setPaymentBearer] = useState<ExpensePaymentBearer>('employee')
  const [attributeValues, setAttributeValues] = useState<Record<string, string | boolean>>({})

  const reset = () => {
    setUsageDate('')
    setAmount('')
    setPayee('')
    setContent('')
    setParticipants('')
    setParticipantCount('')
    setPaymentBearer('employee')
    setAttributeValues({})
  }

  const hasRequiredAttributes = (fieldDefinitions ?? [])
    .filter((field) => field.required)
    .every((field) => {
      const value = attributeValues[field.key]
      return field.type === 'boolean' ? true : Boolean(value)
    })

  const isValid = (() => {
    if (!usageDate || !amount || !hasRequiredAttributes) return false
    if (fieldSet === 'meal') {
      return Boolean(payee && participants && participantCount && content)
    }
    if (fieldSet === 'lodging') {
      return Boolean(payee)
    }
    return Boolean(payee)
  })()

  const buildDescription = (): string | undefined => {
    if (fieldSet === 'meal') {
      return `${payee} - ${content} (${participantCount}名: ${participants})`
    }
    if (fieldSet === 'lodging') {
      return content ? `${payee} - ${content}` : payee
    }
    return content ? `${payee} - ${content}` : payee
  }

  const buildAttributes = (): Record<string, unknown> | undefined => {
    if (!fieldDefinitions || fieldDefinitions.length === 0) return undefined
    const attributes: Record<string, unknown> = {}
    for (const field of fieldDefinitions) {
      attributes[field.key] = coerceAttributeValue(field, attributeValues[field.key] ?? '')
    }
    return attributes
  }

  const handleSubmit = () => {
    if (!isValid) return
    onSubmit({
      category_id: categoryId,
      usage_date: usageDate,
      amount: Number(amount),
      description: buildDescription(),
      payment_bearer: paymentBearer,
      attributes: buildAttributes(),
    })
    reset()
  }

  return (
    <div className="flex flex-col gap-1">
      <h3 className="text-sm font-semibold text-foreground">{fieldSetTitle[fieldSet]}</h3>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="利用日" htmlFor="single-item-usage-date" required>
          <Input
            id="single-item-usage-date"
            type="date"
            value={usageDate}
            onChange={(e) => setUsageDate(e.target.value)}
          />
        </FormField>

        <FormField label="金額" htmlFor="single-item-amount" required>
          <Input
            id="single-item-amount"
            type="number"
            min={0}
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
          />
        </FormField>
      </div>

      {fieldSet === 'meal' && (
        <>
          <FormField label="取引先" htmlFor="single-item-payee" required>
            <Input id="single-item-payee" value={payee} onChange={(e) => setPayee(e.target.value)} />
          </FormField>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label="参加者氏名" htmlFor="single-item-participants" required>
              <Input
                id="single-item-participants"
                value={participants}
                onChange={(e) => setParticipants(e.target.value)}
              />
            </FormField>
            <FormField label="参加人数" htmlFor="single-item-participant-count" required>
              <Input
                id="single-item-participant-count"
                type="number"
                min={0}
                value={participantCount}
                onChange={(e) => setParticipantCount(e.target.value)}
              />
            </FormField>
          </div>
          <FormField label="内容" htmlFor="single-item-content" required>
            <Textarea id="single-item-content" value={content} onChange={(e) => setContent(e.target.value)} />
          </FormField>
        </>
      )}

      {fieldSet === 'lodging' && (
        <>
          <FormField label="宿泊先名" htmlFor="single-item-payee" required>
            <Input id="single-item-payee" value={payee} onChange={(e) => setPayee(e.target.value)} />
          </FormField>
          <FormField label="内容" htmlFor="single-item-content">
            <Textarea id="single-item-content" value={content} onChange={(e) => setContent(e.target.value)} />
          </FormField>
        </>
      )}

      {fieldSet === 'generic' && (
        <>
          <FormField label="取引先" htmlFor="single-item-payee" required>
            <Input id="single-item-payee" value={payee} onChange={(e) => setPayee(e.target.value)} />
          </FormField>
          <FormField label="内容" htmlFor="single-item-content">
            <Textarea id="single-item-content" value={content} onChange={(e) => setContent(e.target.value)} />
          </FormField>
        </>
      )}

      <FormField label="支払方法" htmlFor="single-item-payment-bearer">
        <NativeSelect
          id="single-item-payment-bearer"
          value={paymentBearer}
          onChange={(e) => setPaymentBearer(e.target.value as ExpensePaymentBearer)}
        >
          {PAYMENT_BEARERS.map((bearer) => (
            <option key={bearer} value={bearer}>
              {paymentBearerLabel(bearer)}
            </option>
          ))}
        </NativeSelect>
      </FormField>

      {fieldDefinitions?.map((field) => (
        <FormField key={field.key} label={field.label} htmlFor={`single-item-attr-${field.key}`} required={field.required}>
          {field.type === 'boolean' ? (
            <label className="flex items-center gap-2 text-sm text-foreground">
              <Checkbox
                id={`single-item-attr-${field.key}`}
                checked={Boolean(attributeValues[field.key])}
                onCheckedChange={(checked) =>
                  setAttributeValues((prev) => ({ ...prev, [field.key]: checked === true }))
                }
              />
              {field.label}
            </label>
          ) : field.type === 'select' ? (
            <NativeSelect
              id={`single-item-attr-${field.key}`}
              value={(attributeValues[field.key] as string) ?? ''}
              onChange={(e) => setAttributeValues((prev) => ({ ...prev, [field.key]: e.target.value }))}
            >
              <option value="">選択してください</option>
              {field.options?.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </NativeSelect>
          ) : (
            <Input
              id={`single-item-attr-${field.key}`}
              type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
              value={(attributeValues[field.key] as string) ?? ''}
              onChange={(e) => setAttributeValues((prev) => ({ ...prev, [field.key]: e.target.value }))}
            />
          )}
        </FormField>
      ))}

      <div>
        <Button disabled={!isValid} isLoading={isSubmitting} onClick={handleSubmit}>
          明細を保存して続けて入力する
        </Button>
      </div>
    </div>
  )
}
