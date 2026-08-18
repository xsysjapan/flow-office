import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as groupsApi from '../../api/groups'
import * as usersApi from '../../api/users'
import type { GroupMember, GroupOption, Paginated, User } from '../../api/types'
import { GrantTargetPicker } from './GrantTargetPicker'

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
      expect(onResolvedChange).toHaveBeenLastCalledWith(['user-1'], 'individual', {
        'user-1': '対象太郎(taro@example.com)',
      }),
    )
  })

  it('resolves all members of the selected group', async () => {
    const onResolvedChange = renderPicker()

    await userEvent.click(screen.getByRole('button', { name: 'グループを指定' }))
    await userEvent.selectOptions(await screen.findByRole('combobox'), 'group-1')

    await waitFor(() =>
      expect(onResolvedChange).toHaveBeenLastCalledWith(['member-1', 'member-2'], 'group', {
        'member-1': '営業一郎(ichiro@example.com)',
        'member-2': '営業二郎(jiro@example.com)',
      }),
    )
    expect(screen.getByText('このグループの2名に付与されます')).toBeInTheDocument()
  })

  it('warns when the selected group has no members', async () => {
    const onResolvedChange = renderPicker(vi.fn(), [])

    await userEvent.click(screen.getByRole('button', { name: 'グループを指定' }))
    await userEvent.selectOptions(await screen.findByRole('combobox'), 'group-1')

    await waitFor(() => expect(onResolvedChange).toHaveBeenLastCalledWith([], 'group', {}))
    expect(await screen.findByText(/所属メンバーがいません/)).toBeInTheDocument()
  })
})
