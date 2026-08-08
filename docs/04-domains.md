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
- WorkCalendar
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
