import { describe, expect, it } from 'vitest'
import { isWorkCalendarCreateDirty, type WorkCalendarCreateFormValues } from './workCalendarCreateDirty'
import { isWorkCalendarDaysDirty } from './workCalendarDaysDirty'
import { isWorkCalendarSettingsDirty, type WorkCalendarSettingsValues } from './workCalendarSettingsDirty'
import { isWorkStyleOnboardingDirty, type WorkStyleOnboardingValues } from './workStyleOnboardingDirty'

describe('isWorkCalendarCreateDirty', () => {
  const base: WorkCalendarCreateFormValues = {
    name: '',
    weekStartsOn: '',
    fiscalYearStartMonth: '',
    fiscalYearStartDay: '',
    weekdayPattern: { '1': 'working', '6': 'company_holiday' },
    allowDailyHolidayOverride: true,
    holidaySourceId: '',
  }

  it('is false when unchanged, true on one change, false when restored', () => {
    expect(isWorkCalendarCreateDirty(base, { ...base, weekdayPattern: { ...base.weekdayPattern } })).toBe(false)
    const changed = { ...base, name: '2027年度' }
    expect(isWorkCalendarCreateDirty(base, changed)).toBe(true)
    expect(isWorkCalendarCreateDirty(base, { ...changed, name: '' })).toBe(false)
    expect(isWorkCalendarCreateDirty(base, { ...base, weekdayPattern: { ...base.weekdayPattern, '1': 'company_holiday' } })).toBe(true)
    expect(isWorkCalendarCreateDirty(base, { ...base, allowDailyHolidayOverride: false })).toBe(true)
  })
})

describe('isWorkCalendarSettingsDirty', () => {
  const base: WorkCalendarSettingsValues = {
    name: '標準',
    weekStartsOn: '0',
    fiscalYearStartMonth: '4',
    fiscalYearStartDay: '1',
    allowDailyHolidayOverride: true,
    weekdayPattern: { '1': 'working' },
  }

  it('is false when unchanged, true on one change, false when restored', () => {
    expect(isWorkCalendarSettingsDirty(base, { ...base, weekdayPattern: { '1': 'working' } })).toBe(false)
    const changed = { ...base, fiscalYearStartMonth: '1' }
    expect(isWorkCalendarSettingsDirty(base, changed)).toBe(true)
    expect(isWorkCalendarSettingsDirty(base, { ...changed, fiscalYearStartMonth: '4' })).toBe(false)
    expect(isWorkCalendarSettingsDirty(base, { ...base, weekdayPattern: { '1': 'company_holiday' } })).toBe(true)
  })
})

describe('isWorkCalendarDaysDirty', () => {
  const base = new Map([
    ['2026-04-01', { classification: 'working', note: '' }],
    ['2026-04-02', { classification: 'working', note: '' }],
  ])

  it('is false when unchanged, true on one change, false when restored', () => {
    expect(isWorkCalendarDaysDirty(base, new Map(base))).toBe(false)
    const changed = new Map(base).set('2026-04-02', { classification: 'company_holiday', note: '' })
    expect(isWorkCalendarDaysDirty(base, changed)).toBe(true)
    expect(isWorkCalendarDaysDirty(base, changed.set('2026-04-02', { classification: 'working', note: '' }))).toBe(false)
  })
})

describe('isWorkStyleOnboardingDirty', () => {
  const base: WorkStyleOnboardingValues = { name: '通常勤務', startTime: '09:00', endTime: '18:00', breakMinutes: '60' }

  it('is false when unchanged, true on one change, false when restored', () => {
    expect(isWorkStyleOnboardingDirty(base, { ...base })).toBe(false)
    const changed = { ...base, breakMinutes: '45' }
    expect(isWorkStyleOnboardingDirty(base, changed)).toBe(true)
    expect(isWorkStyleOnboardingDirty(base, { ...changed, breakMinutes: '60' })).toBe(false)
  })
})
