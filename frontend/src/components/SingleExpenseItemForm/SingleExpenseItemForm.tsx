import { useState } from 'react'
import type { SaveExpenseItemInput } from '../../api/expenseClaims'
import { Button } from '../Button/Button'
import { FormField } from '../FormField/FormField'
import { Input } from '../ui/input'
import { Textarea } from '../ui/textarea'

export type SingleExpenseItemFieldSet = 'meal' | 'lodging' | 'generic'

export interface SingleExpenseItemFormProps {
  /** UC-X004b〜d: 経費区分ごとに入力項目が異なる(会食/宿泊/消耗品・その他)。 */
  fieldSet: SingleExpenseItemFieldSet
  categoryId: number
  onSubmit: (input: SaveExpenseItemInput) => void
  isSubmitting?: boolean
}

const fieldSetTitle: Record<SingleExpenseItemFieldSet, string> = {
  meal: '会食・接待費を入力',
  lodging: '宿泊費を入力',
  generic: '消耗品・その他の経費を入力',
}

/**
 * UC-X004b〜d: 会食・宿泊・消耗品/その他の単発経費を1件ずつ入力するフォーム。
 * `fieldSet`によって追加入力項目とdescriptionの整形フォーマットのみが切り替わり、
 * 利用日・金額の共通フィールドと「保存後にフォームをリセットして続けて入力できる」
 * 挙動はどのfieldSetでも共通(バックエンドのデータ構造は増やさない、
 * docs/30-usecases-expense.md UC-X004b〜d)。
 */
export function SingleExpenseItemForm({ fieldSet, categoryId, onSubmit, isSubmitting }: SingleExpenseItemFormProps) {
  const [usageDate, setUsageDate] = useState('')
  const [amount, setAmount] = useState('')
  const [payee, setPayee] = useState('') // 取引先(会食)/宿泊先名(宿泊)/取引先(消耗品・その他)
  const [content, setContent] = useState('') // 内容
  const [participants, setParticipants] = useState('') // 参加者氏名(会食のみ)
  const [participantCount, setParticipantCount] = useState('') // 参加人数(会食のみ)

  const reset = () => {
    setUsageDate('')
    setAmount('')
    setPayee('')
    setContent('')
    setParticipants('')
    setParticipantCount('')
  }

  const isValid = (() => {
    if (!usageDate || !amount) return false
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

  const handleSubmit = () => {
    if (!isValid) return
    onSubmit({
      category_id: categoryId,
      usage_date: usageDate,
      amount: Number(amount),
      description: buildDescription(),
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

      <div>
        <Button disabled={!isValid} isLoading={isSubmitting} onClick={handleSubmit}>
          明細を保存して続けて入力する
        </Button>
      </div>
    </div>
  )
}
