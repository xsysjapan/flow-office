# 17. 主要イベント

`stored_events.event_type` に記録するイベント種別の一覧。新しいイベントを追加する際は
[add-domain-event スキル](../.claude/skills/add-domain-event/SKILL.md) を参照する。

## User

- `user.logged_in`
- `user.synced_from_ms365`
- `user.onboarded_as_admin` (初回オンボーディング(UC-000)での管理者作成。payloadの
  `auth_method`が`sso`(実際のEntra IDログイン結果で作成、entra_user_id設定済み)か
  `local`(ローカルパスワードで作成)かを区別する)
- `user.roles_changed` (UC-M001 権限を設定する)
- `user.hire_date_set` (UC-P002 有給を自動付与する: 継続勤務期間の基準日を設定する)
- `user.termination_date_set` (退社日を設定または解除する)
- `user.sso_account_linked` (UC-004 ローカルパスワードユーザーが任意のタイミングで
  Microsoft 365アカウントを紐づける)

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
- `attendance.break_auto_inserted` (退勤時、働き方のauto_break_enabledが有効かつその日に
  休憩が1件も記録されていない場合に、標準休憩(default_break_start_time〜
  default_break_end_time)を自動でattendance_breaksへ補完する。実際に打刻・編集された
  休憩を上書きすることはない)
- `attendance.clocked_out`
- `attendance.day_created` (UC-A016 出勤日を新規作成する)
- `attendance.day_edited`
- `attendance.day_deleted` (UC-A015 日次勤怠を削除する)
- `attendance.day_calculated`
- `attendance.daily_calculation_adjusted` (日次登録後、区分ごとの時間を手動で補正する。
  実績が再編集され`attendance.day_calculated`が再発生すると解除される)
- `attendance.legal_holiday_designated` (UC-C007 法定休日「決めない方式」の週の法定休日を指定する)
- `attendance_punch.recorded` (payloadに`deviceId`/`authenticationKeyId`/`actorUserId`/
  `integrationId`/`offline`/`idempotencyKey`/`requestId`を追加。docs/23〜docs/25の端末・
  認証キー・アプリ連携経由の打刻に対応するための追記であり、イベント種別自体は増やさない。
  これらのフィールドを持たない過去のイベントはnull相当として扱う)
- `attendance_punch.corrected` (UC-A013 打刻ログを訂正する)
- `attendance_punch.deleted` (UC-A014 打刻ログを削除する)
- `attendance_day.synced_from_punches`
- `attendance.month_submitted`
- `attendance.month_approved`
- `attendance.month_returned`
- `attendance.month_closed`

## Device (docs/23-usecases-devices.md)

- `device.registered` (共有端末の登録、または個人端末の本人登録)
- `device.paired` (ペアリングコード/QRコードによる端末鍵確立の完了)
- `device.pairing_reissued` (ペアリング済み(active)端末に対する再ペアリング用claim tokenの
  再発行。Androidアプリの削除等で端末が打刻できなくなった場合の復旧手段。端末は一旦
  `pending_pairing`に戻り、再ペアリング完了で`device.paired`が改めて記録される)
- `device.disabled` (管理者・本人による一時停止)
- `device.revoked` (紛失・盗難等による失効。再度使うには新規登録が必要)
- `device.deleted` (停止・失効済み端末の一覧からの論理削除。監査証跡は`stored_events`に残る)
- `device.role_assigned` (端末役割(`device_roles`)の追加・変更)
- `device.scope_granted` (外部端末へのAPIスコープ(`device_scopes`)付与)
- `device.settings_updated` (設置場所・自動反映する勤務形態区分など端末設定の変更)
- `device_admin_session.started` (UC-D006: 管理者ICカードをかざす、またはブートストラップ
  経路により端末が管理者モードになった)
- `device_admin_session.ended` (UC-D006: 管理者モードの明示的な終了、または新しいセッション
  による置き換え)

## AuthenticationKey (docs/24-usecases-authentication-keys.md)

- `authentication_key.issued` (本人または管理者代理による認証キー登録。`key_hash`の発行を含む)
- `authentication_key.disabled` (紛失・退職・交換時の無効化)

## Integration (docs/25-usecases-integrations-mcp.md)

- `application_integration.registered` (個人または組織のAPI/MCP連携登録。スコープは登録時に
  選択するため、`scope_granted`という別イベントは持たず`registered`のpayloadに含める)
- `application_integration.token_reissued` (アクセストークンの再発行)
- `application_integration.revoked`

## AttendanceImport / MonthlyAttendanceDraft (docs/26-usecases-monthly-import.md)

- `attendance_import_session.created`
- `attendance_import_session.data_uploaded` (Claudeが構造化した作業報告書データの受け入れ)
- `attendance_import_session.previewed` (差異検出・検証結果の生成)
- `attendance_import_session.applied` (下書きへの反映)
- `attendance_import_session.cancelled`
- `monthly_attendance_draft.created`
- `monthly_attendance_draft.updated` (`bulk_update_attendance_days`相当の一括更新を含む)
- `monthly_attendance_draft.validated`
- `monthly_attendance_draft.submitted` (ユーザーの明示的な指示による月次申請。UC-A008の
  月次提出フローへ引き渡す)
- `monthly_attendance_draft.submission_cancelled`
- `field_provenance.recorded` (AI推定値・ユーザー確認等、項目ごとの出所の記録)
- `field_provenance.confirmed` (ユーザーがAI推定値を確認したことの記録)

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
- `notification.queued` (payloadに`recipientUserId`/`notificationType`/`subjectType`/
  `subjectId`/`title`/`summary`/`detailUrl`を持つ。docs/13-usecases-notification.md)
- `notification.sent`
- `notification.confirmed` (本人が通知一覧またはメール内リンクから確認した)
- `export.created`

## 命名規則

- `<aggregate>.<past_tense_verb>` 形式 (例: `attendance.clocked_in`)。
- 集約(aggregate)は `aggregate_type` + `aggregate_id` で一意に識別する
  (例: `attendance_day` + `attendance_days.id`)。
- イベントは追記のみ。既存イベントの意味を変える場合は新しいイベント種別を追加し、
  旧イベントは残す(イミュータブル)。
