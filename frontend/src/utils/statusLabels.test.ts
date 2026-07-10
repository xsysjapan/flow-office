import { describe, expect, it } from 'vitest'
import {
  attendanceDayStatusLabel,
  attendanceMonthStatusLabel,
  workflowRequestStatusLabel,
} from './statusLabels'

describe('statusLabels', () => {
  it('maps workflow request statuses to a Japanese label and tone', () => {
    expect(workflowRequestStatusLabel('draft')).toEqual({ label: '下書き', tone: 'neutral' })
    expect(workflowRequestStatusLabel('approved')).toEqual({ label: '承認済み', tone: 'success' })
    expect(workflowRequestStatusLabel('cancelled')).toEqual({ label: '取消', tone: 'danger' })
  })

  it('maps attendance month statuses to a Japanese label and tone', () => {
    expect(attendanceMonthStatusLabel('not_submitted')).toEqual({ label: '未提出', tone: 'neutral' })
    expect(attendanceMonthStatusLabel('closed')).toEqual({ label: '締め済み', tone: 'success' })
  })

  it('maps attendance day statuses to a Japanese label and tone', () => {
    expect(attendanceDayStatusLabel('on_break')).toEqual({ label: '休憩中', tone: 'warning' })
    expect(attendanceDayStatusLabel('clocked_out')).toEqual({ label: '退勤済み', tone: 'success' })
  })
})
