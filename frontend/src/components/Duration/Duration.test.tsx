import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Duration } from './Duration'

describe('Duration', () => {
  it('omits zero minutes from the desktop label and pads them in the mobile label', () => {
    render(<Duration minutes={480} />)

    expect(screen.getByText('8時間')).toBeInTheDocument()
    expect(screen.getByText('8:00')).toBeInTheDocument()
  })

  it('shows hours and remaining minutes in both display formats', () => {
    render(<Duration minutes={495} />)

    expect(screen.getByText('8時間15分')).toBeInTheDocument()
    expect(screen.getByText('8:15')).toBeInTheDocument()
  })

  it('shows minutes when the duration is shorter than an hour', () => {
    render(<Duration minutes={30} />)

    expect(screen.getByText('30分')).toBeInTheDocument()
    expect(screen.getByText('0:30')).toBeInTheDocument()
  })
})