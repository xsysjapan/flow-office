import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PermissionDenied } from './PermissionDenied'

describe('PermissionDenied', () => {
  it('shows the default message', () => {
    render(<PermissionDenied />)
    expect(screen.getByRole('alert')).toHaveTextContent('この操作を行う権限がありません。')
  })

  it('supports a custom message', () => {
    render(<PermissionDenied message="このグループを編集する権限がありません。" />)
    expect(screen.getByRole('alert')).toHaveTextContent('このグループを編集する権限がありません。')
  })

  it('does not render a retry button', () => {
    render(<PermissionDenied />)
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })
})
