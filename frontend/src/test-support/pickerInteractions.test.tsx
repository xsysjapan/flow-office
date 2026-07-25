import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { DatePicker } from '../components/DatePicker/DatePicker'
import { DateTimePicker } from '../components/DateTimePicker/DateTimePicker'
import { TimePicker } from '../components/TimePicker/TimePicker'
import { pickDate, pickDateTime, pickTime } from './pickerInteractions'

function ControlledDatePicker({ onChange }: { onChange: (date: string | undefined) => void }) {
  const [value, setValue] = useState<string | undefined>(undefined)
  return (
    <DatePicker
      value={value}
      onChange={(next) => {
        setValue(next)
        onChange(next)
      }}
      showRelativeShortcuts={false}
    />
  )
}

describe('pickerInteractions', () => {
  it('pickDate navigates across months (forward and backward) and selects the exact day', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<ControlledDatePicker onChange={onChange} />)

    // 今日から見て未来・過去どちらの月にも数か月分ナビゲーションする必要があるケースを含める。
    await pickDate(user, '日付を選択', '2026-11-20')
    expect(onChange).toHaveBeenLastCalledWith('2026-11-20')

    await pickDate(user, '2026-11-20', '2025-03-03')
    expect(onChange).toHaveBeenLastCalledWith('2025-03-03')
  })

  it('pickTime selects an hour and a minute independently', async () => {
    const onChange = vi.fn()
    function Controlled() {
      const [value, setValue] = useState<string | undefined>(undefined)
      return (
        <TimePicker
          value={value}
          onChange={(next) => {
            setValue(next)
            onChange(next)
          }}
        />
      )
    }
    const user = userEvent.setup()
    render(<Controlled />)

    await pickTime(user, '時刻を選択', '14:45')
    expect(onChange).toHaveBeenLastCalledWith('14:45')
  })

  it('pickDateTime combines pickDate and pickTime for DateTimePicker', async () => {
    const onChange = vi.fn()
    function Controlled() {
      const [value, setValue] = useState<string | undefined>(undefined)
      return (
        <DateTimePicker
          id="dt"
          value={value}
          onChange={(next) => {
            setValue(next)
            onChange(next)
          }}
          showRelativeShortcuts={false}
        />
      )
    }
    const user = userEvent.setup()
    render(<Controlled />)

    // DateTimePickerは日付を選ぶと同時に時刻側を"00:00"で補うため、日付選択後の時刻トリガーの
    // 表示名は最初のプレースホルダーではなく"00:00"になる。
    await pickDateTime(user, '日付を選択', '00:00', '2026-11-20T14:45')

    expect(onChange).toHaveBeenLastCalledWith('2026-11-20T14:45')
    expect(screen.getByRole('button', { name: '2026-11-20' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '14:45' })).toBeInTheDocument()
  })
})
