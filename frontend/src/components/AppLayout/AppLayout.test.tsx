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
  id: 'user-1',
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
    applySession: vi.fn(),
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

  it('shows the current user department without a legacy role label', () => {
    renderLayout(vi.fn(), { ...mockUser, department: '開発部' })
    expect(screen.getByText('開発部')).toBeInTheDocument()
  })

  it('shows その他申請・経費精算 inside the 勤怠・申請 dropdown menu, without a separate 新規申請、新規作成 or 入力プリセット shortcut', async () => {
    renderLayout()

    await userEvent.click(screen.getByRole('button', { name: '勤怠・申請' }))
    expect(await screen.findByRole('menuitem', { name: 'その他申請' })).toHaveAttribute('href', '/requests')
    expect(screen.getByRole('menuitem', { name: '経費精算' })).toHaveAttribute('href', '/expenses')
    expect(screen.queryByRole('menuitem', { name: '新規申請' })).not.toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: '経費精算(新規作成)' })).not.toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: '経費精算一覧' })).not.toBeInTheDocument()
    // 入力プリセット画面は、いきなり使う人が少ないと想定してメニューには置かない。
    // 経費精算画面のプリセット表示箇所からのみ遷移できるようにする。
    expect(screen.queryByRole('menuitem', { name: '入力プリセット' })).not.toBeInTheDocument()
  })

  it('links 月次勤怠 to the current month detail page', async () => {
    renderLayout()

    await userEvent.click(screen.getByRole('button', { name: '勤怠・申請' }))

    expect(await screen.findByRole('menuitem', { name: '月次勤怠' })).toHaveAttribute(
      'href',
      `/attendance/months/${formatDate(new Date()).slice(0, 7)}`,
    )
  })

  it('shows 承認 as a direct link to the unified approvals screen for a user without back-office roles', async () => {
    renderLayout()

    expect(await screen.findByRole('link', { name: '承認待ち' })).toHaveAttribute('href', '/approvals')
    expect(screen.queryByRole('button', { name: '承認' })).not.toBeInTheDocument()
  })

  it('shows タスク一覧 when its feature is effective', async () => {
    renderLayout(vi.fn(), { ...mockUser, effective_features: ['attendance.entry', 'backoffice.tasks'] })

    expect(await screen.findByRole('link', { name: 'タスク一覧' })).toHaveAttribute('href', '/backoffice-tasks')
  })

  it('hides the 特別休暇 menu items when there is no active special leave type', async () => {
    renderLayout(vi.fn(), mockUser, [])

    await userEvent.click(screen.getByRole('button', { name: '勤怠・申請' }))
    expect(await screen.findByRole('menuitem', { name: '有給' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: '特別休暇' })).not.toBeInTheDocument()
  })

  it('shows the 特別休暇 menu item once an active special leave type exists', async () => {
    renderLayout(vi.fn(), mockUser, [{ id: 1, name: '誕生日休暇', is_active: true, requires_grant: true }])

    await userEvent.click(screen.getByRole('button', { name: '勤怠・申請' }))
    expect(await screen.findByRole('menuitem', { name: '特別休暇' })).toBeInTheDocument()
  })

  it('keeps the 特別休暇 menu items hidden when the only special leave type is inactive', async () => {
    renderLayout(vi.fn(), mockUser, [{ id: 1, name: '廃止済み休暇', is_active: false, requires_grant: true }])

    await userEvent.click(screen.getByRole('button', { name: '勤怠・申請' }))
    expect(screen.queryByRole('menuitem', { name: '特別休暇' })).not.toBeInTheDocument()
  })

  it('calls logout when the logout button is clicked', async () => {
    const logout = vi.fn()
    renderLayout(logout)

    await userEvent.click(screen.getByRole('button', { name: 'ログアウト' }))

    expect(logout).toHaveBeenCalledOnce()
  })

  it('hides admin navigation for a user without effective admin access', () => {
    renderLayout(vi.fn(), { ...mockUser })

    expect(screen.queryByRole('link', { name: '管理メニュー' })).not.toBeInTheDocument()
  })

  it('shows 管理メニュー as a direct link from effective access', async () => {
    renderLayout(vi.fn(), { ...mockUser, effective_features: ['attendance.entry', 'administration.users'], effective_permissions: ['user.view'] })

    expect(await screen.findByRole('link', { name: '管理メニュー' })).toHaveAttribute('href', '/admin')
  })

  it('opens a mobile menu drawer listing every group and its links', async () => {
    renderLayout(vi.fn(), { ...mockUser, effective_features: ['attendance.entry', 'attendance.timesheet', 'workflow.requests', 'paid_leave.requests', 'backoffice.expenses', 'administration.users', 'backoffice.tasks'], effective_permissions: ['user.view'] })

    await userEvent.click(screen.getByRole('button', { name: 'メニューを開く' }))

    expect(await screen.findByRole('heading', { name: 'メニュー' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '今日の勤怠' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'その他申請' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '承認待ち' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'タスク一覧' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '管理メニュー' })).toBeInTheDocument()
  })

  it('closes the mobile menu drawer after choosing a link', async () => {
    renderLayout()

    await userEvent.click(screen.getByRole('button', { name: 'メニューを開く' }))
    await userEvent.click(await screen.findByRole('link', { name: 'その他申請' }))

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
