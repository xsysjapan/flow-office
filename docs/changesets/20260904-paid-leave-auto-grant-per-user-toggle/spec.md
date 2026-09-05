# 有給・特別休暇の自動付与のユーザーごと有効/無効設定

ステータス: 完了

## 変更要望(原文)
休暇の自動設定について、現状自動設定ルールはありますが、ユーザーごとには制御ができません。自動設定の有効・無効について、ユーザーごとに設定できるようにしてください。設定のUIはユーザーごとの画面と自動設定ルールでユーザーを設定していくのの両方向から作ってください。

(追加依頼)特別休暇の自動付与についても同様に対応をお願いします。また、受入テスト条件も確認させてください。

## 背景・目的
有給の自動付与(UC-P002)・特別休暇の自動付与(`GrantScheduledSpecialLeaveHandler`)は、
いずれも付与ルール(`paid_leave_grant_rules` / `special_leave_grant_rules`)の対象条件
(雇用形態/勤務体系・入社日等)にマッチする社員全員へ一律で適用される。出向者・休職者・
契約上休暇を個別管理する社員など、ルール条件には合致するが自動付与の対象から外したい社員を
個別に除外する手段が無い。本変更は両休暇種別についてその個別制御(有効/無効の切替)を追加する。

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
- 特別休暇側も構造は同一(`GrantScheduledPaidLeaveHandler`と同じ考え方、
  `backend/app/Domain/SpecialLeave/Handlers/GrantScheduledSpecialLeaveHandler.php`冒頭コメント参照)。
  差分は次の2点:
  1. 特別休暇は`special_leave_types`(誕生日休暇・慶弔休暇・代休等)ごとに
     `special_leave_grant_rules`が独立して存在し(`special_leave_type_id`で紐付く)、
     判定・出勤率計算もルール単位で個別に走る(有給の8割出勤要件とは別の独自ロジック)。
     有給のように「休暇種別が1つ」ではない。
  2. 出勤率判定は有給消化・特別休暇消化のいずれも出勤扱いとする独自ルール(有給側は
     有給消化のみを出勤扱いとする法定要件ベースの判定)。※本変更はこの判定ロジック自体には
     手を入れない。
  対象社員抽出は`GrantScheduledSpecialLeaveHandler::eligibleUsers()`
  (`backend/app/Domain/SpecialLeave/Handlers/GrantScheduledSpecialLeaveHandler.php:117-127`)で、
  有給側とほぼ同一のクエリ(`hire_date`必須・`usage_start_date`到来済み・`work_style_id`一致)。
  こちらもユーザー個別の有効/無効フラグは存在しない。

## 仕様検討

### 論点1: フラグの持たせ方(データ構造)
- 選択肢:
  - A. `users` テーブルに `paid_leave_auto_grant_enabled`(boolean, default true)を追加し、
    `UserHireDateSet` 等と同様にCommand/Eventで更新・Projectorで反映する。特別休暇用にも
    同様に `special_leave_auto_grant_enabled` を追加する(休暇種別ごとに列を分ける)。
  - B. `AttendanceSubmissionReminderExclusion` に倣い、独立した除外記録テーブル
    (`paid_leave_auto_grant_exclusions`: user_id, reason, excluded_by_user_id)を作り、
    「レコードが存在する = 無効」として扱う。特別休暇用にも別テーブルを作る。
  - C. 休暇種別を問わない汎用テーブル(`leave_auto_grant_settings`:
    user_id, leave_type(enum: paid_leave/special_leave), enabled)を1つ新設する。
- 決定: A(`users.paid_leave_auto_grant_enabled` と `users.special_leave_auto_grant_enabled` の
  2列。いずれもCommand/Event経由)。
- 理由: Bの除外理由記録・監査ログ的な性質は本件では不要(単純なON/OFFで十分)。既存の
  `hire_date`/`usage_start_date`と同じ「社員マスタの属性」として扱うのが最も自然で、
  `UserRoleEditPage`の既存編集フローにそのまま乗せられる。Cは休暇種別が増えるたびに
  enum拡張が必要になり、有給・特別休暇で判定ロジックの実装(それぞれの
  `GrantScheduled*Handler::eligibleUsers()`)が既に分かれている実態と合わない
  (汎用テーブルにしても結局ドメインごとに個別クエリで参照することになり、抽象化の恩恵が薄い)。
  2列に分けることで、将来どちらか一方だけ廃止・追加になっても影響範囲が独立する。
  EventStoreを正とする原則1に従い、直接UPDATEではなくCommand→Event
  (`PaidLeaveAutoGrantEnabledSet` / `SpecialLeaveAutoGrantEnabledSet`)→Projectorで
  `users`テーブルへ反映する。
- 未確定・要確認事項: なし(A案で確定)。

### 論点1a: 特別休暇の粒度(休暇種別ごと vs 全体で1つ)
- 選択肢:
  - A. `special_leave_auto_grant_enabled` を1列とし、社員が持つ**全ての**特別休暇種別
    (誕生日休暇・慶弔休暇・代休等)の自動付与を一括でON/OFFする。
  - B. `special_leave_type_id`ごとに個別のON/OFFを持てるようにする
    (中間テーブル`user_special_leave_auto_grant_settings`: user_id, special_leave_type_id,
    enabled)。
- 決定: A(全体で1つのフラグ)。
- 理由: 有給と同じ「例外的に自動化対象から外したい社員がいる」というユースケースであれば、
  多くの場合「その社員は特別休暇の自動付与を一切使わない(手動運用に切り替える)」という
  全体単位の判断になると想定され、有給側の設計(論点1)と対称にした方が画面・API・実装が
  一貫する。種別ごとの個別制御はYAGNI(現時点でその粒度の要望は無い)。
- 未確定・要確認事項: 「特別休暇の種別ごとに自動付与のON/OFFを分けたい」という要望が
  今後出た場合はB案への拡張(列→中間テーブルへの移行)が必要になる。今回はA案で確定する。

### 論点2: デフォルト値と既存社員への影響
- 選択肢:
  - A. デフォルト `true`(有効)。既存の全社員は挙動が変わらない。
  - B. デフォルト `false`(無効)。明示的に有効化した社員のみ自動付与対象。
- 決定: A(デフォルト`true`)。
- 理由: 本変更は「例外的に自動付与から外したい社員がいる」ことへの対応であり、既存の
  全社員一律自動付与という現行挙動を破壊しない後方互換性を優先する。

### 論点3: 判定ロジックへの組み込み位置
- 選択肢:
  - A. `GrantScheduledPaidLeaveHandler::eligibleUsers()` / 
    `GrantScheduledSpecialLeaveHandler::eligibleUsers()` それぞれのクエリに
    `->where('paid_leave_auto_grant_enabled', true)` /
    `->where('special_leave_auto_grant_enabled', true)` を追加する。
  - B. ルールの`work_style_id`と同様に、ルールごとに対象社員リストを明示管理する
    (ルール側に個別許可リストを持たせる)。
- 決定: A。
- 理由: 要望は「ユーザーごとのON/OFF」であり「ルールごとの対象社員の明示管理」ではない。
  Bは既存の対象条件(雇用形態/勤務体系ベース)の設計思想と重複し、ルールが複数ある場合に
  同じ社員を全ルールへ都度登録する手間が生じる。Aはルール条件通過後の最終ゲートとして
  シンプルに機能し、既存ロジックへの影響も両ハンドラそれぞれ1行で済む。
- 未確定・要確認事項: なし。

### 論点4: API設計
- 選択肢:
  - A. 既存の `UserController`(または相当コントローラ)に
    `PUT /api/users/{user}/paid-leave-auto-grant-enabled` と
    `PUT /api/users/{user}/special-leave-auto-grant-enabled` を追加し、`hire_date`更新と
    同じ場所に置く。
  - B. `PaidLeaveController` / `SpecialLeaveController` 側に社員設定用エンドポイントを
    それぞれ追加する。
- 決定: A。
- 理由: 対象はいずれも`users`テーブルの属性であり、既存の`hire_date`/`usage_start_date`更新
  エンドポイントと同じ責務・同じ権限(`user.update`)で扱うのが一貫している。付与ルール画面
  (論点5)からもこのAPIを叩く。
- 未確定・要確認事項: なし。

### 論点5: UIの「両方向」の実現方法
- 選択肢:
  - A. (a)社員個別編集画面`UserRoleEditPage.tsx`にチェックボックス
    「有給の自動付与を有効にする」「特別休暇の自動付与を有効にする」を追加(hire_date等と
    同じ並び)。
    (b)付与ルール管理画面`PaidLeaveAdminPage.tsx`の`PaidLeaveGrantRulesCard`、および
    `frontend/src/pages/specialLeave/SpecialLeaveAdminPage.tsx`の
    `SpecialLeaveGrantRulesCard`(`Card title="自動付与ルール"`、L126-)に
    「対象社員一覧」セクションを追加し、各ルールの`work_style_id`条件に現在マッチしている
    社員を一覧表示、各行にチェックボックスで自動付与ON/OFFを切替可能にする(同じAPIを叩く)。
  - B. ルール画面側は一覧表示のみ(トグルはUserRoleEditPageのみ)。
- 決定: A。
- 理由: ユーザーの要望が「ユーザーごとの画面」と「自動設定ルールでユーザーを設定していく」の
  両方向を明示しているため、両画面に同じ設定を反映する導線を用意する。裏側の設定値は
  `users.paid_leave_auto_grant_enabled` / `users.special_leave_auto_grant_enabled` 各1本
  なので、二重管理にはならない(表示元が2箇所あるだけ)。`SpecialLeaveAdminPage.tsx`は
  `PaidLeaveAdminPage.tsx`と同一のCard構成パターン(`SpecialLeaveGrantRulesCard`)を持つ
  ことをコードで確認済みのため、同じ実装方針をそのまま適用できる。
- 未確定・要確認事項: なし。

### 論点6: 編集画面の保存方式・対象社員一覧の検索
- 選択肢(保存方式):
  - A. 即時保存(チェック切替と同時にAPI呼び出し)。
  - B. 既存の入社日・利用開始日と同じ「入力→保存ボタン押下で確定」方式。
- 決定: B。
- 理由: `UserRoleEditPage.tsx`内の他フィールドと操作方式を統一するため(ユーザー確認により
  決定)。
- 選択肢(対象社員一覧の検索):
  - A. 検索なしのシンプルな一覧のみ。
  - B. 社員名で絞り込む検索ボックスを追加する。
- 決定: B。
- 理由: 社員数が多い場合に一覧から目的の社員を探しやすくするため(ユーザー確認により決定)。
  既存の`UserPicker`と同様、取得済み一覧に対するクライアントサイドの絞り込みとする
  (サーバー側の検索パラメータは追加しない。対象社員数はルール単位で絞られており、
  全社員一覧より小規模なため)。
  なお、対象社員一覧内のON/OFF切替そのものは(編集画面と異なり)即時反映とする
  (1クリックで完結する単純な操作のため、保存ボタンを挟む必要性が薄い)。
- 未確定・要確認事項: なし。

## 仕様確定事項(まとめ)
- **DB**: `users`テーブルに以下2列を追加するマイグレーションを作成。
  - `paid_leave_auto_grant_enabled boolean not null default true`
  - `special_leave_auto_grant_enabled boolean not null default true`
- **ドメイン(backend/app/Domain/UserManagement)**:
  - Command: `SetPaidLeaveAutoGrantEnabled(userId, enabled, changedByUserId)` /
    `SetSpecialLeaveAutoGrantEnabled(userId, enabled, changedByUserId)`
  - Event: `PaidLeaveAutoGrantEnabledSet(enabled, changedByUserId)`
    (`user.paid_leave_auto_grant_enabled_set`) /
    `SpecialLeaveAutoGrantEnabledSet(enabled, changedByUserId)`
    (`user.special_leave_auto_grant_enabled_set`)
  - Handler: `SetPaidLeaveAutoGrantEnabledHandler` / `SetSpecialLeaveAutoGrantEnabledHandler`
    (`UserHireDateSet`系と同じ構成。存在確認のみ、業務ルール上の禁則は無し)
  - `UserAggregate` に `setPaidLeaveAutoGrantEnabled(bool, string)` /
    `setSpecialLeaveAutoGrantEnabled(bool, string)` を追加
  - `UserProjector::onPaidLeaveAutoGrantEnabledSet()` /
    `onSpecialLeaveAutoGrantEnabledSet()` で `users`テーブルの各列を更新
- **判定ロジック**:
  - `GrantScheduledPaidLeaveHandler::eligibleUsers()`
    (`backend/app/Domain/PaidLeave/Handlers/GrantScheduledPaidLeaveHandler.php:114-130`)の
    クエリに `->where('paid_leave_auto_grant_enabled', true)` を追加する。
  - `GrantScheduledSpecialLeaveHandler::eligibleUsers()`
    (`backend/app/Domain/SpecialLeave/Handlers/GrantScheduledSpecialLeaveHandler.php:117-127`)の
    クエリに `->where('special_leave_auto_grant_enabled', true)` を追加する
    (特別休暇種別を問わず一括で判定。論点1a参照)。
- **API**:
  - `PUT /api/users/{user}/paid-leave-auto-grant-enabled`(body: `{ enabled: boolean }`)
  - `PUT /api/users/{user}/special-leave-auto-grant-enabled`(body: `{ enabled: boolean }`)
  - いずれも権限は既存の`hire_date`更新エンドポイントと同じ `user.update`。
  - `UserResource` のレスポンスに `paid_leave_auto_grant_enabled` / 
    `special_leave_auto_grant_enabled` を含める。
  - 付与ルール対象社員一覧取得用に以下を追加(付与済み日数等の計算は不要、一覧表示専用の
    軽量エンドポイント):
    - `GET /api/paid-leave/grant-rules/{rule}/target-users` →
      `id, name, work_style, paid_leave_auto_grant_enabled`
    - `GET /api/special-leave/grant-rules/{rule}/target-users` →
      `id, name, work_style, special_leave_auto_grant_enabled`
    いずれもそのルールの`work_style_id`条件(未設定なら全社員)にマッチし
    `hire_date`設定済みの社員を返す。
- **フロントエンド**:
  - `frontend/src/api/users.ts` に `updatePaidLeaveAutoGrantEnabled(id, enabled)` /
    `updateSpecialLeaveAutoGrantEnabled(id, enabled)` を追加、型は`User`に
    `paidLeaveAutoGrantEnabled: boolean` / `specialLeaveAutoGrantEnabled: boolean` を追加。
  - `frontend/src/hooks/`配下(既存の`useUpdateHireDate`相当のフックがある場所)に
    `useUpdatePaidLeaveAutoGrantEnabled` / `useUpdateSpecialLeaveAutoGrantEnabled`
    ミューテーションフックを追加。
  - `UserRoleEditPage.tsx`: 入社日・利用開始日の並びに Checkbox
    「有給の自動付与を有効にする」「特別休暇の自動付与を有効にする」を追加する。
    保存方式は既存の入社日・利用開始日と同じ「入力→保存ボタン押下で確定」方式に統一する
    (チェック状態はローカルstateで保持し、既存の`updateHireDate.mutate`と同様に
    保存ボタン押下時に`update*AutoGrantEnabled.mutate`を呼ぶ。画面内の操作方式を統一する)。
  - `PaidLeaveAdminPage.tsx` の `PaidLeaveGrantRulesCard`、および
    `SpecialLeaveAdminPage.tsx` の `SpecialLeaveGrantRulesCard`: 各ルール行に
    「対象社員」展開ボタンを追加し、展開すると`target-users`一覧をテーブル表示する。
    一覧上部に社員名での検索ボックス(`components/ui/input`、既存の`UserPicker`と同様の
    クライアントサイド絞り込み)を設置し、各行にCheckbox(`components/ui/checkbox`)で
    自動付与ON/OFFを切替(この一覧上のCheckboxはトグル即時反映とする。編集画面と異なり
    1件ずつの単純なON/OFF操作であり、保存ボタンを介す必要性が薄いため。切替はそれぞれ
    対応する`update*AutoGrantEnabled`を呼ぶ。ルールへの所属自体は変更しない、あくまで
    社員個別のON/OFF)。
  - 一覧・詳細双方とも、無効化されている社員には`Badge`等で「自動付与:無効」を明示する。
- **既存挙動への影響**: マイグレーション適用直後は全社員`true`のため、既存の自動付与挙動は
  有給・特別休暇いずれも変化しない。

## 対象外
- 特別休暇の種別(`special_leave_type_id`)ごとの個別ON/OFF(論点1a参照。全種別一括の
  ON/OFFのみ実装する)。
- 代休(`requires_grant=false`、事前付与不要の特別休暇種別)は元々自動付与の対象外の運用
  ロジックであり、本変更でも扱いは変えない(`special_leave_auto_grant_enabled`は
  自動付与ルールが走る種別にのみ影響する)。
- 無効化の理由・変更履歴を一覧表示するUI(監査画面)は作らない(将来必要なら`stored_events`
  から追える)。
- 付与ルール自体(`paid_leave_grant_rules` / `special_leave_grant_rules`)の対象条件
  (雇用形態/勤務体系)の変更は行わない。
- 一括ON/OFF切替(全社員一括操作)のUIは作らない(1件ずつの切替のみ)。
- 有給・特別休暇以外の休暇(代休申請等、承認ワークフロー経由や申請不要処理のもの)は対象外。

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`: UC-P002(有給を自動付与する)に、対象者判定条件として
  「`users.paid_leave_auto_grant_enabled = true` の社員のみ」を追記する。また新規ユースケース
  UC-P00X(社員ごとの有給自動付与ON/OFFを設定する)を追加し、2つの操作経路
  (社員編集画面/付与ルール画面の対象社員一覧)を明記する。
- 特別休暇には独立したユースケース文書は無く、`docs/09-usecases-paid-leave.md`内で
  UC-P008/UC-P009等の注記として「同じ構造を特別休暇にも実装する」という形で言及されている
  (確認済み)。そのため本変更も同ファイルのUC-P002注記部分に、有給と併記する形で
  「特別休暇についても`users.special_leave_auto_grant_enabled = true`の社員のみが
  自動付与対象」である旨と、新規ユースケース(社員ごとの有給/特別休暇自動付与ON/OFFを
  設定する。両休暇種別を1つのUC番号にまとめる)を追記する。
- `docs/16-database-schema.md`: `users`テーブル定義に `paid_leave_auto_grant_enabled` /
  `special_leave_auto_grant_enabled` の2列を追記。
- `docs/17-events.md`: `user.paid_leave_auto_grant_enabled_set` /
  `user.special_leave_auto_grant_enabled_set` の2イベントを追記。

## モック・アセット
なし。

## 実装対象
- `backend/database/migrations/`: `users`への2カラム(`paid_leave_auto_grant_enabled`,
  `special_leave_auto_grant_enabled`)追加マイグレーション。
- `backend/app/Domain/UserManagement/Commands/SetPaidLeaveAutoGrantEnabled.php`
- `backend/app/Domain/UserManagement/Commands/SetSpecialLeaveAutoGrantEnabled.php`
- `backend/app/Domain/UserManagement/Events/PaidLeaveAutoGrantEnabledSet.php`
- `backend/app/Domain/UserManagement/Events/SpecialLeaveAutoGrantEnabledSet.php`
- `backend/app/Domain/UserManagement/Handlers/SetPaidLeaveAutoGrantEnabledHandler.php`
- `backend/app/Domain/UserManagement/Handlers/SetSpecialLeaveAutoGrantEnabledHandler.php`
- `backend/app/Domain/UserManagement/Aggregates/UserAggregate.php`(メソッド追加)
- `backend/app/Domain/UserManagement/Projectors/UserProjector.php`(ハンドラ追加)
- `backend/app/Domain/PaidLeave/Handlers/GrantScheduledPaidLeaveHandler.php`(絞り込み条件追加)
- `backend/app/Domain/SpecialLeave/Handlers/GrantScheduledSpecialLeaveHandler.php`(絞り込み条件追加)
- `backend/app/Http/Controllers/Api/UserController.php`(相当箇所にエンドポイント2本追加)
- `backend/app/Http/Controllers/Api/PaidLeaveController.php`(target-usersエンドポイント追加)
- `backend/app/Http/Controllers/Api/SpecialLeaveController.php`(target-usersエンドポイント追加)
- `backend/app/Http/Resources/UserResource.php`
- `backend/routes/api.php`
- `frontend/src/api/users.ts`, `frontend/src/api/paidLeave.ts`, `frontend/src/api/specialLeave.ts`
- `frontend/src/hooks/`配下の該当フックファイル
- `frontend/src/pages/admin/UserRoleEditPage.tsx`
- `frontend/src/pages/paidLeave/PaidLeaveAdminPage.tsx`
- `frontend/src/pages/specialLeave/SpecialLeaveAdminPage.tsx`
- 上記変更に伴うテスト(backend Feature test、frontend component/page test)

## 検証方法
- backend: `cd backend && php artisan test --filter=PaidLeave`、
  `php artisan test --filter=SpecialLeave`、および該当のUserController/UserManagement系テスト
- frontend: `cd frontend && npm run test -- UserRoleEditPage PaidLeaveAdminPage SpecialLeaveAdminPage`
- 手動確認: 社員編集画面でOFFにした社員が`paid-leave:grant-scheduled` /
  `special-leave:grant-scheduled`(コマンド名は実装時に`routes/console.php`で確認)実行時に
  対象から除外されることをローカルDBで確認。

## 受入テスト条件

以下をすべて満たすことを受入条件とする。

1. **既存挙動の非破壊**
   - マイグレーション適用直後、既存の全社員は`paid_leave_auto_grant_enabled = true` /
     `special_leave_auto_grant_enabled = true`になっている。
   - 本変更前と同じ条件(付与ルールの対象条件・記念日・出勤率)を満たす社員は、
     フラグをOFFにしない限り従来どおり自動付与される(回帰なし)。

2. **有給自動付与のON/OFF(ユーザー編集画面から)**
   - `UserRoleEditPage.tsx`で対象社員の「有給の自動付与を有効にする」チェックを外し、
     保存ボタンを押すと(チェックを外しただけでは保存されない)`users.paid_leave_auto_grant_enabled`
     が`false`になる。
   - その状態で`paid-leave:grant-scheduled`(または`GrantScheduledPaidLeave`コマンド)を実行しても、
     当該社員が付与ルールの他の条件(記念日・出勤率等)を満たしていても`paid_leave_grants`が
     作成されない。
   - 再度チェックを入れて保存すると`true`に戻り、以降は通常どおり付与対象に戻る。

3. **特別休暇自動付与のON/OFF(ユーザー編集画面から)**
   - 同様に「特別休暇の自動付与を有効にする」チェックのOFF/ONで
     `users.special_leave_auto_grant_enabled`が切り替わる。
   - OFFの間は、その社員に対して**どの特別休暇種別のルールからも**`special_leave_grants`が
     作成されない(種別ごとの個別制御は対象外・論点1a参照)。

4. **付与ルール管理画面からの設定(逆方向)**
   - `PaidLeaveAdminPage.tsx`の付与ルール一覧で、あるルールの「対象社員」を開くと、
     そのルールの`work_style_id`条件にマッチする社員一覧(現在のON/OFF状態付き)が表示される。
   - 一覧上でチェックボックスを切り替えると、(2)と同じAPIが呼ばれ`users`テーブルが更新される
     (`UserRoleEditPage.tsx`側の表示にも反映される。設定値は1つなので画面をまたいで一致する)。
   - `SpecialLeaveAdminPage.tsx`の付与ルール一覧でも同様に対象社員一覧とON/OFF切替ができる。
   - 対象社員一覧の検索ボックスに社員名を入力すると、一覧がクライアントサイドで絞り込まれる。
   - 一覧上のチェックボックス切替は(編集画面と異なり)保存ボタン無しで即時に反映される。

5. **無効化されている社員の可視化**
   - `UserRoleEditPage.tsx`および付与ルール管理画面の対象社員一覧の双方で、無効化されている
     社員には「自動付与:無効」等のバッジが表示され、一見して分かる。

6. **権限**
   - `user.update`権限を持たない利用者は、上記トグルAPI(`PUT
     /api/users/{user}/paid-leave-auto-grant-enabled` /
     `.../special-leave-auto-grant-enabled`)を呼んでも403になる。

7. **手動付与への非干渉**
   - `paid_leave_auto_grant_enabled` / `special_leave_auto_grant_enabled`が`false`の社員でも、
     管理者による手動付与(`ManualGrantCard`からの`GrantPaidLeave` / `GrantSpecialLeave`)は
     引き続き実行できる(本変更が制御するのは自動付与バッチの対象範囲のみで、手動付与機能には
     影響しない)。

8. **テスト**
   - 上記2・3のシナリオ(フラグON/OFFそれぞれで自動付与バッチを実行した結果)を検証する
     backendのFeatureテストが追加されている。
   - `UserRoleEditPage`・`PaidLeaveAdminPage`・`SpecialLeaveAdminPage`のcomponent/page
     テストが、チェックボックスの表示・切替操作をカバーしている。

## レビュー履歴
- 初版。
- 特別休暇の自動付与についても同様の制御を追加する依頼を受け、対象範囲を有給+特別休暇に拡張。
  特別休暇特有の論点(論点1a: 種別ごとではなく全体で1フラグ)を追加し、受入テスト条件セクションを
  新設。
- ユーザー確認により論点6(編集画面の保存方式=保存ボタン方式に統一、対象社員一覧に検索
  ボックスを追加)を確定。「ドキュメントへの影響」を、特別休暇の独立ユースケース文書は
  存在しない(`docs/09-usecases-paid-leave.md`に併記されている)という調査結果に基づき修正。

## 実装結果

ブランチ `claude/vacation-auto-config-per-user-ljr6qv` に実装済み。

**バックエンド**:
- マイグレーション `backend/database/migrations/2026_09_05_000000_add_auto_grant_enabled_flags_to_users_table.php`
  で `users.paid_leave_auto_grant_enabled` / `special_leave_auto_grant_enabled`(共にdefault true)を追加。
- `App\Domain\UserManagement` に `SetPaidLeaveAutoGrantEnabled`/`SetSpecialLeaveAutoGrantEnabled`
  のCommand/Event/Handlerを追加し、`UserAggregate`/`UserProjector`に反映。
  `config/domain.php`(Command→Handler)・`config/event-sourcing.php`(イベント別名)に登録。
- `GrantScheduledPaidLeaveHandler`/`GrantScheduledSpecialLeaveHandler`の`eligibleUsers()`に
  それぞれのフラグでの絞り込みを追加。
- API: `PUT /users/{user}/paid-leave-auto-grant-enabled` /
  `.../special-leave-auto-grant-enabled`(`UserController`、`user.update`権限)、
  `GET /paid-leave/grant-rules/{rule}/target-users` / `special-leave/grant-rules/{rule}/target-users`
  (`PaidLeaveController`/`SpecialLeaveController`)。`UserResource`に両フラグを追加。
- `docs/16-database-schema.md`・`docs/17-events.md`・`docs/09-usecases-paid-leave.md`を更新。
- 実装レビューで見つけた不具合を修正: `config/domain.php`のCommand→Handler登録漏れ(PUTが500に
  なっていた)、`User`モデルの新カラムに`boolean`castが無くレスポンス上0/1に化けていた点、
  `User::factory()->create()`直後のモデルにDBの`default(true)`が反映されないEloquentの挙動への
  対応(`$attributes`にデフォルト値を設定)。
- テスト: `php artisan test` 922件全て成功。Laravel Pint(フォーマッタ)も通過。

**フロントエンド**:
- `frontend/src/api/users.ts`・`useUsers.ts`に`update{PaidLeave,SpecialLeave}AutoGrantEnabled`の
  クライアント関数・ミューテーションフックを追加。
- `UserRoleEditPage.tsx`: 入社日・利用開始日と同じ「保存ボタン方式」で有給/特別休暇の自動付与
  チェックボックスを追加、無効時は「自動付与:無効」バッジを表示。
- `PaidLeaveAdminPage.tsx`/`SpecialLeaveAdminPage.tsx`: 付与ルールごとの「対象社員」展開セクション
  (社員名検索ボックス付き、チェックボックスは即時反映)を追加。
- テスト: `npm run test -- UserRoleEditPage PaidLeaveAdminPage SpecialLeaveAdminPage` 27件全て成功、
  `npx tsc -b`エラー無し、`npm run lint`(oxlint)は既存の無関係な警告のみでエラー無し。

**未対応・残課題**: なし(受入テスト条件の各項目は上記実装でカバーしている)。
