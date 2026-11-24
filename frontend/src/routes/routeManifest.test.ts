import { describe, expect, it } from 'vitest'
import { selectActiveNavItems } from './routeManifest'

const lending = { id: 'lending', matchPath: '/assets', matchQuery: { management_type: 'lending', lending_status: 'available' } }
const manage = { id: 'manage', matchPath: '/assets' }
const home = { id: 'home', matchPath: '/' }
const days = { id: 'days', matchPath: '/attendance/days' }
const ids = (items: { id: string }[]) => items.map((i) => i.id)

describe('selectActiveNavItems', () => {
  it('selects a path-only item by path (including sub paths)', () => {
    expect(ids(selectActiveNavItems('/attendance/days/2025-03-05', '', [home, days], ['/']))).toEqual(['days'])
  })

  it('ignores the query for path-only items', () => {
    expect(ids(selectActiveNavItems('/attendance/days', '?x=1', [days]))).toEqual(['days'])
  })

  it('does not select an item whose matchQuery does not match', () => {
    expect(ids(selectActiveNavItems('/assets', '', [lending]))).toEqual([])
    expect(ids(selectActiveNavItems('/assets', '?management_type=lending', [lending]))).toEqual([])
  })

  it('selects an item when every matchQuery key matches (extra params allowed)', () => {
    const search = '?management_type=lending&lending_status=available&q=abc'
    expect(ids(selectActiveNavItems('/assets', search, [lending]))).toEqual(['lending'])
  })

  it('prefers the item with the most matching keys among the same path', () => {
    const search = '?management_type=lending&lending_status=available'
    expect(ids(selectActiveNavItems('/assets', search, [manage, lending]))).toEqual(['lending'])
    expect(ids(selectActiveNavItems('/assets', search, [lending, manage]))).toEqual(['lending'])
  })

  it('falls back to the path-only item when the query does not match', () => {
    expect(ids(selectActiveNavItems('/assets', '', [manage, lending]))).toEqual(['manage'])
    expect(ids(selectActiveNavItems('/assets', '?management_type=lending&lending_status=lent', [manage, lending]))).toEqual(['manage'])
  })

  it('selects only the exact-only home on "/" and nothing on unknown paths', () => {
    expect(ids(selectActiveNavItems('/', '', [home, days], ['/']))).toEqual(['home'])
    expect(ids(selectActiveNavItems('/unknown', '', [home, days], ['/']))).toEqual([])
  })
})
