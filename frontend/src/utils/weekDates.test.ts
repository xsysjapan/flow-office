import { describe, expect, it } from 'vitest'
import { addDays, formatDate, mondayOf, weekDates } from './weekDates'

describe('weekDates utilities', () => {
  it('formats a Date as YYYY-MM-DD', () => {
    expect(formatDate(new Date(2026, 6, 9))).toBe('2026-07-09')
  })

  it('finds the Monday of the week for a mid-week date', () => {
    // 2026-07-09 is a Thursday
    expect(formatDate(mondayOf(new Date(2026, 6, 9)))).toBe('2026-07-06')
  })

  it('finds the Monday of the week when the date is already a Monday', () => {
    expect(formatDate(mondayOf(new Date(2026, 6, 6)))).toBe('2026-07-06')
  })

  it('rolls a Sunday back to the preceding Monday', () => {
    expect(formatDate(mondayOf(new Date(2026, 6, 12)))).toBe('2026-07-06')
  })

  it('adds and subtracts days across month boundaries', () => {
    expect(addDays('2026-07-30', 3)).toBe('2026-08-02')
    expect(addDays('2026-08-02', -3)).toBe('2026-07-30')
  })

  it('lists all 7 dates of a week starting from the given Monday', () => {
    expect(weekDates('2026-07-06')).toEqual([
      '2026-07-06',
      '2026-07-07',
      '2026-07-08',
      '2026-07-09',
      '2026-07-10',
      '2026-07-11',
      '2026-07-12',
    ])
  })
})
