import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import * as usersApi from '../../api/users'
import type { Paginated, User, UserSearchResult } from '../../api/types'
import { UserPicker } from './UserPicker'
import { FormField } from '../FormField/FormField'

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
  vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)

  render(
    <QueryClientProvider client={queryClient}>
      <ControlledUserPicker onChange={onChange} />
    </QueryClientProvider>,
  )

  return onChange
}

describe('UserPicker', () => {
  it('shows up to 100 users before a query is entered', async () => {
    renderPicker()

    await userEvent.click(screen.getByRole('combobox'))

    expect(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' })).toBeInTheDocument()
    expect(usersApi.searchUsers).toHaveBeenCalledWith('', 100, undefined)
  })

  it('passes the permission prop through to narrow the candidate search', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)

    render(
      <QueryClientProvider client={queryClient}>
        <UserPicker id="approver" value={undefined} onChange={vi.fn()} permission="asset.manage" />
      </QueryClientProvider>,
    )

    await userEvent.click(screen.getByRole('combobox'))

    await waitFor(() =>
      expect(usersApi.searchUsers).toHaveBeenCalledWith('', 100, 'asset.manage'),
    )
  })

  it('matches the trigger width and constrains long content', async () => {
    renderPicker()

    await userEvent.click(screen.getByRole('combobox'))

    expect(await screen.findByRole('dialog')).toHaveClass(
      'w-[var(--radix-popover-trigger-width)]',
      'max-w-[calc(100vw-2rem)]',
    )
    expect((await screen.findByRole('option')).querySelector('span')).toHaveClass('min-w-0', 'truncate')
  })

  it('moves focus to the search field and draws one ring around the complete input row', async () => {
    renderPicker()

    await userEvent.click(screen.getByRole('combobox'))

    const input = screen.getByPlaceholderText('氏名またはメールアドレスで検索')
    expect(input).toHaveFocus()
    expect(input).toHaveClass(
      'focus:!shadow-none',
      'focus:outline-none',
      'focus:ring-0',
      'focus:ring-offset-0',
      'focus-visible:!shadow-none',
      'focus-visible:outline-none',
      'focus-visible:ring-0',
      'focus-visible:ring-offset-0',
    )
    expect(input.parentElement).toHaveClass('focus-within:ring-2', 'focus-within:ring-inset', 'rounded-t-md')
  })

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

  describe('value passed from the parent', () => {
    const lookupUsers: UserSearchResult[] = [
      { id: 'user-a', name: '山田太郎', email: 'yamada@example.com', department: null, job_title: null },
      { id: 'user-b', name: '鈴木次郎', email: 'suzuki@example.com', department: null, job_title: null },
    ]

    function mockLookup() {
      return vi.spyOn(usersApi, 'searchUsers').mockImplementation(async (_q, _perPage, _permission, ids) => {
        const data = ids ? lookupUsers.filter((user) => ids.includes(user.id)) : lookupUsers
        return { data, meta: { current_page: 1, last_page: 1, total: data.length }, links: { next: null, prev: null } }
      })
    }

    function renderWithValue(value: string | undefined) {
      const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
      const ui = (v: string | undefined) => (
        <QueryClientProvider client={queryClient}>
          <UserPicker id="approver" value={v} onChange={vi.fn()} />
        </QueryClientProvider>
      )
      const result = render(ui(value))
      return { ...result, rerenderWith: (v: string | undefined) => result.rerender(ui(v)) }
    }

    it('shows the fetched name and email when only the id is given', async () => {
      mockLookup()
      renderWithValue('user-a')

      await waitFor(() => expect(screen.getByRole('combobox')).toHaveTextContent('山田太郎(yamada@example.com)'))
      expect(usersApi.searchUsers).toHaveBeenCalledWith(undefined, undefined, undefined, ['user-a'])
    })

    it('shows a loading label while the name is being fetched', async () => {
      vi.spyOn(usersApi, 'searchUsers').mockImplementation(() => new Promise(() => {}))
      renderWithValue('user-a')

      expect(screen.getByRole('combobox')).toHaveTextContent('読み込み中…')
    })

    it('shows an unknown label when no user matches the id', async () => {
      mockLookup()
      renderWithValue('missing')

      await waitFor(() => expect(screen.getByRole('combobox')).toHaveTextContent('不明な社員'))
    })

    it('shows the unknown label when the lookup fails', async () => {
      vi.spyOn(usersApi, 'searchUsers').mockRejectedValue(new Error('boom'))
      renderWithValue('user-a')

      await waitFor(() => expect(screen.getByRole('combobox')).toHaveTextContent('不明な社員'))
    })

    it('shows the placeholder when no value is given', () => {
      mockLookup()
      renderWithValue(undefined)

      expect(screen.getByRole('combobox')).toHaveTextContent('氏名またはメールアドレスで検索')
    })

    it('shows the new user name when the value changes from the parent', async () => {
      mockLookup()
      const { rerenderWith } = renderWithValue('user-a')
      await waitFor(() => expect(screen.getByRole('combobox')).toHaveTextContent('山田太郎(yamada@example.com)'))

      rerenderWith('user-b')

      await waitFor(() => expect(screen.getByRole('combobox')).toHaveTextContent('鈴木次郎(suzuki@example.com)'))
    })
  })
})

describe('UserPicker in a FormField', () => {
  it('sets aria-required on the combobox and links description and error', () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)

    render(
      <QueryClientProvider client={queryClient}>
        <FormField label="承認者" htmlFor="approver" required description="説明です。" error="エラーです。">
          <UserPicker id="approver" value={undefined} onChange={vi.fn()} />
        </FormField>
      </QueryClientProvider>,
    )

    const combobox = screen.getByRole('combobox', { name: '承認者' })
    expect(combobox).toHaveAttribute('aria-required', 'true')
    expect(combobox).toHaveAttribute('aria-invalid', 'true')
    expect(combobox).toHaveAccessibleDescription('説明です。 エラーです。')
  })
})
