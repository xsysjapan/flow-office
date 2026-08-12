import { useEffect, useState } from 'react'
import type { SaveExpenseItemInput } from '../../api/expenseClaims'
import type { ExpenseCategoryFieldDefinition, ExpenseEntryPresetDefinitionItem, ExpensePaymentBearer } from '../../api/types'
import { Button } from '../Button/Button'
import { DatePicker } from '../DatePicker/DatePicker'
import { FormField } from '../FormField/FormField'
import { Checkbox } from '../ui/checkbox'
import { Input } from '../ui/input'
import { NativeSelect } from '../ui/native-select'
import { Textarea } from '../ui/textarea'
import { paymentBearerLabel } from '../../utils/statusLabels'

export type SingleExpenseItemFieldSet = 'meal' | 'lodging' | 'generic' | 'other'

export interface SingleExpenseItemFormProps {
  /** UC-X004b〜d: 経費区分ごとに入力項目が異なる(会食/宿泊/消耗品・その他)。 */
  fieldSet: SingleExpenseItemFieldSet
  categoryId: number
  /** 「経費精算機能 設計・実装指示書」7.2: 区分固有の追加入力項目。attributesとして保存する。 */
  fieldDefinitions?: ExpenseCategoryFieldDefinition[] | null
  /** 明細の作成と同じ操作で領収書を添付できるよう、選択中のファイルも合わせて渡す
   *  (呼び出し側が明細作成後にこのファイルをアップロードする)。 */
  onSubmit: (input: SaveExpenseItemInput, receiptFile: File | null) => void
  isSubmitting?: boolean
  /** プリセットから適用する下書き定義1件。利用日は持たないため利用日は変更しない。
   *  同じプリセットを続けて選び直しても反映されるよう、呼び出し側は`presetApplyToken`を
   *  クリックのたびにインクリメントして渡す。 */
  presetItem?: ExpenseEntryPresetDefinitionItem | null
  presetApplyToken?: number
}

/** プリセットのattributes(保存用の値)を、このフォームが保持するattributeValuesの
 *  形(text/number/date/selectは文字列、booleanは真偽値)へ変換する。 */
function attributesToFieldValues(
  attributes: Record<string, unknown> | null | undefined,
  fieldDefinitions?: ExpenseCategoryFieldDefinition[] | null,
): Record<string, string | boolean> {
  const values: Record<string, string | boolean> = {}
  for (const field of fieldDefinitions ?? []) {
    const raw = attributes?.[field.key]
    if (raw === undefined || raw === null) continue
    values[field.key] = field.type === 'boolean' ? Boolean(raw) : String(raw)
  }
  return values
}

/** 経費明細の添付ファイルとして許可される拡張子(AttachmentController::EXPENSE_ITEM_ALLOWED_EXTENSIONS)。 */
const RECEIPT_ACCEPT = '.pdf,.jpg,.jpeg,.png'

const fieldSetTitle: Record<SingleExpenseItemFieldSet, string> = {
  meal: '会食・接待費を入力',
  lodging: '宿泊費を入力',
  generic: '消耗品の経費を入力',
  other: 'その他の経費を入力',
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
  presetItem,
  presetApplyToken,
}: SingleExpenseItemFormProps) {
  const [usageDate, setUsageDate] = useState('')
  const [amount, setAmount] = useState('')
  const [payee, setPayee] = useState('') // 取引先(会食)/宿泊先名(宿泊)/取引先(消耗品・その他)
  const [content, setContent] = useState('') // 内容
  const [participants, setParticipants] = useState('') // 参加者氏名(会食のみ)
  const [participantCount, setParticipantCount] = useState('') // 参加人数(会食のみ)
  const [paymentBearer, setPaymentBearer] = useState<ExpensePaymentBearer>('employee')
  const [attributeValues, setAttributeValues] = useState<Record<string, string | boolean>>({})
  const [receiptFile, setReceiptFile] = useState<File | null>(null)
  const [receiptInputKey, setReceiptInputKey] = useState(0)

  const reset = () => {
    setUsageDate('')
    setAmount('')
    setPayee('')
    setContent('')
    setParticipants('')
    setParticipantCount('')
    setPaymentBearer('employee')
    setAttributeValues({})
    setReceiptFile(null)
    // input[type=file]はvalueをプログラムから空にできないため、keyを変えて再マウントする。
    setReceiptInputKey((key) => key + 1)
  }

  // プリセット選択時: 利用日はプリセットに持たせていない(利用のたびに変わるため)ので
  // 変更せず、それ以外の項目だけをプリセットの値で置き換える。取引先は元の内容説明を
  // 分解できないため空にし、内容欄にプリセットの説明文をそのまま入れてユーザーに確認・
  // 調整させる。
  useEffect(() => {
    if (!presetItem || !presetApplyToken) return
    setAmount(presetItem.amount != null ? String(presetItem.amount) : '')
    setPayee('')
    setContent(presetItem.description ?? '')
    setParticipants('')
    setParticipantCount('')
    setPaymentBearer(presetItem.payment_bearer ?? 'employee')
    setAttributeValues(attributesToFieldValues(presetItem.attributes, fieldDefinitions))
    setReceiptFile(null)
    setReceiptInputKey((key) => key + 1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [presetApplyToken])

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
    if (fieldSet === 'other') {
      // その他は取引先が無い経費(例: 郵送料の実費精算等)もあるため、取引先は任意とし、
      // 取引先・内容のどちらか一方が入力されていれば足りるとする。
      return Boolean(payee || content)
    }
    return Boolean(payee)
  })()

  const buildDescription = (): string | undefined => {
    if (fieldSet === 'meal') {
      return `${payee} - ${content} (${participantCount}名: ${participants})`
    }
    if (fieldSet === 'other') {
      if (payee && content) return `${payee} - ${content}`
      return payee || content
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
    onSubmit(
      {
        category_id: categoryId,
        usage_date: usageDate,
        amount: Number(amount),
        description: buildDescription(),
        payment_bearer: paymentBearer,
        attributes: buildAttributes(),
      },
      receiptFile,
    )
    reset()
  }

  return (
    <div className="flex flex-col gap-1">
      <h3 className="text-sm font-semibold text-foreground">{fieldSetTitle[fieldSet]}</h3>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="利用日" htmlFor="single-item-usage-date" required>
          <DatePicker
            id="single-item-usage-date"
            value={usageDate || undefined}
            onChange={(date) => setUsageDate(date ?? '')}
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

      {fieldSet === 'other' && (
        <>
          <p className="text-xs text-muted-foreground">取引先・内容のいずれかは入力してください(取引先が無い経費もあるため両方必須にはしません)</p>
          <FormField label="取引先" htmlFor="single-item-payee">
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

      <FormField label="領収書(任意)" htmlFor="single-item-receipt-file">
        <input
          key={receiptInputKey}
          id="single-item-receipt-file"
          type="file"
          accept={RECEIPT_ACCEPT}
          className="text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1 file:text-sm file:font-medium file:text-foreground"
          onChange={(e) => setReceiptFile(e.target.files?.[0] ?? null)}
        />
        <p className="mt-1 text-xs text-muted-foreground">この場で選択すると、明細の保存と同時に添付されます。後から追加・変更することもできます。</p>
      </FormField>

      <div>
        <Button disabled={!isValid} isLoading={isSubmitting} onClick={handleSubmit}>
          明細を保存して続けて入力する
        </Button>
      </div>
    </div>
  )
}
