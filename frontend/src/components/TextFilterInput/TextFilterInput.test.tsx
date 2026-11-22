import { act, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { TextFilterInput } from './TextFilterInput'

function setup(value: string | null = null) {
  const onChange = vi.fn()
  const utils = render(<TextFilterInput label="キーワード" value={value} onChange={onChange} />)
  const input = screen.getByLabelText('キーワード') as HTMLInputElement
  return { onChange, input, ...utils }
}

describe('TextFilterInput', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('可視ラベルでgetByLabelTextできる', () => {
    const { input } = setup()
    expect(input).toHaveAttribute('type', 'search')
  })

  it('入力から300ms後に1回だけonChangeする', () => {
    const { onChange, input } = setup()
    fireEvent.change(input, { target: { value: 'a' } })
    fireEvent.change(input, { target: { value: 'ab' } })
    act(() => {
      vi.advanceTimersByTime(300)
    })
    expect(onChange).toHaveBeenCalledTimes(1)
    expect(onChange).toHaveBeenCalledWith('ab')
  })

  it('299msでは呼ばれない', () => {
    const { onChange, input } = setup()
    fireEvent.change(input, { target: { value: 'a' } })
    act(() => {
      vi.advanceTimersByTime(299)
    })
    expect(onChange).not.toHaveBeenCalled()
  })

  it('変換中は呼ばれず、compositionend後300msで呼ばれる', () => {
    const { onChange, input } = setup()
    fireEvent.compositionStart(input)
    fireEvent.change(input, { target: { value: 'やま' } })
    act(() => {
      vi.advanceTimersByTime(1000)
    })
    expect(onChange).not.toHaveBeenCalled()
    fireEvent.compositionEnd(input)
    act(() => {
      vi.advanceTimersByTime(299)
    })
    expect(onChange).not.toHaveBeenCalled()
    act(() => {
      vi.advanceTimersByTime(1)
    })
    expect(onChange).toHaveBeenCalledWith('やま')
  })

  it('Enterで即時に反映する', () => {
    const { onChange, input } = setup()
    fireEvent.change(input, { target: { value: 'abc' } })
    fireEvent.keyDown(input, { key: 'Enter' })
    expect(onChange).toHaveBeenCalledWith('abc')
    act(() => {
      vi.advanceTimersByTime(300)
    })
    expect(onChange).toHaveBeenCalledTimes(1)
  })

  it('blurで即時に反映する', () => {
    const { onChange, input } = setup()
    fireEvent.change(input, { target: { value: 'abc' } })
    fireEvent.blur(input)
    expect(onChange).toHaveBeenCalledWith('abc')
  })

  it('外からvalueを変えると表示が変わる', () => {
    const { input, rerender, onChange } = setup()
    rerender(<TextFilterInput label="キーワード" value="外部" onChange={onChange} />)
    expect(input.value).toBe('外部')
    rerender(<TextFilterInput label="キーワード" value={null} onChange={onChange} />)
    expect(input.value).toBe('')
  })

  it('自分が反映した値が戻っても末尾空白の表示が残る', () => {
    const { input, rerender, onChange } = setup()
    fireEvent.change(input, { target: { value: '山田 ' } })
    act(() => {
      vi.advanceTimersByTime(300)
    })
    expect(onChange).toHaveBeenCalledWith('山田')
    rerender(<TextFilterInput label="キーワード" value="山田" onChange={onChange} />)
    expect(input.value).toBe('山田 ')
  })

  it('アンマウント後はタイマーが発火してもonChangeしない', () => {
    const { onChange, input, unmount } = setup()
    fireEvent.change(input, { target: { value: 'abc' } })
    unmount()
    act(() => {
      vi.advanceTimersByTime(1000)
    })
    expect(onChange).not.toHaveBeenCalled()
  })

  it('空白のみはnullで反映する', () => {
    const { onChange, input } = setup('abc')
    fireEvent.change(input, { target: { value: '   ' } })
    act(() => {
      vi.advanceTimersByTime(300)
    })
    expect(onChange).toHaveBeenCalledWith(null)
  })

  it('同じ値は連続でonChangeしない', () => {
    const { onChange, input } = setup()
    fireEvent.change(input, { target: { value: 'abc' } })
    fireEvent.keyDown(input, { key: 'Enter' })
    fireEvent.blur(input)
    expect(onChange).toHaveBeenCalledTimes(1)
  })
})
