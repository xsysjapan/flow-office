import { describe, expect, it } from 'vitest'
import type { StoredEvent } from '../api/types'
import {
  attendanceDayStatusLabel,
  attendanceMonthStatusLabel,
  fieldSourceTypeLabel,
  monthlyDraftStatusLabel,
  paidLeaveEventDetail,
  paidLeaveEventTypeLabel,
  parseFieldProvenanceName,
  workflowRequestStatusLabel,
  workLocationTypeLabel,
  WORK_LOCATION_TYPE_OPTIONS,
} from './statusLabels'

function buildEvent(eventType: string, payload: Record<string, unknown>): StoredEvent {
  return {
    id: 1,
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

  it('maps monthly draft statuses to a Japanese label and tone', () => {
    expect(monthlyDraftStatusLabel('needs_review')).toEqual({ label: '要確認', tone: 'warning' })
    expect(monthlyDraftStatusLabel('ready_to_submit')).toEqual({ label: '申請可能', tone: 'success' })
  })

  it('maps field provenance source types to a Japanese label and tone', () => {
    expect(fieldSourceTypeLabel('ai_inferred')).toEqual({ label: 'AI推定(要確認)', tone: 'warning' })
    expect(fieldSourceTypeLabel('user_confirmed')).toEqual({ label: '本人確認済み', tone: 'success' })
  })

  it('maps work location types to a Japanese label and lists them as select options', () => {
    expect(workLocationTypeLabel('remote')).toBe('在宅')
    expect(workLocationTypeLabel('client_site')).toBe('客先')
    expect(WORK_LOCATION_TYPE_OPTIONS).toContainEqual({ value: 'office', label: '出社' })
    expect(WORK_LOCATION_TYPE_OPTIONS).toHaveLength(7)
  })

  it('parses a field provenance name into a date and a Japanese field label', () => {
    expect(parseFieldProvenanceName('2026-07-01:start_time')).toEqual({
      date: '2026-07-01',
      fieldLabel: '出勤時刻',
    })
    expect(parseFieldProvenanceName('2026-07-01:some_future_field')).toEqual({
      date: '2026-07-01',
      fieldLabel: 'some_future_field',
    })
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
