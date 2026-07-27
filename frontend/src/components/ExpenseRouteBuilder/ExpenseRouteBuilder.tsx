import { useRef, useState } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { Input } from '../ui/input'
import { NativeSelect } from '../ui/native-select'
import { Checkbox } from '../ui/checkbox'
import { FormField } from '../FormField/FormField'
import { Button } from '../Button/Button'
import type { SaveExpenseItemInput } from '../../api/expenseClaims'
import type { ExpenseCategory } from '../../api/types'

export interface ExpenseRouteBuilderProps {
  categories: ExpenseCategory[]
  /** ルート内の全区間に共通する既定の対象日・経費区分・目的(生成時にこの値を各明細へ複製する)。 */
  defaultCategoryId?: number
  defaultUsageDate?: string
  onGenerate: (items: SaveExpenseItemInput[]) => void
}

interface StopState {
  id: number
  name: string
}

interface SegmentState {
  transportType: string
  amount: string
  categoryId: number | ''
  /** 徒歩・私用区間など、精算対象外として明細生成から除外する区間。 */
  excluded: boolean
}

function makeSegment(defaultCategoryId?: number): SegmentState {
  return { transportType: '', amount: '', categoryId: defaultCategoryId ?? '', excluded: false }
}

/**
 * UC-X007: 1日の移動経路(自宅→会社→訪問先...)を訪問順の地点として入力し、連続する
 * 地点2つずつを1区間として交通手段・金額・経費区分を入力する。「精算対象外」
 * (徒歩・私用区間)にした区間は生成される明細から除外される。
 */
export function ExpenseRouteBuilder({
  categories,
  defaultCategoryId,
  defaultUsageDate,
  onGenerate,
}: ExpenseRouteBuilderProps) {
  const idCounter = useRef(0)
  const nextId = () => {
    idCounter.current += 1
    return idCounter.current
  }
  const makeInitialStops = (): StopState[] => [
    { id: nextId(), name: '' },
    { id: nextId(), name: '' },
  ]

  const [usageDate, setUsageDate] = useState(defaultUsageDate ?? '')
  const [stops, setStops] = useState<StopState[]>(() => makeInitialStops())
  const [segments, setSegments] = useState<SegmentState[]>(() => [makeSegment(defaultCategoryId)])

  const addStop = () => {
    setStops((prev) => [...prev, { id: nextId(), name: '' }])
    setSegments((prev) => [...prev, makeSegment(defaultCategoryId)])
  }

  const removeStop = (index: number) => {
    if (stops.length <= 2) return
    setStops((prev) => prev.filter((_, i) => i !== index))
    setSegments((prev) => {
      const removeAt = Math.max(index - 1, 0)
      return prev.filter((_, i) => i !== removeAt)
    })
  }

  const updateStopName = (index: number, name: string) => {
    setStops((prev) => prev.map((stop, i) => (i === index ? { ...stop, name } : stop)))
  }

  const updateSegment = (index: number, patch: Partial<SegmentState>) => {
    setSegments((prev) => prev.map((segment, i) => (i === index ? { ...segment, ...patch } : segment)))
  }

  const validStopCount = stops.filter((stop) => stop.name.trim() !== '').length
  const canGenerate = usageDate !== '' && validStopCount >= 2

  const handleGenerate = () => {
    if (!canGenerate) return

    const items: SaveExpenseItemInput[] = []
    for (let i = 0; i < stops.length - 1; i += 1) {
      const segment = segments[i]
      if (!segment || segment.excluded) continue
      const origin = stops[i].name
      const destination = stops[i + 1].name
      const description = segment.transportType
        ? `${origin} → ${destination}(${segment.transportType})`
        : `${origin} → ${destination}`
      items.push({
        category_id: segment.categoryId === '' ? (defaultCategoryId ?? categories[0]?.id ?? 0) : segment.categoryId,
        usage_date: usageDate,
        description,
        amount: Number(segment.amount) || 0,
      })
    }

    onGenerate(items)

    setStops(makeInitialStops())
    setSegments([makeSegment(defaultCategoryId)])
    setUsageDate(defaultUsageDate ?? '')
  }

  return (
    <div className="flex flex-col gap-4">
      <FormField label="対象日" htmlFor="expense-route-usage-date">
        <Input
          id="expense-route-usage-date"
          type="date"
          value={usageDate}
          onChange={(e) => setUsageDate(e.target.value)}
        />
      </FormField>

      <div className="flex flex-col gap-2">
        <p className="text-sm font-medium text-foreground">経路(訪問順)</p>
        {stops.map((stop, index) => (
          <div key={stop.id} className="flex items-end gap-2">
            <div className="flex-1">
              <FormField label={`地点${index + 1}`} htmlFor={`expense-route-stop-${stop.id}`}>
                <Input
                  id={`expense-route-stop-${stop.id}`}
                  value={stop.name}
                  onChange={(e) => updateStopName(index, e.target.value)}
                  placeholder="例: 自宅、会社、訪問先"
                />
              </FormField>
            </div>
            <Button
              type="button"
              variant="secondary"
              size="icon"
              aria-label={`地点${index + 1}を削除`}
              disabled={stops.length <= 2}
              onClick={() => removeStop(index)}
            >
              <Trash2 aria-hidden="true" />
            </Button>
          </div>
        ))}
        <Button type="button" variant="secondary" onClick={addStop}>
          <Plus aria-hidden="true" />
          地点を追加
        </Button>
      </div>

      {segments.length > 0 && (
        <div className="flex flex-col gap-3">
          <p className="text-sm font-medium text-foreground">区間ごとの明細</p>
          {stops.slice(0, -1).map((stop, index) => {
            const segment = segments[index]
            if (!segment) {
              return null
            }
            const nextStop = stops[index + 1]
            return (
              <div key={stop.id} className="flex flex-col gap-2 rounded-md border border-border p-3">
                <p className="text-sm text-foreground">
                  {stop.name || `地点${index + 1}`} → {nextStop.name || `地点${index + 2}`}
                </p>
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
                  <div className="w-40">
                    <FormField label="交通手段" htmlFor={`expense-route-transport-${stop.id}`}>
                      <Input
                        id={`expense-route-transport-${stop.id}`}
                        value={segment.transportType}
                        onChange={(e) => updateSegment(index, { transportType: e.target.value })}
                        placeholder="例: 電車"
                      />
                    </FormField>
                  </div>
                  <div className="w-32">
                    <FormField label="金額" htmlFor={`expense-route-amount-${stop.id}`}>
                      <Input
                        id={`expense-route-amount-${stop.id}`}
                        type="number"
                        value={segment.amount}
                        disabled={segment.excluded}
                        onChange={(e) => updateSegment(index, { amount: e.target.value })}
                      />
                    </FormField>
                  </div>
                  <div className="w-40">
                    <FormField label="経費区分" htmlFor={`expense-route-category-${stop.id}`}>
                      <NativeSelect
                        id={`expense-route-category-${stop.id}`}
                        value={segment.categoryId}
                        onChange={(e) =>
                          updateSegment(index, { categoryId: e.target.value === '' ? '' : Number(e.target.value) })
                        }
                      >
                        <option value="">未選択</option>
                        {categories.map((category) => (
                          <option key={category.id} value={category.id}>
                            {category.name}
                          </option>
                        ))}
                      </NativeSelect>
                    </FormField>
                  </div>
                  <div className="pb-2">
                    <label
                      className="flex items-center gap-1.5 text-sm text-foreground"
                      htmlFor={`expense-route-excluded-${stop.id}`}
                    >
                      <Checkbox
                        id={`expense-route-excluded-${stop.id}`}
                        checked={segment.excluded}
                        onCheckedChange={(checked) => updateSegment(index, { excluded: checked === true })}
                      />
                      精算対象外
                    </label>
                  </div>
                </div>
                {segment.excluded && <p className="text-xs text-muted-foreground">徒歩・私用区間</p>}
              </div>
            )
          })}
        </div>
      )}

      <div>
        <Button type="button" variant="primary" disabled={!canGenerate} onClick={handleGenerate}>
          経路から明細を生成
        </Button>
      </div>
    </div>
  )
}
