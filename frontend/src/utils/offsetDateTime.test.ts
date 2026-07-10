import { describe, expect, it } from 'vitest'
import {
  combineDatetimeLocalWithOffset,
  isoToLocalDatetimeLiteral,
  isoToTimeLiteral,
  offsetMinutesToString,
  offsetStringToMinutes,
} from './offsetDateTime'

describe('isoToLocalDatetimeLiteral', () => {
  it('returns an empty string for a nullish value', () => {
    expect(isoToLocalDatetimeLiteral(null)).toBe('')
    expect(isoToLocalDatetimeLiteral(undefined)).toBe('')
    expect(isoToLocalDatetimeLiteral('')).toBe('')
  })

  it('extracts the literal wall-clock digits without converting to the browser timezone', () => {
    expect(isoToLocalDatetimeLiteral('2026-07-09T22:00:00-05:00')).toBe('2026-07-09T22:00')
    expect(isoToLocalDatetimeLiteral('2026-07-10T05:00:00+09:00')).toBe('2026-07-10T05:00')
  })
})

describe('isoToTimeLiteral', () => {
  it('extracts just the HH:mm portion', () => {
    expect(isoToTimeLiteral('2026-07-09T22:00:00-05:00')).toBe('22:00')
  })

  it('returns an empty string for a nullish value', () => {
    expect(isoToTimeLiteral(null)).toBe('')
  })
})

describe('offsetMinutesToString / offsetStringToMinutes', () => {
  it('round-trips a positive offset', () => {
    expect(offsetMinutesToString(540)).toBe('+09:00')
    expect(offsetStringToMinutes('+09:00')).toBe(540)
  })

  it('round-trips a negative offset', () => {
    expect(offsetMinutesToString(-300)).toBe('-05:00')
    expect(offsetStringToMinutes('-05:00')).toBe(-300)
  })

  it('handles a zero offset', () => {
    expect(offsetMinutesToString(0)).toBe('+00:00')
    expect(offsetStringToMinutes('+00:00')).toBe(0)
  })
})

describe('combineDatetimeLocalWithOffset', () => {
  it('returns null for an empty value', () => {
    expect(combineDatetimeLocalWithOffset('', '+09:00')).toBeNull()
  })

  it('appends the given offset to a datetime-local value', () => {
    expect(combineDatetimeLocalWithOffset('2026-07-09T22:00', '-05:00')).toBe('2026-07-09T22:00:00-05:00')
  })
})
