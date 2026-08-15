import { describe, expect, it } from 'vitest'
import type { StoredEvent } from '../api/types'
import {
  attendanceDayDisplayLabel,
  attendanceDayStatusLabel,
  attendanceMonthStatusLabel,
  attendanceRowDisplayLabel,
  paidLeaveEventDetail,
  paidLeaveEventTypeLabel,
  workflowRequestStatusLabel,
  workLocationTypeLabel,
  WORK_LOCATION_TYPE_OPTIONS,
} from './statusLabels'

function buildEvent(eventType: string, payload: Record<string, unknown>): StoredEvent {
  return {
    id: '1',
    event_id: 'evt-1',
    aggregate_type: 'paid_leave_grant',
    aggregate_id: '1',
    version: 1,
    event_type: eventType,
    payload,
    occurred_at: '2026-08-10T09:00:00+09:00',
  }
}

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

  it('falls back to the status label when work_type is not a leave day (attendanceDayDisplayLabel)', () => {
    expect(attendanceDayDisplayLabel({ status: 'clocked_out', work_type: null })).toEqual({
      label: '退勤済み',
      tone: 'success',
    })
    expect(attendanceDayDisplayLabel({ status: 'working', work_type: undefined })).toEqual({
      label: '勤務中',
      tone: 'info',
    })
  })

  it('shows the schedule holiday badge when there is no actual attendance record yet (attendanceRowDisplayLabel)', () => {
    expect(
      attendanceRowDisplayLabel(undefined, { is_legal_holiday: true, is_company_holiday: false, is_working_day: false }),
    ).toEqual({ label: '法定休日', tone: 'danger' })
    expect(
      attendanceRowDisplayLabel(
        { status: 'not_started', work_type: null, actual_start_at: null, actual_end_at: null },
        { is_legal_holiday: false, is_company_holiday: true, is_working_day: false },
      ),
    ).toEqual({ label: '所定休日', tone: 'warning' })
  })

  it('prefers the actual attendance record over a scheduled holiday once the employee has worked (attendanceRowDisplayLabel)', () => {
    // 休日出勤で実績があるのに、休日バッジに隠れて「退勤済み」等が見えなくなっていた不具合の回帰確認。
    expect(
      attendanceRowDisplayLabel(
        { status: 'clocked_out', work_type: null, actual_start_at: '2026-08-15T09:00:00+09:00', actual_end_at: '2026-08-15T18:00:00+09:00' },
        { is_legal_holiday: true, is_company_holiday: false, is_working_day: false },
      ),
    ).toEqual({ label: '退勤済み', tone: 'success' })
    expect(
      attendanceRowDisplayLabel(
        { status: 'working', work_type: null, actual_start_at: '2026-08-15T09:00:00+09:00', actual_end_at: null },
        { is_legal_holiday: false, is_company_holiday: true, is_working_day: false },
      ),
    ).toEqual({ label: '勤務中', tone: 'info' })
  })

  it('falls back to 未入力 when there is neither a schedule holiday nor an attendance record (attendanceRowDisplayLabel)', () => {
    expect(attendanceRowDisplayLabel(undefined, undefined)).toEqual({ label: '未入力', tone: 'neutral' })
  })

  it('shows a leave-specific label instead of the clocked_out override (attendanceDayDisplayLabel)', () => {
    expect(attendanceDayDisplayLabel({ status: 'clocked_out', work_type: 'paid_leave_full' })).toEqual({
      label: '有給休暇(全休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'clocked_out', work_type: 'paid_leave_am_half' })).toEqual({
      label: '有給休暇(午前半休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'clocked_out', work_type: 'paid_leave_pm_half' })).toEqual({
      label: '有給休暇(午後半休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'clocked_out', work_type: 'paid_leave_hourly' })).toEqual({
      label: '有給休暇(時間休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'clocked_out', work_type: 'special_leave_full' })).toEqual({
      label: '特別休暇(全休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'clocked_out', work_type: 'special_leave_am_half' })).toEqual({
      label: '特別休暇(午前半休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'clocked_out', work_type: 'special_leave_pm_half' })).toEqual({
      label: '特別休暇(午後半休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'clocked_out', work_type: 'special_leave_hourly' })).toEqual({
      label: '特別休暇(時間休)',
      tone: 'info',
    })
  })

  it('maps work location types to a Japanese label and lists them as select options', () => {
    expect(workLocationTypeLabel('remote')).toBe('在宅')
    expect(workLocationTypeLabel('client_site')).toBe('客先')
    expect(WORK_LOCATION_TYPE_OPTIONS).toContainEqual({ value: 'office', label: '出社' })
    expect(WORK_LOCATION_TYPE_OPTIONS).toHaveLength(7)
  })

  it('maps paid leave history event types to a Japanese label and tone, falling back to the raw type', () => {
    expect(paidLeaveEventTypeLabel('paid_leave.granted')).toEqual({ label: '付与', tone: 'success' })
    expect(paidLeaveEventTypeLabel('paid_leave.request_returned')).toEqual({ label: '差戻し', tone: 'warning' })
    expect(paidLeaveEventTypeLabel('paid_leave.unknown_event')).toEqual({ label: 'paid_leave.unknown_event', tone: 'neutral' })
  })

  it('formats each paid leave history event type using its own payload shape', () => {
    expect(paidLeaveEventDetail(buildEvent('paid_leave.granted', { granted_days: 10, expires_on: '2027-06-30' }))).toBe(
      '10日を付与(有効期限 2027-06-30)',
    )
    expect(
      paidLeaveEventDetail(
        buildEvent('paid_leave.requested', { target_date: '2026-08-10', leave_type: 'full', requested_days: 1 }),
      ),
    ).toBe('対象日 2026-08-10 の全休を申請(1日)')
    expect(paidLeaveEventDetail(buildEvent('paid_leave.request_approved', {}))).toBe('有給申請が承認されました')
    expect(paidLeaveEventDetail(buildEvent('paid_leave.request_returned', { comment: '確認してください' }))).toBe(
      '有給申請が差し戻されました: 確認してください',
    )
    expect(paidLeaveEventDetail(buildEvent('paid_leave.request_cancelled', {}))).toBe('有給申請を取り消しました')
    expect(paidLeaveEventDetail(buildEvent('paid_leave.used', { used_on: '2026-08-10', used_days: 0.5 }))).toBe(
      '対象日 2026-08-10 に0.5日を消化',
    )
    expect(paidLeaveEventDetail(buildEvent('paid_leave.warning_raised', { message: '有給が失効間近です' }))).toBe(
      '有給が失効間近です',
    )
  })
})
