import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { AdminDashboardPage } from './AdminDashboardPage'

const mockUser: User = {
  id: 1,
  name: '山田 太郎',
  email: 'yamada@example.com',
  department: '開発部',
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
  roles: ['admin'],
}

function renderPage(user: User = mockUser) {
  const authValue: AuthContextValue = {
    user,
    status: 'authenticated',
    login: vi.fn(),
    completeLogin: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext.Provider value={authValue}>
      <MemoryRouter>
        <AdminDashboardPage />
      </MemoryRouter>
    </AuthContext.Provider>,
  )
}

describe('AdminDashboardPage', () => {
  it('shows a card for each admin function visible to an admin user', () => {
    renderPage()

    expect(screen.getByRole('link', { name: /ユーザー・権限/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /申請種別/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /監査ログ/ })).toBeInTheDocument()
  })

  it('hides admin-only cards for an hr_staff user', () => {
    renderPage({ ...mockUser, roles: ['hr_staff'] })

    expect(screen.getByRole('link', { name: /ユーザー・権限/ })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /申請種別/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /監査ログ/ })).not.toBeInTheDocument()
  })
})
