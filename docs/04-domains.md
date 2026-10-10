# 4. 主要ドメイン

- Auth
- UserManagement(User、ExternalIdentity、FieldAuthority、GroupType、Group、Membership、
  MembershipChangeSet、外部HR取込。人物・利用主体と所属グループを管理する。
  docs/31-user-group-access-foundation.md)
- AccessControl(Feature、Role、Permission、Scope。利用機能と操作権限を分離して扱う。
  docs/31-user-group-access-foundation.md)
- Workflow
- BackOffice
- Attendance
- CompanyCalendar
- Shift
- PaidLeave
- Attachment
- Notification
- Audit
- Export
- Device(端末。共有Android打刻リーダー・個人端末・外部端末を共通のモデルで扱う。
  docs/23-usecases-devices.md)
- AuthenticationKey(認証キー。NFC・生体認証端末の外部ID・QR・FIDO等をユーザーに紐付ける。
  docs/24-usecases-authentication-keys.md)
- Integration(個人/組織のAPI・MCP連携。MCPサーバーはこのドメインのクライアントとして
  勤怠管理APIを呼び出す。docs/25-usecases-integrations-mcp.md)
- AttendanceImport(作業報告書等から月次勤怠下書きを作成する。docs/26-usecases-monthly-import.md)

## 休暇まわりの文脈の責務(変更セット `20261009-keep-leave-work-type-on-edit`)

休暇まわりは次の文脈に分け、各文脈は自分の集約・テーブルだけを書き込む。他の文脈へはイベントで伝え、
他の文脈の変化にはReactor(自文脈のCommandを発行)またはProjector(自文脈の読み取りビューを作る)で反応する
(docs/03-architecture.md 3.10)。

- **申請・承認**(Workflow): 誰が・何を・いつ申請し誰が承認するかの進行状況(`workflow_requests`)。業務固有の
  判断(残数・衝突・締め)は持たない。業務側(`subject_type`)を持つ申請は却下できない。
- **休暇申請**(PaidLeaveRequest・SpecialLeave・CompensatoryLeave の申請部分): 休暇の申請状態
  (申請中・差戻し・承認済み・取消)。申請Handlerは他文脈の集約・テーブルを読み書きしない。
  ワークフローIDから申請への対応表(`leave_request_workflow_links`)を持つ。
- **残数・使用**(PaidLeaveAccount・SpecialLeaveAccount・CompensatoryLeaveAccount): 利用者単位の口座集約。
  付与の残数・消化記録(作成・確定・取消)・充当。承認時の残数不足は拒否せず、充当できた分だけ充当する。
- **勤怠**(Attendance): 勤怠日・日次計算、休暇の勤怠への反映(休暇ビュー`attendance_day_leaves`)、
  同じ日の休暇の衝突判定、締め判定。休暇だけの日は勤怠が`source=leave`で記録する。
  月次集計・給与連携・Excelは勤怠の文脈が持つ。
- **出勤率**(PaidLeaveSchedule・SpecialLeave): 出勤率の判定に使う出勤・休暇のビュー
  (`leave_attendance_rate_*`・`special_leave_attendance_rate_*`)を、勤怠と休暇申請のイベントから作る。
