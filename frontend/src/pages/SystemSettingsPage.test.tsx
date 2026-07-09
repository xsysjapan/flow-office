import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as systemSettingsApi from '../api/systemSettings'
import type { SystemSettings } from '../api/types'
import { SystemSettingsPage } from './SystemSettingsPage'

const settings: SystemSettings = { default_timezone: 'Asia/Tokyo' }

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(systemSettingsApi, 'fetchSystemSettings').mockResolvedValue(settings)

  return render(
    <QueryClientProvider client={queryClient}>
      <SystemSettingsPage />
    </QueryClientProvider>,
  )
}

describe('SystemSettingsPage', () => {
  it('shows the current default timezone', async () => {
    renderPage()

    expect(await screen.findByLabelText('既定タイムゾーン')).toHaveValue('Asia/Tokyo')
  })

  it('saves the updated default timezone', async () => {
    vi.spyOn(systemSettingsApi, 'updateSystemSettings').mockResolvedValue({ default_timezone: 'America/Los_Angeles' })
    renderPage()

    const input = await screen.findByLabelText('既定タイムゾーン')
    await userEvent.clear(input)
    await userEvent.type(input, 'America/Los_Angeles')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(systemSettingsApi.updateSystemSettings).toHaveBeenCalledWith({ default_timezone: 'America/Los_Angeles' }),
    )
    expect(await screen.findByText('保存しました。')).toBeInTheDocument()
  })

  it('shows an error message when saving fails', async () => {
    vi.spyOn(systemSettingsApi, 'updateSystemSettings').mockRejectedValue(new Error('保存に失敗しました'))
    renderPage()

    await screen.findByLabelText('既定タイムゾーン')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('保存に失敗しました')
  })
})
