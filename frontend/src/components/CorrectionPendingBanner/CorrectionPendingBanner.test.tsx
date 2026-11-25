import { act, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { ApiError, apiFetch } from '../../api/client'
import { clearCorrectionPending } from '../../api/correctionPending'
import type { User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { CorrectionPendingBanner } from './CorrectionPendingBanner'

const baseUser: User = {
  id: 'u1',
  name: '山田 太郎',
  email: 'y@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

function renderBanner(permissions: string[]) {
  const authValue: AuthContextValue = {
    user: { ...baseUser, effective_permissions: permissions },
    status: 'authenticated',
    login: vi.fn(),
    completeLogin: vi.fn(),
    applySession: vi.fn(),
    logout: vi.fn(),
  }
  return render(
    <AuthContext.Provider value={authValue}>
      <MemoryRouter>
        <CorrectionPendingBanner />
      </MemoryRouter>
    </AuthContext.Provider>,
  )
}

async function receiveCorrectionPending() {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(JSON.stringify({ code: 'correction_pending', message: '補正待ちです。' }), {
      status: 503,
      headers: { 'content-type': 'application/json' },
    }),
  )
  await act(async () => {
    await apiFetch('/attendance/days').catch(() => undefined)
  })
}

beforeEach(() => clearCorrectionPending())
afterEach(() => vi.restoreAllMocks())

it('renders nothing until a correction_pending error is received', async () => {
  renderBanner([])
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()

  await receiveCorrectionPending()

  expect(screen.getByText('補正待ちです。')).toBeInTheDocument()
  expect(screen.getByText(/運用コマンド から補正を実行してください/)).toBeInTheDocument()
})

it('shows the commands link only with the admin_command.view permission', async () => {
  const { unmount } = renderBanner(['admin_command.view'])
  await receiveCorrectionPending()
  expect(screen.getByRole('link', { name: '運用コマンドを開く' })).toHaveAttribute('href', '/admin/commands')
  unmount()

  renderBanner([])
  expect(screen.getByText('補正待ちです。')).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: '運用コマンドを開く' })).not.toBeInTheDocument()
})

it('lets other screens show the API message through ErrorMessage', () => {
  render(<ErrorMessage error={new ApiError(503, '補正待ちです。', undefined, 'correction_pending')} />)

  expect(screen.getByText('補正待ちです。')).toBeInTheDocument()
})
