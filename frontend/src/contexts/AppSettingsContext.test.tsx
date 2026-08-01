import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '../api/auth'
import { clearToken, setToken } from '../api/client'
import * as leaveApprovalSettingsApi from '../api/leaveApprovalSettings'
import type { LeaveApprovalSettings, User } from '../api/types'
import { AuthProvider } from '../auth/AuthContext'
import { useAppSettings } from './useAppSettings'
import { AppSettingsProvider } from './AppSettingsContext'

const testUser: User = {
  id: 'user-1',
  name: 'テスト太郎',
  email: 'taro@example.com',
  department: '開発部',
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const relaxedSettings: LeaveApprovalSettings = {
  paid_leave_requires_approval: false,
  special_leave_requires_approval: false,
}

function Probe() {
  const { leaveApprovalSettings, isLoading } = useAppSettings()

  return (
    <div>
      <p data-testid="loading">{String(isLoading)}</p>
      <p data-testid="paid">{String(leaveApprovalSettings.paid_leave_requires_approval)}</p>
      <p data-testid="special">{String(leaveApprovalSettings.special_leave_requires_approval)}</p>
    </div>
  )
}

function renderWithProviders() {
  return render(
    <AuthProvider>
      <AppSettingsProvider>
        <Probe />
      </AppSettingsProvider>
    </AuthProvider>,
  )
}

describe('AppSettingsContext', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    clearToken()
  })

  it('exposes fail-open defaults before the fetch resolves (or when unauthenticated)', async () => {
    const fetchSpy = vi
      .spyOn(leaveApprovalSettingsApi, 'fetchLeaveApprovalSettings')
      .mockResolvedValue(relaxedSettings)

    renderWithProviders()

    expect(screen.getByTestId('paid')).toHaveTextContent('true')
    expect(screen.getByTestId('special')).toHaveTextContent('true')

    // 未ログイン(トークンなし)のため、認可が必要なエンドポイントは叩かれない。
    await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('false'))
    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('updates to the fetched values once authenticated', async () => {
    setToken('issued-token')
    vi.spyOn(authApi, 'fetchCurrentUser').mockResolvedValue(testUser)
    vi.spyOn(leaveApprovalSettingsApi, 'fetchLeaveApprovalSettings').mockResolvedValue(relaxedSettings)

    renderWithProviders()

    // フェッチ完了前は既定値(承認必須)のまま表示される。
    expect(screen.getByTestId('paid')).toHaveTextContent('true')

    await waitFor(() => expect(screen.getByTestId('paid')).toHaveTextContent('false'))
    expect(screen.getByTestId('special')).toHaveTextContent('false')
    expect(screen.getByTestId('loading')).toHaveTextContent('false')
  })
})
