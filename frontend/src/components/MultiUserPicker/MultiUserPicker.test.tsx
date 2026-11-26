import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import * as usersApi from '../../api/users'
import type { Paginated, User } from '../../api/types'
import { MultiUserPicker } from './MultiUserPicker'
import { FormField } from '../FormField/FormField'

function ControlledMultiUserPicker({
  onChange,
  initial = [],
  onLabelsChange,
}: {
  onChange: (userIds: string[]) => void
  initial?: string[]
  onLabelsChange?: (labels: Record<string, string>) => void
}) {
  const [value, setValue] = useState<string[]>(initial)

  return (
    <MultiUserPicker
      id="targets"
      onLabelsChange={onLabelsChange}
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

  function renderWithInitial(
    initial: string[],
    impl: (...args: Parameters<typeof usersApi.searchUsers>) => Promise<Paginated<User>>,
    onLabelsChange?: (labels: Record<string, string>) => void,
  ) {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const spy = vi.spyOn(usersApi, 'searchUsers')
    spy.mockReset()
    spy.mockImplementation(impl)
    render(
      <QueryClientProvider client={queryClient}>
        <ControlledMultiUserPicker onChange={vi.fn()} initial={initial} onLabelsChange={onLabelsChange} />
      </QueryClientProvider>,
    )
    return spy
  }

  const byIds = (ids?: string[]) =>
    Promise.resolve({
      ...paginatedUsers,
      data: users.filter((u) => ids?.includes(u.id)),
    })

  it('shows names for initial ids and reports them via onLabelsChange', async () => {
    const onLabelsChange = vi.fn()
    renderWithInitial(['user-1'], (_q, _p, _perm, ids) => byIds(ids), onLabelsChange)

    expect(await screen.findByRole('button', { name: '対象太郎(taro@example.com)を選択解除' })).toBeInTheDocument()
    await waitFor(() =>
      expect(onLabelsChange).toHaveBeenLastCalledWith({ 'user-1': '対象太郎(taro@example.com)' }),
    )
  })

  it('shows a loading label while fetching', async () => {
    renderWithInitial(['user-1'], () => new Promise(() => {}))
    expect(await screen.findByRole('button', { name: '読み込み中…を選択解除' })).toBeInTheDocument()
  })

  it('shows unknown label when the id is not found', async () => {
    renderWithInitial(['missing'], (_q, _p, _perm, ids) => byIds(ids))
    expect(await screen.findByRole('button', { name: '不明な社員を選択解除' })).toBeInTheDocument()
  })

  it('fetches more than 100 ids in chunks of 100', async () => {
    const ids = Array.from({ length: 101 }, (_, i) => `id-${i}`)
    const spy = renderWithInitial(ids, (_q, _p, _perm, got) => byIds(got))
    await waitFor(() => {
      const idCalls = spy.mock.calls.map((c) => c[3]).filter((x): x is string[] => Array.isArray(x))
      expect(idCalls.map((c) => c.length).sort()).toEqual([1, 100])
    })
  })
})

describe('MultiUserPicker in a FormField', () => {
  it('sets aria-required on the combobox and links description and error', () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)

    render(
      <QueryClientProvider client={queryClient}>
        <FormField label="対象者" htmlFor="targets" required description="説明です。" error="エラーです。">
          <MultiUserPicker id="targets" value={[]} onChange={vi.fn()} />
        </FormField>
      </QueryClientProvider>,
    )

    const combobox = screen.getByRole('combobox', { name: '対象者' })
    expect(combobox).toHaveAttribute('aria-required', 'true')
    expect(combobox).toHaveAttribute('aria-invalid', 'true')
    expect(combobox).toHaveAccessibleDescription('説明です。 エラーです。')
  })
})
