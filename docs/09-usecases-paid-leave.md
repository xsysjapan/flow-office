# 9. 有給管理ユースケース

年次有給休暇のWrite Modelは`App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate`
(AggregateId = `userId`)を単一の集約ルートとする。社員一人の年休台帳全体
(Grant/Usage/Allocation)の不変条件をこの集約自身がreplayのみで保証し、Handlerが
Eloquent Projectionを読んで業務ルールを判定することはしない
(docs/changesets/20260906-paid-leave-domain-redesign/spec.md参照)。旧`PaidLeaveGrantAggregate`
/`PaidLeaveRequestAggregate`(`App\Domain\PaidLeave\*`)は同spec Phase 5で削除済みで、
現存しない。

## UC-P001: 有給付与ルールを作成する

1. 管理者が付与ルールを作成する
2. 正社員、短時間勤務、週4勤務などの対象を設定する
3. 継続勤務期間ごとの付与日数を設定する
4. 出勤率条件を設定する
5. ルールを保存する

年次有給休暇は原則として、雇入れから6か月継続勤務し、全労働日の8割以上出勤した労働者に
付与される。短時間労働者にも所定労働日数に応じた比例付与がある。
(出典: 連合(日本労働組合総連合会))

付与ルール(`paid_leave_grant_rules`/`paid_leave_grant_rule_steps`)は継続勤務期間ごとの
付与日数・出勤率条件をマスタ化したもので、UC-P002の日次バッチが判定に用いる。法定の
通常/比例付与判定・シフト勤務判定を独立ドメイン化した`PaidLeaveScheduleAggregate`/
Assessmentは別の変更セット(docs/changesets/20260906-paid-leave-schedule-assessment/spec.md)
で実装済み(UC-P011〜UC-P015参照)。既存のルールマスタ・`paid-leave:grant-scheduled`は
UC-P011の`paid-leave:roll-schedules`に置換される。

## UC-P002: 有給を自動付与する

1. バッチが付与対象者を抽出する
2. 入社日、継続勤務期間、出勤率、勤務形態を確認する
3. 付与ルールに基づき付与日数を決定する
4. `PaidLeaveAccountAggregate`へ`GrantPaidLeave`Commandを発行し、`PaidLeaveGrantCreated`
   イベントとしてGrantを記録する
5. 有効期限を設定する
6. 社員へ通知する

有給休暇の請求権は原則2年で時効消滅するため、付与単位ごとに有効期限を管理する
(有効期限 = 付与日 + 2年)。(出典: チェック労働)

`paid-leave:grant-scheduled`コマンドとしてcronから毎日実行する(routes/console.php)。
判定対象抽出・出勤率計算のロジック自体は再設計前と変わらず、`GrantScheduledPaidLeaveHandler`
(既存の日次全件評価バッチ)が引き続き担う。今回の変更は「判定が確定した後、実際にどこへ
Grantを記録するか」だけで、以下の通り新ドメインの`GrantPaidLeave` Commandを発行するだけの
薄いラッパーに縮小されている(spec.md「実装方針の変更」)。

1. `users.hire_date`(入社日)が設定済みの社員を対象にする。MS365には入社日に相当する
   属性がないため同期対象外で、`user_profile.update` Permissionを持つ担当者が個別に設定する
   (`PUT /api/users/{user}/hire-date`)。
2. 付与ルール(`paid_leave_grant_rules`)ごとに、`work_style_id` が指定されている場合は
   当日その勤務形態が割り当てられている社員のみに絞り込む。
3. 入社日からの継続勤務期間(完了月数)を求め、今日がその「月次記念日」(入社日と同じ日)
   であり、かつ `first_grant_after_months` 以上かつ `grant_cycle_months` の周期に
   ちょうど合致する月のみ付与対象とする(バッチは毎日実行されるが、実際に付与されるのは
   対象者ごとに年1回程度)。
4. 継続勤務期間に応じた付与日数は `paid_leave_grant_rule_steps` から、条件を満たす最大の
   `continuous_service_months` の行を採用する。
5. 出勤率(`min_attendance_rate`)は、直近 `grant_cycle_months` か月間の
   `employee_calendar_entries`(勤務予定日)を分母、`attendance_days` が退勤済みまたは
   有給消化済み(`work_type` が `paid_leave_` で始まる)の日を分子として計算する
   (有給取得日は出勤したものとして扱う)。期間中に勤務予定日が1件も無い場合は判定不能として
   付与しない。
6. 同一社員に同日重複して付与しないよう、当日すでに付与済みの場合はスキップする
   (`PaidLeaveAccountAggregate`の不変条件1「新規Grantは現在の最新Grantより後の日付」により、
   同日付与自体もAggregate側で二重に拒否される)。
7. `users.paid_leave_auto_grant_enabled = true` の社員のみを対象とする(ユーザーごとの
   自動付与ON/OFF設定。UC-P00X参照)。特別休暇の自動付与も同様に
   `users.special_leave_auto_grant_enabled = true` の社員のみが対象となる
   (`GrantScheduledSpecialLeaveHandler`、種別を問わず一括で判定)。いずれも手動付与
   (`GrantPaidLeave` / `GrantSpecialLeave`)には影響しない。

実際の付与処理(Grant作成・イベント記録・Teams通知)は既存のUC-P002手動付与
(`GrantPaidLeave`)と共通のCommandを再利用する。

## UC-P00X: 社員ごとの有給/特別休暇自動付与ON/OFFを設定する

出向者・休職者・契約上休暇を個別管理する社員など、付与ルールの対象条件には合致するが
自動付与の対象から外したい社員を個別に除外できるようにする
(docs/changesets/20260904-paid-leave-auto-grant-per-user-toggle/spec.md)。

1. `users.paid_leave_auto_grant_enabled` / `users.special_leave_auto_grant_enabled`
   (いずれもboolean, default true)で制御する。特別休暇は休暇種別を問わず1つのフラグで
   一括ON/OFFする(種別ごとの個別制御はしない)。
2. `App\Domain\UserManagement` 配下でCommand(`SetPaidLeaveAutoGrantEnabled` /
   `SetSpecialLeaveAutoGrantEnabled`)→Event(`PaidLeaveAutoGrantEnabledSet` /
   `SpecialLeaveAutoGrantEnabledSet`)→`UserProjector`経由で更新する
   (`hire_date`/`usage_start_date`と同じパターン)。
3. 設定経路は2つ(裏側の設定値は1つずつなので二重管理にはならない):
   - 社員個別編集画面(`UserRoleEditPage.tsx`)から、入社日等と同じ「保存ボタンで確定」方式
     で切り替える(`PUT /api/users/{user}/paid-leave-auto-grant-enabled` /
     `.../special-leave-auto-grant-enabled`、権限は`user.update`)。
   - 付与ルール管理画面(`PaidLeaveAdminPage.tsx` / `SpecialLeaveAdminPage.tsx`)の各ルールの
     「対象社員」一覧から、ルールの対象条件に現在マッチする社員一覧を表示し
     (`GET /api/paid-leave/grant-rules/{rule}/target-users` /
     `GET /api/special-leave/grant-rules/{rule}/target-users`)、行ごとのチェックボックスで
     即時に切り替える(同じ更新APIを叩く)。
4. デフォルトは`true`。マイグレーション適用直後は既存の全社員が有効のままであり、
   本機能追加による既存挙動の変化はない。

## UC-P003: 有給を申請する

1. 社員が有給申請を作成する
2. 対象日を選択する
3. 全休、午前半休、午後半休、時間休を選択する
4. `approval.execute` Permissionを持つ任意の社員から承認者を選択する
5. 有給残数を確認する
6. 勤務予定日であることを確認する
7. 申請する

申請単位ごとの取得日数は以下のように決まる。

- 全休: 1.0日
- 午前半休・午後半休: 0.5日
- 時間休: 取得時間 ÷ 対象日の所定労働時間(`employee_calendar_entries.work_style.prescribed_daily_minutes`)

手順5(有給残数を確認する)・手順6(勤務予定日であることを確認する)は申請時にAPIで検証し、
不足・対象外の場合は申請自体を拒否する(422)。同一社員・同一対象日への重複申請
(提出中・承認済みが既にある場合)も拒否する。これらの重複申請チェックはPaidLeaveドメインの
外側(Workflow・申請入口側)の判定として残る(CLAUDE.md原則14、spec.md「Command/Handler」節)。

申請そのものの進行状況(`paid_leave_requests.status`: submitted → approved / returned /
cancelled)は独立ステータス系列として引き続き管理するが、実際にGrantへどう充当するか
(Allocation)を決めるのは常に`PaidLeaveAccountAggregate`である。申請時点では
`DesignatePaidLeaveUsage` Commandにより`PaidLeaveUsageDesignated`イベントが記録され、
未確定(`confirmed=false`)のUsageが1件作られる(下記「Usageのライフサイクル」参照)。

### 期間指定でまとめて申請する(複数日分)

フロントエンドは日付を1件ずつ選ぶ方法と、期間(開始日〜終了日)を選ぶ方法の2通りで
複数日分をまとめて申請できる。バックエンドAPIは1申請=1対象日のままだが(日ごとの
勤務予定・残数チェックを崩さないため)、同じ申請操作で作成した行は`request_group_id`
(nullable uuid)で束ねる。取得単位(全休/半休/時間休)は対象日が1日のみの場合に限り
選択でき、2日以上をまとめて申請する場合は全休固定になる(半休・時間休は1日単位の
概念のため)。

## UC-P004: 有給を承認する

1. 承認者が有給申請を確認する
2. 問題なければ承認する
3. 対象日の勤怠に有給区分を反映する
4. `PaidLeaveAccountAggregate`が最新のGrant時系列に対しAllocationを実行し、Usageを確定する
5. `PaidLeaveUsageConfirmed`イベント・Allocation結果に応じた`PaidLeaveUsageAllocated`
   イベント(0〜複数件)を記録する

手順3(対象日の勤怠に有給区分を反映する)は `attendance_days.work_type` に
`paid_leave_full` / `paid_leave_am_half` / `paid_leave_pm_half` / `paid_leave_hourly`
のいずれかを設定する。全休の場合は出退勤操作が発生しないため、締め忘れ(打刻漏れ)として
警告されないよう `attendance_days.status` を `clocked_out` 扱いにする。この反映自体は
Workflow/申請側のReactorが担い、`work_type`は申請時点(承認前)から反映される(UC-P003)。

手順4(Allocation)は「Allocationの算出アルゴリズム」節の通り。承認済みの日次勤怠が既に
締め(ロック)済みの場合は承認できない(修正申請ワークフローを使う)。

承認・差戻し・取消は、汎用申請(workflow_requests)やバックオフィス処理と同様、独立した
ステータス系列(`paid_leave_requests.status`: submitted → approved / returned / cancelled)
で管理する(承認とバックオフィス処理を分ける方針と同じ考え方)。差戻しは承認者のみ行える。
取消は申請者自身のみ行え、提出中(未承認)・承認済みのいずれの申請も取り消せる
(承認済みの取消の詳細は「実装上のポイント」参照)。

期間指定でまとめて申請した複数日分(同じ`request_group_id`を持つ行)は、承認者がそのうち
1件を承認する操作だけで、まだ提出中の残りの日もまとめて承認する(1日ごとに個別承認する
手間を減らすため)。差戻しはこの連鎖の対象外とし、日ごとに個別に行う(1日だけ差戻したい
場合があるため)。承認画面の詳細には、対象社員の直近1年間(申請中・承認済みの合計)の
有給取得日数を表示し、自動付与のルールに依らず承認者が目視で判断できるようにする
(この日数表示はシステムが上限を強制するものではなく、あくまで判断材料)。

## PaidLeaveAccountAggregateの内部モデル

`PaidLeaveAccountAggregate`はreplayのみで再構築される内部状態として、Grant一覧
(`grantId`ごとの`grantedOn`/`expiresOn`/`grantedDays`/`revoked`/`allocations`等)と
Usage一覧(`usageId`ごとの`usedOn`/`usedDays`/`confirmed`/`cancelled`/`allocations`等)を
保持する。GrantとUsageはそれぞれ独立したUUID(`grantId`/`usageId`)を持ち、
Allocation(`(usageId, grantId)`の組+充当日数)によって結び付けられる。Projection
(`paid_leave_usage_allocations`)を個々に参照・表示できるようにするための設計であり、
Aggregate自身はProjection/Eloquentへは一切アクセスしない。

### Grant時系列スタックの不変条件

1. **新規Grantは最新Grantより後**: 非取消の現在最新Grant(`grantedOn`が最大のもの)より
   `grantedOn`が後である必要がある(同日付与・過去日付の挿入は拒否)。
2. **変更・取消は最新Grantのみ**: `changeGrantAmount`/`changeGrantDate`/`changeGrantExpiry`/
   `revokeGrant`は、現在の最新の非取消Grantに対してのみ実行できる。過去のGrantを直接
   書き換えることはできない(古いGrantを変更したい場合は、間の全Grantを順に取り消して
   最新の状態にしてから操作する必要がある)。
3. **減額の下限**: Grant減額(`changeGrantAmount`)はAllocation合計を下回れない。
4. **有効期限短縮の制約**: 有効期限短縮(`changeGrantExpiry`)はAllocation件数0のGrantのみ
   許可する。
5. **単一Grant内の整合性**: `grantedOn <= expiresOn`を常に維持する(日付・有効期限
   変更のいずれでもこの前後関係が崩れる変更は拒否する)。
6. **取消時のAllocation解除**: Grant取消(`revokeGrant`)時は当該Grantの全Allocationを
   解除する(`PaidLeaveUsageAllocationReleased`を発行。Usage自体は取消しない)。

### Allocationの算出アルゴリズム(`AllocationPlanner`)

Usage確定(`confirmUsage`)時、副作用のないDomain Service`AllocationPlanner`
(`App\Domain\PaidLeaveAccount\Support\AllocationPlanner`)が、以下の順でどのGrantへ
どれだけ充当するかを算出する。承認画面のPreview APIを追加する際も同じロジックを
共有する想定(Phase 5、現時点ではスコープ外)。

1. **Step 1(有効期限近い順)**: `usedOn`時点で有効(`grantedOn <= usedOn <= expiresOn`)
   かつ未取消のGrantのうち、空きがあるものを`expiresOn`昇順(失効が近い順)に充当する。
2. **Step 2(直近未来Grant1件のみ)**: Step 1で不足が残る場合、`usedOn`より後の
   `grantedOn`を持つ未取消Grantのうち`grantedOn`が最も近い1件のみを対象に、残り不足分を
   充当する(複数の未来Grantへは進まない)。
3. **Step 3(未充当のまま残す)**: それでも不足が残る場合、残りは充当せず未充当のまま
   Usageに残す。確定(承認)自体は失敗させない(残高不足でも承認するかどうかは人間が
   判断する。CLAUDE.md原則参照)。

### 未充当Usageの自動再Allocation

Grant追加・増額・有効期限延長・Usage取消によるAllocation解除など、「空きが発生した契機」
の都度、Aggregateは未充当のUsage(確定済み・未取消・充当不足があるもの)を`usedOn`昇順
(同日は登録順)に再評価し、空きが生まれたGrantへ自動的に充当する
(`allocateUnallocatedUsages`)。既存のAllocationは組み替えない(常に「残りの不足分」だけを
追加充当する、安定ソートの再Allocation)。同一Command実行内・同一Aggregateバージョン内で
完結させることで、楽観的排他との整合を保つ。

## Usageのライフサイクル

1. **designated(未確定)**: 申請時点で`DesignatePaidLeaveUsage`により`PaidLeaveUsageDesignated`
   イベントが記録され、`confirmed=false`のUsageが作られる。この時点ではAllocationは
   行われない。
2. **confirmed(確定)**: 承認時に`ConfirmPaidLeaveUsage`により`PaidLeaveUsageConfirmed`が
   記録され、続けてAllocationが実行される(`PaidLeaveUsageAllocated`、0〜複数件)。
3. **cancelled(取消)**: `CancelPaidLeaveUsage`により、既存のAllocationがあれば全て解除
   (`PaidLeaveUsageAllocationReleased`)したうえで`PaidLeaveUsageCancelled`が記録され、
   解放された枠は他の未充当Usageへ自動的に再Allocationされる(取消はUsage単位で行い、
   1件の承認済み申請が複数Grantにまたがって充当されていても取消は常にそのUsage全体に
   対して行う)。

全日(1.0)・半日(0.5)・時間単位(hourly、端数日数を`usedDays`としてそのまま反映)いずれも
同じUsage経路で扱う。時間単位年休(時間を単位とした独立の残高管理・別建て制度)の新規構築は
対象外だが、既存本番機能である「1日未満の時間数を指定した有給申請」自体は継続提供する
(spec.md「実装方針の変更」)。未知の`usageType`のみ`DomainRuleException`で例外化する。

## Workflow統合(申請フローとの連携)

「申請(進行状況)」の正は`workflow_requests`側、「有給固有の申請内容」の正は
`paid_leave_requests`側という分離を維持する(CLAUDE.md原則14)。

- 申請提出時: 有給固有のCommand(`RequestPaidLeaveHandler`等、既存のCommandクラス・
  ルーティング・Workflow Reactorからのディスパッチ先は維持)が、内部で
  `PaidLeaveAccountAggregate::designateUsage()`を呼ぶ(`DesignatePaidLeaveUsage`
  Command経由)。`paid_leave_requests`には日数・区分・対象日等の有給固有パラメータを
  そのまま保持する。
- 承認時: `ApprovePaidLeaveRequestHandler`が`ConfirmPaidLeaveUsage`を発行し、
  Aggregate内でAllocationが実行される。
- 差戻し・取消(承認前): `ReturnPaidLeaveRequestHandler`/`CancelPaidLeaveRequestHandler`が
  未確定Usageを`CancelPaidLeaveUsage`で取り消す(再提出時は新規Usageを作成する)。
- 承認済み申請の取消: `CancelPaidLeaveRequestHandler`が`CancelPaidLeaveUsage`を発行し、
  Allocationを解除して残高を復元する(旧ドメインではAllocation済みGrantの取消は
  ブロックされていたが、新ドメインの不変条件6「取消時に全Allocationを解除」により
  取消可能・残高復元が正しい挙動である。cutover時の意図的な仕様変更)。

`paid_leave_requests`のAPI応答形状(`/paid-leave/grants`・`/requests`・`/usages`・
`/history`・`/grant-rules`)はcutover前後で変更していない。応答フィールドは
`PaidLeaveUsageAllocationProjector`が旧Projectorの全列を引き継いで供給する。

## UC-P005: 有給消滅警告を出す

1. バッチが有効期限90日以内の有給を検索する
2. 残日数がある社員を抽出する
3. 社員と管理者へ Teams 通知する
4. 警告履歴を記録する

`paid-leave:warn-expiring` コマンドとしてcronから毎日実行する。対象は
「残日数(`paid_leave_grants.remaining_days`。Allocationからの非正規化キャッシュ)が
0より大きく、有効期限(`expires_on`)が今日から90日以内、かつまだ警告していない
(`paid_leave_grants.expiry_warned_at` が未設定)」付与。警告後は`expiry_warned_at`を
記録し、同じ付与に重複して通知しない。「社員と管理者へ通知する」はTeamsが通知専用の
単一チャンネル(webhook)である現在の実装上、対象者名を含む1件の通知として送る
(docs/03-architecture.md、CLAUDE.md「Teamsは通知専用」)。

警告を記録するイベントは`PaidLeaveAccountAggregate::raiseGrantWarning()`が発行する
`PaidLeaveGrantWarningRaised`(`grantId`/`warningType`/`message`。旧`PaidLeaveGrantAggregate::
raiseWarning`/`paid_leave.warning_raised`の置き換え。`RaisePaidLeaveGrantWarning` Command
経由)。残高等の不変条件には関与しない、警告済みフラグ記録専用のイベントである。

## UC-P006: 年5日取得義務を警告する

1. バッチが年10日以上付与された社員を抽出する
2. 取得義務期間内の取得日数を確認する
3. 5日未満で期限が近い場合に警告する
4. 社員、承認者、管理部へ通知する

年10日以上の年次有給休暇が付与される労働者には、使用者による年5日の取得時季指定義務がある。
(出典: 都道府県労働局所在地一覧)

`paid-leave:warn-five-day-obligation` コマンドとしてcronから毎日実行する。取得義務期間は
付与日(`granted_on`)から1年とし、期限まで60日以内かつ取得日数(Allocationの合計)が
5日未満の付与を対象にする。警告後は`paid_leave_grants.five_day_obligation_warned_at`を
記録し、重複通知しない。「承認者」は有給申請ごとに都度指定され固定の対応者を持たないため
(CLAUDE.md「承認者は都度指定」)、通知は社員本人と管理部宛の1件として送る。
`PaidLeaveGrantWarningRaised`イベント(`warningType: five_day_obligation`)を記録する。

このバッチ(`WarnFiveDayObligation`)自体の新ドメインへの本格統合(取得日数の判定を
Allocationベースへ完全移行する等)は今回の再設計のスコープ外とし、当面は現行のまま残置する
(spec.md「対象外」)。

## UC-P007: 有給履歴を確認する

1. 社員本人が自分の有給履歴を確認する、または管理者・人事担当者が対象社員を選んで
   有給履歴を確認する
2. 付与・申請・承認・差戻し・取消・消化・移行のイベントを日時の新しい順に一覧表示する

`paid_leave_grants`/`paid_leave_requests`/`paid_leave_usage_allocations`/
`paid_leave_balances` の現在の残高・ステータス一覧(UC-P001〜UC-P004の画面)とは別に、
`stored_events`(EventStore)を正の記録として直接検索し、対象社員に関する一連のイベントを
時系列で表示する。`stored_events`の`aggregate_uuid`は`PaidLeaveAccountAggregate`の
AggregateId(=対象社員の`user_id`)そのものであるため、旧ドメインのように個々のGrant/
Request idで絞り込む必要はなく、社員の`user_id`1つで一連のイベント(Grant系・Usage系・
移行イベントを含む)を横断的に取得できる(旧ドメインからの構造上の簡素化)。

自分の履歴は誰でも閲覧できる。他の社員の履歴は`paid_leave.read` Permissionを持ち、対象Userを
含むScopeが有効な場合だけ閲覧できる(`GET /paid-leave/grants/user/{userId}`等)。

## UC-P008: 有給付与を取り消す

1. 人事担当者・管理者(`leave.manage` Permission)が、発行済みの有給付与(`paid_leave_grants`)
   の中から取り消す対象を選ぶ
2. 取消理由を入力する(任意)
3. `revokeGrant`の不変条件により、現在最新の非取消Grantでなければ拒否される
4. `RevokePaidLeaveGrant` Commandにより、当該Grantの全Allocationを解除
   (`PaidLeaveUsageAllocationReleased`)したうえで`PaidLeaveGrantRevoked`イベントを記録し、
   `paid_leave_grants.status`を`revoked`に、`revoked_at`/`revoked_by_user_id`/
   `revoke_reason`を設定する

`POST /paid-leave/grants/{grant}/revoke`(`RevokePaidLeaveGrant`
Command/`RevokePaidLeaveGrantHandler`)。旧ドメインでは「消化済み(`used_days > 0`)の
Grantは取消不可」というガードだったが、新ドメインの不変条件は「取消は現在最新の非取消
Grantのみ」「取消時に全Allocationを解除して残高を復元」であり、Allocation済み(消化済み)
であっても最新Grantであれば取消可能・Allocationは自動的に解除・再配分される(cutoverに
伴う意図的な仕様変更。「実装上のポイント」参照)。同じ構造の取消を特別休暇
(`special_leave_grants`、`RevokeSpecialLeaveGrant`)にも実装する(こちらは旧ドメインの
まま、対象外)。

## UC-P009: 管理者が社員の有給申請を取り消す・消化明細を確認する

1. 人事担当者・管理者(`leave.manage` Permission)が対象社員の有給消化明細
   (`paid_leave_usage_allocations`/`paid_leave_usages`)を一覧で確認する
   (`GET /paid-leave/usages/user/{userId}`。`used_on`の新しい順。関連する申請の現在の
   `status`(`request_status`)も併せて返し、`approved`以外(既に取消・返却済み)の明細は
   取消不可であることを画面側で判別できるようにする)
2. 承認済みの申請を選び、取消理由等を確認したうえで管理者が直接取り消す
   (`POST /paid-leave/requests/{id}/admin-cancel`)

消化明細(`paid_leave_usage_allocations`)は`PaidLeaveUsageAllocated`/
`PaidLeaveUsageAllocationReleased`イベントから作成・更新される派生データであり、
消化明細1件だけを独立して取消・巻き戻すという操作は存在しない(1件の承認済み申請が
複数の付与にまたがって消化することがあり、取消は常に申請単位(Usage単位)で行う。
ドメインとしての取消は`CancelPaidLeaveRequestHandler`が`CancelPaidLeaveUsage`を発行し、
対象Usageの全Allocationを巻き戻す形で実装済み)。

UC-P003で説明した申請者本人による取消(`POST /paid-leave/requests/{id}/cancel`)は
`cancelledByUserId === 対象申請のuser_id`のみ許可する自己申請限定の経路のままとし、
管理者向けにはそれとは別の`admin-cancel`エンドポイントを追加する。内部的には同じ
`CancelPaidLeaveRequest` Command/`CancelPaidLeaveRequestHandler`を使うが、
`isAdminAction: true`を渡すことで本人一致チェックをバイパスする(`cancelledByUserId`には
取消操作を行った管理者自身のIDを渡し、監査上「誰が取り消したか」を正しく記録する)。
承認済み申請のみ取消可能・締め済み月は取消不可等、既存の業務ルールはすべてそのまま適用される。
同じ構造を特別休暇(`POST /special-leave/requests/{id}/admin-cancel`、
`GET /special-leave/usages/user/{userId}`)・代休(`POST /compensatory-leave/requests/{id}/admin-cancel`、
`GET /compensatory-leave/usages/user/{userId}`)にも実装する。

## UC-P010: 既存システムからの有給データを移行する(cutover専用)

1. 人事担当者・管理者(`leave.manage` Permission)が、旧システム(または旧Projection)から
   読み取った社員ごとの移行データを用意する
2. 社員ごとに`usage_start_date`時点(cutover日)で有効なGrant状態を、以下3モードの
   いずれかの形式で用意する
   - モードA: Grant単位で完全に分かる(`original_granted_on`/`original_granted_days`とも
     判明)
   - モードB: 前年度繰越+当年度残高(`original_granted_days`は不明、`original_granted_on`は
     判明)
   - モードC: 残高と有効期限のみ判明(`original_granted_on`/`original_granted_days`とも不明)
3. `MigratePaidLeaveAccount` Commandを発行し、`PaidLeaveAccountAggregate::migrateGrants()`
   経由で`PaidLeaveAccountMigrated`イベントを1回だけ記録する
4. 移行後は通常のGrant操作(`GrantPaidLeave`等)・Usage操作がそのまま利用できる

移行は口座ごとに一度きりの操作であり、既にGrantが1件でも存在する口座への再実行は拒否する
(`DomainRuleException`)。移行前の個別Usage履歴の完全再現は行わない
(依頼書「過去の全Usage履歴再現は必須としない」の方針)。

**不変条件上の上限の扱い**: 全モード共通で、Aggregate内部の不変条件(減額・Allocation
超過判定等)が参照する「利用可能な上限」は常に`remainingDaysAtCutover`(切替時点の実際の
残日数)であり、`originalGrantedOn`/`originalGrantedDays`はnullable可能な監査・表示専用の
付随メタデータ(`paid_leave_grants.original_granted_days`/`cutover_metadata`列)として
保持するに留める。モードC(元付与日数不明)でも架空の上限は作らない。「最新Grant」判定の
起算日(`grantedOn`相当)は、モードA/Bでは`originalGrantedOn`をそのまま使い、モードCで
`originalGrantedOn`が不明な場合のみ`cutoverDate`を代替の順序キーとして用いる。

移行で作成されたGrantは`paid_leave_grants.source`が`migration`(通常のGrantは`manual`)と
なり、監査上区別できる。

**入力経路は2つ**:

- 管理者専用`POST /paid-leave/migrate`(1社員分。単発の疎通確認・修正用)
- 一括投入用artisanコマンド`php artisan paid-leave:migrate-accounts <file.json> [--dry-run]`
  (社員ごとにGrantが入れ子になるためCSVではなくJSON形式を採用。1件の入力データが不正でも
  バッチ全体を中断せず、行ごとの成否を最後にまとめて報告する部分失敗許容)

いずれも`leave.manage`相当の管理者権限に限定する。UI(移行専用の管理画面)は当変更セットの
スコープ外(spec.md「対象外」)で、artisanコマンド・単発APIのみで完結する。

## UC-P011: 付与予定(Schedule)をローリング生成する

1. `paid-leave:roll-schedules`コマンドがcronから毎日実行される
2. `hire_date`設定済み・`paid_leave_auto_grant_enabled=true`・`usage_start_date`未設定
   または到来済みの社員を対象に抽出する
3. 社員ごとに`EnsureFutureScheduleGenerated` Commandを発行し、現在から1年先までの
   `paid_leave_schedule_entries`(通常付与/比例付与/シフト勤務の各区分。判定不能な場合は
   `NeedsReview`)の存在を保証する
4. `scheduled_on`が到来済み(社員本人のタイムゾーン基準の「今日」以前)かつ`Scheduled`の
   ままのエントリについて、続けてUC-P012の出勤率Assessmentを実行する

旧`paid-leave:grant-scheduled`(UC-P002、`GrantScheduledPaidLeaveHandler`による日次全件
評価)を置換するバッチで、`App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate`
(AggregateId = `userId`)が状態を管理する(docs/changesets/20260906-paid-leave-schedule-assessment/spec.md)。
1年先まで先出しでScheduleを生成しておくことで、UC-P013の一覧画面から確定前の付与予定を
事前に確認できるようにする。1社員の失敗が他の社員の処理を止めないよう、行単位で成否を
収集して継続する(部分失敗許容)。既存の`paid_leave_grant_rules`ベースのバッチ判定
(UC-P001/UC-P002)とは独立した別ドメインであり、両者は共存しない(cutover的な置換)。

## UC-P012: 出勤率Assessmentを実行する

1. `RunAttendanceRateAssessment` Commandにより、対象エントリの直近期間の勤務予定日数
   (分母)・出勤日数(分子)を`attendance_days`等から算出し、出勤率を求める
2. `paid_leave_grant_policies`(通常付与)/`paid_leave_proportional_grant_policies`
   (比例付与)の現在有効な版(`version`最大)を判定根拠として記録する
   (`assessment_policy_version`)
3. 出勤率に基づく自動判定結果(`Eligible` / `NotEligible`)を算出し、
   `PaidLeaveScheduleAssessmentRecorded`イベントを記録する
4. 既にUC-P014のOverrideが行われている場合でもこのイベント自体は発行されるが、
   エントリの導出ステータスはOverride結果(`assessment_final_result`)を優先し、
   自動判定に上書きされない

`POST /paid-leave/schedule-entries/{entry}/reassess`(「再判定」ボタン)でも同一条件で
手動再実行できる。UC-P011のバッチから`scheduled_on`到来時に自動実行される他、勤怠データが
事後修正された場合に手動で再判定する用途を想定する。

## UC-P013: 付与予定一覧を確認する

1. 人事担当者・管理者が`/admin/paid-leave/schedule`画面で付与予定一覧を確認する
   (`GET /paid-leave/schedule-entries`)
2. `status`フィルタ(`all`/`eligible`/`not_eligible`/`needs_review`/`changed`)で絞り込む。
   `changed`(変更あり)は永続化された列ではなく、個別修正済み
   (`manual_override_by_user_id`あり)かつ再計算で`NeedsReview`へ押し出された行を示す
   合成フィルタ
3. 社員名の部分一致(`user_name`)、付与予定日(`scheduled_on`)の期間(`scheduled_on_from`/
   `scheduled_on_to`)で絞り込む
4. 一覧の行から詳細パネルを開き、`GET /paid-leave/schedule-entries/{entry}`でAssessment内訳
   (判定対象期間・分母/分子・出勤率・判定根拠の`policy_version`)を確認する

一覧の列は社員/付与予定日/区分(`regular`/`proportional`/`shift`/`NeedsReview`)/候補付与
日数/出勤率/判定状態(`status`)。詳細は「実装上のポイント」に追記の通り、区分・判定とも
`NeedsReview`になりうる。

## UC-P014: 判定結果をOverrideする

1. 人事担当者・管理者が、`NeedsReview`または自動判定に納得できないエントリを選ぶ
2. 最終判定(`Eligible`/`NotEligible`)と理由(必須)を入力する
3. `POST /paid-leave/schedule-entries/{entry}/override`により`OverrideScheduleAssessment`
   Commandが発行され、`PaidLeaveScheduleAssessmentOverridden`イベントが記録される
4. 以降、当該エントリの導出ステータスはUC-P012の自動判定結果より本Override結果
   (`assessment_final_result`)を優先する

区分・候補付与日数そのものの手動修正は別経路(`PATCH /paid-leave/schedule-entries/{entry}`、
`ManuallyEditScheduleEntry` Command)で、理由必須・以後の自動再計算(`RecalculateFutureSchedule`)
から保護される(`manual_override_by_user_id`が設定される)。両者は独立した操作で、区分修正は
判定結果のOverrideを兼ねない。

## UC-P015: 付与予定を一括付与する

1. 人事担当者・管理者が一覧を`Eligible`フィルタで絞り込み、対象エントリを複数選択する
2. `POST /paid-leave/schedule-entries/apply-grants`(`entry_ids`)を実行する
3. エントリごとに`ApplyScheduledGrants` Commandが発行され、`PaidLeaveAccountAggregate::grant()`
   (UC-P002と共通のGrant発行経路)が成功した後、`PaidLeaveScheduleEntryGranted`イベントで
   当該エントリのステータスを`Granted`へ遷移し、`granted_paid_leave_grant_id`を設定する
4. 対象社員の`paid_leave_balances`(UC-P007と同じ経路)に反映される

`Eligible`以外のエントリが選択に混入していた場合や、1件のAggregate例外が他のエントリの
処理を止めないよう、エントリ単位で成功/失敗を収集し、レスポンスに部分失敗を含めて返す
(黙って握りつぶさない。`paid-leave:migrate-accounts`と同じ「行単位継続」方針)。

## 実装上のポイント

- 付与ルール (`paid_leave_grant_rules` / `paid_leave_grant_rule_steps`) はマスタ化し、
  継続勤務期間ごとの付与日数・出勤率条件をハードコードしない。
- 消化(Allocation)は「Allocationの算出アルゴリズム」節の通り、有効期限が近い付与分から
  優先的に消し込み、それでも不足する場合に限り直近未来Grant1件だけを追加で充当する。
  `paid_leave_usage_allocations`が充当関係のSource of Truthで、`paid_leave_grants`の
  `allocated_days`/`remaining_days`・`paid_leave_balances`はそこからの非正規化キャッシュに
  過ぎない。
- UC-P001〜UC-P006はすべて実装済み。UC-P002(自動付与)・UC-P005(消滅警告)・
  UC-P006(年5日警告)は`paid-leave:grant-scheduled` / `paid-leave:warn-expiring` /
  `paid-leave:warn-five-day-obligation` の3コマンドとしてcronから毎日実行する
  (routes/console.php)。MVP自体は UC-P001(付与ルールマスタ)・付与の手動実行・
  UC-P003(有給申請)・UC-P004(有給承認・消化)までを最小範囲としていたが
  ([21-mvp-scope.md](./21-mvp-scope.md) 参照)、以降のフェーズでバッチ3種、さらに
  PaidLeaveAccountAggregateへの全面移行(本章)を実装した。
- UC-P003/UC-P004は汎用申請(workflow_requests)とは別の独立したCommand/Event
  (`paid_leave_requests` を有給固有の申請内容の正データとする専用ドメイン)として実装する。
  承認時に`attendance_days`への反映とAllocation実行という汎用申請の承認(バックオフィス
  タスク自動生成のみ)とは異なる業務ルールを持つため。
- UC-P002の継続勤務期間・出勤率判定には `users.hire_date`(入社日)を使う。MS365には
  対応する属性がないため同期対象外で、`user_profile.update` Permissionを持つ担当者が個別に
  設定する必要がある(未設定の社員は自動付与の対象外になる)。
- UC-P005/UC-P006の警告は同一の `PaidLeaveGrantWarningRaised` イベントを共有し、
  `warningType` (`expiry` / `five_day_obligation`) で区別する。重複通知を防ぐため、
  `paid_leave_grants.expiry_warned_at` / `five_day_obligation_warned_at` にそれぞれ
  一度警告した事実を記録し、以降の実行では対象から除外する(一度きりの警告。期限が過ぎても
  再警告はしない)。
- 期間指定の複数日申請・1回の承認でのまとめ承認(`request_group_id`)は特別休暇
  (`special_leave_requests`)・代休(`compensatory_leave_requests`、後述)にも同じ仕組みで
  実装済み。3ドメインとも、同じ`request_group_id`を持つ行のうち1件が承認されると、
  まだ提出中の他の行もまとめて承認される。差戻しは対象外で、日ごとに個別に行う。
- **承認済み申請の取消**: 有給・特別休暇・代休のいずれも、承認済みの申請を申請者本人が
  即座に取り消せる(承認者の再承認は不要)。有給は`CancelPaidLeaveUsage`によりAllocationを
  解除して残高を復元する(特別休暇・代休は引き続き旧構造の`*_grant`へ取消イベントを記録して
  残数を戻す)。対象日の`attendance_days.work_type`もクリアし、全休で実際の打刻が無い
  場合はステータスも未入力(`not_started`)へ巻き戻す(半休・時間休は実際の打刻由来の
  ステータスをそのまま維持する)。対象日を含む月次勤怠が既に提出・承認・締め済みの場合は
  取消できない(`AttendanceEditGuard::assertMutable`が他の編集操作と同じ基準で拒否する。
  修正が必要な場合は修正申請ワークフローを使う)。期間指定でまとめて承認された申請の取消は
  1件ずつ個別に行う(承認のようなグループ単位のカスケードはない)。
- 既知の制約: `attendance_daily_calculations`(日次集計)は現時点で `work_type` を区別せず
  `actual_start_at`/`actual_end_at` のみから計算する。全休日は労働時間が0分として集計される
  (欠勤ではなく有給消化であることは `attendance_days.work_type` で判別できるが、給与計算上の
  「有給分の賃金換算」は本実装のスコープ外。給与計算ソフト側で `work_type` を見て加算する、
  または後続フェーズで日次集計に有給分を組み込む対応が必要)。
- 承認画面Allocation Preview API・Grant管理UI・社員別有給画面は、今回の再設計のスコープ外
  (spec.md「対象外」参照)。ドメインロジック(Aggregate・Allocation算出)自体は完成済みの
  ため、画面実装のみが必要になった時点で別途変更セットを起こす想定。
  `PaidLeaveScheduleAggregate`/Assessment・法定通常/比例付与判定・シフト勤務判定・
  ローリングSchedule生成バッチ(1年先まで)は、別の変更セット
  (docs/changesets/20260906-paid-leave-schedule-assessment/spec.md)で実装済み
  (UC-P011〜UC-P015参照)。

## 代休(`App\Domain\CompensatoryLeave`)

有給・特別休暇とは異なり、代休の付与(Grant)は申請ではなく休日出勤の勤怠実績
(`attendance_days`)から自動導出される。所定休日・法定休日に実働がある日次勤怠が
保存される都度、`AttendanceDayCalculated`/`AttendanceDailyCalculationAdjusted`/
`AttendanceDayDeleted` を購読するReactorが`SyncCompensatoryLeaveGrant`コマンドを発行し、
`compensatory_leave_grants`(status=draft)を1対1(`attendance_day_id`ユニーク)で同期する。
休日出勤でなくなった・実績が削除された場合、draft状態のGrantのみ取り消す
(確定済みGrantは触らない。整合性は月次確認画面の警告`compensatory_leave_warnings`で扱う)。
代休は今回の有給ドメイン再設計の対象外で、引き続き従来通りの独立ドメインとして実装されている。

月次勤怠の提出(`AttendanceMonthSubmitted`)を受けて、対象月のdraft Grantを一括で
`confirmed`に確定する(`system_settings.compensatory_leave_valid_days`が設定されていれば
提出時点からのN日後を`expires_on`とし、未設定なら無期限)。確定後のGrantのみが消化申請
(`compensatory_leave_requests`、特別休暇と同じ申請・承認・消化のフロー)の対象になる。
取得単位(全休/半休/時間単位)は`system_settings.compensatory_leave_unit`
(`daily`/`half_day`/`hourly`)で制限し、半休判定の閾値は
`compensatory_leave_half_day_threshold_minutes`で設定する。未使用の確定済みGrantは
取消申請でき(`compensatory_leave_grant_cancellations`)、`compensatory_leave_requires_approval`
の設定に応じて即時取消/承認要のいずれかになる。詳細は`docs/03-architecture.md`・
`app/Domain/CompensatoryLeave/`を参照。

### 代休の手動付与・管理者直接取消

休日出勤の勤怠実績からの自動導出(上記)とは別に、人事担当者・管理者(`leave.manage`
Permission)が管理者操作として代休を手動付与できる。付与理由の例: 自動導出漏れの事後対応、
制度移行時の付与調整など。任意の日数を自由入力させるのではなく、実際に休日出勤した対象日
(`work_date`)を指定させ、その日の`attendance_days`(実労働時間)から自動導出フローと
**同じ換算ルール**(`system_settings.compensatory_leave_unit`等、
`App\Domain\CompensatoryLeave\Services\CompensatoryLeaveGrantCalculator`)で付与日数を
算出する(`POST /compensatory-leave/grants`、`GrantCompensatoryLeave`
Command/`GrantCompensatoryLeaveHandler`)。対象日に休日出勤の実績が無い場合はエラーになる。

手動付与は承認不要のため、作成と同時に`status=confirmed`となり(月次提出による確定
ステップを経ない)、`compensatory_leave_grants.source`に`manual`を記録して自動導出分
(`attendance`)と区別する。手動付与には元になった`attendance_days`行への1:1紐付けが
無いため`attendance_day_id`はnullのままとなる。

管理者は`POST /compensatory-leave/grants/{grant}/revoke`で代休Grantを直接取り消せる
(`source`が`attendance`/`manual`のどちらでも利用可能)。既存の社員起点の
取消申請→承認フロー(上記、`request-cancellation`/`grant-cancellations/{id}/approve`)とは
別の、承認を経ない管理者専用の即時取消経路であり、既存の`CancelCompensatoryLeaveGrant`
Command/Handlerをそのまま再利用する(`used_days`が0より大きい場合は同様に取消不可)。

有給・特別休暇のUC-P007と同様、`GET /compensatory-leave/history/mine`(本人)・
`GET /compensatory-leave/history/user/{userId}`(`leave.manage` Permission)で
代休の付与・申請・承認・差戻し・取消のstored_eventsを時系列(新しい順)で確認できる
(`App\Domain\Leave\Support\LeaveHistoryQuery`共通実装。手動付与・自動導出のどちらの
Grantも区別なく含まれる)。
