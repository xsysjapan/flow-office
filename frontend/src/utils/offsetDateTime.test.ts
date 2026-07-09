import { describe, expect, it } from 'vitest'
import { datetimeLocalToIso8601 } from './offsetDateTime'

describe('datetimeLocalToIso8601', () => {
  it('returns null for an empty value', () => {
    expect(datetimeLocalToIso8601('')).toBeNull()
  })

  it('appends the browser timezone offset to a datetime-local value', () => {
    const result = datetimeLocalToIso8601('2026-07-09T21:00')

    expect(result).toMatch(/^2026-07-09T21:00:00[+-]\d{2}:\d{2}$/)
  })

  it('round-trips to the same wall-clock time when parsed back', () => {
    const result = datetimeLocalToIso8601('2026-07-09T21:00')!
    const parsed = new Date(result)

    expect(parsed.getHours()).toBe(21)
    expect(parsed.getMinutes()).toBe(0)
  })
})
