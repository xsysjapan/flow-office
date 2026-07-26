import { useState } from 'react'
import { ArrowDown, ArrowUp, Copy, Trash2 } from 'lucide-react'
import type { EditableRow } from '../../hooks/useEditableRows'
import type { SaveExpenseItemInput } from '../../api/expenseClaims'
import type { ExpenseCategory } from '../../api/types'
import { Button } from '../Button/Button'
import { Input } from '../ui/input'
import { NativeSelect } from '../ui/native-select'
import { Checkbox } from '../ui/checkbox'
import { Textarea } from '../ui/textarea'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../ui/table'

export interface ExpenseItemsTableProps {
  rows: EditableRow<SaveExpenseItemInput>[]
  categories: ExpenseCategory[]
  onAddRow: () => void
  onUpdateRow: (rowId: number, patch: Partial<SaveExpenseItemInput>) => void
  onRemoveRow: (rowId: number) => void
  onDuplicateRow: (rowId: number) => void
  onMoveRow: (rowId: number, direction: 'up' | 'down') => void
  onPasteRows: (rows: SaveExpenseItemInput[]) => void
}

/** 貼り付けテキストを "usage_date,origin,destination,transport_type,amount,destination_name,purpose" の
 *  タブ/カンマ区切り行としてパースする(UC-X006手順3)。categoryは含まれないため呼び出し側で既定値を補う。 */
function parsePastedRows(text: string, defaultCategoryId: number): SaveExpenseItemInput[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.length > 0)
    .map((line) => {
      const cols = line.split(/\t|,/).map((c) => c.trim())
      const [usage_date = '', origin = '', destination = '', transport_type = '', amountRaw = '', destination_name = '', purpose = ''] =
        cols
      const amount = Number(amountRaw)
      return {
        category_id: defaultCategoryId,
        usage_date,
        origin: origin || undefined,
        destination: destination || undefined,
        transport_type: transport_type || undefined,
        amount: Number.isFinite(amount) ? amount : 0,
        destination_name: destination_name || undefined,
        purpose: purpose || undefined,
      }
    })
}

export function ExpenseItemsTable({
  rows,
  categories,
  onAddRow,
  onUpdateRow,
  onRemoveRow,
  onDuplicateRow,
  onMoveRow,
  onPasteRows,
}: ExpenseItemsTableProps) {
  const [selectedRowIds, setSelectedRowIds] = useState<number[]>([])
  const [showPasteArea, setShowPasteArea] = useState(false)
  const [pasteText, setPasteText] = useState('')
  const [bulkDate, setBulkDate] = useState('')
  const [bulkPurpose, setBulkPurpose] = useState('')
  const [bulkProjectId, setBulkProjectId] = useState('')

  const total = rows.reduce((sum, row) => sum + (row.amount || 0), 0)

  function toggleRowSelected(rowId: number, checked: boolean) {
    setSelectedRowIds((prev) => (checked ? [...prev, rowId] : prev.filter((id) => id !== rowId)))
  }

  function handleImportPastedRows() {
    const defaultCategoryId = categories[0]?.id ?? 0
    const parsed = parsePastedRows(pasteText, defaultCategoryId)
    onPasteRows(parsed)
    setPasteText('')
    setShowPasteArea(false)
  }

  function handleApplyBulkFields() {
    const patch: Partial<SaveExpenseItemInput> = {}
    if (bulkDate) patch.usage_date = bulkDate
    if (bulkPurpose) patch.purpose = bulkPurpose
    if (bulkProjectId) patch.project_id = bulkProjectId
    if (Object.keys(patch).length === 0) return
    selectedRowIds.forEach((rowId) => onUpdateRow(rowId, patch))
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-2">
        <Button type="button" variant="secondary" onClick={onAddRow}>
          行を追加
        </Button>
        <Button type="button" variant="secondary" onClick={() => setShowPasteArea((prev) => !prev)}>
          複数行貼り付け
        </Button>
      </div>

      {showPasteArea && (
        <div className="flex flex-col gap-2 rounded-md border border-border bg-muted/30 p-3">
          <label htmlFor="expense-items-paste-area" className="text-sm font-medium text-foreground">
            貼り付け(日付,出発地,到着地,手段,金額,訪問先,目的の順・1行1明細)
          </label>
          <Textarea
            id="expense-items-paste-area"
            value={pasteText}
            onChange={(e) => setPasteText(e.target.value)}
            placeholder={'2026-07-01\t自宅\t本社\t電車\t420\t本社\t定例会議'}
          />
          <p className="text-xs text-muted-foreground">取り込み後に経費区分を選択してください</p>
          <div>
            <Button type="button" variant="secondary" onClick={handleImportPastedRows}>
              取り込む
            </Button>
          </div>
        </div>
      )}

      {selectedRowIds.length > 0 && (
        <div className="flex flex-wrap items-end gap-2 rounded-md border border-border bg-muted/30 p-3">
          <div className="flex flex-col gap-1">
            <label htmlFor="expense-items-bulk-date" className="text-xs font-medium text-foreground">
              日付
            </label>
            <Input id="expense-items-bulk-date" type="date" value={bulkDate} onChange={(e) => setBulkDate(e.target.value)} />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="expense-items-bulk-purpose" className="text-xs font-medium text-foreground">
              目的
            </label>
            <Input id="expense-items-bulk-purpose" value={bulkPurpose} onChange={(e) => setBulkPurpose(e.target.value)} />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="expense-items-bulk-project" className="text-xs font-medium text-foreground">
              案件
            </label>
            <Input id="expense-items-bulk-project" value={bulkProjectId} onChange={(e) => setBulkProjectId(e.target.value)} />
          </div>
          <Button type="button" variant="secondary" onClick={handleApplyBulkFields}>
            選択行に反映
          </Button>
        </div>
      )}

      <Table>
        <TableHeader>
          <TableRow>
            <TableHead />
            <TableHead>日付</TableHead>
            <TableHead>経費区分</TableHead>
            <TableHead>出発地</TableHead>
            <TableHead>到着地</TableHead>
            <TableHead>手段</TableHead>
            <TableHead>金額</TableHead>
            <TableHead>訪問先</TableHead>
            <TableHead>目的</TableHead>
            <TableHead>操作</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row, index) => (
            <TableRow key={row.rowId}>
              <TableCell>
                <Checkbox
                  aria-label={`${index + 1}行目を選択`}
                  checked={selectedRowIds.includes(row.rowId)}
                  onCheckedChange={(checked) => toggleRowSelected(row.rowId, checked === true)}
                />
              </TableCell>
              <TableCell>
                <Input
                  type="date"
                  aria-label={`${index + 1}行目の日付`}
                  value={row.usage_date}
                  onChange={(e) => onUpdateRow(row.rowId, { usage_date: e.target.value })}
                />
              </TableCell>
              <TableCell>
                <NativeSelect
                  aria-label={`${index + 1}行目の経費区分`}
                  value={row.category_id}
                  onChange={(e) => onUpdateRow(row.rowId, { category_id: Number(e.target.value) })}
                >
                  {categories.map((category) => (
                    <option key={category.id} value={category.id}>
                      {category.name}
                    </option>
                  ))}
                </NativeSelect>
              </TableCell>
              <TableCell>
                <Input
                  aria-label={`${index + 1}行目の出発地`}
                  value={row.origin ?? ''}
                  onChange={(e) => onUpdateRow(row.rowId, { origin: e.target.value })}
                />
              </TableCell>
              <TableCell>
                <Input
                  aria-label={`${index + 1}行目の到着地`}
                  value={row.destination ?? ''}
                  onChange={(e) => onUpdateRow(row.rowId, { destination: e.target.value })}
                />
              </TableCell>
              <TableCell>
                <Input
                  aria-label={`${index + 1}行目の手段`}
                  value={row.transport_type ?? ''}
                  onChange={(e) => onUpdateRow(row.rowId, { transport_type: e.target.value })}
                />
              </TableCell>
              <TableCell>
                <Input
                  type="number"
                  aria-label={`${index + 1}行目の金額`}
                  value={row.amount}
                  onChange={(e) => onUpdateRow(row.rowId, { amount: Number(e.target.value) })}
                />
              </TableCell>
              <TableCell>
                <Input
                  aria-label={`${index + 1}行目の訪問先`}
                  value={row.destination_name ?? ''}
                  onChange={(e) => onUpdateRow(row.rowId, { destination_name: e.target.value })}
                />
              </TableCell>
              <TableCell>
                <Input
                  aria-label={`${index + 1}行目の目的`}
                  value={row.purpose ?? ''}
                  onChange={(e) => onUpdateRow(row.rowId, { purpose: e.target.value })}
                />
              </TableCell>
              <TableCell>
                <div className="flex items-center gap-1">
                  <Button
                    type="button"
                    variant="secondary"
                    size="icon"
                    aria-label={`${index + 1}行目を複製`}
                    onClick={() => onDuplicateRow(row.rowId)}
                  >
                    <Copy />
                  </Button>
                  <Button
                    type="button"
                    variant="secondary"
                    size="icon"
                    aria-label={`${index + 1}行目を上に移動`}
                    disabled={index === 0}
                    onClick={() => onMoveRow(row.rowId, 'up')}
                  >
                    <ArrowUp />
                  </Button>
                  <Button
                    type="button"
                    variant="secondary"
                    size="icon"
                    aria-label={`${index + 1}行目を下に移動`}
                    disabled={index === rows.length - 1}
                    onClick={() => onMoveRow(row.rowId, 'down')}
                  >
                    <ArrowDown />
                  </Button>
                  <Button
                    type="button"
                    variant="danger"
                    size="icon"
                    aria-label={`${index + 1}行目を削除`}
                    onClick={() => onRemoveRow(row.rowId)}
                  >
                    <Trash2 />
                  </Button>
                </div>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>

      <p className="text-sm font-medium text-foreground">合計金額: {total.toLocaleString()}円</p>
    </div>
  )
}
