import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { Pagination } from './Pagination'

describe('Pagination', () => {
  it('renders the current page and total', () => {
    render(<Pagination currentPage={2} lastPage={5} total={92} onPageChange={vi.fn()} />)
    expect(screen.getByText('92件中 2 / 5 ページ')).toBeInTheDocument()
  })

  it('disables the previous button on the first page', () => {
    render(<Pagination currentPage={1} lastPage={5} total={92} onPageChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: '前のページ' })).toBeDisabled()
    expect(screen.getByRole('button', { name: '次のページ' })).toBeEnabled()
  })

  it('disables the next button on the last page', () => {
    render(<Pagination currentPage={5} lastPage={5} total={92} onPageChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: '次のページ' })).toBeDisabled()
  })

  it('calls onPageChange with the next/previous page', async () => {
    const user = userEvent.setup()
    const onPageChange = vi.fn()
    render(<Pagination currentPage={2} lastPage={5} total={92} onPageChange={onPageChange} />)

    await user.click(screen.getByRole('button', { name: '次のページ' }))
    expect(onPageChange).toHaveBeenCalledWith(3)

    await user.click(screen.getByRole('button', { name: '前のページ' }))
    expect(onPageChange).toHaveBeenCalledWith(1)
  })

  it('renders nothing when there is only one page', () => {
    const { container } = render(<Pagination currentPage={1} lastPage={1} total={3} onPageChange={vi.fn()} />)
    expect(container).toBeEmptyDOMElement()
  })
})
