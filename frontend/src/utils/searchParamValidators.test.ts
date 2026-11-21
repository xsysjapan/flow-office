import { describe, expect, it } from 'vitest'
import {
  isOneOf,
  isValidDateParam,
  isValidPageParam,
  isValidPositiveIntIdParam,
  isValidUuidParam,
  isValidYearMonthParam,
  isValidYearParam,
} from './searchParamValidators'

describe('searchParamValidators', () => {
  describe('isValidDateParam', () => {
    it('accepts existing calendar dates', () => {
      expect(isValidDateParam('2026-07-09')).toBe(true)
      expect(isValidDateParam('2026-01-01')).toBe(true)
      expect(isValidDateParam('2026-12-31')).toBe(true)
      expect(isValidDateParam('2028-02-29')).toBe(true)
    })

    it('rejects dates that do not exist in the calendar', () => {
      expect(isValidDateParam('2026-02-30')).toBe(false)
      expect(isValidDateParam('2026-02-29')).toBe(false)
      expect(isValidDateParam('2026-04-31')).toBe(false)
      expect(isValidDateParam('2026-13-01')).toBe(false)
      expect(isValidDateParam('2026-00-10')).toBe(false)
      expect(isValidDateParam('2026-07-00')).toBe(false)
    })

    it('rejects values in other formats', () => {
      expect(isValidDateParam('2026-7-9')).toBe(false)
      expect(isValidDateParam('2026/07/09')).toBe(false)
      expect(isValidDateParam('20260709')).toBe(false)
      expect(isValidDateParam('2026-07-09T00:00:00')).toBe(false)
      expect(isValidDateParam(' 2026-07-09')).toBe(false)
      expect(isValidDateParam('')).toBe(false)
    })

    it('rejects null', () => {
      expect(isValidDateParam(null)).toBe(false)
    })
  })

  describe('isValidYearMonthParam', () => {
    it('accepts YYYY-MM with month 01 to 12', () => {
      expect(isValidYearMonthParam('2026-01')).toBe(true)
      expect(isValidYearMonthParam('2026-12')).toBe(true)
    })

    it('rejects months outside 01 to 12 and other formats', () => {
      expect(isValidYearMonthParam('2026-00')).toBe(false)
      expect(isValidYearMonthParam('2026-13')).toBe(false)
      expect(isValidYearMonthParam('2026-1')).toBe(false)
      expect(isValidYearMonthParam('2026')).toBe(false)
      expect(isValidYearMonthParam('2026-07-01')).toBe(false)
      expect(isValidYearMonthParam('')).toBe(false)
    })

    it('rejects null', () => {
      expect(isValidYearMonthParam(null)).toBe(false)
    })
  })

  describe('isValidYearParam', () => {
    it('accepts a 4-digit year', () => {
      expect(isValidYearParam('2026')).toBe(true)
      expect(isValidYearParam('1999')).toBe(true)
    })

    it('rejects other lengths and non-digits', () => {
      expect(isValidYearParam('26')).toBe(false)
      expect(isValidYearParam('20260')).toBe(false)
      expect(isValidYearParam('abcd')).toBe(false)
      expect(isValidYearParam('2026-07')).toBe(false)
      expect(isValidYearParam('')).toBe(false)
    })

    it('rejects null', () => {
      expect(isValidYearParam(null)).toBe(false)
    })
  })

  describe('isValidPageParam', () => {
    it('accepts integers of 1 or more in decimal', () => {
      expect(isValidPageParam('1')).toBe(true)
      expect(isValidPageParam('2')).toBe(true)
      expect(isValidPageParam('10')).toBe(true)
      expect(isValidPageParam('123456')).toBe(true)
    })

    it('rejects 0, negative numbers, decimals, non-numbers and leading zeros', () => {
      expect(isValidPageParam('0')).toBe(false)
      expect(isValidPageParam('-1')).toBe(false)
      expect(isValidPageParam('2.5')).toBe(false)
      expect(isValidPageParam('abc')).toBe(false)
      expect(isValidPageParam('01')).toBe(false)
      expect(isValidPageParam('1e3')).toBe(false)
      expect(isValidPageParam(' 1')).toBe(false)
      expect(isValidPageParam('')).toBe(false)
    })

    it('rejects null', () => {
      expect(isValidPageParam(null)).toBe(false)
    })
  })

  describe('isValidPositiveIntIdParam', () => {
    it('behaves the same as isValidPageParam', () => {
      expect(isValidPositiveIntIdParam('1')).toBe(true)
      expect(isValidPositiveIntIdParam('42')).toBe(true)
      expect(isValidPositiveIntIdParam('0')).toBe(false)
      expect(isValidPositiveIntIdParam('-1')).toBe(false)
      expect(isValidPositiveIntIdParam('2.5')).toBe(false)
      expect(isValidPositiveIntIdParam('abc')).toBe(false)
      expect(isValidPositiveIntIdParam('01')).toBe(false)
      expect(isValidPositiveIntIdParam(null)).toBe(false)
    })
  })

  describe('isValidUuidParam', () => {
    it('accepts lowercase and uppercase UUIDs regardless of version', () => {
      expect(isValidUuidParam('123e4567-e89b-12d3-a456-426614174000')).toBe(true)
      expect(isValidUuidParam('123E4567-E89B-12D3-A456-426614174000')).toBe(true)
      expect(isValidUuidParam('00000000-0000-0000-0000-000000000000')).toBe(true)
      expect(isValidUuidParam('f47ac10b-58cc-4372-a567-0e02b2c3d479')).toBe(true)
    })

    it('rejects wrong lengths, misplaced hyphens and non-hex characters', () => {
      expect(isValidUuidParam('123e4567-e89b-12d3-a456-42661417400')).toBe(false)
      expect(isValidUuidParam('123e4567e89b-12d3-a456-426614174000')).toBe(false)
      expect(isValidUuidParam('123e4567-e89b-12d3-a456-4266141740000')).toBe(false)
      expect(isValidUuidParam('123e4567-e89b-12d3-a456-42661417400g')).toBe(false)
      expect(isValidUuidParam('not-a-uuid')).toBe(false)
      expect(isValidUuidParam('')).toBe(false)
    })

    it('rejects null', () => {
      expect(isValidUuidParam(null)).toBe(false)
    })
  })

  describe('isOneOf', () => {
    const statuses = ['active', 'inactive'] as const

    it('returns true only for an exact match with one of the values', () => {
      expect(isOneOf('active', statuses)).toBe(true)
      expect(isOneOf('inactive', statuses)).toBe(true)
    })

    it('returns false for values not in the list, case differences and empty strings', () => {
      expect(isOneOf('deleted', statuses)).toBe(false)
      expect(isOneOf('Active', statuses)).toBe(false)
      expect(isOneOf(' active', statuses)).toBe(false)
      expect(isOneOf('', statuses)).toBe(false)
    })

    it('returns false for null', () => {
      expect(isOneOf(null, statuses)).toBe(false)
    })

    it('returns false for any value when the list is empty', () => {
      expect(isOneOf('active', [])).toBe(false)
    })
  })
})
