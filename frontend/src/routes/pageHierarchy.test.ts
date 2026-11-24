import { describe, expect, it } from 'vitest'
import appSource from '../App.tsx?raw'
import { adminNavGroups } from '../components/AdminLayout/adminNavGroups'
import { ancestorsOf, findActiveNavPath, pageNodes, resolvePage } from './pageHierarchy'

/** App.tsxのソースから、ネストを解決した全ルートの絶対パスを取り出す。
 *  除外: 公開ルート・`*`・要素が<Navigate>だけのルート・コンポーネント内でリダイレクトするだけの`/assets/qr/:token`。 */
function extractAppRoutePatterns(source: string): string[] {
  const src = source.replace(/\{\/\*[\s\S]*?\*\/\}/g, '')
  const tokens = [...src.matchAll(/<Route\b|<\/Route>/g)]
  const stack: string[] = []
  const patterns: string[] = []
  const join = (base: string, rel: string) =>
    rel.startsWith('/') ? rel : `${base === '/' ? '' : base}/${rel}`.replace(/\/$/, '') || '/'

  tokens.forEach((token, i) => {
    if (token[0] === '</Route>') {
      stack.pop()
      return
    }
    const end = i + 1 < tokens.length ? tokens[i + 1].index : src.length
    const segment = src.slice(token.index, end).trim()
    const rel = /\bpath="([^"]*)"/.exec(segment)?.[1] ?? ''
    const base = stack[stack.length - 1] ?? ''
    const full = rel === '' ? base || '/' : join(base || '/', rel)
    if (segment.endsWith('/>')) {
      const navigateOnly = /element=\{\s*<Navigate\b/.test(segment)
      patterns.push(navigateOnly ? `!${full}` : full)
    } else {
      stack.push(full)
    }
  })
  return patterns
}

const EXCLUDED = new Set(['/login', '/onboarding', '/auth/callback', '*', '/*', '/assets/qr/:token'])

describe('pageHierarchy: App.tsx coverage', () => {
  const all = extractAppRoutePatterns(appSource)
  const targets = all.filter((p) => !p.startsWith('!') && !EXCLUDED.has(p))

  it('extracts a plausible number of routes from App.tsx', () => {
    expect(targets.length).toBeGreaterThan(60)
    expect(targets).toContain('/admin/work-calendars/:calendarId/years/:fiscalYear/days')
    expect(targets).toContain('/')
    expect(targets).toContain('/admin')
  })

  it.each(targets)('registers %s', (pattern) => {
    const concrete = pattern.replace(/:([A-Za-z]+)/g, (_, name: string) =>
      name === 'yearMonth' ? '2026-11' : name === 'date' ? '2026-11-21' : name === 'fiscalYear' ? '2026' : 'x1',
    )
    expect(resolvePage(concrete), concrete).not.toBeNull()
  })
})

describe('resolvePage', () => {
  it('resolves static pages', () => {
    expect(resolvePage('/paid-leave/history')).toEqual({ title: '有給履歴', parentPath: '/paid-leave', menuEntry: false })
    expect(resolvePage('/paid-leave')).toEqual({ title: '有給', parentPath: null, menuEntry: false })
    expect(resolvePage('/')).toEqual({ title: 'ホーム', parentPath: null, menuEntry: false })
  })

  it('marks only the day and month detail pages as menu entries', () => {
    expect(resolvePage('/attendance/days/2026-11-21')?.menuEntry).toBe(true)
    expect(resolvePage('/attendance/months/2026-11')?.menuEntry).toBe(true)
    expect(resolvePage('/attendance/week')?.menuEntry).toBe(false)
    expect(resolvePage('/attendance/months')?.menuEntry).toBe(false)
    expect(resolvePage('/paid-leave/history')?.menuEntry).toBe(false)
  })

  it('returns null for unknown paths', () => {
    expect(resolvePage('/unknown/path')).toBeNull()
  })

  it('prefers the more specific pattern', () => {
    expect(resolvePage('/expenses/presets')?.title).toBe('入力プリセット')
    expect(resolvePage('/expenses/new')?.title).toBe('経費精算を作成')
    expect(resolvePage('/expenses/abc')?.title).toBe('経費精算詳細')
    expect(resolvePage('/assets/bulk/lend')?.parentPath).toBe('/assets')
    expect(resolvePage('/admin/work-calendars/new')?.title).toBe('会社カレンダーを作成')
  })

  it('builds dynamic titles and parents for months and days', () => {
    expect(resolvePage('/attendance/months/2026-11')).toEqual({ title: '2026年11月の勤怠', parentPath: '/attendance/months', menuEntry: true })
    expect(resolvePage('/attendance/days/2026-11-21')).toEqual({
      title: '11月21日の勤怠',
      parentPath: '/attendance/months/2026-11',
      menuEntry: true,
    })
  })

  it('falls back to generic names for malformed parameters without throwing', () => {
    expect(resolvePage('/attendance/months/abc')).toEqual({ title: '月次勤怠', parentPath: '/attendance/months', menuEntry: true })
    expect(resolvePage('/attendance/days/2026-13-40')).toEqual({ title: '日次勤怠', parentPath: '/attendance/months', menuEntry: true })
    expect(resolvePage('/attendance/days/xyz')).toEqual({ title: '日次勤怠', parentPath: '/attendance/months', menuEntry: true })
    expect(resolvePage('/admin/work-calendars/c1/years/abc/days')?.title).toBe('日別設定')
  })

  it('builds parents containing parameters', () => {
    expect(resolvePage('/expenses/e1/edit')?.parentPath).toBe('/expenses/e1')
    expect(resolvePage('/assets/a1/edit')?.parentPath).toBe('/assets/a1')
    expect(resolvePage('/admin/work-calendars/c1/years/2026/days')).toEqual({
      title: '2026年度の日別設定',
      parentPath: '/admin/work-calendars/c1',
      menuEntry: false,
    })
  })

  it('switches titles on id === "new"', () => {
    expect(resolvePage('/admin/groups/new')?.title).toBe('グループを作成')
    expect(resolvePage('/admin/groups/g1')?.title).toBe('グループ詳細')
    expect(resolvePage('/admin/request-types/new')?.title).toBe('申請種別を作成')
    expect(resolvePage('/admin/request-types/r1')?.title).toBe('申請種別を編集')
    expect(resolvePage('/admin/expense-categories/new')?.title).toBe('経費区分を作成')
    expect(resolvePage('/admin/expense-categories/c1')?.title).toBe('経費区分を編集')
  })

  it('uses the admin nav label for admin list pages with no parent', () => {
    for (const group of adminNavGroups) {
      for (const item of group.items) {
        expect(resolvePage(item.to)).toEqual({ title: item.label, parentPath: null, menuEntry: false })
      }
    }
  })
})

describe('ancestorsOf', () => {
  it('lists the path itself then its parents, nearest first', () => {
    expect(ancestorsOf('/attendance/days/2026-11-21')).toEqual([
      '/attendance/days/2026-11-21',
      '/attendance/months/2026-11',
      '/attendance/months',
    ])
    expect(ancestorsOf('/paid-leave')).toEqual(['/paid-leave'])
  })

  it('returns just the path for unknown pages', () => {
    expect(ancestorsOf('/nope')).toEqual(['/nope'])
  })

  it('stops at 10 entries (cycle guard) for every registered node', () => {
    for (const node of pageNodes) {
      expect(ancestorsOf(node.pattern.replace(/:[A-Za-z]+/g, 'x1')).length).toBeLessThanOrEqual(10)
    }
  })
})

describe('findActiveNavPath', () => {
  const appNav = [
    '/',
    '/attendance/days',
    '/attendance/week',
    '/attendance/months',
    '/requests',
    '/paid-leave',
    '/special-leave',
    '/expenses',
    '/assets',
    '/assets',
    '/admin',
  ]
  const adminNav = [...adminNavGroups.flatMap((g) => g.items.map((i) => i.to)), '/admin']

  it('lights the parent of a history page', () => {
    expect(findActiveNavPath('/paid-leave/history', appNav)).toEqual(['/paid-leave'])
  })

  it('lights 申請一覧 for a request detail', () => {
    expect(findActiveNavPath('/requests/abc', appNav)).toEqual(['/requests'])
  })

  it('lights 月次勤怠 for a past month', () => {
    expect(findActiveNavPath('/attendance/months/2025-03', appNav)).toEqual(['/attendance/months'])
  })

  it('lights only 日次勤怠 (not 月次勤怠) for a day page', () => {
    expect(findActiveNavPath('/attendance/days/2025-03-05', appNav)).toEqual(['/attendance/days'])
  })

  it('lights every item sharing the same path', () => {
    expect(findActiveNavPath('/assets/a1', appNav)).toEqual(['/assets', '/assets'])
  })

  it('lights only home on "/"', () => {
    expect(findActiveNavPath('/', appNav, ['/'])).toEqual(['/'])
  })

  it('does not light home for other pages', () => {
    expect(findActiveNavPath('/unknown', appNav, ['/'])).toEqual([])
  })

  it('lights the admin list item for details and sub pages', () => {
    expect(findActiveNavPath('/admin/users/u1', adminNav)).toEqual(['/admin/users'])
    expect(findActiveNavPath('/admin/paid-leave/schedule', adminNav)).toEqual(['/admin/paid-leave'])
    expect(findActiveNavPath('/admin/work-calendars/c1/years/2026/days', adminNav)).toEqual(['/admin/work-calendars'])
  })

  it('lights /admin only on /admin itself when it is exact-only', () => {
    expect(findActiveNavPath('/admin/users', adminNav, ['/admin'])).toEqual(['/admin/users'])
    expect(findActiveNavPath('/admin', adminNav, ['/admin'])).toEqual(['/admin'])
    expect(findActiveNavPath('/admin/unknown-page', adminNav, ['/admin'])).toEqual([])
  })

  it('lights /admin for admin sub pages with an app-sidebar style navPaths', () => {
    expect(findActiveNavPath('/admin/users/u1', ['/', '/admin', '/requests'], ['/'])).toEqual(['/admin'])
  })

  it('takes the longest match within the same level', () => {
    expect(findActiveNavPath('/admin/access/roles', ['/admin/access', '/admin/access/roles'])).toEqual(['/admin/access/roles'])
  })
})
