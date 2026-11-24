import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FormActions } from './FormActions'

describe('FormActions', () => {
  it('stacks on narrow containers and aligns right on wider ones', () => {
    render(
      <FormActions>
        <button>キャンセル</button>
      </FormActions>,
    )
    const row = screen.getByRole('button').parentElement
    expect(row).toHaveClass('flex', 'flex-col-reverse', 'gap-2', 'pt-2', '@sm:flex-row', '@sm:justify-end')
  })

  it('keeps the order of the children', () => {
    render(
      <FormActions>
        <button>キャンセル</button>
        <button>下書き保存</button>
        <button>申請する</button>
      </FormActions>,
    )
    expect(screen.getAllByRole('button').map((b) => b.textContent)).toEqual(['キャンセル', '下書き保存', '申請する'])
  })
})
