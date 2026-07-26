import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { ExpenseItemsTable } from './ExpenseItemsTable'
import type { EditableRow } from '../../hooks/useEditableRows'
import type { SaveExpenseItemInput } from '../../api/expenseClaims'
import type { ExpenseCategory } from '../../api/types'

const categories: ExpenseCategory[] = [
  {
    id: 1,
    code: 'transport',
    name: '交通費',
    description: null,
    evidence_type_default: 'not_required',
    receipt_required_threshold: null,
    approval_skip_threshold: null,
    is_active: true,
  },
  {
    id: 2,
    code: 'lodging',
    name: '宿泊費',
    description: null,
    evidence_type_default: 'required',
    receipt_required_threshold: null,
    approval_skip_threshold: null,
    is_active: true,
  },
]

function makeRows(): EditableRow<SaveExpenseItemInput>[] {
  return [
    { rowId: 1, category_id: 1, usage_date: '2026-07-01', origin: '自宅', destination: '本社', transport_type: '電車', amount: 420, destination_name: '本社', purpose: '定例会議' },
    { rowId: 2, category_id: 2, usage_date: '2026-07-02', origin: '', destination: '', transport_type: '', amount: 1000, destination_name: '', purpose: '' },
  ]
}

function baseProps() {
  return {
    rows: makeRows(),
    categories,
    onAddRow: vi.fn(),
    onUpdateRow: vi.fn(),
    onRemoveRow: vi.fn(),
    onDuplicateRow: vi.fn(),
    onMoveRow: vi.fn(),
    onPasteRows: vi.fn(),
  }
}

describe('ExpenseItemsTable', () => {
  it('renders a row per item and the total amount', () => {
    render(<ExpenseItemsTable {...baseProps()} />)

    expect(screen.getByLabelText('1行目の目的')).toHaveValue('定例会議')
    expect(screen.getByText('合計金額: 1,420円')).toBeInTheDocument()
  })

  it('calls onUpdateRow with the correct patch when editing a cell', async () => {
    const user = userEvent.setup()
    const props = baseProps()
    render(<ExpenseItemsTable {...props} />)

    await user.type(screen.getByLabelText('1行目の訪問先'), 'X')

    expect(props.onUpdateRow).toHaveBeenLastCalledWith(1, { destination_name: '本社X' })
  })

  it('calls onAddRow when the add-row button is clicked', async () => {
    const user = userEvent.setup()
    const props = baseProps()
    render(<ExpenseItemsTable {...props} />)

    await user.click(screen.getByRole('button', { name: '行を追加' }))

    expect(props.onAddRow).toHaveBeenCalledTimes(1)
  })

  it('calls onDuplicateRow, onRemoveRow and onMoveRow with the correct rowId/direction', async () => {
    const user = userEvent.setup()
    const props = baseProps()
    render(<ExpenseItemsTable {...props} />)

    await user.click(screen.getByLabelText('1行目を複製'))
    expect(props.onDuplicateRow).toHaveBeenCalledWith(1)

    await user.click(screen.getByLabelText('2行目を上に移動'))
    expect(props.onMoveRow).toHaveBeenCalledWith(2, 'up')

    await user.click(screen.getByLabelText('1行目を削除'))
    expect(props.onRemoveRow).toHaveBeenCalledWith(1)
  })

  it('disables move buttons at the boundaries', () => {
    render(<ExpenseItemsTable {...baseProps()} />)

    expect(screen.getByLabelText('1行目を上に移動')).toBeDisabled()
    expect(screen.getByLabelText('2行目を下に移動')).toBeDisabled()
    expect(screen.getByLabelText('1行目を下に移動')).not.toBeDisabled()
    expect(screen.getByLabelText('2行目を上に移動')).not.toBeDisabled()
  })

  it('parses pasted rows and calls onPasteRows', async () => {
    const user = userEvent.setup()
    const props = baseProps()
    render(<ExpenseItemsTable {...props} />)

    await user.click(screen.getByRole('button', { name: '複数行貼り付け' }))
    const textarea = screen.getByLabelText(/貼り付け/)
    await user.type(textarea, '2026-07-10,自宅,本社,電車,420,本社,定例会議{enter}2026-07-11,本社,取引先,タクシー,abc,取引先,商談')
    await user.click(screen.getByRole('button', { name: '取り込む' }))

    expect(props.onPasteRows).toHaveBeenCalledWith([
      { category_id: 1, usage_date: '2026-07-10', origin: '自宅', destination: '本社', transport_type: '電車', amount: 420, destination_name: '本社', purpose: '定例会議' },
      { category_id: 1, usage_date: '2026-07-11', origin: '本社', destination: '取引先', transport_type: 'タクシー', amount: 0, destination_name: '取引先', purpose: '商談' },
    ])
  })

  it('applies bulk fields to checked rows only', async () => {
    const user = userEvent.setup()
    const props = baseProps()
    render(<ExpenseItemsTable {...props} />)

    await user.click(screen.getByLabelText('1行目を選択'))
    await user.type(screen.getByLabelText('目的'), '出張')
    await user.click(screen.getByRole('button', { name: '選択行に反映' }))

    expect(props.onUpdateRow).toHaveBeenCalledWith(1, { purpose: '出張' })
    expect(props.onUpdateRow).not.toHaveBeenCalledWith(2, expect.anything())
  })
})
