import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as groupsApi from '../../api/groups'
import type { GroupOption } from '../../api/types'
import { GroupPicker } from './GroupPicker'

const groups: GroupOption[] = [
  { id: 'group-1', name: '営業部' },
  { id: 'group-2', name: '開発部' },
]

function renderPicker(value: string | undefined = undefined) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(groupsApi, 'fetchGroups').mockResolvedValue(groups)
  const onChange = vi.fn()

  render(
    <QueryClientProvider client={queryClient}>
      <GroupPicker id="group" value={value} onChange={onChange} />
    </QueryClientProvider>,
  )

  return onChange
}

describe('GroupPicker', () => {
  it('lists available groups', async () => {
    renderPicker()

    expect(await screen.findByRole('option', { name: '営業部' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: '開発部' })).toBeInTheDocument()
  })

  it('reports the selected group id', async () => {
    const onChange = renderPicker()

    await screen.findByRole('option', { name: '営業部' })
    await userEvent.selectOptions(screen.getByRole('combobox'), 'group-1')

    expect(onChange).toHaveBeenCalledWith('group-1')
  })
})
