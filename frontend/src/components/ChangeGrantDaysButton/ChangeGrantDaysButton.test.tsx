import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { ChangeGrantDaysButton } from './ChangeGrantDaysButton'

function setup(overrides: Partial<React.ComponentProps<typeof ChangeGrantDaysButton>> = {}) {
  const onChange = vi.fn().mockResolvedValue(undefined)
  render(
    <ChangeGrantDaysButton
      id="change-days"
      grantedOn="2026-07-01"
      currentDays={10}
      usedDays={1}
      onChange={onChange}
      {...overrides}
    />,
  )
  return onChange
}

describe('ChangeGrantDaysButton', () => {
  it('opens the dialog with the current days and calls onChange with the days and reason', async () => {
    const onChange = setup()

    await userEvent.click(screen.getByRole('button', { name: '日数を変更' }))
    const input = await screen.findByLabelText(/新しい付与日数/)
    expect(input).toHaveValue(10)
    await userEvent.clear(input)
    await userEvent.type(input, '12')
    await userEvent.type(screen.getByLabelText('変更理由(任意)'), '訂正')
    await userEvent.click(screen.getByRole('button', { name: '変更する' }))

    expect(onChange).toHaveBeenCalledWith(12, '訂正')
  })

  it('passes undefined reason when empty', async () => {
    const onChange = setup()

    await userEvent.click(screen.getByRole('button', { name: '日数を変更' }))
    await userEvent.click(await screen.findByRole('button', { name: '変更する' }))

    expect(onChange).toHaveBeenCalledWith(10, undefined)
  })

  it('disables confirm when empty, below 0.5 or below the used days', async () => {
    const onChange = setup({ usedDays: 2.2 })

    await userEvent.click(screen.getByRole('button', { name: '日数を変更' }))
    const input = await screen.findByLabelText(/新しい付与日数/)
    const confirm = screen.getByRole('button', { name: '変更する' })

    await userEvent.clear(input)
    expect(confirm).toBeDisabled()
    await userEvent.type(input, '2.4')
    expect(confirm).toBeDisabled()
    await userEvent.clear(input)
    await userEvent.type(input, '2.5')
    expect(confirm).toBeEnabled()
    expect(onChange).not.toHaveBeenCalled()
  })

  it('renders a disabled button with the reason as title', () => {
    setup({ disabled: true, disabledReason: '最新の付与のみ変更できます。' })

    const button = screen.getByRole('button', { name: '日数を変更' })
    expect(button).toBeDisabled()
    expect(button).toHaveAttribute('title', '最新の付与のみ変更できます。')
  })
})
