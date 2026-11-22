import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import {
  MAX_BACK_DEPTH,
  useBackTarget,
  useForwardState,
  useKeepBackState,
  useSearchParamsKeepingBackState,
  type BackTo,
} from './backState'

function Probe({ override }: { override?: { to?: string; label?: string } }) {
  const target = useBackTarget(override)
  return <pre data-testid="target">{JSON.stringify(target)}</pre>
}

function ForwardProbe() {
  return <pre data-testid="forward">{JSON.stringify(useForwardState())}</pre>
}

function KeepProbe() {
  return <pre data-testid="keep">{JSON.stringify(useKeepBackState() ?? null)}</pre>
}

function read(testId: string) {
  return JSON.parse(screen.getByTestId(testId).textContent ?? 'null')
}

function renderAt(entry: string | { pathname: string; state?: unknown }, ui: React.ReactNode) {
  return render(<MemoryRouter initialEntries={[entry]}>{ui}</MemoryRouter>)
}

describe('useBackTarget', () => {
  it('returns the recorded origin with pathname, query and hash, restoring the nested state', () => {
    const state = {
      backTo: {
        pathname: '/requests',
        search: '?status=pending&page=2',
        hash: '#list',
        backTo: { pathname: '/paid-leave', search: '', hash: '' },
      },
    }
    renderAt({ pathname: '/requests/abc', state }, <Probe />)

    expect(read('target')).toEqual({
      to: '/requests?status=pending&page=2#list',
      state: { backTo: { pathname: '/paid-leave', search: '', hash: '' } },
      label: '申請一覧',
    })
  })

  it('falls back to the hierarchy parent when there is no origin', () => {
    renderAt('/paid-leave/history', <Probe />)

    expect(read('target')).toEqual({ to: '/paid-leave', label: '有給' })
  })

  it('returns null for a first-level page without an origin', () => {
    renderAt('/paid-leave', <Probe />)

    expect(read('target')).toBeNull()
  })

  it.each(['//evil.example/x', '/\\evil.example'])('ignores an unsafe origin %s and uses the parent', (pathname) => {
    renderAt({ pathname: '/paid-leave/history', state: { backTo: { pathname, search: '', hash: '' } } }, <Probe />)

    expect(read('target')).toEqual({ to: '/paid-leave', label: '有給' })
  })

  it('ignores a malformed state value', () => {
    renderAt({ pathname: '/paid-leave/history', state: { backTo: 'nope' } }, <Probe />)

    expect(read('target').to).toBe('/paid-leave')
  })

  it('truncates a nested origin deeper than the limit', () => {
    let chain: BackTo | undefined
    for (let i = 8; i >= 1; i--) {
      chain = { pathname: `/requests/r${i}`, search: '', hash: '', backTo: chain }
    }
    renderAt({ pathname: '/requests/abc', state: { backTo: chain } }, <Probe />)

    const target = read('target')
    expect(target.to).toBe('/requests/r1')
    let depth = 1
    let cursor = target.state?.backTo
    while (cursor) {
      depth++
      cursor = cursor.backTo
    }
    // 戻り先自身(1段)+復元したstate(残り)で合計MAX_BACK_DEPTH段に収まる
    expect(depth).toBe(MAX_BACK_DEPTH)
  })

  it('does not copy other keys of the origin state', () => {
    const state = {
      backTo: { pathname: '/requests', search: '', hash: '', secret: 'x', backTo: { pathname: '/paid-leave', search: '', hash: '', extra: 1 } },
      other: 'y',
    }
    renderAt({ pathname: '/requests/abc', state }, <Probe />)

    expect(read('target').state).toEqual({ backTo: { pathname: '/paid-leave', search: '', hash: '' } })
  })

  it('prefers override.to and override.label', () => {
    const state = { backTo: { pathname: '/requests', search: '', hash: '' } }
    renderAt({ pathname: '/requests/abc', state }, <Probe override={{ to: '/approvals' }} />)
    expect(read('target')).toEqual({ to: '/approvals', label: '承認待ち' })
  })

  it('uses override.label over the resolved title', () => {
    renderAt('/admin/work-calendars/c1/years/2026/days', <Probe override={{ label: '標準カレンダー' }} />)

    expect(read('target')).toEqual({ to: '/admin/work-calendars/c1', label: '標準カレンダー' })
  })

  it('uses a generic label when the target page is not registered', () => {
    renderAt('/paid-leave/history', <Probe override={{ to: '/somewhere-unknown' }} />)

    expect(read('target')).toEqual({ to: '/somewhere-unknown', label: '前の画面' })
  })

  it('returns null for a menu-entry day page opened without an origin', () => {
    renderAt('/attendance/days/2026-11-21', <Probe />)

    expect(read('target')).toBeNull()
  })

  it('returns null for a menu-entry month page opened without an origin', () => {
    renderAt('/attendance/months/2026-11', <Probe />)

    expect(read('target')).toBeNull()
  })

  it('returns null for a menu-entry page whose origin is unsafe', () => {
    renderAt({ pathname: '/attendance/months/2026-11', state: { backTo: { pathname: '//evil.example/x', search: '', hash: '' } } }, <Probe />)

    expect(read('target')).toBeNull()
  })

  it('returns the recorded origin for a menu-entry page drilled down from a list', () => {
    const state = { backTo: { pathname: '/attendance/months/2026-11', search: '', hash: '' } }
    renderAt({ pathname: '/attendance/days/2026-11-21', state }, <Probe />)

    expect(read('target')).toEqual({ to: '/attendance/months/2026-11', label: '2026年11月の勤怠' })
  })

  it('still falls back to the parent for a non-menu page without an origin', () => {
    renderAt('/requests/abc', <Probe />)

    expect(read('target')).toEqual({ to: '/requests', label: '申請一覧' })
  })

  it('still uses override.to for a menu-entry page without an origin', () => {
    renderAt('/attendance/months/2026-11', <Probe override={{ to: '/attendance/months' }} />)

    expect(read('target')).toEqual({ to: '/attendance/months', label: '月次勤怠' })
  })
})

describe('useForwardState', () => {
  it('records the current location with the existing origin nested', () => {
    const state = { backTo: { pathname: '/paid-leave', search: '', hash: '' }, other: 'drop' }
    renderAt({ pathname: '/requests/abc', state }, <ForwardProbe />)

    expect(read('forward')).toEqual({
      backTo: {
        pathname: '/requests/abc',
        search: '',
        hash: '',
        backTo: { pathname: '/paid-leave', search: '', hash: '' },
      },
    })
  })

  it('records search and hash of the current location', () => {
    renderAt('/requests?status=a&page=2#x', <ForwardProbe />)

    expect(read('forward')).toEqual({ backTo: { pathname: '/requests', search: '?status=a&page=2', hash: '#x' } })
  })

  it('caps the nesting at the limit by dropping the oldest entries', () => {
    let chain: BackTo | undefined
    for (let i = 8; i >= 1; i--) {
      chain = { pathname: `/p${i}`, search: '', hash: '', backTo: chain }
    }
    renderAt({ pathname: '/requests/abc', state: { backTo: chain } }, <ForwardProbe />)

    const paths: string[] = []
    let cursor: BackTo | undefined = read('forward').backTo
    while (cursor) {
      paths.push(cursor.pathname)
      cursor = cursor.backTo
    }
    expect(paths).toEqual(['/requests/abc', '/p1', '/p2', '/p3', '/p4'])
    expect(paths).toHaveLength(MAX_BACK_DEPTH)
  })
})

describe('useKeepBackState', () => {
  it('returns the current backTo only', () => {
    renderAt({ pathname: '/requests/abc', state: { backTo: { pathname: '/paid-leave', search: '', hash: '' }, other: 1 } }, <KeepProbe />)

    expect(read('keep')).toEqual({ backTo: { pathname: '/paid-leave', search: '', hash: '' } })
  })

  it('returns undefined when there is no origin', () => {
    renderAt('/requests/abc', <KeepProbe />)

    expect(read('keep')).toBeNull()
  })
})

describe('useSearchParamsKeepingBackState', () => {
  function Page() {
    const [params, setParams] = useSearchParamsKeepingBackState()
    const location = useLocation()
    return (
      <>
        <button type="button" onClick={() => setParams({ page: '2' })}>
          next
        </button>
        <button type="button" onClick={() => setParams({ page: '3' }, { replace: true })}>
          replace
        </button>
        <span data-testid="page">{params.get('page') ?? 'none'}</span>
        <pre data-testid="state">{JSON.stringify(location.state)}</pre>
      </>
    )
  }

  it('keeps the origin state after setSearchParams', async () => {
    const backTo = { pathname: '/paid-leave', search: '', hash: '' }
    renderAt({ pathname: '/requests/abc', state: { backTo, other: 'drop' } }, <Page />)

    await userEvent.click(screen.getByRole('button', { name: 'next' }))
    expect(screen.getByTestId('page')).toHaveTextContent('2')
    expect(read('state')).toEqual({ backTo })

    await userEvent.click(screen.getByRole('button', { name: 'replace' }))
    expect(screen.getByTestId('page')).toHaveTextContent('3')
    expect(read('state')).toEqual({ backTo })
  })

  it('works without an origin state', async () => {
    renderAt('/requests/abc', <Page />)

    await userEvent.click(screen.getByRole('button', { name: 'next' }))
    expect(screen.getByTestId('page')).toHaveTextContent('2')
    expect(read('state')).toBeNull()
  })
})
