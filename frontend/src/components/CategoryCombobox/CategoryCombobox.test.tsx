import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { CategoryCombobox } from './CategoryCombobox'

function ControlledCombobox({ onChange, suggestions }: { onChange: (value: string) => void; suggestions: string[] }) {
  const [value, setValue] = useState('')
  return (
    <CategoryCombobox
      id="category"
      value={value}
      onChange={(next) => {
        setValue(next)
        onChange(next)
      }}
      suggestions={suggestions}
    />
  )
}

describe('CategoryCombobox', () => {
  it('shows matching suggestions while typing', async () => {
    render(<ControlledCombobox onChange={vi.fn()} suggestions={['ノートPC', 'デスクトップPC', 'モニター']} />)

    await userEvent.type(screen.getByRole('combobox'), 'ノート')

    expect(await screen.findByRole('option', { name: 'ノートPC' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'モニター' })).not.toBeInTheDocument()
  })

  it('fills the input and reports the value when a suggestion is selected', async () => {
    const onChange = vi.fn()
    render(<ControlledCombobox onChange={onChange} suggestions={['ノートPC', 'デスクトップPC']} />)

    await userEvent.type(screen.getByRole('combobox'), 'ノート')
    await userEvent.click(await screen.findByRole('option', { name: 'ノートPC' }))

    expect(onChange).toHaveBeenLastCalledWith('ノートPC')
    expect(screen.getByRole('combobox')).toHaveValue('ノートPC')
  })

  it('allows free text input not present in suggestions', async () => {
    const onChange = vi.fn()
    render(<ControlledCombobox onChange={onChange} suggestions={['ノートPC']} />)

    await userEvent.type(screen.getByRole('combobox'), '未登録カテゴリ')

    expect(screen.getByRole('combobox')).toHaveValue('未登録カテゴリ')
    expect(onChange).toHaveBeenLastCalledWith('未登録カテゴリ')
  })

  it('shows no suggestions dropdown when no suggestion matches', async () => {
    render(<ControlledCombobox onChange={vi.fn()} suggestions={['ノートPC']} />)

    await userEvent.type(screen.getByRole('combobox'), 'zzz')

    expect(screen.queryByRole('option')).not.toBeInTheDocument()
  })
})
