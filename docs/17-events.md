# 17. 主要イベント

`stored_events.event_type` に記録するイベント種別の一覧。新しいイベントを追加する際は
[add-domain-event スキル](../.claude/skills/add-domain-event/SKILL.md) を参照する。

## User

- `user.logged_in`
- `user.synced_from_ms365`
- `user.onboarded_as_admin` (初回オンボーディング(UC-000)での管理者作成。payloadの
  `auth_method`が`sso`(実際のEntra IDログイン結果で作成、ExternalIdentityリンク済み)か
  `local`(ローカルパスワードで作成)かを区別する)
- `user.hire_date_set` (UC-P002 有給を自動付与する: 継続勤務期間の基準日を設定する)
- `user.termination_date_set` (退社日を設定または解除する)
- `user.sso_account_linked` (UC-004 ローカルパスワードユーザーが任意のタイミングで
  Microsoft 365アカウントを紐づける)
- `external_identity.linked`
- `external_identity.unlinked`
- `user.field_authority_changed`
- `group.created` / `group.updated` / `group.inactivated`
- `group_type.created` / `group_type.updated` / `group_type.inactivated`
- `membership.added` / `membership.removed` / `membership.primary_changed`
- `membership_change_set.created` / `membership_change_set.scheduled` /
  `membership_change_set.applied` / `membership_change_set.failed` /
  `membership_change_set.cancelled`
- `feature.assigned_to_group` / `feature.removed_from_group`
- `user.feature_suspended` / `user.feature_suspension_removed`
- `role.created` / `role.updated` / `role.inactivated`
- `role.permissions_changed`
- `role_assignment.created` / `role_assignment.updated` / `role_assignment.removed`
- `system_settings.updated` (システム設定の直接更新と同一トランザクションで記録する監査イベント)

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

## CompanyCalendar / Shift

- `company_calendar.created` (会社カレンダー本体の作成)
- `company_calendar.default_changed` (デフォルトカレンダーの切り替え。既存デフォルトの自動解除を
  含む)
- `company_calendar.fiscal_year_settings_changed` (本体の`fiscal_year_start_month`/
  `fiscal_year_start_day`の変更。既に生成済みの`company_calendar_years`の`starts_on`/
  `ends_on`には影響せず、以後生成される年度にのみ反映される)
- `company_calendar_year.created` (カレンダー年度の作成。下書き)
- `company_calendar_year.batch_generated` (定期バッチ〔UC-C014〕によるカレンダー年度の生成。
  `company_calendar_year.created`と同時に発生し、バッチ起因であることをUC-C011の即時生成と
  区別するための印として記録する)
- `company_calendar_year.duplicated` (既存年度から翌年度への複製)
- `company_calendar.published` (カレンダー年度の公開。旧: 年度と本体が未分離だった頃の名残の
  イベント名だが互換のため維持する)
- `company_calendar_year.unpublished` (公開済み年度を下書きに戻す。締め済み月が無い場合のみ)
- `company_calendar_year.archived` (カレンダー年度の廃止)
- `company_calendar_day.updated` (会社カレンダー日の`schedule_state`個別編集)
- `company_calendar_day.reverted` (会社カレンダー日の個別編集の取消)
- `holiday_calendar_source.registered` (祝日iCalendarソースの登録)
- `holiday_calendar_source.synced` (祝日iCalendar同期の実行。追加/更新/削除件数を含む)
- `holiday_calendar_source.sync_reverted` (同期実行1回分の取消)
- `holiday_calendar_source.disabled` (祝日iCalendarソースの無効化)
- `calendar_bulk_operation.applied` (複数従業員予定の一括操作の確定適用。
  `operation_type`〔`calendar_apply`/`rotation_generate`/`bulk_edit`〕を含む)
- `calendar_bulk_operation.reverted` (一括操作の取消。除外件数を含む)
- `work_style.created`
- `work_style.default_changed` (会社のデフォルト働き方の切り替え。既存デフォルトの解除も
  同一イベントの`previous_default_work_style_id`に記録する。初回オンボーディングで
  「通常勤務」を作成した際にも`previous_default_work_style_id=null`で発生する)
- `work_style.updated` (勤務形態の設定内容の変更。初回オンボーディングで作成された
  標準の勤務形態(system_generated=true)も対象。code・is_default・system_generatedは
  このイベントでは変更しない)
- `employee_calendar_entry.assigned` (UC-C003のカレンダー基準一括生成、UC-C004のシフトパターン
  日別割当、UC-C008のローテーションからの一括生成のいずれからも発生する)
- `employee_calendar_entry.plan_changed` (1か月単位変形労働時間制の所定労働時間の事後編集)
- `employee_calendar_entry.published` (UC-C004 手順6: 3交代制シフト表を公開する)
- `employee_calendar_entry.unpublished` (UC-C013: 従業員予定の公開を取り消して下書きに戻す。対象日に
  勤務実績が無い場合のみ)
- `employee_calendar_entry.overridden` (UC-C013: 従業員予定を会社カレンダー・ローテーション等の自動
  生成結果から個別に上書きする。休日出勤〔`HOLIDAY_WORK`〕・振替休日〔`SUBSTITUTE_HOLIDAY`〕
  の登録も同イベントの`entry_type`で区別する)
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

- `attendance.break_auto_inserted` (1日分の勤務が矛盾なく組み立てられた際、働き方の
  auto_break_enabledが有効かつその日に休憩が1件も記録されていない場合に、標準休憩
  (default_break_start_time〜default_break_end_time)を自動でattendance_breaksへ
  補完する。実際に打刻・編集された休憩を上書きすることはない。WEB・端末のどちらの
  経路でも`AttendanceDayPunchSyncer`が同じ規則で発生させる)
- `attendance.day_created` (UC-A016 出勤日を新規作成する)
- `attendance.day_edited`
- `attendance.day_deleted` (UC-A015 日次勤怠を削除する)
- `attendance.day_calculated`
- `attendance.daily_calculation_adjusted` (日次登録後、区分ごとの時間を手動で補正する。
  実績が再編集され`attendance.day_calculated`が再発生すると解除される)
- `attendance.legal_holiday_designated` (UC-C007 法定休日「決めない方式」の週の法定休日を指定する)
- `attendance.submission_reminder_excluded` (管理者が特定の社員×年月を勤怠未提出督促
  (WarnUnsubmittedAttendanceHandler)の対象から個別に除外する。誤ってその月を提出対象に
  してしまった場合等の例外的対応で、usage_start_date/hire_dateによる除外条件とは別の
  汎用的な除外リストとして持つ)
- `attendance_punch.recorded` (payloadに`deviceId`/`authenticationKeyId`/`actorUserId`/
  `integrationId`/`offline`/`idempotencyKey`/`requestId`を追加。docs/23〜docs/25の端末・
  認証キー・アプリ連携経由の打刻に対応するための追記であり、イベント種別自体は増やさない。
  これらのフィールドを持たない過去のイベントはnull相当として扱う)
- `attendance_punch.corrected` (UC-A013 打刻ログを訂正する)
- `attendance_punch.deleted` (UC-A014 打刻ログを削除する)
- `attendance_day.synced_from_punches`
- `attendance_day.live_status_synced` (打刻ログがまだ矛盾なく1日分の勤務として組み立て
  られない間(出勤のみ・休憩開始のみ等)に、最新の打刻から`attendance_days.status`のみを
  反映する。WEB画面・端末のどちらの打刻でも発生しうる。既に退勤済みの日には発生しない)
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
- `device.enabled` (停止(disabled)中の端末を`pending_pairing`に戻し、UC-D002のペアリングを
  やり直せるようにする。失効(revoked)端末には使えない)
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
- `paid_leave.usage_designated` (paid_leave_request集約が記録。申請時点(承認前)で
  `paid_leave_usages`に確定前(grant_id未設定・is_confirmed=false)の行を作る。
  docs/16-database-schema.md paid_leave_usages参照)
- `paid_leave.request_approved`
- `paid_leave.request_returned`
- `paid_leave.request_cancelled`
- `paid_leave.used`
- `paid_leave.usage_reversed`
- `paid_leave.expired`
- `paid_leave.warning_raised`

## SpecialLeave

`paid_leave.*`と同じ構造(`usage_designated`/`used`/`usage_reversed`のライフサイクルは
paid_leave_usagesと同じ。docs/16-database-schema.md paid_leave_usages参照)。

- `special_leave.granted`
- `special_leave.requested`
- `special_leave.usage_designated`
- `special_leave.request_approved`
- `special_leave.request_returned`
- `special_leave.request_cancelled`
- `special_leave.used`
- `special_leave.usage_reversed`

## CompensatoryLeave

`paid_leave.*`と同じ構造(`usage_designated`/`used`/`usage_reversed`のライフサイクルは
paid_leave_usagesと同じ。docs/16-database-schema.md paid_leave_usages参照)。休日出勤の
勤怠実績から自動導出される付与(grant)に関するイベントが別途ある。

- `compensatory_leave.grant_synced`
- `compensatory_leave.grant_removed`
- `compensatory_leave.grant_confirmed`
- `compensatory_leave.grant_cancelled`
- `compensatory_leave.requested`
- `compensatory_leave.request_shared`
- `compensatory_leave.usage_designated`
- `compensatory_leave.request_approved`
- `compensatory_leave.request_returned`
- `compensatory_leave.request_cancelled`
- `compensatory_leave.used`
- `compensatory_leave.usage_reversed`

## Attachment / Notification / Export (横断)

- `attachment.uploaded`
- `attachment.downloaded` (UC-F002: 閲覧ログを監査ログに残す)
- `notification.queued` (payloadに`recipientUserId`/`notificationType`/`subjectType`/
  `subjectId`/`title`/`summary`/`detailUrl`を持つ。docs/13-usecases-notification.md)
- `notification.sent`
- `notification.confirmed` (本人が通知一覧またはメール内リンクから確認した)
- `export.created`

## 命名規則

`user.roles_changed`と`user.roles_migrated_from_legacy`は、旧ユーザーロール機構で記録済みの
StoredEventを復元するための履歴互換イベントである。旧機構の廃止後は新規発行しない。
本番履歴補正では、この2種を同時刻の `membership.added` / `membership.removed` に変換する。
詳細は[32-stored-event-history-normalization.md](./32-stored-event-history-normalization.md)を参照する。

- `<aggregate>.<past_tense_verb>` 形式 (例: `attendance_punch.`)。
- 集約(aggregate)は `aggregate_type` + `aggregate_id` で一意に識別する
  (例: `attendance_day` + `attendance_days.id`)。
- イベントは追記のみ。既存イベントの意味を変える場合は新しいイベント種別を追加し、
  旧イベントは残す(イミュータブル)。

ただし、本番カットオーバー処理が業務事実と異なる合成イベントや逆転した日時を作った場合に限り、
承認済みの一回限りのデータ補正として、原本DBバックアップと専用バックアップテーブルを作成した上で
履歴を再構成できる。通常のアプリケーション処理から既存イベントを更新してはならない。
