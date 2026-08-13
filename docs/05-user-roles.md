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
モデルに従う。**新しいPermissionコードは追加しない**(既存の`work-styles`/`shift-patterns`/
`rotation-patterns`と同じ枠組みに揃える。`backend/routes/api.php`の実装を参照)。

- 会社カレンダー本体・年度・会社カレンダー日の作成・編集・複製・公開・公開取消・廃止、祝日
  iCalendarソースの登録・同期・競合解決、複数従業員の一括予定変更(プレビュー・確定・取消)、
  従業員予定の個別上書き・公開/公開取消は、既存の`work-styles`/`shift-patterns`と同じ
  `permission:attendance.manage,any`(既存Permission、GLOBALスコープのみ)で保護する。新しい
  Permissionコードは作らない。
- `employee_calendar_entry.read`(自分の従業員予定閲覧)は、既存の
  `GET /employee-calendar-entries`ルートが使う`ability:schedule:self:read`
  (Sanctumトークンアビリティ)をそのまま使う。公開済み(`is_published=true`)の自分の従業員
  予定だけを返す制御はController側のクエリ条件で行う(既存実装と同じ)。
- UC-C007(法定休日「決めない方式」の指定)は、既存の法定休日指定APIと同じ
  `permission:attendance.manage,any`(管理者による他社員分の指定)または本人操作用の既存
  Sanctumアビリティ(実装時に既存の`legal_holiday_designations`関連エンドポイントの認可方式に
  合わせる)を使う。専用の新設Permissionは作らない。
- カレンダー年度の定期バッチ生成(UC-C014)はユーザー操作を経由しないシステム内部処理のため、
  Permission判定の対象にしない。一方、UC-C011の「今すぐ生成する」は管理者が画面から押す操作
  であり、`permission:attendance.manage,any`を要求する(バッチと同じ生成ロジックを呼ぶだけ
  であることはPermission判定を免除する理由にならない)。
- 一括操作の対象をグループ・部署単位に限定する権限スコープ(指示書13章が想定するGROUP
  スコープでの制限)は、`attendance.manage`が現状GLOBALスコープのみのPermissionであるため
  実装しない(既存のWorkStyle/ShiftPattern管理と同じ制約)。将来グループ単位の制限が必要に
  なった場合は、`attendance.manage`のスコープ定義(`database/seeders/AccessControlSeeder.php`)
  に`group`を追加してから対応する。
- 会社カレンダー・従業員予定関連の実行結果は、既存`company_calendars`実装
  (`WorkCalendarAggregate`/`EmployeeShiftAssignmentAggregate`、既にspatie方式へ移行済み)に
  揃えて新`stored_events`に記録される。現時点の`AuditLogController`は`stored_events`を
  検索するため(docs/29-event-sourcing-framework-migration.md「監査ログ・申請履歴の再設計」
  参照)、本機能の変更も31.11節の監査画面にそのまま表示される(`legacy_stored_events`への
  記録ではないため、追加対応は不要)。
- 一般社員が自分の予定変更を依頼したい場合は、既存の汎用申請ワークフロー
  (`docs/10-usecases-workflow.md`)経由とし、専用の申請経路は持たない。
- API・MCP連携経由の操作も、Web画面と同じFeature・Permission判定を共通のCommandHandlerで通す
  (`docs/25-usecases-integrations-mcp.md`)。
