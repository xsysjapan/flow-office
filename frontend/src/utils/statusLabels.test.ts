import { describe, expect, it } from 'vitest'
import type { StoredEvent } from '../api/types'
import {
  assetHistoryEventTypes,
  compensatoryLeaveHistoryEventTypes,
  paidLeaveHistoryEventTypes,
  specialLeaveHistoryEventTypes,
} from '../generated/historyEventTypes'
import {
  assetHistoryEventTypeLabel,
  attendanceDayDisplayLabel,
  attendanceDayOffBadges,
  attendanceDayStatusLabel,
  attendanceMonthStatusLabel,
  attendanceRowDisplayLabel,
  compensatoryLeaveEventDetail,
  compensatoryLeaveEventTypeLabel,
  paidLeaveEventDetail,
  paidLeaveEventTypeLabel,
  specialLeaveEventDetail,
  specialLeaveEventTypeLabel,
  workflowRequestStatusLabel,
  workLocationTypeLabel,
  WORK_LOCATION_TYPE_OPTIONS,
  adminCommandRunStatusLabel,
  backOfficeTaskSourceTypeLabel,
  backOfficeTaskTypeLabel,
  groupStatusLabel,
  membershipKindLabel,
  shiftDayTypeLabel,
  userFieldKeyLabel,
  userSourceTypeLabel,
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

  it('falls back to the status label when the day has no leave (attendanceDayDisplayLabel)', () => {
    expect(attendanceDayDisplayLabel({ status: 'clocked_out' })).toEqual({
      label: '退勤済み',
      tone: 'success',
    })
    expect(attendanceDayDisplayLabel({ status: 'working', leaves: [] })).toEqual({
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
        { status: 'not_started', actual_start_at: null, actual_end_at: null },
        { is_legal_holiday: false, is_company_holiday: true, is_working_day: false },
      ),
    ).toEqual({ label: '所定休日', tone: 'warning' })
  })

  it('prefers the actual attendance record over a scheduled holiday once the employee has worked (attendanceRowDisplayLabel)', () => {
    // 休日出勤で実績があるのに、休日バッジに隠れて「退勤済み」等が見えなくなっていた不具合の回帰確認。
    expect(
      attendanceRowDisplayLabel(
        { status: 'clocked_out', actual_start_at: '2026-08-15T09:00:00+09:00', actual_end_at: '2026-08-15T18:00:00+09:00' },
        { is_legal_holiday: true, is_company_holiday: false, is_working_day: false },
      ),
    ).toEqual({ label: '退勤済み', tone: 'success' })
    expect(
      attendanceRowDisplayLabel(
        { status: 'working', actual_start_at: '2026-08-15T09:00:00+09:00', actual_end_at: null },
        { is_legal_holiday: false, is_company_holiday: true, is_working_day: false },
      ),
    ).toEqual({ label: '勤務中', tone: 'info' })
  })

  it('shows the leave label over the schedule holiday for a leave-only day (attendanceRowDisplayLabel)', () => {
    expect(
      attendanceRowDisplayLabel(
        {
          status: 'not_started',
          actual_start_at: null,
          actual_end_at: null,
          leaves: [
            {
              leave_kind: 'compensatory',
              unit: 'full',
              hours: null,
              minutes: null,
              special_leave_type_id: null,
              request_id: 'request-1',
              workflow_request_id: null,
              request_status: 'submitted',
            },
          ],
        },
        { is_legal_holiday: false, is_company_holiday: true, is_working_day: false },
      ),
    ).toEqual({ label: '代休(全休)', tone: 'info' })
  })

  it('falls back to 未入力 when there is neither a schedule holiday nor an attendance record (attendanceRowDisplayLabel)', () => {
    expect(attendanceRowDisplayLabel(undefined, undefined)).toEqual({ label: '未入力', tone: 'neutral' })
  })

  it('shows the leave labels from leaves instead of the status (attendanceDayDisplayLabel)', () => {
    const leave = (
      leave_kind: 'paid' | 'special' | 'compensatory',
      unit: 'full' | 'am_half' | 'pm_half' | 'hourly',
    ) => ({
      leave_kind,
      unit,
      hours: null,
      minutes: null,
      special_leave_type_id: null,
      request_id: 'request-1',
      workflow_request_id: null,
      request_status: 'approved' as const,
    })
    expect(attendanceDayDisplayLabel({ status: 'not_started', leaves: [leave('paid', 'full')] })).toEqual({
      label: '有給休暇(全休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'not_started', leaves: [leave('paid', 'am_half')] })).toEqual({
      label: '有給休暇(午前半休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'not_started', leaves: [leave('paid', 'pm_half')] })).toEqual({
      label: '有給休暇(午後半休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'not_started', leaves: [leave('paid', 'hourly')] })).toEqual({
      label: '有給休暇(時間休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'not_started', leaves: [leave('special', 'full')] })).toEqual({
      label: '特別休暇(全休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'not_started', leaves: [leave('special', 'hourly')] })).toEqual({
      label: '特別休暇(時間休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'clocked_out', leaves: [leave('compensatory', 'full')] })).toEqual({
      label: '代休(全休)',
      tone: 'info',
    })
    expect(attendanceDayDisplayLabel({ status: 'clocked_out', leaves: [leave('compensatory', 'am_half')] })).toEqual({
      label: '代休(午前半休)',
      tone: 'info',
    })
  })

  it('joins multiple leaves of one day into one label (attendanceDayDisplayLabel)', () => {
    const leave = (
      leave_kind: 'paid' | 'special' | 'compensatory',
      unit: 'full' | 'am_half' | 'pm_half' | 'hourly',
    ) => ({
      leave_kind,
      unit,
      hours: null,
      minutes: null,
      special_leave_type_id: null,
      request_id: `request-${leave_kind}-${unit}`,
      workflow_request_id: null,
      request_status: 'submitted' as const,
    })
    expect(
      attendanceDayDisplayLabel({ status: 'not_started', leaves: [leave('paid', 'am_half'), leave('compensatory', 'pm_half')] }),
    ).toEqual({ label: '有給休暇(午前半休)・代休(午後半休)', tone: 'info' })
  })

  it('maps work location types to a Japanese label and lists them as select options', () => {
    expect(workLocationTypeLabel('remote')).toBe('在宅')
    expect(workLocationTypeLabel('client_site')).toBe('客先')
    expect(WORK_LOCATION_TYPE_OPTIONS).toContainEqual({ value: 'office', label: '出社' })
    expect(WORK_LOCATION_TYPE_OPTIONS).toHaveLength(7)
  })

  it('maps paid leave history event types to a Japanese label and tone, falling back to その他', () => {
    expect(paidLeaveEventTypeLabel('paid_leave_account.grant_created')).toEqual({ label: '付与', tone: 'success' })
    expect(paidLeaveEventTypeLabel('paid_leave_request.returned')).toEqual({ label: '差戻し', tone: 'warning' })
    expect(paidLeaveEventTypeLabel('paid_leave_account.usage_confirmed')).toEqual({ label: '消化', tone: 'info' })
    expect(paidLeaveEventTypeLabel('paid_leave.granted')).toEqual({ label: 'その他', tone: 'neutral' })
    expect(paidLeaveEventTypeLabel('paid_leave.unknown_event')).toEqual({ label: 'その他', tone: 'neutral' })
  })

  it('labels the grant_amount_changed event for paid and special leave and formats its detail', () => {
    expect(paidLeaveEventTypeLabel('paid_leave_account.grant_amount_changed')).toEqual({ label: '付与日数の変更', tone: 'info' })
    expect(specialLeaveEventTypeLabel('special_leave_account.grant_amount_changed')).toEqual({ label: '付与日数の変更', tone: 'info' })
    expect(
      paidLeaveEventDetail(buildEvent('paid_leave_account.grant_amount_changed', { new_granted_days: 12, reason: '訂正' })),
    ).toBe('付与日数を12日に変更(訂正)')
    expect(
      specialLeaveEventDetail(buildEvent('special_leave_account.grant_amount_changed', { new_granted_days: 3.5, reason: null })),
    ).toBe('付与日数を3.5日に変更')
  })

  it('formats grant revoke, date/expiry change, usage and workflow share events without event codes', () => {
    expect(paidLeaveEventDetail(buildEvent('paid_leave_account.grant_revoked', { reason: '誤登録' }))).toBe('付与を取り消しました(誤登録)')
    expect(paidLeaveEventDetail(buildEvent('paid_leave_account.grant_revoked', { reason: null }))).toBe('付与を取り消しました')
    expect(specialLeaveEventDetail(buildEvent('special_leave_account.grant_revoked', { reason: null }))).toBe('付与を取り消しました')
    expect(paidLeaveEventDetail(buildEvent('paid_leave_account.grant_date_changed', { new_granted_on: '2026-04-01', reason: null }))).toBe(
      '付与日を2026-04-01に変更',
    )
    expect(
      paidLeaveEventDetail(buildEvent('paid_leave_account.grant_expiry_changed', { new_expires_on: '2028-04-30', reason: '訂正' })),
    ).toBe('有効期限を2028-04-30に変更(訂正)')
    expect(paidLeaveEventDetail(buildEvent('paid_leave_account.usage_designated', { used_on: '2026-08-31', used_days: 1, hours: null }))).toBe(
      '対象日 2026-08-31 に1日の消化を指定',
    )
    expect(paidLeaveEventDetail(buildEvent('paid_leave_account.usage_designated', { used_on: '2026-08-31', used_days: 0.25, hours: 2 }))).toBe(
      '対象日 2026-08-31 に2時間の消化を指定',
    )
    expect(
      specialLeaveEventDetail(buildEvent('special_leave_account.usage_designated', { used_on: '2026-09-01', used_days: 0.1875, used_minutes: 90 })),
    ).toBe('対象日 2026-09-01 に1時間30分の消化を指定')
    expect(specialLeaveEventDetail(buildEvent('special_leave_account.usage_designated', { used_on: '2026-09-01', used_days: 0.5, used_minutes: 60 }))).toBe(
      '対象日 2026-09-01 に1時間の消化を指定',
    )
    expect(specialLeaveEventDetail(buildEvent('special_leave_account.usage_designated', { used_on: '2026-09-01', used_days: 0.0625, used_minutes: 30 }))).toBe(
      '対象日 2026-09-01 に30分の消化を指定',
    )
    expect(paidLeaveEventDetail(buildEvent('paid_leave_account.usage_allocated', { allocated_days: 1 }))).toBe('付与へ1日を充当')
    expect(paidLeaveEventDetail(buildEvent('paid_leave_account.usage_allocation_released', { released_days: 0.5 }))).toBe('付与への充当0.5日を解除')
    expect(paidLeaveEventDetail(buildEvent('paid_leave_request.shared', {}))).toBe('申請がワークフローに連携されました')
    expect(specialLeaveEventDetail(buildEvent('special_leave.request_shared', {}))).toBe('申請がワークフローに連携されました')
    expect(paidLeaveEventDetail(buildEvent('paid_leave_account.something_new', {}))).toBe('')
  })

  it('labels grant_date_changed and grant_expiry_changed events', () => {
    expect(paidLeaveEventTypeLabel('paid_leave_account.grant_date_changed')).toEqual({ label: '付与日の変更', tone: 'info' })
    expect(paidLeaveEventTypeLabel('paid_leave_account.grant_expiry_changed')).toEqual({ label: '有効期限の変更', tone: 'info' })
  })

  it('formats each paid leave history event type using its own payload shape', () => {
    expect(paidLeaveEventDetail(buildEvent('paid_leave_account.grant_created', { granted_days: 10, expires_on: '2027-06-30' }))).toBe(
      '10日を付与(有効期限 2027-06-30)',
    )
    expect(
      paidLeaveEventDetail(
        buildEvent('paid_leave_request.requested', { target_date: '2026-08-10', leave_type: 'full', requested_days: 1 }),
      ),
    ).toBe('対象日 2026-08-10 の全休を申請(1日)')
    expect(paidLeaveEventDetail(buildEvent('paid_leave_request.approved', {}))).toBe('有給申請が承認されました')
    expect(paidLeaveEventDetail(buildEvent('paid_leave_request.returned', { comment: '確認してください' }))).toBe(
      '有給申請が差し戻されました: 確認してください',
    )
    expect(paidLeaveEventDetail(buildEvent('paid_leave_request.cancelled', {}))).toBe('有給申請を取り消しました')
    expect(paidLeaveEventDetail(buildEvent('paid_leave_account.usage_confirmed', {}))).toBe('有給の消化が確定しました')
    expect(paidLeaveEventDetail(buildEvent('paid_leave_account.grant_warning_raised', { message: '有給が失効間近です' }))).toBe(
      '有給が失効間近です',
    )
  })

  describe('attendanceDayOffBadges', () => {
    const leave = (leave_kind: 'paid' | 'compensatory', unit: 'full' | 'am_half') => ({
      leave_kind,
      unit,
      hours: null,
      minutes: null,
      special_leave_type_id: null,
      request_id: 'req-1',
      workflow_request_id: null,
      request_status: 'approved' as const,
    })

    it('returns an empty array when there is nothing planned', () => {
      expect(attendanceDayOffBadges(undefined, undefined)).toEqual([])
      expect(attendanceDayOffBadges({ is_working_day: true }, [])).toEqual([])
    })

    it('returns the legal holiday badge only', () => {
      expect(attendanceDayOffBadges({ is_legal_holiday: true }, undefined)).toEqual([{ label: '法定休日', tone: 'danger' }])
    })

    it('returns the company holiday followed by the public holiday name', () => {
      expect(attendanceDayOffBadges({ is_company_holiday: true, public_holiday_name: '文化の日' }, undefined)).toEqual([
        { label: '所定休日', tone: 'warning' },
        { label: '文化の日', tone: 'neutral' },
      ])
    })

    it('returns every leave of the day as info badges', () => {
      expect(attendanceDayOffBadges(null, [leave('paid', 'full'), leave('compensatory', 'am_half')])).toEqual([
        { label: '有給休暇(全休)', tone: 'info' },
        { label: '代休(午前半休)', tone: 'info' },
      ])
    })
  })

  // 生成ファイル(backend の events:export-history-types が書き出す)の全種別について、表示名と詳細文の漏れを検出する。
  describe('履歴イベントの表示名・詳細文の網羅 (生成された種別一覧)', () => {
    const leaveTypeGroups = [
      { name: '有給', types: paidLeaveHistoryEventTypes, label: paidLeaveEventTypeLabel, detail: paidLeaveEventDetail },
      { name: '特別休暇', types: specialLeaveHistoryEventTypes, label: specialLeaveEventTypeLabel, detail: specialLeaveEventDetail },
      {
        name: '代休',
        types: compensatoryLeaveHistoryEventTypes,
        label: compensatoryLeaveEventTypeLabel,
        detail: compensatoryLeaveEventDetail,
      },
    ]

    for (const group of leaveTypeGroups) {
      it(`${group.name}: every event type has a display name and a detail without showing the event code`, () => {
        for (const type of group.types) {
          const meta = group.label(type)
          expect(meta.label, type).not.toBe(type)
          expect(meta.label, type).not.toBe('その他')
          // payloadが空でも詳細関数は例外を投げない(詳細文の有無は種別ごとに異なる)
          expect(() => group.detail(buildEvent(type, {})), type).not.toThrow()
        }
      })
    }

    it('asset: every event type has a display name without showing the event code', () => {
      for (const type of assetHistoryEventTypes) {
        expect(assetHistoryEventTypeLabel(type), type).not.toBe(type)
        expect(assetHistoryEventTypeLabel(type), type).not.toBe('その他')
      }
    })

    it('falls back to その他 and an empty detail for unknown leave event types', () => {
      expect(compensatoryLeaveEventTypeLabel('compensatory_leave.unknown_event')).toEqual({ label: 'その他', tone: 'neutral' })
      expect(paidLeaveEventDetail(buildEvent('paid_leave.granted', {}))).toBe('')
      expect(specialLeaveEventDetail(buildEvent('special_leave_account.unknown_event', {}))).toBe('')
      expect(compensatoryLeaveEventDetail(buildEvent('compensatory_leave_account.unknown_event', {}))).toBe('')
    })

    it('falls back to その他 for unknown asset event types, including Object prototype keys', () => {
      expect(assetHistoryEventTypeLabel('asset.unknown_event')).toBe('その他')
      expect(assetHistoryEventTypeLabel('constructor')).toBe('その他')
    })
  })
})

describe('enum value labels (内部コードを画面に出さない)', () => {
  it('maps known back office task types and shows unknown ones as-is', () => {
    expect(backOfficeTaskTypeLabel('attendance_month_confirmation')).toBe('月次勤怠の確定')
    expect(backOfficeTaskTypeLabel('expense_reimbursement')).toBe('経費の精算')
    expect(backOfficeTaskTypeLabel('general_affairs')).toBe('general_affairs')
    expect(backOfficeTaskTypeLabel('総務対応')).toBe('総務対応')
  })

  it('maps back office task source types and shows unknown ones as-is', () => {
    expect(backOfficeTaskSourceTypeLabel('attendance_month')).toBe('月次勤怠')
    expect(backOfficeTaskSourceTypeLabel('workflow_request')).toBe('申請')
    expect(backOfficeTaskSourceTypeLabel('expense_claim')).toBe('経費精算')
    expect(backOfficeTaskSourceTypeLabel('other')).toBe('other')
  })

  it('maps group statuses', () => {
    expect(groupStatusLabel('active')).toBe('有効')
    expect(groupStatusLabel('inactive')).toBe('無効')
    expect(groupStatusLabel('planned_inactive')).toBe('無効化予定')
    expect(groupStatusLabel('unknown_status')).toBe('unknown_status')
  })

  it('merges the membership kind with the primary marker into one label', () => {
    expect(membershipKindLabel('primary', true)).toBe('主所属')
    expect(membershipKindLabel('member', true)).toBe('主所属')
    expect(membershipKindLabel('member', false)).toBe('所属')
    expect(membershipKindLabel('primary', false)).toBe('主所属')
    expect(membershipKindLabel('guest', false)).toBe('guest')
  })

  it('maps admin command run statuses', () => {
    expect(adminCommandRunStatusLabel('queued')).toBe('待機中')
    expect(adminCommandRunStatusLabel('running')).toBe('実行中')
    expect(adminCommandRunStatusLabel('succeeded')).toBe('成功')
    expect(adminCommandRunStatusLabel('failed')).toBe('失敗')
    expect(adminCommandRunStatusLabel('unknown')).toBe('unknown')
  })

  it('maps shift day types', () => {
    expect(shiftDayTypeLabel('weekday')).toBe('平日')
    expect(shiftDayTypeLabel('legal_holiday')).toBe('法定休日')
    expect(shiftDayTypeLabel('company_holiday')).toBe('所定休日')
    expect(shiftDayTypeLabel('special_working_day')).toBe('特別稼働日')
    expect(shiftDayTypeLabel('x')).toBe('x')
  })

  it('maps user source types', () => {
    expect(userSourceTypeLabel('local')).toBe('ローカル')
    expect(userSourceTypeLabel('external_hr')).toBe('外部人事')
    expect(userSourceTypeLabel('microsoft_entra')).toBe('Microsoft 365')
  })

  it('maps user field keys to the labels used on the user list', () => {
    expect(userFieldKeyLabel('display_name')).toBe('氏名')
    expect(userFieldKeyLabel('employment_status')).toBe('在籍状況')
    expect(userFieldKeyLabel('account_status')).toBe('アカウント状態')
    expect(userFieldKeyLabel('hire_date')).toBe('入社日')
    expect(userFieldKeyLabel('custom_field')).toBe('custom_field')
  })

  it('does not treat Object prototype keys as known values', () => {
    expect(backOfficeTaskTypeLabel('constructor')).toBe('constructor')
    expect(shiftDayTypeLabel('toString')).toBe('toString')
  })
})
