import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as specialLeaveApi from '../../api/specialLeave'
import type { SpecialLeaveType, User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { formatDate } from '../../utils/weekDates'
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

function renderLayout(logout = vi.fn(), user: User = mockUser, specialLeaveTypes: SpecialLeaveType[] = []) {
  const authValue: AuthContextValue = {
    user,
    status: 'authenticated',
    login: vi.fn(),
    completeLogin: vi.fn(),
    logout,
  }
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(specialLeaveApi, 'fetchSpecialLeaveTypes').mockResolvedValue(specialLeaveTypes)

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthContext.Provider value={authValue}>
        <MemoryRouter initialEntries={['/']}>
          <Routes>
            <Route path="/" element={<AppLayout />}>
              <Route index element={<p>今日の勤怠画面</p>} />
            </Route>
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
}

describe('AppLayout', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('shows the current user name and the routed content', () => {
    renderLayout()
    expect(screen.getByText('山田 太郎')).toBeInTheDocument()
    expect(screen.getByText('今日の勤怠画面')).toBeInTheDocument()
  })

  it('shows the current user department and role', () => {
    renderLayout(vi.fn(), { ...mockUser, department: '開発部', roles: ['admin'] })
    expect(screen.getByText('開発部 ・ 管理者')).toBeInTheDocument()
  })

  it('shows the 申請 group links inside its dropdown menu', async () => {
    renderLayout()

    await userEvent.click(screen.getByRole('button', { name: '申請' }))
    expect(await screen.findByRole('menuitem', { name: '自分の申請' })).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: '新規申請' })).toBeInTheDocument()
  })

  it('links 月次勤怠 to the current month detail page', async () => {
    renderLayout()

    await userEvent.click(screen.getByRole('button', { name: '勤怠' }))

    expect(await screen.findByRole('menuitem', { name: '月次勤怠' })).toHaveAttribute(
      'href',
      `/attendance/months/${formatDate(new Date()).slice(0, 7)}`,
    )
  })

  it('shows the 承認 group links inside its dropdown menu', async () => {
    renderLayout()

    await userEvent.click(screen.getByRole('button', { name: '承認' }))
    expect(await screen.findByRole('menuitem', { name: '承認待ち' })).toBeInTheDocument()
  })

  it('hides the 特別休暇 menu items when there is no active special leave type', async () => {
    renderLayout(vi.fn(), mockUser, [])

    await userEvent.click(screen.getByRole('button', { name: '勤怠' }))
    expect(await screen.findByRole('menuitem', { name: '有給' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: '特別休暇' })).not.toBeInTheDocument()
  })

  it('shows the 特別休暇 menu item under 勤怠 once an active special leave type exists', async () => {
    renderLayout(vi.fn(), mockUser, [{ id: 1, name: '誕生日休暇', is_active: true }])

    await userEvent.click(screen.getByRole('button', { name: '勤怠' }))
    expect(await screen.findByRole('menuitem', { name: '特別休暇' })).toBeInTheDocument()
  })

  it('shows the 特別休暇申請承認 menu item under 承認 once an active special leave type exists', async () => {
    renderLayout(vi.fn(), mockUser, [{ id: 1, name: '誕生日休暇', is_active: true }])

    await userEvent.click(screen.getByRole('button', { name: '承認' }))
    expect(await screen.findByRole('menuitem', { name: '特別休暇申請承認' })).toBeInTheDocument()
  })

  it('keeps the 特別休暇 menu items hidden when the only special leave type is inactive', async () => {
    renderLayout(vi.fn(), mockUser, [{ id: 1, name: '廃止済み休暇', is_active: false }])

    await userEvent.click(screen.getByRole('button', { name: '勤怠' }))
    expect(screen.queryByRole('menuitem', { name: '特別休暇' })).not.toBeInTheDocument()
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

  it('opens a mobile menu drawer listing every group and its links', async () => {
    renderLayout(vi.fn(), { ...mockUser, roles: ['admin'] })

    await userEvent.click(screen.getByRole('button', { name: 'メニューを開く' }))

    expect(await screen.findByRole('heading', { name: 'メニュー' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '今日の勤怠' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '自分の申請' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '承認待ち' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'タスク一覧' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '管理メニュー' })).toBeInTheDocument()
  })

  it('closes the mobile menu drawer after choosing a link', async () => {
    renderLayout()

    await userEvent.click(screen.getByRole('button', { name: 'メニューを開く' }))
    await userEvent.click(await screen.findByRole('link', { name: '自分の申請' }))

    expect(screen.queryByRole('heading', { name: 'メニュー' })).not.toBeInTheDocument()
  })

  it('shows a logout button inside the mobile menu drawer', async () => {
    const logout = vi.fn()
    renderLayout(logout)

    await userEvent.click(screen.getByRole('button', { name: 'メニューを開く' }))
    await screen.findByRole('heading', { name: 'メニュー' })

    await userEvent.click(screen.getByRole('button', { name: 'ログアウト' }))

    expect(logout).toHaveBeenCalledOnce()
    expect(screen.queryByRole('heading', { name: 'メニュー' })).not.toBeInTheDocument()
  })
})
