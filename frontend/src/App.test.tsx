import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as attendanceApi from './api/attendance'
import { AuthProvider } from './auth/AuthContext'
import App from './App'

function renderApp(initialPath: string) {
  const queryClient = new QueryClient()

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('App routing', () => {
  it('redirects unauthenticated users from the home page to the login page', async () => {
    renderApp('/')

    await waitFor(() => expect(screen.getByText('Microsoftでログイン')).toBeInTheDocument())
  })

  it('renders the login page directly', async () => {
    renderApp('/login')

    expect(await screen.findByText('Microsoftでログイン')).toBeInTheDocument()
  })

  it('redirects unknown routes to the home page (which then redirects to login)', async () => {
    renderApp('/does-not-exist')

    await waitFor(() => expect(screen.getByText('Microsoftでログイン')).toBeInTheDocument())
  })
})

describe('App routing (authenticated)', () => {
  it('shows the app layout and today attendance page for an authenticated user', async () => {
    vi.spyOn(attendanceApi, 'fetchToday').mockResolvedValue({
      id: 'day-1',
      user_id: 'user-1',
      work_date: '2026-07-09',
      status: 'not_started',
      actual_start_at: null,
      actual_end_at: null,
      work_type: null,
      note: null,
      is_locked: false,
      breaks: [],
      calculation: null,
    })

    localStorage.setItem('flow-office.token', 'existing-token')
    vi.spyOn(await import('./api/auth'), 'fetchCurrentUser').mockResolvedValue({
      id: 'user-1',
      name: '山田 太郎',
      email: 'yamada@example.com',
      department: null,
      job_title: null,
      employment_status: 'active',
      last_login_at: null,
    })

    renderApp('/')

    expect(await screen.findByText('山田 太郎')).toBeInTheDocument()
  })
})
