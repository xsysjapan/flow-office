import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as onboardingApi from '../../api/onboarding'
import { OnboardingPage } from './OnboardingPage'

const applySession = vi.fn()

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ applySession }),
}))

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/onboarding']}>
      <Routes>
        <Route path="/onboarding" element={<OnboardingPage />} />
        <Route path="/" element={<p>ホーム</p>} />
      </Routes>
    </MemoryRouter>,
  )
}

async function fillRequiredFields() {
  await userEvent.type(screen.getByLabelText('氏名'), 'テスト管理者')
  await userEvent.type(screen.getByLabelText('メールアドレス'), 'admin@example.com')
  await userEvent.type(screen.getByLabelText('テナントID'), 'tenant-1')
  await userEvent.type(screen.getByLabelText('クライアントID'), 'client-1')
  await userEvent.type(screen.getByLabelText('クライアントシークレット'), 'secret-1')
}

describe('OnboardingPage', () => {
  beforeEach(() => {
    applySession.mockReset()
  })

  it('disables the submit button until required fields are filled', async () => {
    renderPage()

    expect(screen.getByRole('button', { name: 'セットアップを完了する' })).toBeDisabled()

    await fillRequiredFields()

    expect(screen.getByRole('button', { name: 'セットアップを完了する' })).toBeEnabled()
  })

  it('submits onboarding, logs in with the returned session, and navigates home', async () => {
    const user = { id: 1, name: 'テスト管理者', email: 'admin@example.com', department: null, job_title: null, employment_status: 'active', last_login_at: null }
    vi.spyOn(onboardingApi, 'submitOnboarding').mockResolvedValue({ token: 'test-token', user })

    renderPage()
    await fillRequiredFields()
    await userEvent.click(screen.getByRole('button', { name: 'セットアップを完了する' }))

    await waitFor(() =>
      expect(onboardingApi.submitOnboarding).toHaveBeenCalledWith(
        expect.objectContaining({
          admin_name: 'テスト管理者',
          admin_email: 'admin@example.com',
          m365_tenant_id: 'tenant-1',
          m365_client_id: 'client-1',
          m365_client_secret: 'secret-1',
        }),
      ),
    )
    await waitFor(() => expect(applySession).toHaveBeenCalledWith('test-token', user))
    expect(await screen.findByText('ホーム')).toBeInTheDocument()
  })

  it('shows an error message when onboarding fails', async () => {
    vi.spyOn(onboardingApi, 'submitOnboarding').mockRejectedValue(new Error('既にセットアップ済みです'))

    renderPage()
    await fillRequiredFields()
    await userEvent.click(screen.getByRole('button', { name: 'セットアップを完了する' }))

    expect(await screen.findByText('既にセットアップ済みです')).toBeInTheDocument()
    expect(applySession).not.toHaveBeenCalled()
  })
})
