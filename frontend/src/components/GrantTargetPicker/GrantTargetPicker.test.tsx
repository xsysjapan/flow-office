import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as groupsApi from '../../api/groups'
import * as usersApi from '../../api/users'
import type { GroupMember, GroupOption, Paginated, User } from '../../api/types'
import { GrantTargetPicker, type GrantTargetPickerProps } from './GrantTargetPicker'
import { FormField } from '../FormField/FormField'

const targetUser: User = {
  id: 'user-1',
  name: '対象太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const paginatedUsers: Paginated<User> = {
  data: [targetUser],
  meta: { current_page: 1, last_page: 1, total: 1 },
  links: { next: null, prev: null },
}

const groups: GroupOption[] = [{ id: 'group-1', name: '営業部' }]

const members: GroupMember[] = [
  { user_id: 'member-1', name: '営業一郎', email: 'ichiro@example.com', membership_kind: 'primary', is_primary: true },
  { user_id: 'member-2', name: '営業二郎', email: 'jiro@example.com', membership_kind: 'primary', is_primary: true },
]

function renderPicker(onResolvedChange = vi.fn(), groupMembers: GroupMember[] = members) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
  vi.spyOn(groupsApi, 'fetchGroups').mockResolvedValue(groups)
  vi.spyOn(groupsApi, 'fetchGroupMembers').mockResolvedValue(groupMembers)

  render(
    <QueryClientProvider client={queryClient}>
      <GrantTargetPicker idPrefix="grant" onResolvedChange={onResolvedChange} />
    </QueryClientProvider>,
  )

  return onResolvedChange
}

describe('GrantTargetPicker', () => {
  it('resolves individually selected users', async () => {
    const onResolvedChange = renderPicker()

    await userEvent.click(screen.getByRole('combobox'))
    await userEvent.click(await screen.findByRole('option', { name: '対象太郎(taro@example.com)' }))

    await waitFor(() =>
      expect(onResolvedChange).toHaveBeenLastCalledWith(
        ['user-1'],
        'individual',
        { 'user-1': '対象太郎(taro@example.com)' },
        undefined,
      ),
    )
  })

  it('resolves all members of the selected group', async () => {
    const onResolvedChange = renderPicker()

    await userEvent.click(screen.getByRole('button', { name: 'グループを指定' }))
    await userEvent.selectOptions(await screen.findByRole('combobox'), 'group-1')

    await waitFor(() =>
      expect(onResolvedChange).toHaveBeenLastCalledWith(
        ['member-1', 'member-2'],
        'group',
        {
          'member-1': '営業一郎(ichiro@example.com)',
          'member-2': '営業二郎(jiro@example.com)',
        },
        'group-1',
      ),
    )
    expect(screen.getByText('このグループの所属メンバーが対象です(現在2名)')).toBeInTheDocument()
    expect(screen.getByText(/付与は、実行した時点の直接の所属メンバーに行います。退職者は対象から除かれます。/)).toBeInTheDocument()
  })

  it('warns when the selected group has no members', async () => {
    const onResolvedChange = renderPicker(vi.fn(), [])

    await userEvent.click(screen.getByRole('button', { name: 'グループを指定' }))
    await userEvent.selectOptions(await screen.findByRole('combobox'), 'group-1')

    await waitFor(() => expect(onResolvedChange).toHaveBeenLastCalledWith([], 'group', {}, 'group-1'))
    expect(await screen.findByText(/所属メンバーがいません/)).toBeInTheDocument()
  })

  it('hides the group mode when allowGroup is false', () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(groupsApi, 'fetchGroups').mockResolvedValue(groups)
    render(
      <QueryClientProvider client={queryClient}>
        <GrantTargetPicker idPrefix="grant" onResolvedChange={vi.fn()} allowGroup={false} />
      </QueryClientProvider>,
    )

    expect(screen.getByRole('button', { name: '個人を指定' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'グループを指定' })).not.toBeInTheDocument()
  })

  function renderNamed(props: Partial<GrantTargetPickerProps> = {}) {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    vi.spyOn(usersApi, 'searchUsers').mockImplementation((_q, _p, _perm, ids) =>
      Promise.resolve(ids ? { ...paginatedUsers, data: [targetUser].filter((u) => ids.includes(u.id)) } : paginatedUsers),
    )
    vi.spyOn(groupsApi, 'fetchGroups').mockResolvedValue(groups)
    vi.spyOn(groupsApi, 'fetchGroupMembers').mockResolvedValue(members)
    const onResolvedChange = vi.fn()
    const tree = (p: Partial<GrantTargetPickerProps>) => (
      <QueryClientProvider client={queryClient}>
        <GrantTargetPicker idPrefix="grant" onResolvedChange={onResolvedChange} {...p} />
      </QueryClientProvider>
    )
    const view = render(tree(props))
    return { onResolvedChange, rerender: (p: Partial<GrantTargetPickerProps>) => view.rerender(tree(p)) }
  }

  it('keeps names after switching individual -> group -> individual', async () => {
    renderNamed()

    await userEvent.click(screen.getByRole('combobox'))
    await userEvent.click(await screen.findByRole('option', { name: '対象太郎(taro@example.com)' }))
    await userEvent.keyboard('{Escape}')
    await userEvent.click(screen.getByRole('button', { name: 'グループを指定' }))
    await userEvent.click(screen.getByRole('button', { name: '個人を指定' }))

    expect(await screen.findByRole('button', { name: '対象太郎(taro@example.com)を選択解除' })).toBeInTheDocument()
  })

  it('shows names for reset individual ids and passes them to onResolvedChange', async () => {
    const { onResolvedChange, rerender } = renderNamed({ resetSignal: 0 })
    rerender({ resetSignal: 1, resetIndividualIds: ['user-1'] })

    expect(await screen.findByRole('button', { name: '対象太郎(taro@example.com)を選択解除' })).toBeInTheDocument()
    await waitFor(() =>
      expect(onResolvedChange).toHaveBeenLastCalledWith(
        ['user-1'],
        'individual',
        { 'user-1': '対象太郎(taro@example.com)' },
        undefined,
      ),
    )
  })
})

describe('GrantTargetPicker in a FormField', () => {
  function renderInFormField() {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(groupsApi, 'fetchGroups').mockResolvedValue(groups)
    vi.spyOn(groupsApi, 'fetchGroupMembers').mockResolvedValue(members)

    render(
      <QueryClientProvider client={queryClient}>
        <FormField label="付与対象" htmlFor="grant-users" required error="エラーです。">
          <GrantTargetPicker idPrefix="grant" onResolvedChange={vi.fn()} />
        </FormField>
      </QueryClientProvider>,
    )
  }

  it('links the label, required and error to the individual-mode input', () => {
    renderInFormField()
    const combobox = screen.getByLabelText('付与対象')
    expect(combobox).toHaveAttribute('id', 'grant-users')
    expect(combobox).toHaveAttribute('aria-required', 'true')
    expect(combobox).toHaveAttribute('aria-invalid', 'true')
    expect(combobox).toHaveAccessibleDescription('エラーです。')
  })

  it('links the label, required and error to the group-mode input', async () => {
    renderInFormField()
    await userEvent.click(screen.getByRole('button', { name: 'グループを指定' }))
    const select = screen.getByLabelText('付与対象')
    expect(select.tagName).toBe('SELECT')
    expect(select).toHaveAttribute('aria-required', 'true')
    expect(select).toHaveAttribute('aria-invalid', 'true')
    expect(select).toHaveAccessibleDescription('エラーです。')
  })
})
