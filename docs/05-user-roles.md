# 5. ユーザー種別とアクセス制御

以下は業務上の代表的な利用者区分であり、固定の権限セットではない。

- 一般社員
- 承認者
- バックオフィス担当者
- 経理担当者
- 総務担当者
- 人事担当者
- システム管理者

承認者は固定ルートだけでなく、申請時に任意の社員を指定できるようにする。

実際のアクセス制御では、機能の利用可否を`Feature`、機能内の操作可否を`Permission`、
Permissionの集合を`Role`として分離する。RoleはユーザーまたはグループにScope付きで割り当て、
複数の割当結果は加算する。Featureはグループへ付与し、ユーザー単位では例外的な利用停止のみを
扱う。承認Permissionと、個々の申請における実際の承認担当者も別概念とする。

詳細と受入条件は[31. ユーザー・グループ・権限・利用機能管理基盤](./31-user-group-access-foundation.md)
を正本とする。

## 会社カレンダー・従業員予定に関するFeature・Permission

固定の役職名(人事担当者・総務担当者等)を認可根拠にせず、31章のFeature/Permission/RoleAssignment
モデルに従う。

- Feature`company_calendar`(会社カレンダー機能)を有効化したグループのユーザーのみ、以下の
  Permissionを保有できる: `company_calendar.manage`(本体・年度の作成・編集・複製・廃止、
  会社カレンダー日の個別編集)、`company_calendar.publish`(年度の公開・公開取消)、
  `holiday_calendar_source.manage`(祝日iCalendarソースの登録・同期・競合解決)。
- Feature`employee_calendar_entry`を有効化したグループのユーザーのみ、以下のPermissionを
  保有できる: `employee_calendar_entry.override`(個別上書き・休日出勤/振替休日登録・公開/
  公開取消)、`employee_calendar_entry.bulk_edit`(複数従業員予定の一括操作のプレビュー・確定・
  取消)。Scopeは`GROUP`(対象部署・対象グループ配下に限定)または`GLOBAL`のいずれかを明示する。
- `employee_calendar_entry.read`は`SELF`スコープを`ALL_USERS`の基本Permission(31.1節の
  `EMPLOYEE` RoleAssignment)に含め、全社員が公開済み(`is_published=true`)の自分の従業員予定
  だけを閲覧できるようにする。他社員分の閲覧には`GROUP`/`GLOBAL`スコープの
  `employee_calendar_entry.read`が必要。
- UC-C007(法定休日「決めない方式」の指定)は`legal_holiday_designation.manage`で保護する。
  `SELF`スコープを`ALL_USERS`の基本Permissionに含め、社員本人が自分の週の指定をできるように
  する。他社員分の指定(UC-C007手順1「管理者が」)には`GROUP`/`GLOBAL`スコープの
  `legal_holiday_designation.manage`が必要。`employee_calendar_entry.override`とは別の
  Permissionとする(対象データが`legal_holiday_designations`であり、`employee_calendar_entries`
  の`schedule_state`自体を書き換える操作ではないため)。
- カレンダー年度の定期バッチ生成(UC-C014)はユーザー操作を経由しないシステム内部処理のため、
  Permission判定の対象にしない。一方、UC-C011の「今すぐ生成する」(`POST
  /api/onboarding/calendar/generate-now`)は管理者が画面から押す操作であり、
  `company_calendar.manage`を要求する(バッチと同じ生成ロジックを呼ぶだけであることは
  Permission判定を免除する理由にならない)。
- 会社カレンダー・従業員予定関連の実行結果は、既存`company_calendars`実装に揃えて
  `legacy_stored_events`に記録される(docs/20-implementation-notes.md参照)。現時点の
  `AuditLogController`は`stored_events`(新spatie方式)のみを検索する簡略化がされているため
  (docs/29-event-sourcing-framework-migration.md「監査ログ・申請履歴の再設計」参照)、
  本機能の変更は31.11節の監査画面にまだ表示されない。本機能(会社カレンダー・従業員予定)の
  実装に着手する際、spatie方式へ移行するか、監査画面側の対応を別途行うまでの既知の制約とする。
- 一般社員が自分の予定変更を依頼したい場合は、既存の汎用申請ワークフロー
  (`docs/10-usecases-workflow.md`)経由とし、専用の申請経路は持たない。
- API・MCP連携経由の操作も、Web画面と同じFeature・Permission判定を共通のCommandHandlerで通す
  (`docs/25-usecases-integrations-mcp.md`)。
