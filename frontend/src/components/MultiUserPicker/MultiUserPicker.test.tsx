import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import * as usersApi from '../../api/users'
import type { Paginated, User } from '../../api/types'
import { MultiUserPicker } from './MultiUserPicker'

function ControlledMultiUserPicker({ onChange }: { onChange: (userIds: string[]) => void }) {
  const [value, setValue] = useState<string[]>([])

  return (
    <MultiUserPicker
      id="targets"
      value={value}
      onChange={(userIds) => {
        setValue(userIds)
        onChange(userIds)
      }}
    />
  )
}

const users: User[] = [
  {
    id: 'user-1',
    name: '対象太郎',
    email: 'taro@example.com',
    department: null,
    job_title: null,
    employment_status: 'active',
    last_login_at: null,
  },
  {
    id: 'user-2',
    name: '対象花子',
    email: 'hanako@example.com',
    department: null,
    job_title: null,
    employment_status: 'active',
    last_login_at: null,
  },
]

const paginatedUsers: Paginated<User> = {
  data: users,
  meta: { current_page: 1, last_page: 1, total: users.length },
  links: { next: null, prev: null },
}

function renderPicker(onChange = vi.fn()) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)

  render(
    <QueryClientProvider client={queryClient}>
      <ControlledMultiUserPicker onChange={onChange} />
    </QueryClientProvider>,
  )

  return onChange
}

describe('MultiUserPicker', () => {
  it('adds multiple users as chips and reports the selected ids', async () => {
    const onChange = renderPicker()

    await userEvent.click(screen.getByRole('combobox'))
    await userEvent.click(await screen.findByRole('option', { name: '対象太郎(taro@example.com)' }))
    await userEvent.click(await screen.findByRole('option', { name: '対象花子(hanako@example.com)' }))

    await waitFor(() => expect(onChange).toHaveBeenLastCalledWith(['user-1', 'user-2']))
    expect(screen.getByRole('button', { name: '対象太郎(taro@example.com)を選択解除' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '対象花子(hanako@example.com)を選択解除' })).toBeInTheDocument()
    expect(document.getElementById('targets')).toHaveTextContent('2名を選択中')
  })

  it('removes a user when its chip remove button is clicked', async () => {
    const onChange = renderPicker()

    await userEvent.click(screen.getByRole('combobox'))
    await userEvent.click(await screen.findByRole('option', { name: '対象太郎(taro@example.com)' }))
    await waitFor(() => expect(onChange).toHaveBeenLastCalledWith(['user-1']))

    await userEvent.click(screen.getByRole('button', { name: '対象太郎(taro@example.com)を選択解除' }))

    await waitFor(() => expect(onChange).toHaveBeenLastCalledWith([]))
    expect(screen.queryByRole('button', { name: '対象太郎(taro@example.com)を選択解除' })).not.toBeInTheDocument()
  })
})
