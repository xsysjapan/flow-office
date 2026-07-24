import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '../api/auth'
import { clearToken, getToken, setToken } from '../api/client'
import type { User } from '../api/types'
import { AuthProvider } from './AuthContext'
import { useAuth } from './useAuth'

const testUser: User = {
  id: 'user-1',
  name: 'テスト太郎',
  email: 'taro@example.com',
  department: '開発部',
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

function Probe() {
  const { user, status, completeLogin, logout } = useAuth()

  return (
    <div>
      <p data-testid="status">{status}</p>
      <p data-testid="user">{user?.name ?? 'なし'}</p>
      <button onClick={() => completeLogin('one-time-code')}>login</button>
      <button onClick={() => logout()}>logout</button>
    </div>
  )
}

describe('AuthContext', () => {
  beforeEach(() => {
    clearToken()
  })

  afterEach(() => {
    vi.restoreAllMocks()
    clearToken()
  })

  it('starts unauthenticated when no token is stored', async () => {
    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>,
    )

    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('unauthenticated'))
  })

  it('exchanges a code for a token and stores the user on completeLogin', async () => {
    vi.spyOn(authApi, 'exchangeCodeForToken').mockResolvedValue({ token: 'issued-token', user: testUser })

    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>,
    )
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('unauthenticated'))

    await userEvent.click(screen.getByText('login'))

    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))
    expect(screen.getByTestId('user')).toHaveTextContent('テスト太郎')
    expect(getToken()).toBe('issued-token')
  })

  it('clears the token and user on logout', async () => {
    vi.spyOn(authApi, 'exchangeCodeForToken').mockResolvedValue({ token: 'issued-token', user: testUser })
    vi.spyOn(authApi, 'logout').mockResolvedValue(undefined)

    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>,
    )
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('unauthenticated'))
    await userEvent.click(screen.getByText('login'))
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))

    await userEvent.click(screen.getByText('logout'))

    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('unauthenticated'))
    expect(screen.getByTestId('user')).toHaveTextContent('なし')
    expect(getToken()).toBeNull()
  })

  it('restores the session from a stored token', async () => {
    vi.spyOn(authApi, 'fetchCurrentUser').mockResolvedValue(testUser)
    setToken('existing-token')

    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>,
    )

    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))
    expect(screen.getByTestId('user')).toHaveTextContent('テスト太郎')
  })
})
