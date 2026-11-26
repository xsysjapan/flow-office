import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { YearMonthPicker } from './YearMonthPicker'
import { FormField } from '../FormField/FormField'

function ControlledPicker({ initialValue }: { initialValue?: string }) {
  const [value, setValue] = useState<string | undefined>(initialValue)
  return <YearMonthPicker value={value} onChange={setValue} />
}

describe('YearMonthPicker', () => {
  it('selects a month in YYYY-MM form and closes the popover', async () => {
    const onChange = vi.fn()
    render(<YearMonthPicker value="2026-07" onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '2026年7月' }))
    await userEvent.click(screen.getByRole('option', { name: '2026年8月' }))

    expect(onChange).toHaveBeenCalledWith('2026-08')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('moves between years', async () => {
    render(<YearMonthPicker value="2026-07" onChange={vi.fn()} />)
    await userEvent.click(screen.getByRole('button', { name: '2026年7月' }))
    await userEvent.click(screen.getByRole('button', { name: '翌年へ' }))
    expect(screen.getByText('2027年')).toBeInTheDocument()
  })

  it('clears a selected value', async () => {
    render(<ControlledPicker initialValue="2026-07" />)
    await userEvent.click(screen.getByRole('button', { name: '2026年7月' }))
    await userEvent.click(screen.getByRole('button', { name: 'クリア' }))
    expect(screen.getByRole('button', { name: '年月を選択' })).toBeInTheDocument()
  })

  it('disables months outside min and max', async () => {
    render(<YearMonthPicker value="2026-07" min="2026-06" max="2026-08" onChange={vi.fn()} />)
    await userEvent.click(screen.getByRole('button', { name: '2026年7月' }))
    expect(screen.getByRole('option', { name: '2026年5月' })).toBeDisabled()
    expect(screen.getByRole('option', { name: '2026年6月' })).not.toBeDisabled()
    expect(screen.getByRole('option', { name: '2026年9月' })).toBeDisabled()
  })

  it('supports arrow-key navigation and selection', async () => {
    const onChange = vi.fn()
    render(<YearMonthPicker value="2026-07" onChange={onChange} />)
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: '2026年7月' }))
    expect(screen.getByRole('option', { name: '2026年7月' })).toHaveFocus()
    await user.keyboard('{ArrowRight}{Enter}')

    expect(onChange).toHaveBeenCalledWith('2026-08')
  })
})

describe('YearMonthPicker in a FormField', () => {
  it('adds the required note to aria-describedby of the button', () => {
    render(
      <FormField label="対象" htmlFor="field" required description="説明です。" error="エラーです。">
        <YearMonthPicker id="field" value={undefined} onChange={() => {}} />
      </FormField>,
    )
    const button = screen.getByLabelText('対象')
    expect(button).toHaveAttribute('aria-describedby', 'field-required field-description field-error')
    expect(button).toHaveAccessibleDescription(/必須.*説明です。.*エラーです。/)
    expect(button).toHaveAttribute('aria-invalid', 'true')
    expect(button).not.toHaveAttribute('aria-required')
  })
})
