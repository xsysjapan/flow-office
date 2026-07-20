import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '../../api/auth'
import type { User } from '../../api/types'
import { AccountSettingsPage } from './AccountSettingsPage'

const baseUser: User = {
  id: 1,
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
  sso_linked: false,
}

let currentUser: User = baseUser

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

beforeEach(() => {
  currentUser = baseUser
  vi.restoreAllMocks()
})

describe('AccountSettingsPage', () => {
  it('未連携なら連携ボタンを表示し、押すとMicrosoftのログインURLへ遷移する', async () => {
    vi.spyOn(authApi, 'fetchMicrosoftLinkRedirectUrl').mockResolvedValue({ url: 'https://login.microsoftonline.com/authorize' })
    const originalLocation = window.location
    Object.defineProperty(window, 'location', { value: { href: '' }, writable: true, configurable: true })

    render(<AccountSettingsPage />)

    expect(screen.getByText('Microsoft 365 と連携する')).toBeInTheDocument()
    await userEvent.click(screen.getByText('Microsoft 365 と連携する'))

    await waitFor(() => expect(window.location.href).toBe('https://login.microsoftonline.com/authorize'))

    Object.defineProperty(window, 'location', { value: originalLocation, writable: true, configurable: true })
  })

  it('連携済みなら連携済みバッジを表示する', () => {
    currentUser = { ...baseUser, sso_linked: true }

    render(<AccountSettingsPage />)

    expect(screen.getByText('連携済み')).toBeInTheDocument()
    expect(screen.queryByText('Microsoft 365 と連携する')).not.toBeInTheDocument()
  })

  it('URL取得に失敗したらエラーを表示する', async () => {
    vi.spyOn(authApi, 'fetchMicrosoftLinkRedirectUrl').mockRejectedValue(new Error('failed'))

    render(<AccountSettingsPage />)
    await userEvent.click(screen.getByText('Microsoft 365 と連携する'))

    await waitFor(() => expect(screen.getByText('failed')).toBeInTheDocument())
  })
})
