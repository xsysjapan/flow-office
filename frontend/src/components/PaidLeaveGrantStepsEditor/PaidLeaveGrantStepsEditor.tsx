import { useState } from 'react'
import type { PaidLeaveGrantCategory, PaidLeaveGrantPolicies, PaidLeaveGrantRuleStep } from '../../api/types'
import { GRANT_CATEGORIES, GRANT_CATEGORY_LABELS, statutoryMinimumDays } from '../../lib/paidLeaveGrantRule'
import { Button } from '../Button/Button'
import { FormField } from '../FormField/FormField'
import { Input } from '../ui/input'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '../ui/tabs'

export interface PaidLeaveGrantStepsEditorProps {
  steps: PaidLeaveGrantRuleStep[]
  onChange: (steps: PaidLeaveGrantRuleStep[]) => void
  /** 法定表。下回りの参考表示(行ごとの法定最低日数)に使う。未取得のときは表示しない。 */
  statutory: PaidLeaveGrantPolicies | undefined
  /** バックエンドの422のフィールドエラー。キーは `steps.{i}.{項目}`(iはsteps配列の位置)。 */
  fieldErrors?: Record<string, string[]>
}

/**
 * 付与日数の表を区分(通常・週4〜1日)ごとのタブで編集する。行の追加・削除・日数の編集はすべて
 * 親のstepsを`onChange`で差し替える(保存は親の画面の保存ボタンで行う)。
 */
export function PaidLeaveGrantStepsEditor({ steps, onChange, statutory, fieldErrors }: PaidLeaveGrantStepsEditorProps) {
  const [activeCategory, setActiveCategory] = useState<PaidLeaveGrantCategory>('normal')
  const [monthsInput, setMonthsInput] = useState('')
  const [daysInput, setDaysInput] = useState('')

  const errorsOfRow = (index: number): string[] =>
    Object.entries(fieldErrors ?? {})
      .filter(([key]) => key.startsWith(`steps.${index}.`))
      .flatMap(([, messages]) => messages)

  const categoryHasError = (category: PaidLeaveGrantCategory): boolean =>
    steps.some((step, index) => step.grant_category === category && errorsOfRow(index).length > 0)

  const rowsOf = (category: PaidLeaveGrantCategory) =>
    steps
      .map((step, index) => ({ step, index }))
      .filter(({ step }) => step.grant_category === category)
      .sort((a, b) => a.step.continuous_service_months - b.step.continuous_service_months)

  const months = Number(monthsInput)
  const isDuplicate =
    monthsInput !== '' &&
    steps.some((step) => step.grant_category === activeCategory && step.continuous_service_months === months)
  const addDisabledReason =
    monthsInput === '' || daysInput === ''
      ? '継続勤務月数と付与日数を入力してください。'
      : isDuplicate
        ? 'この区分には、同じ継続勤務月数の行があります。'
        : undefined

  const handleAdd = () => {
    if (addDisabledReason) return
    onChange([...steps, { grant_category: activeCategory, continuous_service_months: months, grant_days: Number(daysInput) }])
    setMonthsInput('')
    setDaysInput('')
  }

  const handleChangeDays = (index: number, grantDays: number) => {
    onChange(steps.map((step, i) => (i === index ? { ...step, grant_days: grantDays } : step)))
  }

  const handleRemove = (index: number) => {
    onChange(steps.filter((_, i) => i !== index))
  }

  return (
    <div className="mb-4 flex flex-col gap-2">
      <span className="text-sm font-medium text-foreground">付与日数</span>

      <Tabs value={activeCategory} onValueChange={(value) => setActiveCategory(value as PaidLeaveGrantCategory)}>
        <TabsList className="flex-wrap">
          {GRANT_CATEGORIES.map((category) => (
            <TabsTrigger key={category} value={category}>
              {GRANT_CATEGORY_LABELS[category]}
              {categoryHasError(category) && <span className="ml-1 text-xs text-destructive">(エラーあり)</span>}
            </TabsTrigger>
          ))}
        </TabsList>

        {GRANT_CATEGORIES.map((category) => {
          const rows = rowsOf(category)
          return (
            <TabsContent key={category} value={category}>
              {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">この区分の付与日数はまだありません。</p>
              ) : (
                <ul className="flex flex-col gap-2">
                  {rows.map(({ step, index }) => {
                    const minimum = statutory
                      ? statutoryMinimumDays(statutory, step.grant_category, step.continuous_service_months)
                      : undefined
                    const rowErrors = errorsOfRow(index)
                    return (
                      <li
                        key={`${step.grant_category}-${step.continuous_service_months}`}
                        className="flex flex-wrap items-center gap-2 text-sm"
                      >
                        <span className="text-foreground">継続勤務{step.continuous_service_months}か月</span>
                        <Input
                          type="number"
                          aria-label={`${GRANT_CATEGORY_LABELS[category]} 継続勤務${step.continuous_service_months}か月の付与日数`}
                          className="w-24"
                          value={step.grant_days}
                          onChange={(e) => handleChangeDays(index, Number(e.target.value))}
                        />
                        <span className="text-foreground">日</span>
                        {minimum !== undefined && (
                          <span className="text-xs text-muted-foreground">
                            {`(この継続勤務月数の法定最低日数: ${minimum}日)`}
                          </span>
                        )}
                        {rowErrors.map((message) => (
                          <span key={message} className="text-xs text-destructive">
                            {message}
                          </span>
                        ))}
                        <Button
                          variant="secondary"
                          size="sm"
                          aria-label={`${GRANT_CATEGORY_LABELS[category]} 継続勤務${step.continuous_service_months}か月の行を削除`}
                          onClick={() => handleRemove(index)}
                        >
                          削除
                        </Button>
                      </li>
                    )
                  })}
                </ul>
              )}
            </TabsContent>
          )
        })}
      </Tabs>

      <div className="mt-2 flex flex-col gap-1">
        <p className="text-xs text-muted-foreground">追加先: {GRANT_CATEGORY_LABELS[activeCategory]}</p>
        <div className="flex flex-wrap items-end gap-3">
          <FormField label="継続勤務(か月)" htmlFor="step-months">
            <Input id="step-months" type="number" value={monthsInput} onChange={(e) => setMonthsInput(e.target.value)} />
          </FormField>
          <FormField label="付与日数" htmlFor="step-days">
            <Input id="step-days" type="number" value={daysInput} onChange={(e) => setDaysInput(e.target.value)} />
          </FormField>
          <Button variant="secondary" disabled={addDisabledReason !== undefined} onClick={handleAdd}>
            追加
          </Button>
        </div>
        {addDisabledReason && <p className="text-xs text-muted-foreground">{addDisabledReason}</p>}
      </div>
    </div>
  )
}
