# 17. 主要イベント

`stored_events.event_type` に記録するイベント種別の一覧。新しいイベントを追加する際は
[add-domain-event スキル](../.claude/skills/add-domain-event/SKILL.md) を参照する。

## User

- `user.logged_in`
- `user.synced_from_ms365`
- `user.roles_changed` (UC-M001 権限を設定する)

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
- `employee_shift.assigned`
- `shift_pattern.created`

## Attendance

- `attendance.clocked_in`
- `attendance.break_started`
- `attendance.break_ended`
- `attendance.clocked_out`
- `attendance.day_edited`
- `attendance.day_calculated`
- `attendance.month_submitted`
- `attendance.month_approved`
- `attendance.month_returned`
- `attendance.month_closed`

## PaidLeave

- `paid_leave.rule_created`
- `paid_leave.granted`
- `paid_leave.requested`
- `paid_leave.used`
- `paid_leave.expired`
- `paid_leave.warning_raised`

## Attachment / Notification / Export (横断)

- `attachment.uploaded`
- `notification.queued`
- `notification.sent`
- `export.created`

## 命名規則

- `<aggregate>.<past_tense_verb>` 形式 (例: `attendance.clocked_in`)。
- 集約(aggregate)は `aggregate_type` + `aggregate_id` で一意に識別する
  (例: `attendance_day` + `attendance_days.id`)。
- イベントは追記のみ。既存イベントの意味を変える場合は新しいイベント種別を追加し、
  旧イベントは残す(イミュータブル)。
