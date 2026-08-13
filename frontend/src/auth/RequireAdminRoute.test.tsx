import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { User } from '../api/types'
import { RequireAdminRoute } from './RequireAdminRoute'
import { useAuth } from './useAuth'

vi.mock('./useAuth')

function renderAt(pathname: string, effectiveFeatures: string[] = [], effectivePermissions: string[] = []) {
  vi.mocked(useAuth).mockReturnValue({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      department: null,
      account_status: 'active',
      effective_features: effectiveFeatures,
      effective_permissions: effectivePermissions,
    } as User,
  } as ReturnType<typeof useAuth>)

  return render(
    <MemoryRouter initialEntries={[pathname]}>
      <Routes>
        <Route path="/" element={<p>ホーム</p>} />
        <Route
          path="admin/*"
          element={
            <RequireAdminRoute>
              <p>管理画面</p>
            </RequireAdminRoute>
          }
        />
      </Routes>
    </MemoryRouter>,
  )
}

describe('RequireAdminRoute', () => {
  it('renders the admin page when the user has the required feature and permission', () => {
    renderAt('/admin/users', ['administration.users'], ['user.view'])

    expect(screen.getByText('管理画面')).toBeInTheDocument()
  })

  it('allows access to an expense setting from effective access', () => {
    renderAt('/admin/expense-categories', ['backoffice.expenses'], ['expense_category.manage'])

    expect(screen.getByText('管理画面')).toBeInTheDocument()
  })

  it('redirects to / when the user lacks effective access for that section', () => {
    renderAt('/admin/expense-categories')

    expect(screen.getByText('ホーム')).toBeInTheDocument()
    expect(screen.queryByText('管理画面')).not.toBeInTheDocument()
  })

  it('matches sub-paths against their parent nav item (e.g. /admin/users/:id)', () => {
    renderAt('/admin/users/123', ['administration.users'], ['user.view'])

    expect(screen.getByText('管理画面')).toBeInTheDocument()
  })

  it('allows a calendar manager to access a nested work calendar year page', () => {
    renderAt('/admin/work-calendars/calendar-1/years/year-1/days', ['attendance.entry'], ['attendance.manage'])

    expect(screen.getByText('管理画面')).toBeInTheDocument()
  })

  it('redirects a plain employee away from the admin dashboard root', () => {
    renderAt('/admin')

    expect(screen.getByText('ホーム')).toBeInTheDocument()
  })

  it('allows any effective access used by an admin section to reach the dashboard root', () => {
    renderAt('/admin', ['backoffice.expenses'], ['expense_category.manage'])

    expect(screen.getByText('管理画面')).toBeInTheDocument()
  })
})
