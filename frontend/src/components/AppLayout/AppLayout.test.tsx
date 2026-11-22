import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
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

function renderLayout(logout = vi.fn(), user: User = mockUser, specialLeaveTypes: SpecialLeaveType[] = [], initialPath = '/') {
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
        <MemoryRouter initialEntries={[initialPath]}>
          <Routes>
            <Route path="/" element={<AppLayout />}>
              <Route index element={<p>今日の勤怠画面</p>} />
              <Route path="*" element={<p>配下の画面</p>} />
            </Route>
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
}

/** PC用の左サイドバー(<aside>)。モバイル用ハンバーガーメニューが開いていない限り、
 *  ナビ項目はここにしか描画されない。 */
function sidebar() {
  return within(screen.getByRole('complementary'))
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

  it('lists その他申請・経費精算 under the 申請 group in the sidebar', () => {
    renderLayout()

    expect(sidebar().getByRole('link', { name: '申請一覧' })).toHaveAttribute('href', '/requests')
    expect(sidebar().getByRole('link', { name: '経費精算' })).toHaveAttribute('href', '/expenses')
  })

  it('links 月次勤怠 to the current month detail page', () => {
    renderLayout()

    expect(sidebar().getByRole('link', { name: '月次勤怠' })).toHaveAttribute(
      'href',
      `/attendance/months/${formatDate(new Date()).slice(0, 7)}`,
    )
  })

  it('shows 承認一覧 as a direct link to the unified approvals screen', () => {
    renderLayout()

    expect(sidebar().getByRole('link', { name: '承認一覧' })).toHaveAttribute('href', '/approvals')
  })

  it('shows タスク一覧 when its feature is effective', () => {
    renderLayout(vi.fn(), { ...mockUser, effective_features: ['attendance.entry', 'backoffice.tasks'] })

    expect(sidebar().getByRole('link', { name: 'タスク一覧' })).toHaveAttribute('href', '/backoffice-tasks')
  })

  it('hides the 特別休暇 link when there is no active special leave type', async () => {
    renderLayout(vi.fn(), mockUser, [])

    expect(await sidebar().findByRole('link', { name: '有給' })).toBeInTheDocument()
    expect(sidebar().queryByRole('link', { name: '特別休暇' })).not.toBeInTheDocument()
  })

  it('shows the 特別休暇 link once an active special leave type exists', async () => {
    renderLayout(vi.fn(), mockUser, [{ id: 1, name: '誕生日休暇', is_active: true, requires_grant: true }])

    expect(await sidebar().findByRole('link', { name: '特別休暇' })).toBeInTheDocument()
  })

  it('keeps the 特別休暇 link hidden when the only special leave type is inactive', async () => {
    renderLayout(vi.fn(), mockUser, [{ id: 1, name: '廃止済み休暇', is_active: false, requires_grant: true }])

    await screen.findByText('山田 太郎')
    expect(sidebar().queryByRole('link', { name: '特別休暇' })).not.toBeInTheDocument()
  })

  it('calls logout when the logout button is clicked', async () => {
    const logout = vi.fn()
    renderLayout(logout)

    await userEvent.click(screen.getByRole('button', { name: 'ログアウト' }))

    expect(logout).toHaveBeenCalledOnce()
  })

  it('hides admin navigation for a user without effective admin access', () => {
    renderLayout(vi.fn(), { ...mockUser })

    expect(sidebar().queryByRole('link', { name: '管理メニュー' })).not.toBeInTheDocument()
  })

  it('shows 管理メニュー as a direct link from effective access', () => {
    renderLayout(vi.fn(), { ...mockUser, effective_features: ['attendance.entry', 'administration.users'], effective_permissions: ['user.view'] })

    expect(sidebar().getByRole('link', { name: '管理メニュー' })).toHaveAttribute('href', '/admin')
  })

  it('opens a mobile menu drawer listing every group and its links', async () => {
    renderLayout(vi.fn(), { ...mockUser, effective_features: ['attendance.entry', 'attendance.timesheet', 'workflow.requests', 'paid_leave.requests', 'backoffice.expenses', 'administration.users', 'backoffice.tasks'], effective_permissions: ['user.view'] })

    await userEvent.click(screen.getByRole('button', { name: 'メニューを開く' }))

    const dialog = within(await screen.findByRole('dialog'))
    expect(await dialog.findByRole('heading', { name: 'メニュー' })).toBeInTheDocument()
    expect(dialog.getByRole('link', { name: '週次勤怠' })).toBeInTheDocument()
    expect(dialog.getByRole('link', { name: '申請一覧' })).toBeInTheDocument()
    expect(dialog.getByRole('link', { name: '承認一覧' })).toBeInTheDocument()
    expect(dialog.getByRole('link', { name: 'タスク一覧' })).toBeInTheDocument()
    expect(dialog.getByRole('link', { name: '管理メニュー' })).toBeInTheDocument()
  })

  it('closes the mobile menu drawer after choosing a link', async () => {
    renderLayout()

    await userEvent.click(screen.getByRole('button', { name: 'メニューを開く' }))
    const dialog = within(await screen.findByRole('dialog'))
    await userEvent.click(dialog.getByRole('link', { name: '申請一覧' }))

    expect(screen.queryByRole('heading', { name: 'メニュー' })).not.toBeInTheDocument()
  })

  it('shows a logout button inside the mobile menu drawer', async () => {
    const logout = vi.fn()
    renderLayout(logout)

    await userEvent.click(screen.getByRole('button', { name: 'メニューを開く' }))
    const dialog = within(await screen.findByRole('dialog'))
    await dialog.findByRole('heading', { name: 'メニュー' })

    await userEvent.click(dialog.getByRole('button', { name: 'ログアウト' }))

    expect(logout).toHaveBeenCalledOnce()
    expect(screen.queryByRole('heading', { name: 'メニュー' })).not.toBeInTheDocument()
  })

  it('does not render a mobile bottom nav (removed in favor of the hamburger menu only)', () => {
    renderLayout()

    expect(screen.queryByRole('navigation', { name: 'メインナビゲーション(モバイル)' })).not.toBeInTheDocument()
  })

  it('links the flow-office logo to the home page in both the sidebar and the mobile header', () => {
    renderLayout()

    expect(sidebar().getByRole('link', { name: 'ホームに戻る' })).toHaveAttribute('href', '/')

    const mobileHeader = within(screen.getByRole('banner'))
    expect(mobileHeader.getByRole('link', { name: 'ホームに戻る' })).toHaveAttribute('href', '/')
  })

  it('lights the 月次勤怠 item on a past month page', () => {
    renderLayout(vi.fn(), mockUser, [], '/attendance/months/2024-03')

    expect(sidebar().getByRole('link', { name: '月次勤怠' })).toHaveClass('bg-accent')
    expect(sidebar().getByRole('link', { name: '日次勤怠' })).not.toHaveClass('bg-accent')
    expect(sidebar().getByRole('link', { name: 'ホーム' })).not.toHaveClass('bg-accent')
  })

  it('lights the 日次勤怠 item (not 月次勤怠) on any day page', () => {
    renderLayout(vi.fn(), mockUser, [], '/attendance/days/2024-03-05')

    expect(sidebar().getByRole('link', { name: '日次勤怠' })).toHaveClass('bg-accent')
    expect(sidebar().getByRole('link', { name: '月次勤怠' })).not.toHaveClass('bg-accent')
  })

  it('lights the parent item on a sub page and only home on "/"', () => {
    const { unmount } = renderLayout(vi.fn(), mockUser, [], '/paid-leave/history')
    expect(sidebar().getByRole('link', { name: '有給' })).toHaveClass('bg-accent')
    unmount()

    renderLayout()
    expect(sidebar().getByRole('link', { name: 'ホーム' })).toHaveClass('bg-accent')
    expect(sidebar().getByRole('link', { name: '有給' })).not.toHaveClass('bg-accent')
  })

  it('sets document.title from the page hierarchy', () => {
    renderLayout(vi.fn(), mockUser, [], '/paid-leave/history')

    expect(document.title).toBe('有給履歴 | flow-office')
  })

  it('falls back to the app name as document.title for an unregistered page', () => {
    renderLayout(vi.fn(), mockUser, [], '/not-registered')

    expect(document.title).toBe('flow-office')
  })

  it('lights 管理メニュー on admin sub pages and sets aria-current on the active item', () => {
    renderLayout(vi.fn(), { ...mockUser, effective_features: ['attendance.entry', 'administration.users'], effective_permissions: ['user.view'] }, [], '/admin/users/u1')

    const admin = sidebar().getByRole('link', { name: '管理メニュー' })
    expect(admin).toHaveClass('bg-accent')
    expect(admin).toHaveAttribute('aria-current', 'page')
    expect(sidebar().getByRole('link', { name: 'ホーム' })).not.toHaveAttribute('aria-current')
  })
})
