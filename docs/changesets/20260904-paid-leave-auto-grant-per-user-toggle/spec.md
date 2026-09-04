# 有給自動付与のユーザーごと有効/無効設定

ステータス: レビュー中

## 変更要望(原文)
休暇の自動設定について、現状自動設定ルールはありますが、ユーザーごとには制御ができません。自動設定の有効・無効について、ユーザーごとに設定できるようにしてください。設定のUIはユーザーごとの画面と自動設定ルールでユーザーを設定していくのの両方向から作ってください。

## 背景・目的
有給の自動付与(UC-P002)は `paid_leave_grant_rules` の対象条件(雇用形態/勤務体系・入社日等)
にマッチする社員全員へ一律で適用される。出向者・休職者・契約上有給を個別管理する社員など、
ルール条件には合致するが自動付与の対象から外したい社員を個別に除外する手段が無い。本変更は
その個別制御(有効/無効の切替)を追加する。

## 現状(As-Is)
- `docs/09-usecases-paid-leave.md` UC-P001(有給付与ルールを作成する、L3-13): 付与ルールは
  対象グループ(雇用形態/勤務体系)・勤続月数ごとの付与日数・出勤率条件のみを持つ。ユーザー単位の
  設定項目は無い。
- `docs/09-usecases-paid-leave.md` UC-P002(有給を自動付与する、L15-50): 日次cron
  `paid-leave:grant-scheduled` が `is_active=true` の全ルールを取得し、
  `App\Domain\PaidLeave\Handlers\GrantScheduledPaidLeaveHandler::eligibleUsers()`
  (`backend/app/Domain/PaidLeave/Handlers/GrantScheduledPaidLeaveHandler.php:114-130`)で
  `users.hire_date IS NOT NULL` かつ `usage_start_date` 到来済み、必要なら
  `work_style_id` 一致の社員を抽出し、記念日・出勤率判定後に `GrantPaidLeave` を発行する。
  ユーザー個別の有効/無効フラグは存在しない。
- 付与ルールのマスタ: `paid_leave_grant_rules`(`is_active`, `work_style_id`,
  `min_attendance_rate`, `first_grant_after_months`, `grant_cycle_months`) +
  `paid_leave_grant_rule_steps`(`continuous_service_months`, `grant_days`)。
  モデル: `backend/app/Models/PaidLeaveGrantRule.php` / `PaidLeaveGrantRuleStep.php`。
- 既存の類似パターン: `users.hire_date` / `users.usage_start_date` は
  `App\Domain\UserManagement` 配下でCommand→Event(`UserHireDateSet`等)→
  `UserProjector`経由で更新される(`backend/app/Domain/UserManagement/Handlers/SetUserHireDateHandler.php`、
  `Events/UserHireDateSet.php`)。フロントは社員個別編集画面
  `frontend/src/pages/admin/UserRoleEditPage.tsx`(L85-795付近)で入社日・利用開始日を編集する。
- 付与ルール管理画面: `frontend/src/pages/paidLeave/PaidLeaveAdminPage.tsx` の
  `PaidLeaveGrantRulesCard`(L33-183)。ルールのCRUDのみで、対象社員の一覧・個別設定は無い。
- `docs/16-database-schema.md` L1000-1018: `paid_leave_grant_rules` /
  `paid_leave_grant_rule_steps` の定義。`paid_leave_grants` は付与結果(出力)であり設定ではない。

## 仕様検討

### 論点1: フラグの持たせ方(データ構造)
- 選択肢:
  - A. `users` テーブルに `paid_leave_auto_grant_enabled`(boolean, default true)を追加し、
    `UserHireDateSet` 等と同様にCommand/Eventで更新・Projectorで反映する。
  - B. `AttendanceSubmissionReminderExclusion` に倣い、独立した除外記録テーブル
    (`paid_leave_auto_grant_exclusions`: user_id, reason, excluded_by_user_id)を作り、
    「レコードが存在する = 無効」として扱う。
  - C. 有給以外の休暇種別(代休等、`GrantScheduledSpecialLeaveHandler`)も将来同様の制御が
    要る前提で、休暇種別を問わない汎用テーブル(`leave_auto_grant_settings`:
    user_id, leave_type, enabled)を新設する。
- 決定: A(`users.paid_leave_auto_grant_enabled`、Command/Event経由)。
- 理由: 今回の要望は「有給の自動設定」に限定されており(原文に代休等の言及なし)、Bの除外理由
  記録・監査ログ的な性質は本件では不要(単純なON/OFFで十分)。既存の`hire_date`/
  `usage_start_date`と同じ「社員マスタの1属性」として扱うのが最も自然で、
  `UserRoleEditPage`の既存編集フローにそのまま乗せられる。CはYAGNI(現時点で代休側の要望はない)。
  EventStoreを正とする原則1に従い、直接UPDATEではなくCommand→Event
  (`PaidLeaveAutoGrantEnabledSet`)→Projectorで`users`テーブルへ反映する。
- 未確定・要確認事項: なし(A案で確定)。

### 論点2: デフォルト値と既存社員への影響
- 選択肢:
  - A. デフォルト `true`(有効)。既存の全社員は挙動が変わらない。
  - B. デフォルト `false`(無効)。明示的に有効化した社員のみ自動付与対象。
- 決定: A(デフォルト`true`)。
- 理由: 本変更は「例外的に自動付与から外したい社員がいる」ことへの対応であり、既存の
  全社員一律自動付与という現行挙動を破壊しない後方互換性を優先する。

### 論点3: 判定ロジックへの組み込み位置
- 選択肢:
  - A. `GrantScheduledPaidLeaveHandler::eligibleUsers()` のクエリに
    `->where('paid_leave_auto_grant_enabled', true)` を追加する。
  - B. ルールの`work_style_id`と同様に、ルールごとに対象社員リストを明示管理する
    (ルール側に個別許可リストを持たせる)。
- 決定: A。
- 理由: 要望は「ユーザーごとのON/OFF」であり「ルールごとの対象社員の明示管理」ではない。
  Bは既存の対象条件(雇用形態/勤務体系ベース)の設計思想と重複し、ルールが複数ある場合に
  同じ社員を全ルールへ都度登録する手間が生じる。Aはルール条件通過後の最終ゲートとして
  シンプルに機能し、既存ロジックへの影響も1行で済む。
- 未確定・要確認事項: なし。

### 論点4: API設計
- 選択肢:
  - A. 既存の `UserController`(または相当コントローラ)に
    `PUT /api/users/{user}/paid-leave-auto-grant-enabled` を追加し、`hire_date`更新と
    同じ場所に置く。
  - B. `PaidLeaveController` 側に社員設定用エンドポイントを追加する。
- 決定: A。
- 理由: 対象は`users`テーブルの属性であり、既存の`hire_date`/`usage_start_date`更新
  エンドポイントと同じ責務・同じ権限(`user.update`)で扱うのが一貫している。付与ルール画面
  (論点5)からもこのAPIを叩く。
- 未確定・要確認事項: なし。

### 論点5: UIの「両方向」の実現方法
- 選択肢:
  - A. (a)社員個別編集画面`UserRoleEditPage.tsx`にチェックボックス
    「有給の自動付与を有効にする」を追加(hire_date等と同じ並び)。
    (b)付与ルール管理画面`PaidLeaveAdminPage.tsx`の`PaidLeaveGrantRulesCard`に
    「対象社員一覧」セクションを追加し、各ルールの`work_style_id`条件に現在マッチしている
    社員を一覧表示、各行にチェックボックスで自動付与ON/OFFを切替可能にする(同じAPIを叩く)。
  - B. ルール画面側は一覧表示のみ(トグルはUserRoleEditPageのみ)。
- 決定: A。
- 理由: ユーザーの要望が「ユーザーごとの画面」と「自動設定ルールでユーザーを設定していく」の
  両方向を明示しているため、両画面に同じ設定を反映する導線を用意する。裏側の設定値は
  `users.paid_leave_auto_grant_enabled` 一本なので、二重管理にはならない(表示元が2箇所
  あるだけ)。
- 未確定・要確認事項: なし。

## 仕様確定事項(まとめ)
- **DB**: `users`テーブルに `paid_leave_auto_grant_enabled boolean not null default true` を
  追加するマイグレーションを作成。
- **ドメイン(backend/app/Domain/UserManagement)**:
  - Command: `SetPaidLeaveAutoGrantEnabled(userId, enabled, changedByUserId)`
  - Event: `PaidLeaveAutoGrantEnabledSet(enabled, changedByUserId)`(`user.paid_leave_auto_grant_enabled_set`)
  - Handler: `SetPaidLeaveAutoGrantEnabledHandler`(`UserHireDateSet`系と同じ構成。存在確認のみ、
    業務ルール上の禁則は無し)
  - `UserAggregate` に `setPaidLeaveAutoGrantEnabled(bool $enabled, string $changedByUserId)` を追加
  - `UserProjector::onPaidLeaveAutoGrantEnabledSet()` で `users.paid_leave_auto_grant_enabled` を更新
- **PaidLeave判定ロジック**: `GrantScheduledPaidLeaveHandler::eligibleUsers()`
  (`backend/app/Domain/PaidLeave/Handlers/GrantScheduledPaidLeaveHandler.php:114-130`)の
  クエリに `->where('paid_leave_auto_grant_enabled', true)` を追加する。
- **API**:
  - `PUT /api/users/{user}/paid-leave-auto-grant-enabled`(body: `{ enabled: boolean }`)、
    権限は既存の`hire_date`更新エンドポイントと同じ `user.update`。
  - `UserResource` のレスポンスに `paid_leave_auto_grant_enabled` を含める。
  - 付与ルール対象社員一覧取得用に `GET /api/paid-leave/grant-rules/{rule}/target-users` を追加し、
    そのルールの`work_style_id`条件(未設定なら全社員)にマッチし`hire_date`設定済みの社員を
    `id, name, work_style, paid_leave_auto_grant_enabled` 付きで返す(付与済み日数等の計算は不要、
    一覧表示専用の軽量エンドポイント)。
- **フロントエンド**:
  - `frontend/src/api/users.ts` に `updatePaidLeaveAutoGrantEnabled(id, enabled)` を追加、
    型は`User`に`paidLeaveAutoGrantEnabled: boolean`を追加。
  - `frontend/src/hooks/useUsers.ts`(存在する場合。無ければ既存の`useUpdateHireDate`相当の
    フックがある場所)に `useUpdatePaidLeaveAutoGrantEnabled` ミューテーションフックを追加。
  - `UserRoleEditPage.tsx`: 入社日・利用開始日の並びに Checkbox
    「有給の自動付与を有効にする」を追加し、変更時に即時保存(既存の他フィールドの
    保存ボタン方式に合わせる。既存コードの`updateHireDate.mutate`と同様の呼び出し形にする)。
  - `PaidLeaveAdminPage.tsx` の `PaidLeaveGrantRulesCard`: 各ルール行に「対象社員」展開ボタンを
    追加し、展開すると`target-users`一覧をテーブル表示、各行にCheckbox
    (`components/ui/checkbox`)で自動付与ON/OFFを切替(切替は`updatePaidLeaveAutoGrantEnabled`
    を呼ぶ。ルールへの所属自体は変更しない、あくまで社員個別のON/OFF)。
  - 一覧・詳細双方とも、無効化されている社員には`Badge`等で「自動付与:無効」を明示する。
- **既存挙動への影響**: マイグレーション適用直後は全社員`true`のため、既存の自動付与挙動は
  変化しない。

## 対象外
- 代休(`GrantScheduledSpecialLeaveHandler`)など有給以外の休暇種別への同様の制御拡張は行わない。
- 無効化の理由・変更履歴を一覧表示するUI(監査画面)は作らない(将来必要なら`stored_events`
  から追える)。
- 付与ルール自体(`paid_leave_grant_rules`)の対象条件(雇用形態/勤務体系)の変更は行わない。
- 一括ON/OFF切替(全社員一括操作)のUIは作らない(1件ずつの切替のみ)。

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`: UC-P002(有給を自動付与する)に、対象者判定条件として
  「`users.paid_leave_auto_grant_enabled = true` の社員のみ」を追記する。また新規ユースケース
  UC-P00X(社員ごとの有給自動付与ON/OFFを設定する)を追加し、2つの操作経路
  (社員編集画面/付与ルール画面の対象社員一覧)を明記する。
- `docs/16-database-schema.md`: `users`テーブル定義に `paid_leave_auto_grant_enabled` 列を追記。
- `docs/17-events.md`: `user.paid_leave_auto_grant_enabled_set` イベントを追記。

## モック・アセット
なし。

## 実装対象
- `backend/database/migrations/`: `users`へのカラム追加マイグレーション。
- `backend/app/Domain/UserManagement/Commands/SetPaidLeaveAutoGrantEnabled.php`
- `backend/app/Domain/UserManagement/Events/PaidLeaveAutoGrantEnabledSet.php`
- `backend/app/Domain/UserManagement/Handlers/SetPaidLeaveAutoGrantEnabledHandler.php`
- `backend/app/Domain/UserManagement/Aggregates/UserAggregate.php`(メソッド追加)
- `backend/app/Domain/UserManagement/Projectors/UserProjector.php`(ハンドラ追加)
- `backend/app/Domain/PaidLeave/Handlers/GrantScheduledPaidLeaveHandler.php`(絞り込み条件追加)
- `backend/app/Http/Controllers/Api/UserController.php`(相当箇所にエンドポイント追加)
- `backend/app/Http/Controllers/Api/PaidLeaveController.php`(target-usersエンドポイント追加)
- `backend/app/Http/Resources/UserResource.php`
- `backend/routes/api.php`
- `frontend/src/api/users.ts`, `frontend/src/api/paidLeave.ts`
- `frontend/src/hooks/`配下の該当フックファイル
- `frontend/src/pages/admin/UserRoleEditPage.tsx`
- `frontend/src/pages/paidLeave/PaidLeaveAdminPage.tsx`
- 上記変更に伴うテスト(backend Feature test、frontend component/page test)

## 検証方法
- backend: `cd backend && php artisan test --filter=PaidLeave`
  および該当のUserController/UserManagement系テスト
- frontend: `cd frontend && npm run test -- UserRoleEditPage PaidLeaveAdminPage`
- 手動確認: 社員編集画面でOFFにした社員が`paid-leave:grant-scheduled`コマンド実行時に
  対象から除外されることをローカルDBで確認。

## レビュー履歴
初版。

## 実装結果
未着手。
