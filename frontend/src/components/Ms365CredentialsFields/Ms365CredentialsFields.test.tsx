import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { Ms365CredentialsFields, type Ms365CredentialsFieldsValue } from './Ms365CredentialsFields'

const emptyValue: Ms365CredentialsFieldsValue = {
  tenantId: '',
  clientId: '',
  clientSecret: '',
  mockEnabled: false,
}

describe('Ms365CredentialsFields', () => {
  it('renders all fields with the given id prefix', () => {
    render(<Ms365CredentialsFields idPrefix="test" value={emptyValue} onChange={vi.fn()} />)

    expect(screen.getByLabelText('テナントID')).toBeInTheDocument()
    expect(screen.getByLabelText('クライアントID')).toBeInTheDocument()
    expect(screen.getByLabelText('クライアントシークレット')).toBeInTheDocument()
    expect(screen.getByLabelText('リダイレクトURI')).toBeInTheDocument()
    expect(screen.getByLabelText('ローカル開発用モックOIDC(mock-oidc)を使う')).toBeInTheDocument()
  })

  it('calls onChange with the updated field when typing', async () => {
    const onChange = vi.fn()
    render(<Ms365CredentialsFields idPrefix="test" value={emptyValue} onChange={onChange} />)

    await userEvent.type(screen.getByLabelText('テナントID'), 'x')

    expect(onChange).toHaveBeenCalledWith({ ...emptyValue, tenantId: 'x' })
  })

  it('shows required markers when required is true', () => {
    render(<Ms365CredentialsFields idPrefix="test" value={emptyValue} onChange={vi.fn()} required />)

    expect(screen.getAllByText('*').length).toBeGreaterThan(0)
  })

  it('shows a placeholder hint when the client secret is already configured', () => {
    render(
      <Ms365CredentialsFields idPrefix="test" value={emptyValue} onChange={vi.fn()} clientSecretConfigured />,
    )

    expect(screen.getByPlaceholderText('設定済み(変更する場合のみ入力)')).toBeInTheDocument()
  })
})
