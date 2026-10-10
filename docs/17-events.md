# 17. 主要イベント

勤怠の日別配賦イベントとして `attendance_day.weekly_overtime_allocated` を記録する。このイベントは
週40時間超を対象日の所定内・所定外法定外労働へ移した絶対値を保持する。

`stored_events.event_type` に記録するイベント種別の一覧。新しいイベントを追加する際は
[add-domain-event スキル](../.claude/skills/add-domain-event/SKILL.md) を参照する。

## User

- `user.logged_in`
- `user.synced_from_ms365`
- `user.onboarded_as_admin` (初回オンボーディング(UC-000)での管理者作成。payloadの
  `auth_method`が`sso`(実際のEntra IDログイン結果で作成、ExternalIdentityリンク済み)か
  `local`(ローカルパスワードで作成)かを区別する)
- `user.hire_date_set` (UC-P002 有給を自動付与する: 継続勤務期間の基準日を設定する)
- `user.paid_leave_auto_grant_enabled_set` (有給の自動付与をユーザーごとに有効/無効化する。
  docs/changesets/20260904-paid-leave-auto-grant-per-user-toggle/spec.md参照)
- `user.special_leave_auto_grant_enabled_set` (特別休暇の自動付与をユーザーごとに有効/無効化する。
  同上)
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
- `role.features_changed`(Role→Feature自動適用のFeatureマスタ変更。保存後にそのRoleを保持する
  全グループへ`GroupFeatureSyncService`が`group_feature_assignments`を差分同期する)
- `role_assignment.created` / `role_assignment.updated` / `role_assignment.removed`
  (`subject_type='group'`の場合、保存後に`GroupFeatureSyncService`が対象グループの
  `group_feature_assignments`を差分同期する)
- `system_settings.updated` (システム設定の直接更新と同一トランザクションで記録する監査イベント)

## Workflow (汎用申請)

- `workflow_request.drafted`
- `workflow_request.submitted`
- `workflow_request.approved`
- `workflow_request.returned`
- `workflow_request.cancelled`
- `workflow_request.rejected` (編集・再提出不可の終端状態。`RejectWorkflowRequest`/
  `WorkflowRequestRejected`。全申請種別で共通利用可能な汎用機能だが、現時点では備品貸出申請
  (`asset_loan`)のみが却下ボタンをUIに露出する。docs/34-usecases-asset-management.md参照)


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
- `attendance_day.corrected` (補正専用。運用の補正コマンド(data-correction)が発行する`CorrectAttendanceDay`で記録する。勤怠日の現在の正しい状態一式(勤怠日の列・休憩・不就労区間・日次計算・週40時間の配賦)と補正ID・理由・操作者を持つ。行が無ければ作り、あれば全項目を置き換える。過去のイベントは書き換えない。同じ補正IDは1度だけ記録する。出勤率ビュー・代休の休日出勤ビューも同じイベントから更新する。代休付与の同期Reactor(SyncCompensatoryLeaveAccountGrantOnAttendanceDayCalculatedReactor)も購読し、記録された日次計算で下書き付与を同期する(日次計算が無ければ外す))
- `attendance.month_submitted`
- `attendance.month_approved`
- `attendance.month_returned`
- `attendance.month_closed`
- `attendance.month_reopened` (救済コマンド: 管理者専用。締め済みの月次勤怠の締めを取り消し、
  承認済み状態に戻す。docs/07-usecases-attendance.md UC-A017)
- `attendance.month_confirmation_reverted` (救済コマンド: バックオフィス担当者専用。汎用申請
  ワークフロー「勤怠確定取消依頼」の承認後、承認済みの月次勤怠の確定を取り消し未提出状態に
  戻す。docs/07-usecases-attendance.md UC-A018)

### 休暇に伴う勤怠の記録(休暇まわりの連携)

休暇の反映・解除は勤怠文脈が自分のCommandで行い、勤怠日・日次計算を通常のイベントとして記録する
(変更セット`20261009-keep-leave-work-type-on-edit`の論点3・15)。休暇の値は`work_type`へ書き込まない。
休暇の有無は休暇ビュー(`attendance_day_leaves`)から判定する。

- `attendance_day.created` (`source=leave`): 休暇だけの日に、勤怠日が無い場合に勤怠が記録する
  (status=not_started、実績なし)。`createdByUserId`は連鎖の起点の操作者(休暇の申請者・承認者・取消者。
  システム処理で操作者がいない場合は申請者)。打刻・日次編集で通常どおり上書きでき、
  打刻の取り込みで`source=punch`に変わる。
- `attendance_day.calculated`: 休暇の申請・承認・差戻し・取消のたびに日次計算を記録する。
  代休口座の同期のため、利用者・勤務日・日区分・実労働分を末尾に持つ(計算イベントの項目追加)。
- `attendance_day.deleted`: 休暇の差戻し・取消で、`source=leave`の日に実績(出退勤・休憩・不就労区間・
  作業内容・勤務形態区分・備考)・手動調整・週40時間配賦のいずれも無い場合に記録する(論点15)。
  `deletedByUserId`は連鎖の起点の操作者。これ以外は日次計算をやり直す(`attendance_day.calculated`)。

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

## PaidLeave(廃止・監査目的でstored_eventsに残存)

年次有給休暇は2026年9月の`PaidLeaveAccountAggregate`への再設計(cutover、
docs/changesets/20260906-paid-leave-domain-redesign/spec.md)により、以下の
`paid_leave.*`イベント群は**廃止**された。旧`App\Domain\PaidLeave\Aggregates\
{PaidLeaveGrantAggregate,PaidLeaveRequestAggregate}`および対応するCommand/Handler/
Projectorはコードから削除済みで、以後この名前空間から新規に発行されることはない。
イベントクラス自体は`stored_events`に残る過去データのreplay・履歴参照のためだけに
物理的に残置している(`enforce_event_class_map`解決のため)。新規実装・新規参照では
下記「PaidLeaveAccount」節のイベントを使うこと。

- `paid_leave.rule_created`
- `paid_leave.granted`
- `paid_leave.requested`
- `paid_leave.usage_designated` (旧paid_leave_request集約が記録。申請時点(承認前)で
  `paid_leave_usages`に確定前(grant_id未設定・is_confirmed=false)の行を作る)
- `paid_leave.request_approved`
- `paid_leave.request_returned`
- `paid_leave.request_cancelled`
- `paid_leave.used`
- `paid_leave.usage_reversed`
- `paid_leave.expired`
- `paid_leave.warning_raised`
- `paid_leave.grant_revoked` (管理者による付与取消。消化済み日数がある付与は取消不可
  ―この制約自体も新ドメインでは変更されている。docs/09-usecases-paid-leave.md UC-P008参照)

## PaidLeaveAccount(現行、`App\Domain\PaidLeaveAccount\Events\`)

`App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate`
(AggregateId = `userId`)が発行する。`config/event-sourcing.php`に`paid_leave_account.*`
エイリアスで登録される(実クラス名は`PaidLeaveAccount`接頭辞を持たず、設定側で
`use ... as PaidLeaveAccountXxx`のエイリアスにより`paid_leave.*`時代の同名クラスとの
衝突を避けている)。詳細は docs/09-usecases-paid-leave.md「PaidLeaveAccountAggregateの
内部モデル」を参照。

- `paid_leave_account.grant_created` → `PaidLeaveGrantCreated`
  (grantId, grantedOn, expiresOn, grantedDays, grantReason, source)。新規Grant発行
  (手動・自動付与とも共通)。
- `paid_leave_account.grant_amount_changed` → `PaidLeaveGrantAmountChanged`
  (grantId, newGrantedDays, reason, changedByUserId)。最新Grantの日数変更
  (Allocation合計を下回る減額は不可)。
- `paid_leave_account.grant_date_changed` → `PaidLeaveGrantDateChanged`
  (grantId, newGrantedOn, reason, changedByUserId)。最新Grantの付与日変更。
- `paid_leave_account.grant_expiry_changed` → `PaidLeaveGrantExpiryChanged`
  (grantId, newExpiresOn, reason, changedByUserId)。最新Grantの有効期限変更
  (Allocation済みGrantの短縮は不可)。
- `paid_leave_account.grant_revoked` → `PaidLeaveGrantRevoked`
  (grantId, revokedByUserId, reason)。UC-P008。最新Grantの取消(全Allocationを解除)。
- `paid_leave_account.grant_warning_raised` → `PaidLeaveGrantWarningRaised`
  (grantId, warningType, message)。UC-P005/UC-P006の警告記録専用(`warningType`が
  `expiry`/`five_day_obligation`)。残高等の不変条件には関与しない。
- `paid_leave_account.usage_designated` → `PaidLeaveUsageDesignated`
  (usageId, workflowRequestId, attendanceDayId, usedOn, usedDays, usageType,
  paidLeaveRequestId, approverUserId, reason, requestGroupId, hours)。申請時点
  (承認前)に未確定Usageを1件作成する。`usageType`/`paidLeaveRequestId`等はAggregateの
  不変条件には使わず、`paid_leave_requests`Projection再構築用に運ぶだけ。
  (2026-10-10の変更以降、申請状態は`paid_leave_request.*`(下記「PaidLeaveRequest」節)が持ち、
  残数側のProjectorは`paid_leave_requests`を更新しない。`paidLeaveRequestId`は申請IDから
  消化記録を特定する対応にのみ使う。申請の作成・承認・差戻し・取消は`PaidLeaveAccount`に
  `usage_designated`/`usage_confirmed`/`usage_cancelled`を記録するReactorで行う。)
- `paid_leave_account.usage_confirmed` → `PaidLeaveUsageConfirmed`
  (usageId, confirmedByUserId)。承認によりUsageを確定し、続けてAllocationを実行する。
- `paid_leave_account.usage_cancelled` → `PaidLeaveUsageCancelled`
  (usageId, cancelledByUserId, reason)。Usage取消(事前に全Allocationを解除する
  `usage_allocation_released`が発行される)。
- `paid_leave_account.usage_allocated` → `PaidLeaveUsageAllocated`
  (usageId, grantId, allocatedDays)。`AllocationPlanner`の算出結果に基づき、
  UsageとGrantを充当する(1件のUsage確定で複数件発行されうる)。
- `paid_leave_account.usage_allocation_released` → `PaidLeaveUsageAllocationReleased`
  (usageId, grantId, releasedDays)。Grant取消・Usage取消時にAllocationを解除する。
- `paid_leave_account.migrated` → `PaidLeaveAccountMigrated`
  (cutoverDate, grants[])。既存システム/旧ドメインからのcutover移行専用。口座ごとに
  1回だけ発行され、通常の`grant()`が課す不変条件(直前Grantより後の日付であること等)を
  経由しない`migrateGrants()`から発行される。`grants[]`の各要素は
  `{grantId, originalGrantedOn?, originalGrantedDays?, remainingDaysAtCutover, expiresOn,
  source, cutoverMetadata?}`(docs/09-usecases-paid-leave.md UC-P010参照)。

## PaidLeaveRequest(有給申請の申請状態、`App\Domain\PaidLeaveRequest\Events\`)

有給申請の集約`PaidLeaveRequestAggregate`(AggregateId = 申請ID)が発行する。申請状態
(申請中・差戻し・承認済み・取消)だけを持ち、残数・消化記録は`PaidLeaveAccount`が持つ
(docs/09-usecases-paid-leave.md、変更セット`20261009-keep-leave-work-type-on-edit`の論点4)。
イベントクラス名は旧`PaidLeave\Events\PaidLeaveRequestApproved`等との衝突を避けて
`PaidLeaveRequestLifecycle{Requested,Shared,Approved,Returned,Resubmitted,Cancelled,Migrated}`
とし、`config/event-sourcing.php`で`paid_leave_request.*`に対応づける。

- `paid_leave_request.requested` → `PaidLeaveRequestLifecycleRequested`
  (userId, targetDate, leaveType, hours, requestedDays, approverUserId, reason, requestGroupId,
  workflowRequestId)。申請の作成(申請中)。
- `paid_leave_request.shared` → `PaidLeaveRequestLifecycleShared`(workflowRequestId)。
  申請・承認文脈のReactor(`SubmitWorkflowRequestOnPaidLeaveRequestSharedReactor`)がワークフローを提出する。
- `paid_leave_request.approved` → `PaidLeaveRequestLifecycleApproved`(approvedByUserId, userId)。
- `paid_leave_request.returned` → `PaidLeaveRequestLifecycleReturned`(returnedByUserId, comment, userId)。
  差戻し。申請前の状態に戻り、残数側は未確定の消化記録を取り消す。
- `paid_leave_request.resubmitted` → `PaidLeaveRequestLifecycleResubmitted`(resubmittedByUserId,
  userId, targetDate, leaveType, hours, requestedDays, approverUserId, reason, requestGroupId,
  workflowRequestId)。差戻しからの再提出(内容は変えない。残数側は新しい消化記録を作る)。
- `paid_leave_request.cancelled` → `PaidLeaveRequestLifecycleCancelled`(cancelledByUserId, reason, userId)。
  申請中・差戻し・承認済みからの取消。承認済みの取消でも承認(ワークフロー)は取り消さない(設計原則13)。
- `paid_leave_request.migrated` → `PaidLeaveRequestLifecycleMigrated`(本変更前の申請の引き継ぎ)。
  現在の状態・対象日・取得単位・時間数・ワークフローID・まとめ申請ID・対応する消化記録ID(`usageId`)を
  含む。運用コマンド`paid-leave:migrate-requests`が今の時点に追記する。
  申請IDごとに、`migrated`より前は旧系統(旧`paid_leave.*`/cutover後の`paid_leave_account.*`+
  `workflow_request.returned`)、以後は`paid_leave_request.*`だけで状態を作る(3系統の切り替え)。

## SpecialLeaveAccount(特別休暇の利用者単位の口座集約、`App\Domain\SpecialLeaveAccount\Events\`)

特別休暇の付与の残数と消化記録(作成・確定・取消)を持つ口座集約
(`SpecialLeaveAccountAggregate`)が発行する。AggregateIdは
`UserManagementStreamId::for('special_leave_account', userId)`の派生ID(有給の口座と
`userId`が衝突しないため)。申請の状態は`special_leave.*`(申請文脈)が持つ。

- `special_leave_account.grant_registered` → `SpecialLeaveAccountGrantRegistered`
  (grantId, specialLeaveTypeId, grantedOn, expiresOn, grantedDays, grantReason, userId)。付与の登録。
- `special_leave_account.grant_revoked` → `SpecialLeaveAccountGrantRevoked`(grantId, revokedByUserId, reason)。
- `special_leave_account.usage_designated` → `SpecialLeaveAccountUsageDesignated`
  (usageId, requestId, specialLeaveTypeId, usedOn, usageType, usedDays, usedMinutes, userId)。
  申請時の消化記録の作成(`SpecialLeaveUsageOnSpecialLeaveRequestReactor`)。
- `special_leave_account.usage_confirmed` → `SpecialLeaveAccountUsageConfirmed`
  (usageId, allocations, unallocatedDays)。承認時に付与へ充当する。残数が不足していても確定し、
  充当できなかった分を`unallocatedDays`に記録する(論点17)。
- `special_leave_account.usage_cancelled` → `SpecialLeaveAccountUsageCancelled`
  (usageId, releasedAllocations, reason)。差戻し・取消で消化記録を取り消し、充当を解除する。
- `special_leave_account.migrated` → `SpecialLeaveAccountMigrated`(grants, usages, userId)。
  運用コマンド`special-leave:migrate-to-account`が、既存の付与・未取消の消化記録の現在の状態を
  今の時点に追記する(差し戻された申請の消化記録は含めない。申請IDと消化記録IDの対応を含む)。

## CompensatoryLeaveAccount(代休の利用者単位の口座集約、`App\Domain\CompensatoryLeaveAccount\Events\`)

代休の付与の残数と消化記録を持つ口座集約(`CompensatoryLeaveAccountAggregate`)が発行する。
AggregateIdは特別休暇の口座と同じく種類ごとの派生IDを使う。付与は勤怠の日次計算に同期される
(`attendance_day.calculated`等を受けるReactor)。

- `compensatory_leave_account.grant_synced` → `CompensatoryLeaveAccountGrantSynced`
  (userId, grantId, sourceWorkDate, grantedDays, grantedMinutes)。休日出勤の日次計算からの付与の同期
  (`SyncCompensatoryLeaveAccountGrantOnAttendanceDayCalculatedReactor`)。
- `compensatory_leave_account.grant_removed` → `CompensatoryLeaveAccountGrantRemoved`(userId, grantId, reason)。
- `compensatory_leave_account.grant_confirmed` → `CompensatoryLeaveAccountGrantConfirmed`
  (userId, grantId, confirmedAt, expiresOn)。月次勤怠の提出時の付与の確定
  (`ConfirmCompensatoryLeaveGrantsOnAttendanceMonthSubmittedReactor`。判定は日付範囲)。
- `compensatory_leave_account.grant_manually_granted` → `CompensatoryLeaveAccountGrantManuallyGranted`
  (userId, grantId, sourceWorkDate, grantedDays, grantedMinutes, expiresOn, grantReason)。管理者の手動付与。
  休日出勤の確認は口座側の休日出勤ビュー(`compensatory_holiday_work_days`)で行う。
- `compensatory_leave_account.grant_cancelled` → `CompensatoryLeaveAccountGrantCancelled`
  (userId, grantId, cancelledByUserId, reason)。付与の取消(申請・承認に伴う取消を含む)。
- `compensatory_leave_account.usage_designated` → `CompensatoryLeaveAccountUsageDesignated`
  (userId, usageId, requestId, usedOn, usageType, usedDays, usedMinutes)。申請時の消化記録の作成。
- `compensatory_leave_account.usage_confirmed` → `CompensatoryLeaveAccountUsageConfirmed`
  (userId, usageId, allocations, unallocatedDays, unallocatedMinutes)。承認時の充当。不足分は記録する(論点17)。
- `compensatory_leave_account.usage_cancelled` → `CompensatoryLeaveAccountUsageCancelled`
  (userId, usageId, releasedAllocations, reason)。差戻し・取消で消化記録を取り消す。
- `compensatory_leave_account.migrated` → `CompensatoryLeaveAccountMigrated`(userId, grants, usages)。
  運用コマンド`compensatory-leave:migrate-to-account`が追記する(特別休暇と同じ規則)。

## 休暇まわりの連携(購読関係、原則15)

休暇の申請・承認・残数・勤怠は、互いのテーブルを直接読み書きせず、以下のイベントの購読
(Reactor/Projector)で連動する(変更セット`20261009-keep-leave-work-type-on-edit`の仕様確定事項A〜E)。

- 申請・承認 → 休暇申請: `workflow_request.drafted/submitted/approved/returned/cancelled`を、
  休暇申請文脈のReactor(`*RequestOnWorkflowRequest*Reactor`・`*ApprovalOnWorkflowRequestReactor`)が受ける。
  ワークフローIDから申請への対応は休暇申請文脈の対応表(`leave_request_workflow_links`)で引く。
  まとめ申請の兄弟承認は、休暇申請の`*.approved`を申請・承認文脈の
  `ApproveWorkflowRequestOn*RequestApprovedReactor`が受けて兄弟のワークフローを承認する(逆方向)。
- 休暇申請 → 申請・承認: 休暇申請の`*.shared`を`SubmitWorkflowRequestOn*RequestSharedReactor`が受けて提出する。
  休暇申請の`*.cancelled`を`CancelWorkflowRequestOn{PaidLeave,SpecialLeave,CompensatoryLeave}RequestCancelledReactor`
  が受けてワークフローを取り消す(`viaReactor`。承認済みは変えない)。
- 休暇申請 → 残数・使用: 有給は`paid_leave_request.requested/resubmitted`で`DesignatePaidLeaveUsage`、
  `.approved`で`ConfirmPaidLeaveUsage`、`.returned/.cancelled`で`CancelPaidLeaveUsage`。特別・代休は
  `*_leave_request.requested/resubmitted`・`approved`・`returned/cancelled`を受けて各口座集約に消化記録を作成・確定・取消する
  (`SpecialLeaveUsageOnSpecialLeaveRequestReactor`・`CompensatoryLeaveUsageOnCompensatoryLeaveRequestReactor`
  ・`PaidLeaveUsageOnPaidLeaveRequestReactor`)。
- 休暇申請 → 勤怠: 休暇申請のイベントを受けた勤怠の休暇ビュー(`attendance_day_leaves`)のProjectorと、
  `AttendanceDayOn{PaidLeave,SpecialLeave,CompensatoryLeave}RequestReactor`が勤怠のCommand
  (`ApplyLeaveToAttendanceDay`/`ReleaseLeaveFromAttendanceDay`)を発行する。締め判定・同じ日の衝突は
  勤怠側で行い、違反は連鎖全体を取り消す(論点7・14)。
- 勤怠 → 代休口座: `attendance_day.calculated`・`attendance_day.daily_calculation_adjusted`・
  `attendance_day.deleted`を代休口座の同期Reactorが受ける(計算イベントの利用者・勤務日・日区分・実労働分で判定)。
  `attendance.month_submitted`で代休付与の確定を行う。

## PaidLeaveSchedule(`App\Domain\PaidLeaveSchedule\Events\`)

`App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate`(AggregateId = `userId`)
が発行する。`config/event-sourcing.php`に`paid_leave_schedule.*`エイリアスで登録される。
将来付与予定Schedule・出勤率Assessmentを扱う(Phase A、
docs/changesets/20260906-paid-leave-schedule-assessment/spec.md参照)。

- `paid_leave_schedule.entry_created` → `PaidLeaveScheduleEntryCreated`
  (scheduleEntryId, scheduledOn, category, candidateGrantDays, isDeterminate)。新規Schedule
  エントリ作成(新入社員展開・月次ローリング生成・再計算での再作成のいずれからも発行される)。
  `isDeterminate=false`の場合、エントリは`Scheduled`ではなく`NeedsReview`状態で作成される
  (候補日数を確定できない場合。`docs/changesets/20260914-port-to-pr112/spec.md`参照)。
- `paid_leave_schedule.entry_superseded` → `PaidLeaveScheduleEntrySuperseded`
  (scheduleEntryId, reason, previousScheduledOn, previousCategory,
  previousCandidateGrantDays)。再計算により既存エントリが置き換えられたことを記録する
  (置き換え前の内容も監査用に保持)。
- `paid_leave_schedule.assessment_recorded` → `PaidLeaveScheduleAssessmentRecorded`
  (scheduleEntryId, assessmentId, periodStart, periodEnd, denominatorDays, attendanceDays,
  excludedDays, attendanceRate, policyVersion, automaticResult)。出勤率Assessment結果を記録し、
  エントリの状態を`automaticResult`(Eligible/NotEligible/NeedsReview)へ遷移させる。
- `paid_leave_schedule.assessment_overridden` → `PaidLeaveScheduleAssessmentOverridden`
  (scheduleEntryId, assessmentId, finalResult, reason, operatorUserId)。管理者による判定結果の
  上書き(理由必須)。
- `paid_leave_schedule.entry_manually_edited` → `PaidLeaveScheduleEntryManuallyEdited`
  (scheduleEntryId, changes, reason, operatorUserId)。Scheduleエントリの個別修正。以後の
  自動再計算(`recalculateFutureSchedule`)の対象から除外される。
- `paid_leave_schedule.entry_granted` → `PaidLeaveScheduleEntryGranted`
  (scheduleEntryId, grantId, operatorUserId)。管理者の一括付与操作でEligibleエントリを
  Grantedへ遷移させる(実際の`PaidLeaveAccount\Commands\GrantPaidLeave`発行はHandlerが担う)。
- `paid_leave_schedule.entry_cancelled` → `PaidLeaveScheduleEntryCancelled`
  (scheduleEntryId, reason)。エントリが不要になった場合の取消(再計算での削除・
  管理者操作の両方から発行されうる)。

## SpecialLeave

`paid_leave.*`と同じ構造(`usage_designated`/`used`/`usage_reversed`のライフサイクルは
paid_leave_usagesと同じ。docs/16-database-schema.md paid_leave_usages参照)。

2026-10-10の変更で、申請状態(申請・差戻し・再申請・承認・取消・提出)は`special_leave.*`が持ち、
付与・消化(残数)は`SpecialLeaveAccount`の`special_leave_account.*`が持つ。`special_leave.granted`・
`special_leave.usage_designated`・`special_leave.used`・`special_leave.usage_reversed`・`special_leave.grant_revoked`は
旧集約の過去データの再生用に残置する(新規には発行されない)。

- `special_leave.granted` (旧。新規付与は`special_leave_account.grant_registered`)
- `special_leave.requested`
- `special_leave.request_shared` (申請の提出。申請・承認文脈のReactorがワークフローを提出する)
- `special_leave.request_resubmitted` (`SpecialLeaveRequestResubmitted`。差戻しされた申請の再申請。
  同じ内容で申請中に戻し、残数側は新しい消化記録を作る。論点8)
- `special_leave.usage_designated` (旧。新規は`special_leave_account.usage_designated`)
- `special_leave.request_approved`
- `special_leave.request_returned`
- `special_leave.request_cancelled`
- `special_leave.used` (旧)
- `special_leave.usage_reversed` (旧)
- `special_leave.grant_revoked` (旧。管理者による付与取消。新規は`special_leave_account.grant_revoked`)

## CompensatoryLeave

`paid_leave.*`と同じ構造(`usage_designated`/`used`/`usage_reversed`のライフサイクルは
paid_leave_usagesと同じ。docs/16-database-schema.md paid_leave_usages参照)。休日出勤の
勤怠実績から自動導出される付与(grant)に関するイベントが別途ある。

2026-10-10の変更で、申請状態は`compensatory_leave.*`(申請)、付与・消化(残数)は
`CompensatoryLeaveAccount`の`compensatory_leave_account.*`が持つ。付与と消化の以下のイベントは
旧集約の過去データの再生用に残置する(新規には発行されない)。

- `compensatory_leave.grant_synced` (旧。新規は`compensatory_leave_account.grant_synced`)
- `compensatory_leave.grant_removed` (旧)
- `compensatory_leave.grant_confirmed` (旧)
- `compensatory_leave.grant_cancelled` (旧)
- `compensatory_leave.requested`
- `compensatory_leave.request_shared`
- `compensatory_leave.usage_designated` (旧。新規は`compensatory_leave_account.usage_designated`)
- `compensatory_leave.request_approved`
- `compensatory_leave.request_returned`
- `compensatory_leave.request_cancelled`
- `compensatory_leave.request_resubmitted` (`CompensatoryLeaveRequestResubmitted`。差戻しされた申請の
  再申請。論点8)
- `compensatory_leave.used` (旧)
- `compensatory_leave.usage_reversed` (旧)
- `compensatory_leave.manually_granted` (旧。管理者による手動付与。新規は
  `compensatory_leave_account.grant_manually_granted`。休日出勤の対象日を指定し、
  勤怠実績からの自動導出と同じ換算ルールで日数を算出、承認不要でstatus=confirmedの行を
  1イベントで作成する。docs/09-usecases-paid-leave.md「代休の手動付与・管理者直接取消」参照)

## Attachment / Notification / Export (横断)

- `attachment.uploaded`
- `attachment.downloaded` (UC-F002: 閲覧ログを監査ログに残す)
- `notification.queued` (payloadに`recipientUserId`/`notificationType`/`subjectType`/
  `subjectId`/`title`/`summary`/`detailUrl`を持つ。docs/13-usecases-notification.md)
- `notification.sent`
- `notification.confirmed` (本人が通知一覧またはメール内リンクから確認した)
- `export.created` (UC-E001/UC-E002: CSV/Excel出力の履歴。`exportType`で出力種別を区別する。
  `idempotencyKey`はnullable(後方互換。経費の証跡アーカイブ以外では未使用))
- `internal_archive.created` (UC-X012: 経費の証跡アーカイブExcelを`InternalArchivePublisher`で
  内部保存したことを記録する。`idempotencyKey`は「対象データID+出力種別+実行回数」から
  決定的に導出する。外部システムへの送信は行わない)
- `external_integration.published` (docs/33-usecases-attendance-external-api.md: 勤怠月次確定
  データ(`attendance_months`)をfreee/moneyforward等の外部APIへ`ExternalApiPublisher`経由で
  送信したことを記録する。`idempotencyKey`は「対象データID(attendance_months.id)+連携先+
  出力種別+実行回数」から決定的に導出する。送信に成功した場合のみ記録し、失敗はAPIレスポンスの
  `failures`で通知する(イベントは記録しない)。フェーズ3(docs/30-usecases-expense.md UC-X012)
  では経費申請(承認済み)の外部API送信でも同一イベントクラスをそのまま再利用する
  (新規イベントは追加しない)。対象データIDフィールド(`attendanceMonthId`)には
  `expense_claims.id`を渡し、`exportType`/`idempotencyKey`の接頭辞
  (`attendance_external_api_*`/`expense_external_api_*`)で対象種別を区別する)

## Asset (docs/34-usecases-asset-management.md)

`AssetAggregate`1つのみが発行する。貸出申請専用のイベントは持たず、申請の進行状況は
上記`workflow_request.submitted`/`.approved`/`.rejected`/`.withdrawn`/`.cancelled`のみで
表現する(`asset_loan_requests`Projectionはこれらを購読するReactorが更新する)。

- `asset.registered` (`assetNo`/`name`/`category`/`serialNumber`/`managementType`/
  `lendingMethod`/`defaultLocationText`/`qrToken`/`notes`/`registeredByUserId`)
- `asset.details_updated` (`name`/`category`/`serialNumber`/`notes`/`updatedByUserId`)
- `asset.deleted` (`deletedByUserId`。Projectionからは物理削除するが`stored_events`は削除しない)
- `asset.management_type_changed` (`managementType`/`changedByUserId`)
- `asset.lending_method_changed` (`lendingMethod`/`changedByUserId`)
- `asset.qr_code_reissued` (`qrToken`/`reissuedByUserId`。`qr_token`のみ差し替え、`asset_no`・
  履歴は変更しない)
- `asset.default_location_set` (`locationText`/`setByUserId`。貸出品のみ)
- `asset.loaned` (`loanId`/`borrowerUserId`/`lentByUserId`/`expectedReturnAt`(nullable)/
  `loanRequestId`(nullable。`approval`方式で承認済み申請に基づく貸与の場合のみ)/`loanedAt`。
  `self_service`/`backoffice`/`approval`いずれもこの1つのイベントで表現する)
- `asset.returned` (`loanId`/`returnedByUserId`/`returnNote`(nullable)/`returnedAt`)
- `asset.installed` (`locationText`/`installedByUserId`/`installedAt`。設置品のみ)
- `asset.relocated` (`locationText`/`relocatedByUserId`/`relocatedAt`。設置済み状態での設置場所
  変更、設置品のみ)
- `asset.removed_from_installation` (`removedByUserId`/`removedAt`。撤去→保管、設置品のみ)
- `asset.repair_started` (`note`(nullable)/`startedByUserId`。貸出品・設置品共通)
- `asset.repair_completed` (`note`(nullable)/`completedByUserId`。貸出品・設置品共通)
- `asset.reported_lost` (`note`(nullable)/`reportedByUserId`。貸出中の場合も借用者情報
  (`current_loan_id`/`asset_loans`)は保持したまま`lending_status`のみ`lost`へ遷移する)
- `asset.recovered_from_lost` (`wasLoanedBeforeLoss`/`recoveredByUserId`。発見時点で貸出中扱い
  だったかにより`lending_status`が`loaned`/`available`のどちらへ戻るかを決める)
- `asset.disposed` (`note`(nullable)/`disposedByUserId`。廃棄はProjection上の行を残し
  `status=disposed`のまま検索・一覧対象にも表示する)

管理番号の自動採番(`asset_number_rules`、docs/34-usecases-asset-management.md参照)は
監査専用の別Aggregate(`AssetNumberRuleAuditAggregate`)から以下を発行する。
`asset_number_rules`自体はEloquentが正のマスタであり、これらのイベントから
Projectionを再生成する対象ではない。

- `asset_number_rule.configured` (`assetNumberRuleId`/`category`(nullable。`null`は
  デフォルトルール)/`prefix`/`digitCount`/`enabled`/`isDefault`/`actorUserId`)
- `asset_number.issued` (`assetNumberRuleId`/`category`/`issuedNumber`/`assetNo`/
  `actorUserId`。カテゴリ一致ルールまたはデフォルトルールいずれから採番したかは
  `assetNumberRuleId`から判別する)

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

### 日次計算5区分への履歴補正

カットオーバー時は、先にDB全体をバックアップしてから
管理メニューの「運用コマンド」で「勤怠計算イベント履歴補正」を `apply` 無効で実行して
対象件数を確認し、次に `apply` を有効にして一度だけ実行する（CLIでは
`php artisan attendance:normalize-calculation-events`、続けて
`php artisan attendance:normalize-calculation-events --apply`）。対象の
`attendance_day.calculated`、`work_style.created`、`work_style.updated`を専用バックアップテーブルへ
複製してから、5区分と作業日境界のメタデータを既存イベントへ追記する。バックアップテーブルが
既に存在する場合は再実行を拒否する。完了後は同画面から「勤怠計算Projection再構築」、
「月次勤怠スナップショット再計算」の順に実行する。DBマイグレーション自体はCI/CDで先に完了させ、
これらのコマンドを処理するキューワーカーが稼働していることを確認する。
