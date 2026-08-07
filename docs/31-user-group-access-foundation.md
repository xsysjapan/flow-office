# 31. ユーザー・グループ・権限・利用機能管理基盤

## 31.1 目的と対象範囲

勤怠・申請・経費等の各業務機能から共通利用する、次の管理基盤を定義する。

- ユーザーとMicrosoft Entra ID・外部HR等の外部IDの対応
- 組織、雇用区分、プロジェクト、委員会等を統一的に扱うグループ
- 現在の所属と、将来日付で適用する所属変更予約
- Feature（機能を利用できるか）とPermission（機能内で何を操作できるか）
- ユーザーまたはグループに対する、スコープ付きRoleの割当
- 外部HRとの項目別の管理責任分担、同期差分確認
- 特権操作と同期・変更履歴の監査

対象は最大100人程度の企業とする。高機能なIAMや人事ワークフローを再現せず、専門知識が
なくても運用できる範囲に限定する。

## 31.2 設計原則

### 許可モデル

- FeatureとPermissionは初期状態をすべてOFFとし、明示的に付与されたものだけを有効にする。
- 有効なPermissionは、ユーザーへの直接Role割当と、所属グループ経由のRole割当の和集合とする。
- 明示的Deny、親グループからの権限継承、自由記述の条件式、AWS IAM互換ポリシーは導入しない。
- Featureが無効なら、Permissionを持っていても画面・URL・API・MCPのすべてで利用不可とする。
- FeatureとPermissionの判定は必ずサーバー側でも行い、画面表示だけに依存しない。

```text
effective_features(user) =
  union(features assigned to active groups the user belongs to)
  - user feature suspensions

effective_permissions(user, resource) =
  union(valid direct role assignments)
  + union(valid role assignments inherited through active memberships)
  filtered by assignment scope
```

Featureは「その機能自体を利用できるか」だけを表す。固定時間制、フレックス、シフト、
3交代制、残業上限等の業務設定値はFeatureに含めず、既存の勤務形態・業務ルールで管理する。

### Event Sourcingとの整合

原則として、状態変更はCommand → CommandHandler → `stored_events`を経由する。本書のテーブル名は
書き込みモデルまたはProjectionの候補であり、権限・Featureのキャッシュは派生データとして正本に
しない。例外として`system_settings`は管理者専用APIから直接更新するが、同一トランザクションで
変更内容を表す監査用イベントを`stored_events`へ追記する。これ以外の例外は個別仕様で明示する。

## 31.3 ユーザーと外部ID

`User`は人物または利用主体を表し、内部主キーには不変のUUIDを用いる。社員番号、メールアドレス、
Microsoft Object IDを主キーにしない。正社員、契約社員、派遣、パート、役員、外部承認者、
外部ベンダー、インターン、必要に応じたサービスアカウントを扱えるものとする。

```text
User
- id: UUID
- display_name
- email
- account_status: PENDING | ACTIVE | SUSPENDED | LEAVE | RETIRED | DISABLED
- source_type: LOCAL | MICROSOFT_ENTRA | EXTERNAL_HR | IMPORT
- created_at
- updated_at

ExternalIdentity
- id
- user_id
- provider
- external_tenant_id
- external_subject_id
- external_code
- email
- status
- linked_at
- last_synced_at
```

Microsoft Entra IDでは`external_subject_id`にObject ID、`external_tenant_id`にTenant IDを保持する。
メールアドレスは不変識別子として使用しない。Money Forwardや外部HRのIDも`ExternalIdentity`で
汎用的に管理し、サービス固有列を`users`へ追加しない。同一provider内の外部ID重複と、許可されて
いないTenant IDからのリンクを拒否する。リンク・解除・再リンクは監査対象とする。

退職・無効化したユーザーを物理削除せず、過去の勤怠、申請、承認、所属、権限履歴を参照可能にする。

## 31.4 項目別の管理責任

ユーザー項目または項目グループごとに管理元を保持する。

```text
FieldAuthority
- id
- tenant_id
- field_key
- authority_type: LOCAL | EXTERNAL_HR
- provider (nullable)
- updated_at
```

例として氏名・社員番号・所属・在籍状態を外部HR、Microsoft Object ID・働き方・外部サービスIDを
本システム管理にできる。外部管理項目は通常画面と更新APIの両方で編集不可とし、管理元と最終同期
日時を表示する。将来の連携解除や管理元変更は可能な構造とする。

## 31.5 グループと所属

組織、雇用区分、プロジェクト、委員会、拠点、承認担当者集合等を`Group`で統一管理する。

```text
GroupType
- id
- code: ORGANIZATION | EMPLOYMENT | PROJECT | COMMITTEE | LOCATION | APPROVAL | CUSTOM
- name
- is_system
- status
- membership_limit_type
- max_memberships_per_user
- primary_membership_required
- max_primary_memberships

Group
- id
- tenant_id
- group_type_id
- name
- code
- description
- parent_group_id (nullable)
- status: ACTIVE | PLANNED_INACTIVE | INACTIVE

Membership
- id
- user_id
- group_id
- membership_kind: PRIMARY | SECONDARY | MEMBER | TEMPORARY | OBSERVER
- is_primary
- created_at
- created_by
```

システムプリセットのGroupTypeは削除不可とし、名称と表示順のみ変更可能、内部コードは変更不可と
する。独自GroupTypeは追加・編集・廃止できる。親子階層は主に組織用で、親は最大1つ、循環参照と
複数親を禁止する。階層そのものによる権限継承は行わない。

所属数制約はGroupTypeごとに設定する。例として組織は複数所属可・主所属最大1、雇用区分は最大1、
プロジェクトと委員会は複数可とする。現在のMembershipだけを制約検証の対象とせず、予約適用後の
状態をシミュレーションしてから変更セットを受理する。無効なGroupへの新規所属は許可しない。

## 31.6 将来日付の所属変更

未来の異動・所属変更は現在のMembershipに開始・終了日を混在させず、変更セットとして管理する。

```text
MembershipChangeSet
- id
- user_id
- effective_at
- source_type: MANUAL | CSV_IMPORT | EXTERNAL_HR | API
- status: DRAFT | SCHEDULED | APPLIED | FAILED | CANCELLED
- created_by
- created_at
- applied_at
- cancelled_at
- note

MembershipChangeItem
- id
- change_set_id
- operation: ADD | REMOVE | REPLACE | SET_PRIMARY
- group_type_id
- from_group_id (nullable)
- to_group_id (nullable)
- target_group_id (nullable)
- is_primary
```

UIの「置換」は内部で必要に応じて削除と追加へ展開する。適用時にはユーザー単位でロックし、同一
トランザクションで全明細を適用する。一部適用は禁止し、失敗時は全体をロールバックして`FAILED`
とする。適用順は削除、追加、主所属設定とし、最後に所属数と主所属制約を再検証する。予約は適用前
なら変更・取消でき、適用後の訂正は新しい変更セットで行う。適用後はFeature・Permissionの
キャッシュを無効化する。

## 31.7 Feature

```text
Feature
- id
- code
- name
- parent_feature_id (nullable)
- status

GroupFeatureAssignment
- id
- group_id
- feature_id
- assigned_by
- assigned_at

UserFeatureSuspension
- id
- user_id
- feature_id
- reason
- starts_at (nullable)
- ends_at (nullable)
- created_by
```

Featureはグループにだけ付与し、複数グループの結果を和集合にする。ユーザーへの直接Feature付与は
行わない。例外的な個人停止だけを`UserFeatureSuspension`で表現する。親FeatureをONにした場合は
子Featureを初期選択するが、保存状態は各Featureの明示的な割当として扱い、親子の暗黙継承に
依存しない。Featureには業務設定値入力欄を設けない。

## 31.8 Role・Permission・Scope

Permissionは`Resource.Action`（例: `attendance.read`, `attendance.update`,
`user.manage`）で表し、画面では業務カテゴリ別チェックボックスとして表示する。自由な条件式は
入力させない。

```text
Role
- id
- tenant_id (nullable: system role)
- name
- description
- is_system
- status

Permission
- id
- code
- resource
- action
- description

RolePermission
- role_id
- permission_id

RoleAssignment
- id
- subject_type: USER | GROUP
- subject_id
- role_id
- scope_type: GLOBAL | GROUP | SELF | APPROVAL_TASK
- scope_group_id (nullable)
- include_descendants
- starts_at (nullable)
- ends_at (nullable)
- status
- assigned_by
```

Roleはユーザーまたはグループへ直接付与できる。`GLOBAL`は意図せず選ばれないよう明示選択を必須に
し、`GROUP`では対象グループと配下を含むかを指定する。対象Group未指定を全社扱いにしない。
システムRoleは削除不可・内部識別子変更不可、企業独自Roleは追加・編集・廃止可能とする。

「承認できるPermission」と実際の承認担当者は分離する。Permissionは承認操作の可否だけを表し、
個々の申請の実担当者は申請時に生成される承認タスクで決定する。異動時には権限の付け替えではなく、
必要に応じて承認ルートを変更でき、その履歴を残す。

## 31.9 管理画面

- ユーザー一覧: 氏名、社員番号、メール、Microsoft連携状態、主所属、雇用区分、在籍状態、
  管理元、Feature概要を表示し、Group・GroupType・未連携・外部HR管理等で検索する。
- ユーザー詳細: 基本情報、外部ID、現在所属、予約変更、働き方、有効Feature、
  有効Role/Permission、個別停止、変更履歴を分けて表示する。
- グループ管理: GroupType別画面から基本情報、メンバー、Feature、Role、管理スコープ、履歴を確認する。
- Role管理: Permissionを業務カテゴリ別チェックボックスで編集し、Resource/Action/Conditionの
  生入力UIは作らない。
- RoleAssignment: 付与先、Role、対象範囲、配下を含むか、有効期間を選び、設定結果を自然文で
  プレビューする。

## 31.10 外部HR同期

初期対応はCSV差分取込とし、手動確認後に適用する。後続で定期API同期とWebhookへ拡張できる構造に
する。外部HR由来の将来日付の所属変更は`MembershipChangeSet`として登録し、適用日は手動予約と
同じ処理を使う。外部HRが現在値しか提供しない場合は、現在との差分から即時適用の変更セットを
生成してよい。同期結果と失敗理由を監査ログに残す。

## 31.11 監査とセキュリティ

ユーザー、外部ID、Group/GroupType、Membership、変更セット、Role/RoleAssignment、Feature、
個別停止、外部HR同期、承認ルート、システム設定の変更を監査対象とする。監査の正本は
`stored_events`とし、専用の監査テーブルやProjectionは作らない。監査画面は`stored_events`を
直接検索する。検索精度・検索性能に関する追加要件は設けない。

各監査対象イベントのpayloadまたはmetadataには、可能な範囲で操作者、日時、対象種別、対象ID、
操作種別、変更前後、理由、リクエストID、同期元を含める。`stored_events`は物理削除・改変しない。

- システム管理者を0人にできず、最後のシステム管理者を無効化できない。
- 自分自身への特権Role付与は企業設定により禁止できる。
- 外部HR管理項目をAPIからも更新不可にする。
- ChangeSet適用をトランザクション化し、Feature/Permission判定結果を変更後に再計算する。

## 31.12 推奨エンティティ

```text
User, UserProfile, ExternalIdentity, FieldAuthority
Group, GroupType, Membership, MembershipHistory
MembershipChangeSet, MembershipChangeItem
Role, Permission, RolePermission, RoleAssignment
Feature, GroupFeatureAssignment, UserFeatureSuspension
stored_events（監査ログとして直接検索）
```

`RoleAssignment`の付与先をポリモーフィックにする場合も、Laravelの汎用Morphを無条件に採用せず、
外部キー制約、可読性、検索性を比較して決める。初期対象はUserとGroupだけとする。

## 31.13 集約設計

テーブルや管理画面をそのまま集約境界にせず、同一トランザクションで守る不変条件ごとに次の
集約を定義する。集約間の参照はIDに限定し、他集約の現在状態が必要な検証はCommandHandlerが
読み取りモデルから取得した事実を集約へ渡す。

### UserIdentity集約

- 集約ID: `user_id`
- 管理対象: Userの状態、ExternalIdentity、FieldAuthority
- 不変条件:
  - User IDは変更しない。
  - 同じprovider・Tenant ID・Subject IDを複数Userへリンクしない。
  - 外部管理項目を通常のユーザー更新Commandから変更しない。
  - 退職・無効化してもUserを物理削除しない。
- 主なイベント: `external_identity.linked`、`external_identity.unlinked`、
  `user.field_authority_changed`

外部IDのシステム全体での一意性は、Handlerでの事前検証に加えてDBのunique制約でも保証する。

### Group集約

- 集約ID: `group_id`
- 管理対象: Groupの基本情報と親Group
- 不変条件:
  - 親Groupは最大1つ。
  - 自身または子孫を親にできない。
  - INACTIVEなGroupを新規所属・新規割当の対象にできない。
  - システムGroupの内部コードを変更・削除できない。
- 主なイベント: `group.created`、`group.updated`、`group.inactivated`

GroupTypeは独立したマスタ集約とし、システム種別の内部コード、所属数制約、主所属制約を管理する。

### UserMembership集約

- 集約ID: `user_id`
- 管理対象: 1人の現在所属の集合
- 不変条件:
  - 同一Groupへの重複所属を禁止する。
  - GroupTypeごとの最大所属数と最大主所属数を超えない。
  - 主所属必須のGroupTypeでは適用後も主所属を1つ以上維持する。
  - 複数明細の適用は全成功または全失敗とし、一部適用しない。
- 主なイベント: `membership.added`、`membership.removed`、`membership.primary_changed`

所属数制約はGroup単位ではなく「1人の所属集合」に対する制約であるため、Group集約へ含めない。

### MembershipChangeSet集約

- 集約ID: `change_set_id`
- 管理対象: 適用日時、変更明細、状態遷移
- 状態遷移: `DRAFT → SCHEDULED → APPLIED | FAILED`、および適用前の`CANCELLED`
- 不変条件:
  - APPLIED、FAILED、CANCELLEDから内容を変更しない。
  - 適用時は対象UserMembership集約をユーザー単位でロックする。
  - 適用後状態をシミュレーションしてからイベントを永続化する。
- 主なイベント: `membership_change_set.created`、`scheduled`、`applied`、`failed`、`cancelled`

ChangeSet自身の状態遷移と、UserMembershipへの実際の所属変更は同一DBトランザクションで永続化する。

### GroupAccess集約

- 集約ID: `group_id`
- 管理対象: Groupに明示的に付与されたFeatureの集合
- 不変条件:
  - INACTIVEなGroup・Featureへ新規付与しない。
  - 親Feature・親Groupから暗黙継承しない。
  - 同じFeatureを重複付与しない。
- 主なイベント: `feature.assigned_to_group`、`feature.removed_from_group`

UserFeatureSuspensionは`user_id`を集約IDとする個人例外集約として扱い、直接Feature付与には拡張しない。

### Role集約

- 集約ID: `role_id`
- 管理対象: Role基本情報とPermission集合
- 不変条件:
  - システムRoleの内部コードを変更・削除しない。
  - 存在しない・無効なPermissionを含めない。
  - Role継承を持たない。
- 主なイベント: `role.created`、`role.updated`、`role.permissions_changed`、`role.inactivated`

### RoleAssignment集約

- 集約ID: `role_assignment_id`
- 管理対象: 付与先、Role、Scope、有効期間、状態
- 不変条件:
  - 付与先はUserまたはGroupだけとする。
  - `GLOBAL`は明示指定を必須とする。
  - `GROUP`では`scope_group_id`を必須とし、それ以外では指定を禁止する。
  - 開始日時は終了日時以前とする。
  - 無効なUser、Group、Roleへ新規割当しない。
- 主なイベント: `role_assignment.created`、`updated`、`removed`

### 認可判定と集約の関係

有効Feature・Permissionは複数集約を横断するため、それ自体を更新可能な集約にしない。
Membership、GroupAccess、Role、RoleAssignment、UserFeatureSuspensionから生成・計算する読み取りモデル
またはキャッシュとする。キャッシュ失効は各Projectorが担当し、キャッシュを更新するCommandは
作らない。

### 同時実行制御

- 各集約はspatie/laravel-event-sourcingのaggregate versionで楽観ロックする。
- spatieのイベントストアは集約クラスを含めずUUIDでストリームを識別するため、同じ業務IDを使う
  別集約（例: UserIdentityとUserMembership、GroupとGroupAccess）はUUID v5で集約種別を含む
  専用ストリームIDを決定的に生成する。業務上のUser ID・Group IDはイベントpayloadに保持する。
- MembershipChangeSet適用時だけは、同一Userへの複数予約の競合を防ぐためUser行または専用の
  membership lock行を`SELECT ... FOR UPDATE`でロックする。
- DBのunique・外部キー制約は集約の検証を置き換えるものではなく、競合時の最後の防衛線とする。

## 31.14 実装順序

1. User、ExternalIdentity、GroupType、Group、Membership、基本監査
2. Feature、Feature階層、GroupFeatureAssignment、有効Feature判定、UI/API/MCPガード
3. Role、Permission、RolePermission、RoleAssignment、Scope、有効権限確認画面
4. MembershipChangeSet/Item、差分UI、制約シミュレーション、予約適用・取消・失敗処理
5. CSV差分取込、FieldAuthority、外部HR管理項目、Microsoft Entra・外部サービスID連携

## 31.15 受入条件

- Feature未付与または個別停止中のユーザーは、画面・URL・API・MCPから機能を利用できない。
- 複数所属グループから得るFeatureとPermissionが加算される。
- RoleをUserとGroupの双方へ、明示したScopeと有効期間付きで割り当てられる。
- GroupTypeごとの所属数・主所属・階層制約が、現在変更と予約変更の双方で検証される。
- 将来日付の複数変更が一括適用され、一部適用されない。変更・取消・失敗理由を追跡できる。
- 外部HR管理項目は編集できず、管理元・最終同期日時・CSV差分を確認してから適用できる。
- 退職・無効化後も過去の勤怠、申請、所属、権限、監査履歴を参照できる。

## 31.16 明示的な対象外

- 明示的Denyと汎用ポリシー言語
- ロール同士の継承
- 自由記述の条件式、複数ポリシーの交差条件
- 複数親Groupと親Groupからの暗黙権限継承
- UserへのFeature直接付与
- 対象Group未指定を暗黙に全社扱いすること
- Featureへの勤務・給与・経費等の業務設定値の混在
- 本格的な人事異動・採用・評価・給与ワークフロー
