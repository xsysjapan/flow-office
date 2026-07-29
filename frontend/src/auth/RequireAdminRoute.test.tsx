import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { User } from '../api/types'
import { RequireAdminRoute } from './RequireAdminRoute'
import { useAuth } from './useAuth'

vi.mock('./useAuth')

function renderAt(pathname: string, roles: string[] | undefined) {
  vi.mocked(useAuth).mockReturnValue({
    user: { roles } as User,
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
  it('renders the admin page when the user has the required role', () => {
    renderAt('/admin/users', ['admin'])

    expect(screen.getByText('管理画面')).toBeInTheDocument()
  })

  it('allows a role that is only listed for that specific nav item (e.g. accounting_staff for expense settings)', () => {
    renderAt('/admin/expense-categories', ['accounting_staff'])

    expect(screen.getByText('管理画面')).toBeInTheDocument()
  })

  it('redirects to / when the user lacks the role required for that section', () => {
    renderAt('/admin/expense-categories', ['employee'])

    expect(screen.getByText('ホーム')).toBeInTheDocument()
    expect(screen.queryByText('管理画面')).not.toBeInTheDocument()
  })

  it('matches sub-paths against their parent nav item (e.g. /admin/users/:id)', () => {
    renderAt('/admin/users/123', ['hr_staff'])

    expect(screen.getByText('管理画面')).toBeInTheDocument()
  })

  it('redirects a plain employee away from the admin dashboard root', () => {
    renderAt('/admin', ['employee'])

    expect(screen.getByText('ホーム')).toBeInTheDocument()
  })

  it('allows any role used by some admin section to reach the dashboard root', () => {
    renderAt('/admin', ['accounting_staff'])

    expect(screen.getByText('管理画面')).toBeInTheDocument()
  })
})
