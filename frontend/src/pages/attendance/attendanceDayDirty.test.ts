import { describe, expect, it } from 'vitest'
import { isCalculationAdjustDirty, isDayFormDirty, type DayFormValues } from './attendanceDayDirty'

const base: DayFormValues = {
  actualStartAt: '2026-07-06T09:00',
  actualEndAt: '2026-07-06T18:00',
  offset: '+09:00',
  workType: '',
  workLocationType: '',
  note: '',
  reason: '',
  breaks: [{ start: '2026-07-06T12:00', end: '2026-07-06T12:45' }],
  leaveSegments: [],
}

const copy = (): DayFormValues => ({
  ...base,
  breaks: base.breaks.map((row) => ({ ...row })),
  leaveSegments: [],
})

describe('isDayFormDirty', () => {
  it('is false when nothing changed', () => {
    expect(isDayFormDirty(base, copy())).toBe(false)
  })

  it('is true when one field changes and false again when restored', () => {
    const changed = { ...copy(), note: 'メモ' }
    expect(isDayFormDirty(base, changed)).toBe(true)
    expect(isDayFormDirty(base, { ...changed, note: '' })).toBe(false)
  })

  it('detects a changed break row, and an added leave segment', () => {
    const rows = copy()
    rows.breaks[0].end = '2026-07-06T13:00'
    expect(isDayFormDirty(base, rows)).toBe(true)
    expect(isDayFormDirty(base, { ...copy(), leaveSegments: [{ start: '', end: '', note: '' }] })).toBe(true)
  })
})

describe('isCalculationAdjustDirty', () => {
  const adjustBase = { fields: { prescribed_work_minutes: '480', payroll_work_minutes: '480' }, reason: '' }

  it('is false when nothing changed', () => {
    expect(isCalculationAdjustDirty(adjustBase, { fields: { ...adjustBase.fields }, reason: '' })).toBe(false)
  })

  it('is true when a field or the reason changes and false again when restored', () => {
    const changed = { fields: { ...adjustBase.fields, prescribed_work_minutes: '420' }, reason: '' }
    expect(isCalculationAdjustDirty(adjustBase, changed)).toBe(true)
    expect(isCalculationAdjustDirty(adjustBase, { ...changed, fields: { ...adjustBase.fields } })).toBe(false)
    expect(isCalculationAdjustDirty(adjustBase, { fields: { ...adjustBase.fields }, reason: '理由' })).toBe(true)
  })
})
