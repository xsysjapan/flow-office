import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { ExpenseItemsTable } from './ExpenseItemsTable'
import { useEditableRows } from '../../hooks/useEditableRows'
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

const sampleRows: SaveExpenseItemInput[] = [
  { category_id: 1, usage_date: '2026-07-01', origin: '自宅', destination: '本社', transport_type: '電車', amount: 420, destination_name: '本社', purpose: '定例会議' },
  { category_id: 1, usage_date: '2026-07-02', origin: '本社', destination: '取引先', transport_type: 'タクシー', amount: 1800, destination_name: '取引先A社', purpose: '商談' },
]

function Demo({ initialRows }: { initialRows: SaveExpenseItemInput[] }) {
  const { rows, addRow, updateRow, removeRow, duplicateRow, appendRows, moveRow } =
    useEditableRows<SaveExpenseItemInput>(initialRows)

  return (
    <ExpenseItemsTable
      rows={rows}
      categories={categories}
      onAddRow={() => addRow({ category_id: categories[0]?.id ?? 0, usage_date: '', amount: 0 })}
      onUpdateRow={updateRow}
      onRemoveRow={removeRow}
      onDuplicateRow={duplicateRow}
      onMoveRow={moveRow}
      onPasteRows={appendRows}
    />
  )
}

const meta = {
  title: 'Components/ExpenseItemsTable',
  component: ExpenseItemsTable,
} satisfies Meta<typeof ExpenseItemsTable>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    rows: [],
    categories,
    onAddRow: () => {},
    onUpdateRow: () => {},
    onRemoveRow: () => {},
    onDuplicateRow: () => {},
    onMoveRow: () => {},
    onPasteRows: () => {},
  },
  render: () => <Demo initialRows={sampleRows} />,
}

export const Empty: Story = {
  args: {
    rows: [],
    categories,
    onAddRow: () => {},
    onUpdateRow: () => {},
    onRemoveRow: () => {},
    onDuplicateRow: () => {},
    onMoveRow: () => {},
    onPasteRows: () => {},
  },
  render: () => <Demo initialRows={[]} />,
}
