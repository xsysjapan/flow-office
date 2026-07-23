import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { AdminLayout } from './AdminLayout'

const mockUser: User = {
  id: 'user-1',
  name: '山田 太郎',
  email: 'yamada@example.com',
  department: '開発部',
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
  roles: ['admin'],
}

function renderLayout(user: User = mockUser) {
  const authValue: AuthContextValue = {
    user,
    status: 'authenticated',
    login: vi.fn(),
    completeLogin: vi.fn(),
    applySession: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext.Provider value={authValue}>
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route path="/admin" element={<AdminLayout />}>
            <Route index element={<p>管理メニューの中身</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </AuthContext.Provider>,
  )
}

describe('AdminLayout', () => {
  it('shows the routed content and sidebar links for an admin user', () => {
    renderLayout()

    expect(screen.getByText('管理メニューの中身')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'ユーザー・権限' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '申請種別' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '監査ログ' })).toBeInTheDocument()
  })

  it('hides admin-only sections for an hr_staff user', () => {
    renderLayout({ ...mockUser, roles: ['hr_staff'] })

    expect(screen.getByRole('link', { name: 'ユーザー・権限' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: '申請種別' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: '監査ログ' })).not.toBeInTheDocument()
  })

  it('has a link back to the main app', () => {
    renderLayout()

    expect(screen.getByRole('link', { name: /アプリに戻る/ })).toBeInTheDocument()
  })
})
