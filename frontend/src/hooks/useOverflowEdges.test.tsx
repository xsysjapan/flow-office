import { act, render } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { computeOverflowEdges, useOverflowEdges } from './useOverflowEdges'

function Probe() {
  const ref = useOverflowEdges<HTMLDivElement>()
  return (
    <div ref={ref} data-testid="wrapper">
      <table />
    </div>
  )
}

let resizeCallbacks: Array<() => void> = []
const originalResizeObserver = globalThis.ResizeObserver
const dims = { scrollWidth: 0, clientWidth: 0, scrollLeft: 0 }

beforeEach(() => {
  resizeCallbacks = []
  class StubResizeObserver {
    constructor(callback: () => void) {
      resizeCallbacks.push(callback)
    }
    observe() {}
    unobserve() {}
    disconnect() {}
  }
  globalThis.ResizeObserver = StubResizeObserver as unknown as typeof ResizeObserver
  for (const key of ['scrollWidth', 'clientWidth', 'scrollLeft'] as const) {
    Object.defineProperty(HTMLElement.prototype, key, {
      configurable: true,
      get: () => dims[key],
    })
  }
})

afterEach(() => {
  globalThis.ResizeObserver = originalResizeObserver
  for (const key of ['scrollWidth', 'clientWidth', 'scrollLeft']) {
    delete (HTMLElement.prototype as unknown as Record<string, unknown>)[key]
  }
})

function setDims(scrollWidth: number, clientWidth: number, scrollLeft: number) {
  dims.scrollWidth = scrollWidth
  dims.clientWidth = clientWidth
  dims.scrollLeft = scrollLeft
}

function attrs(el: HTMLElement) {
  return { start: el.hasAttribute('data-overflow-start'), end: el.hasAttribute('data-overflow-end') }
}

describe('computeOverflowEdges', () => {
  it('has no edges when the content fits (including a 1px rounding gap)', () => {
    expect(computeOverflowEdges({ scrollWidth: 500, clientWidth: 500, scrollLeft: 0 })).toEqual({ start: false, end: false })
    expect(computeOverflowEdges({ scrollWidth: 501, clientWidth: 500, scrollLeft: 0 })).toEqual({ start: false, end: false })
  })

  it('treats a fractional scroll position at the right edge as the end', () => {
    expect(computeOverflowEdges({ scrollWidth: 1000, clientWidth: 500, scrollLeft: 499.5 })).toEqual({ start: true, end: false })
  })
})

describe('useOverflowEdges', () => {
  it('sets no attribute when nothing overflows', () => {
    setDims(500, 500, 0)
    const { getByTestId } = render(<Probe />)
    expect(attrs(getByTestId('wrapper'))).toEqual({ start: false, end: false })
  })

  it('sets only data-overflow-end at the left edge', () => {
    setDims(1000, 500, 0)
    const { getByTestId } = render(<Probe />)
    expect(attrs(getByTestId('wrapper'))).toEqual({ start: false, end: true })
  })

  it('sets both attributes in the middle, then only start at the right edge, on scroll', () => {
    setDims(1000, 500, 0)
    const { getByTestId } = render(<Probe />)
    const wrapper = getByTestId('wrapper')

    setDims(1000, 500, 200)
    act(() => {
      wrapper.dispatchEvent(new Event('scroll'))
    })
    expect(attrs(wrapper)).toEqual({ start: true, end: true })

    setDims(1000, 500, 500)
    act(() => {
      wrapper.dispatchEvent(new Event('scroll'))
    })
    expect(attrs(wrapper)).toEqual({ start: true, end: false })
  })

  it('updates when the size changes (ResizeObserver)', () => {
    setDims(500, 500, 0)
    const { getByTestId } = render(<Probe />)
    const wrapper = getByTestId('wrapper')
    expect(attrs(wrapper)).toEqual({ start: false, end: false })

    setDims(1200, 500, 0)
    act(() => {
      resizeCallbacks.forEach((callback) => callback())
    })
    expect(attrs(wrapper)).toEqual({ start: false, end: true })

    setDims(500, 500, 0)
    act(() => {
      resizeCallbacks.forEach((callback) => callback())
    })
    expect(attrs(wrapper)).toEqual({ start: false, end: false })
  })
})
