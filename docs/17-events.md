# 17. 主要イベント

`stored_events.event_type` に記録するイベント種別の一覧。新しいイベントを追加する際は
[add-domain-event スキル](../.claude/skills/add-domain-event/SKILL.md) を参照する。

## User

- `user.logged_in`
- `user.synced_from_ms365`
- `user.roles_changed` (UC-M001 権限を設定する)
- `user.hire_date_set` (UC-P002 有給を自動付与する: 継続勤務期間の基準日を設定する)

## Workflow (汎用申請)

- `workflow_request.drafted`
- `workflow_request.submitted`
- `workflow_request.approved`
- `workflow_request.returned`
- `workflow_request.cancelled`

## BackOffice

- `backoffice_task.created`
- `backoffice_task.assigned`
- `backoffice_task.status_changed`
- `backoffice_task.completed`

## WorkCalendar / Shift

- `work_calendar.created`
- `work_calendar.published`
- `work_calendar_day.updated`
- `work_style.created`
- `work_style.default_changed` (会社のデフォルト働き方の切り替え。既存デフォルトの解除も
  同一イベントの`previous_default_work_style_id`に記録する。初回オンボーディングで
  「通常勤務」を作成した際にも`previous_default_work_style_id=null`で発生する)
- `employee_shift.assigned` (UC-C003のカレンダー基準一括生成、UC-C004のシフトパターン
  日別割当、UC-C008のローテーションからの一括生成のいずれからも発生する)
- `employee_shift.plan_changed` (1か月単位変形労働時間制の所定労働時間の事後編集)
- `employee_shift.published` (UC-C004 手順6: 3交代制シフト表を公開する)
- `shift_pattern.created`
- `shift_pattern.updated`
- `rotation_pattern.created` (UC-C008: 交代制勤務のローテーションパターンを登録する)
- `employee_rotation.assigned` (UC-C008: 社員のローテーション開始基準(パターン・開始日・
  開始位置)を設定する。既存の基準を上書きした場合も同じイベントで発生する)
- `user_work_style_monthly_assignment.assigned` (ユーザーの月次働き方割当。過去月を壊さず
  対象の年月だけを追加・更新する)
- `user_work_style_monthly_assignment.removed` (指示書13章: 個別指定を取り消し「会社の
  デフォルトを使用」に戻す。対象年月が今月より前の場合は取り消せない)

## Attendance

- `attendance.clocked_in`
- `attendance.break_started`
- `attendance.break_ended`
- `attendance.clocked_out`
- `attendance.day_created` (UC-A016 出勤日を新規作成する)
- `attendance.day_edited`
- `attendance.day_deleted` (UC-A015 日次勤怠を削除する)
- `attendance.day_calculated`
- `attendance.daily_calculation_adjusted` (日次登録後、区分ごとの時間を手動で補正する。
  実績が再編集され`attendance.day_calculated`が再発生すると解除される)
- `attendance.legal_holiday_designated` (UC-C007 法定休日「決めない方式」の週の法定休日を指定する)
- `attendance_punch.recorded`
- `attendance_punch.corrected` (UC-A013 打刻ログを訂正する)
- `attendance_punch.deleted` (UC-A014 打刻ログを削除する)
- `attendance_day.synced_from_punches`
- `attendance.month_submitted`
- `attendance.month_approved`
- `attendance.month_returned`
- `attendance.month_closed`

## PaidLeave

- `paid_leave.rule_created`
- `paid_leave.granted`
- `paid_leave.requested`
- `paid_leave.request_approved`
- `paid_leave.request_returned`
- `paid_leave.request_cancelled`
- `paid_leave.used`
- `paid_leave.expired`
- `paid_leave.warning_raised`

## Attachment / Notification / Export (横断)

- `attachment.uploaded`
- `attachment.downloaded` (UC-F002: 閲覧ログを監査ログに残す)
- `notification.queued`
- `notification.sent`
- `export.created`

## 命名規則

- `<aggregate>.<past_tense_verb>` 形式 (例: `attendance.clocked_in`)。
- 集約(aggregate)は `aggregate_type` + `aggregate_id` で一意に識別する
  (例: `attendance_day` + `attendance_days.id`)。
- イベントは追記のみ。既存イベントの意味を変える場合は新しいイベント種別を追加し、
  旧イベントは残す(イミュータブル)。
