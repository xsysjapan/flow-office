import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { AssetScanInput } from './AssetScanInput'

describe('AssetScanInput', () => {
  it('入力してEnterを押すとonSubmitが呼ばれ、入力欄がクリアされる', async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined)
    render(<AssetScanInput id="scan" label="対象備品" onSubmit={onSubmit} />)

    const input = screen.getByLabelText('対象備品') as HTMLInputElement
    fireEvent.change(input, { target: { value: 'EQ-00121' } })
    fireEvent.submit(input.closest('form') as HTMLFormElement)

    expect(onSubmit).toHaveBeenCalledWith('EQ-00121')
    await waitFor(() => expect(input.value).toBe(''))
  })

  it('disabledの場合は追加ボタンが押せず、理由が表示される', () => {
    const onSubmit = vi.fn()
    render(
      <AssetScanInput
        id="scan"
        label="対象備品"
        onSubmit={onSubmit}
        disabled
        disabledReason="先に貸出先ユーザーを選択してください。"
      />,
    )

    expect(screen.getByText('先に貸出先ユーザーを選択してください。')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '追加' })).toBeDisabled()
  })

  it('空入力ではonSubmitが呼ばれない', () => {
    const onSubmit = vi.fn()
    render(<AssetScanInput id="scan" label="対象備品" onSubmit={onSubmit} />)

    const input = screen.getByLabelText('対象備品') as HTMLInputElement
    fireEvent.submit(input.closest('form') as HTMLFormElement)

    expect(onSubmit).not.toHaveBeenCalled()
  })
})
