import { useMemo, useState } from 'react'
import { ja } from 'react-day-picker/locale'
import type { ExpenseRouteTemplate } from '../../api/types'
import type { SaveExpenseItemInput } from '../../api/expenseClaims'
import { formatDate } from '../../utils/weekDates'
import { Button } from '../Button/Button'
import { Calendar } from '../ui/calendar'
import { NativeSelect } from '../ui/native-select'
import { Label } from '../ui/label'

export interface ExpenseTemplateBulkGeneratorProps {
  templates: ExpenseRouteTemplate[]
  onGenerate: (items: SaveExpenseItemInput[]) => void
}

function templateOptionLabel(template: ExpenseRouteTemplate): string {
  return `${template.name} (${template.origin} ⇔ ${template.destination}, ${template.transport_type}, ${template.amount.toLocaleString()}円)`
}

/**
 * UC-X008: 1件の移動経路テンプレートを選び、カレンダーから複数日を選択して、
 * 選択日数分の経費明細(SaveExpenseItemInput)を一括生成する。金額はテンプレートの
 * 既定値をそのままコピーするだけで、生成後の編集はこのコンポーネントの外側で行う。
 */
export function ExpenseTemplateBulkGenerator({ templates, onGenerate }: ExpenseTemplateBulkGeneratorProps) {
  const activeTemplates = useMemo(() => templates.filter((template) => template.is_active), [templates])
  const [templateId, setTemplateId] = useState<string>('')
  const [dates, setDates] = useState<Date[]>([])

  const selectedTemplate = activeTemplates.find((template) => String(template.id) === templateId)
  const canGenerate = Boolean(selectedTemplate) && dates.length > 0

  const handleGenerate = () => {
    if (!selectedTemplate || dates.length === 0) return

    const description = `${selectedTemplate.origin} → ${selectedTemplate.destination}(${selectedTemplate.transport_type})`
    const items: SaveExpenseItemInput[] = dates.map((date) => ({
      category_id: selectedTemplate.category_id,
      usage_date: formatDate(date),
      description,
      amount: selectedTemplate.amount,
    }))

    onGenerate(items)
    setDates([])
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="expense-template-bulk-generator-template">テンプレート</Label>
        <NativeSelect
          id="expense-template-bulk-generator-template"
          value={templateId}
          onChange={(event) => setTemplateId(event.target.value)}
        >
          <option value="">テンプレートを選択</option>
          {activeTemplates.map((template) => (
            <option key={template.id} value={template.id}>
              {templateOptionLabel(template)}
            </option>
          ))}
        </NativeSelect>
      </div>

      <div className="flex flex-col gap-1.5">
        <Label>対象日(複数選択可)</Label>
        <Calendar
          mode="multiple"
          locale={ja}
          labels={{
            labelPrevious: () => '前の月へ',
            labelNext: () => '次の月へ',
          }}
          selected={dates}
          onSelect={(nextDates) => setDates(nextDates ?? [])}
        />
      </div>

      {selectedTemplate && dates.length > 0 && (
        <p className="text-sm text-muted-foreground">
          {dates.length}日分・{(selectedTemplate.amount * dates.length).toLocaleString()}円を追加します
        </p>
      )}

      <div>
        <Button type="button" disabled={!canGenerate} onClick={handleGenerate}>
          まとめて追加
        </Button>
      </div>
    </div>
  )
}
