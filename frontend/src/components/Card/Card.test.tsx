import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Card } from './Card'

describe('Card', () => {
  it('renders the title and children', () => {
    render(<Card title="今日の勤怠">内容</Card>)
    expect(screen.getByRole('heading', { name: '今日の勤怠' })).toBeInTheDocument()
    expect(screen.getByText('内容')).toBeInTheDocument()
  })

  it('renders actions when provided', () => {
    render(
      <Card title="経費精算" actions={<button>編集</button>}>
        内容
      </Card>,
    )
    expect(screen.getByRole('button', { name: '編集' })).toBeInTheDocument()
  })

  it('renders navigation in a separate row below the title on mobile', () => {
    render(
      <Card title="勤怠" actions={<span>提出済み</span>} navigation={<button>前日</button>}>
        内容
      </Card>,
    )

    const navigationRow = screen.getByRole('button', { name: '前日' }).parentElement
    expect(navigationRow).toHaveClass('w-full', 'md:w-auto')
    expect(navigationRow?.previousElementSibling).toContainElement(screen.getByRole('heading', { name: '勤怠' }))
    expect(navigationRow?.previousElementSibling).toContainElement(screen.getByText('提出済み'))
  })

  it('omits the header when no title or actions are given', () => {
    render(<Card>内容のみ</Card>)
    expect(screen.queryByRole('heading')).not.toBeInTheDocument()
  })
})
