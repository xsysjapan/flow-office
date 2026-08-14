import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { Table, TableBody, TableCell } from '../ui/table'
import { ClickableTableRow } from './ClickableTableRow'

function renderRow(onRowClick: () => void, disabled = false) {
  return render(
    <Table>
      <TableBody>
        <ClickableTableRow onRowClick={onRowClick} rowLabel="山田太郎の詳細を開く" disabled={disabled}>
          <TableCell>山田太郎</TableCell>
        </ClickableTableRow>
      </TableBody>
    </Table>,
  )
}

describe('ClickableTableRow', () => {
  it('calls onRowClick when the row is clicked', async () => {
    const onRowClick = vi.fn()
    renderRow(onRowClick)

    await userEvent.click(screen.getByRole('row', { name: '山田太郎の詳細を開く' }))

    expect(onRowClick).toHaveBeenCalledTimes(1)
  })

  it('calls onRowClick on Enter and Space', async () => {
    const onRowClick = vi.fn()
    renderRow(onRowClick)

    const row = screen.getByRole('row', { name: '山田太郎の詳細を開く' })
    row.focus()
    await userEvent.keyboard('{Enter}')
    await userEvent.keyboard(' ')

    expect(onRowClick).toHaveBeenCalledTimes(2)
  })

  it('does not respond to clicks or keyboard when disabled', async () => {
    const onRowClick = vi.fn()
    renderRow(onRowClick, true)

    expect(screen.queryByRole('row', { name: '山田太郎の詳細を開く' })).not.toBeInTheDocument()
    expect(screen.getByText('山田太郎')).toBeInTheDocument()
  })
})
