import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { AppLayout } from './AppLayout'

const mockUser: User = {
  id: 1,
  name: '山田 太郎',
  email: 'yamada@example.com',
  department: '開発部',
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

function renderLayout(logout = vi.fn(), user: User = mockUser) {
  const authValue: AuthContextValue = {
    user,
    status: 'authenticated',
    login: vi.fn(),
    completeLogin: vi.fn(),
    logout,
  }

  return render(
    <AuthContext.Provider value={authValue}>
      <MemoryRouter initialEntries={['/']}>
        <Routes>
          <Route path="/" element={<AppLayout />}>
            <Route index element={<p>今日の勤怠画面</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </AuthContext.Provider>,
  )
}

describe('AppLayout', () => {
  it('shows the current user name and the routed content', () => {
    renderLayout()
    expect(screen.getByText('山田 太郎')).toBeInTheDocument()
    expect(screen.getByText('今日の勤怠画面')).toBeInTheDocument()
  })

  it('shows the current user department and role', () => {
    renderLayout(vi.fn(), { ...mockUser, department: '開発部', roles: ['admin'] })
    expect(screen.getByText('開発部 ・ 管理者')).toBeInTheDocument()
  })

  it('shows navigation links', () => {
    renderLayout()
    expect(screen.getByRole('link', { name: '自分の申請' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '承認待ち' })).toBeInTheDocument()
  })

  it('calls logout when the logout button is clicked', async () => {
    const logout = vi.fn()
    renderLayout(logout)

    await userEvent.click(screen.getByRole('button', { name: 'ログアウト' }))

    expect(logout).toHaveBeenCalledOnce()
  })

  it('hides admin-only navigation links for a user without admin roles', () => {
    renderLayout(vi.fn(), { ...mockUser, roles: ['employee'] })

    expect(screen.queryByRole('link', { name: '管理メニュー' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'タスク一覧' })).not.toBeInTheDocument()
  })

  it('shows a single admin menu entry point for an admin user', () => {
    renderLayout(vi.fn(), { ...mockUser, roles: ['admin'] })

    expect(screen.getByRole('link', { name: '管理メニュー' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'タスク一覧' })).toBeInTheDocument()
  })
})
