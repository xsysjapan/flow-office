import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ComponentProps } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { Button } from './Button'

function Anchor(props: ComponentProps<'a'>) {
  return <a {...props} />
}

describe('Button', () => {
  it('renders its children', () => {
    render(<Button>出勤</Button>)
    expect(screen.getByRole('button', { name: '出勤' })).toBeInTheDocument()
  })

  it('calls onClick when clicked', async () => {
    const onClick = vi.fn()
    render(<Button onClick={onClick}>出勤</Button>)

    await userEvent.click(screen.getByRole('button'))

    expect(onClick).toHaveBeenCalledOnce()
  })

  it('shows a loading label and disables the button while isLoading', () => {
    render(<Button isLoading>出勤</Button>)

    const button = screen.getByRole('button', { name: '処理中...' })
    expect(button).toBeDisabled()
  })

  it('is disabled when the disabled prop is set', () => {
    render(<Button disabled>出勤</Button>)
    expect(screen.getByRole('button')).toBeDisabled()
  })

  it('renders the child element directly when asChild is set', () => {
    render(
      <Button asChild variant="secondary">
        <Anchor href="/paid-leave/history">履歴を見る</Anchor>
      </Button>,
    )

    const link = screen.getByRole('link', { name: '履歴を見る' })
    expect(link).toHaveAttribute('href', '/paid-leave/history')
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })
})
