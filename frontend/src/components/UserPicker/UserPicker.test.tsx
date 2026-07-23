import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import * as usersApi from '../../api/users'
import type { Paginated, User } from '../../api/types'
import { UserPicker } from './UserPicker'

function ControlledUserPicker({ onChange }: { onChange: (userId: string | undefined) => void }) {
  const [value, setValue] = useState<string | undefined>(undefined)

  return (
    <UserPicker
      id="approver"
      value={value}
      onChange={(userId) => {
        setValue(userId)
        onChange(userId)
      }}
    />
  )
}

const users: User[] = [
  {
    id: 'approver-1',
    name: '承認者花子',
    email: 'hanako@example.com',
    department: null,
    job_title: null,
    employment_status: 'active',
    last_login_at: null,
  },
]

const paginatedUsers: Paginated<User> = {
  data: users,
  meta: { current_page: 1, last_page: 1, total: 1 },
  links: { next: null, prev: null },
}

function renderPicker(onChange = vi.fn()) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)

  render(
    <QueryClientProvider client={queryClient}>
      <ControlledUserPicker onChange={onChange} />
    </QueryClientProvider>,
  )

  return onChange
}

describe('UserPicker', () => {
  it('shows matching users as suggestions while typing', async () => {
    renderPicker()

    await userEvent.click(screen.getByRole('combobox'))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '花子')

    expect(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' })).toBeInTheDocument()
  })

  it('reports the selected user id and closes the popover', async () => {
    const onChange = renderPicker()

    await userEvent.click(screen.getByRole('combobox'))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '花子')
    await userEvent.click(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' }))

    await waitFor(() => expect(onChange).toHaveBeenCalledWith('approver-1'))
    expect(screen.queryByRole('option', { name: '承認者花子(hanako@example.com)' })).not.toBeInTheDocument()
    expect(screen.getByRole('combobox')).toHaveTextContent('承認者花子(hanako@example.com)')
  })
})
