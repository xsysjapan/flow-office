# 年次有給休暇 付与Schedule/Assessmentドメイン新設(将来付与予定の確認画面)

ステータス: レビュー中

## 変更要望(原文)

> B(将来付与予定Schedule画面)です。変更セット案を作成してください。

直前のやり取りでの選択肢提示(A: Allocation確認画面 / B: 将来付与予定Schedule画面)に
対する回答。文脈: 完了済みの変更セット`docs/changesets/20260906-paid-leave-domain-redesign/`
で、「自動割当ではなくスケジュールを確認する画面などが必要になるはず」という指摘を受け、
現状調査の結果「将来付与予定を確認する画面は存在せず、バックエンドにもSchedule/Assessment
ドメイン自体が無い(元の依頼書§26-41相当は前回の変更セットで意図的にスコープ外とした)」
ことが判明したため、その部分を独立した変更セットとして新規に設計する。

## 背景・目的

現行の`GrantScheduledPaidLeaveHandler`(前回cutoverで新Aggregateへの発行先だけ付け替え、
判定ロジック自体は無変更)は、**「毎日全社員を評価し、その場でGrant確定」**という
日次バッチ方式である。この方式には以下の問題がある。

1. 管理者が「来月・再来月に誰にいくら付与される予定か」を事前に確認できない
   (バッチ実行日にならないと結果が分からない)。
2. 出勤率判定がバッチ内部にインラインで埋め込まれており、判定根拠(分母日数・出勤日数・
   除外日数等)が監査可能な形で残らない。
3. 出勤率80%未満で「対象外」判定になった場合の管理者による例外判断(Override)の
   仕組みが無い。
4. 通常付与/比例付与/シフト勤務の法定区分判定が実装されておらず、全ルールが
   `work_style_id`単位のカスタムルール任せになっている。

本変更セットは、依頼書§25-41(将来の付与予定Schedule・出勤率Assessment・法定通常/比例/
シフト判定)を実装し、上記を解消する。あわせて、「事前に確認できる」を実現する管理画面
(付与予定一覧・出勤率確認・Override)を追加する。

## 現状(As-Is)

Explore調査結果(要点)。

1. **`GrantScheduledPaidLeaveHandler`**
   (`backend/app/Domain/PaidLeave/Handlers/GrantScheduledPaidLeaveHandler.php`):
   タイムゾーングループ×ルール単位の日次全件評価。`eligibleUsers()`→
   `monthsOfServiceOnAnniversary()`(hire_date起算のanniversary当日のみ)→
   `first_grant_after_months`/`grant_cycle_months`判定→`resolveGrantDays()`
   (`paid_leave_grant_rule_steps`から`continuous_service_months`最大一致のステップを採用)→
   `meetsAttendanceRate()`(直近`grant_cycle_months`ヶ月の`EmployeeCalendarEntry.is_working_day`
   を分母、`AttendanceDay.status='clocked_out' OR work_type LIKE 'paid_leave_%'`を分子とする
   出勤率計算、既定80%)→cutover後は
   `App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave`を`source: 'scheduled_batch'`付きで
   発行(確認済み)。`paid-leave:grant-scheduled`(`backend/app/Console/Commands/
   GrantScheduledPaidLeaveCommand.php`)としてcron日次実行
   (`backend/routes/console.php:29`)。
2. **`paid_leave_grant_rules`/`paid_leave_grant_rule_steps`**: 前回changesetの決定通り、
   法定基準への準拠は問わない「会社独自の上乗せルール」として存続(§34-35の法定判定とは
   別レイヤー)。列は`name`/`work_style_id`(nullable)/`min_attendance_rate`(既定80)/
   `first_grant_after_months`/`grant_cycle_months`/`is_active`、steps側は
   `continuous_service_months`/`grant_days`。管理API`PaidLeaveController`に
   `indexRules`/`storeRule`/`targetUsers`はあるが**更新・削除エンドポイントは無い**。
   フロントは`PaidLeaveAdminPage.tsx`の`PaidLeaveGrantRulesCard`
   (作成フォーム+一覧)と`PaidLeaveGrantRuleTargetUsersSection`
   (対象社員プレビュー+自動付与トグル)のみ。
3. **`WorkStyle`**(`backend/app/Models/WorkStyle.php`): `prescribed_daily_minutes`/
   `prescribed_weekly_minutes`/`is_shift_based`/`work_time_system`等はあるが、
   **週所定労働日数・年間所定労働日数に相当する列は存在しない**。「平均所定労働時間」を
   算出するメソッドも無い(実際の日次スケジュールは`EmployeeCalendarEntry`側にある)。
4. **`EmployeeCalendarEntry`**: `user_id`/`work_date`/`work_style_id`/`is_working_day`等を
   持つEvent Sourcing駆動のUUID主キーテーブル。過去日程分も物理削除されず残り、
   `meetsAttendanceRate()`が直近N ヶ月を実際にクエリして参照可能なことを確認。
   ただし**将来日程を一括生成するバッチは存在しない**(`EmployeeCalendarEntryAggregate`は
   個別の割当操作ごとに作られる)。
5. **`AttendanceDay`**: 出勤日判定は`status='clocked_out' OR work_type LIKE 'paid_leave_%'`。
6. **users列**: `hire_date`/`usage_start_date`/`paid_leave_auto_grant_enabled`は
   cutover後も現状のまま使用継続を確認済み。
7. **「将来日程を事前生成するバッチ」の既存パターン**: 完全に同型のものは無いが、
   `GenerateCompanyCalendarYearsCommand`(`calendar:generate-years`、日次cron、
   べき等・ドラフト状態で先行生成)が最も近い設計パターン。また
   `ApplyScheduledMembershipChangesCommand`
   (`membership_change_sets`の`status=scheduled AND effective_at<=now()`をポーリングし
   CommandBus経由で適用、失敗は行単位でFailコマンドとして記録)は
   「予定された将来変更を期日到来時に適用する」という別の観点で参考になる。
8. **フロントエンド**: 有給休暇admin画面は`PaidLeaveAdminPage.tsx`のみ。将来付与予定・
   出勤率Assessment・Override機能に相当する画面は存在しない。

## 仕様検討

### 論点1: PaidLeaveScheduleの集約境界

- 選択肢:
  - A. `PaidLeaveScheduleAggregate`をAggregateId=userIdとし、その社員の将来Schedule
    エントリ一式(複数の`scheduled_on`)を内部で保持する(直前の変更セットにおける
    `PaidLeaveAccountAggregate`と対をなす設計)。
  - B. Scheduleエントリ1件ごとに独立したAggregate(AggregateId=schedule entry id)とする。
  - C. Event Sourcing化せず、素朴なEloquentモデルとしてCRUD管理する。
- 決定: A。
- 理由: 依頼書§27「月次バッチで全社員について1年先までScheduleが存在することを保証する」
  「以下の変更時には未来Scheduleを再計算する」という要求は、社員単位でまとめて
  再生成・再評価する操作が中心であり、社員単位Aggregateの方が「過去確定Schedule不変・
  未来のみ再計算」という不変条件(§27)を自然に保証できる。CはCLAUDE.md原則1
  (状態変更はCommand→Handler→stored_eventsで行う)に反するため却下。Bは
  「月次ローリングで1年先まで保証する」「条件変更時に未来分だけ一括再計算する」操作が
  1エントリ単位のAggregateだと集約をまたいだトランザクションになり実装が煩雑になるため
  却下。
- 未確定・要確認事項: なし。

### 論点2: Schedule EntryとAssessmentの関係

- 選択肢:
  - A. 1つのScheduleエントリ(scheduled_on単位)が1つのAssessment記録を持つ
    (Schedule Entry:Assessment = 1:1、再評価のたびに新しいAssessment版を追記)。
  - B. AssessmentをSchedule Entryに畳み込み、同一テーブルの列として持つ
    (バージョン履歴を持たない)。
- 決定: A。
- 理由: 依頼書§30「Assessment結果は監査可能にする」、§40「calculated attendance rate/
  automatic result/final result/override reason/operator/timestamp」の記録要求は、
  「誰が・いつ・何を根拠に・どう判定し・誰が上書きしたか」を全て残す必要があり、
  1回きりの列上書きでは「前回の自動判定が何だったか」を失う。Assessmentは
  Schedule Entryに対して複数回記録されうる(再判定のたびに新規追加、最新版を
  `final_result`として参照)構造とする。
- 未確定・要確認事項: なし。

### 論点3: 法定区分判定に必要なWorkStyleマスタの拡張

- 選択肢:
  - A. `WorkStyle`へ`weekly_scheduled_days`(週所定労働日数)・`annual_scheduled_days`
    (年間所定労働日数)をnullable列として追加し、管理者が契約条件として直接入力する。
    法定区分判定(§34: 週30時間以上/週5日以上/年217日以上→通常、それ以外は比例)は
    この列と既存`prescribed_weekly_minutes`から機械的に行う。未入力の場合は
    「要確認」判定とする。
  - B. `EmployeeCalendarEntry`の実績(直近一定期間の実際の稼働日数)から週所定労働日数を
    逆算する(入力不要だが、実績ベースになり「所定」の意味からずれる)。
- 決定: A。
- 理由: 依頼書§34の通常/比例判定は「所定労働日数」という契約上の概念であり、実績日数とは
  法的に別概念(実績は§37「平均所定労働時間」という補助情報の算出源として引き続き
  `EmployeeCalendarEntry`を使う)。Bは実績と所定を混同し、欠勤や休職期間があると
  誤判定するリスクがある。未入力時に機械的に80%未満的な判定へ倒さず「要確認」とする点は
  依頼書§31の精神(データ不足時にNeedsReview)を通常/比例判定にも一貫して適用する。
- 未確定・要確認事項: なし。

### 論点4: 法定通常付与表・比例付与表・シフト判定のマスタ構造

- 選択肢:
  - A. `paid_leave_grant_policies`(通常付与: continuous_service_months→grant_days、
    version管理)、`paid_leave_proportional_grant_policies`
    (比例付与区分×continuous_service_months→grant_days、version管理)、
    `paid_leave_grant_expiry_policy`(既定2年、version管理)を新設し、
    シードで現行法定値(依頼書§34の表)を投入する。
  - B. コード定数として実装する。
- 決定: A。
- 理由: CLAUDE.md原則8「法務判断が必要な値はマスタ化する。ハードコードしない」に
  明確に該当する。versionを持たせることで、将来法改正があっても過去に確定した
  Assessment・Grantの根拠を変えずに新版を追加できる。
- 未確定・要確認事項: なし。

### 論点5: 既存`paid_leave_grant_rules`(会社独自ルール)と新設法定Policyの関係

- 選択肢:
  - A. 会社独自ルール(`paid_leave_grant_rules`)は、法定Policyが算出した「法定最低日数」
    を下回る内容を保存時に拒否するバリデーションのみ追加し、実際のSchedule生成では
    「法定Policyによる判定」をベースラインとして採用しつつ、`work_style_id`に
    一致する独自ルールが存在すればそちらを優先する(独自ルールが無ければ法定Policy
    そのものを適用)。
  - B. 独自ルールを廃止し、法定Policyのみで運用する。
- 決定: A。
- 理由: 前回変更セットの「対象外」節で既に「会社独自の上乗せルールの余地として残す」
  方針を確定済み。今回はその方針を維持しつつ、依頼書§34-36で要求されている
  法定判定自体(通常/比例/シフト区分の自動判定)を新設する。
- 未確定・要確認事項: 独自ルールと法定Policyが「食い違う」場合(例:
  独自ルールのstepsが法定最低日数を下回る)の保存時バリデーション具体仕様は、
  実装フェーズで`PaidLeaveGrantRuleController`の既存バリデーションへ追記する形で
  詳細化する(仕様の大枠は本節で確定済みのため、実装を妨げるレベルの未確定ではない)。

### 論点6: 月次ローリングSchedule生成の実装方式

- 選択肢:
  - A. `GenerateCompanyCalendarYearsCommand`(`calendar:generate-years`)と同じ
    「日次cron・べき等・先行生成」パターンを踏襲した新規artisanコマンド
    `paid-leave:roll-schedules`を新設し、全社員について「現在から1年先まで
    Scheduleが存在すること」を保証する(既に存在する未来分はスキップ、無い月だけ生成)。
  - B. 月初のみ実行する月次バッチとする。
- 決定: A(実行頻度は日次だが、内容は「既に十分先まで生成済みならほぼ何もしない」
  べき等処理なので、依頼書§26の「月次バッチ」という表現は実装上「日次cron・べき等」で
  代替する。実質的な結果は同じ)。
- 理由: 既存の`calendar:generate-years`と実行頻度・べき等性のパターンを揃えることで、
  運用者(cron監視)にとって一貫した挙動になる。月初のみの実行だと、月の途中で
  hire_date変更等の再計算要求が来た際に対応が遅れる。
- 未確定・要確認事項: なし。

### 論点7: Schedule再計算のトリガーと「過去確定・個別修正の保護」の実装

- 選択肢:
  - A. `hire_date`/`usage_start_date`/`work_style_id`割当/独自ルール変更等の各Command
    Handlerの延長でReactorを設置し、対象社員の`PaidLeaveScheduleAggregate`へ
    `RecalculateFutureSchedule`を発行する。Aggregate内部で「ステータスが`Granted`/
    `Cancelled`の確定済みエントリ」と「`is_manually_overridden=true`の個別修正済み
    エントリ」を再計算対象から除外し、除外した個別修正エントリについては
    「新しい算出結果と食い違う場合は`NeedsReview`へ強制遷移させ、個別修正内容自体は
    保持する」という規則で処理する。
  - B. 個別修正されたエントリも無条件で最新条件で上書きする。
- 決定: A。
- 理由: 依頼書§28「個別修正されたScheduleを後続のPolicy変更で黙って上書きしない。
  競合した場合は要確認として管理者に見せる」に直接対応する。
- 未確定・要確認事項: なし。

### 論点8: Grant自動化 vs 管理者一括承認

- 選択肢:
  - A. Assessment結果が`Eligible`(自動判定 or Override後)になった時点で、
    Schedule EntryからCommandBus経由で自動的に
    `App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave`を発行する
    (現行`GrantScheduledPaidLeaveHandler`と同じ「自動確定」の考え方を維持)。
  - B. `Eligible`になっても即Grantはせず、管理者が付与予定画面で確認し「一括付与」
    ボタンを押した時点で初めてGrantを発行する。
- 決定: B。
- 理由: 今回のユーザー要望そのものが「自動割当ではなくスケジュールを確認する画面が
  必要」という問題意識に基づく。依頼書§39でも「必要なら一括付与を可能にする」という
  管理者操作を前提とした文言になっている。ただし`Eligible`確定後に無期限で放置されると
  実質的な付与漏れになるため、`scheduled_on`当日を過ぎた`Eligible`エントリは
  管理画面のフィルタ「付与対象」に残り続け、`paid-leave:roll-schedules`実行時に
  「Eligibleのまま`scheduled_on`から30日以上未処理」のログ警告を出す
  (メール通知は依頼書の対象外方針に合わせ追加しない。既存`WarnFiveDayObligation`等と
  同じ通知経路を転用する新規通知は本変更セットのスコープ外とする)。
- 未確定・要確認事項: なし。

### 論点9: 出勤率Assessmentロジックの実装場所

- 選択肢:
  - A. `App\Domain\PaidLeaveSchedule\Support\AttendanceRateAssessor`という独立
    ステートレスサービスへ切り出し、現行`GrantScheduledPaidLeaveHandler::meetsAttendanceRate()`
    と同一の分母/分子定義(直近N ヶ月の`EmployeeCalendarEntry.is_working_day`分母、
    `AttendanceDay.status='clocked_out' OR work_type LIKE 'paid_leave_%'`分子)を
    踏襲しつつ、`usage_start_date`を跨ぐ場合(依頼書§32)とデータ不足
    (分母日数ゼロ等、依頼書§31)を`NeedsReview`として返すよう拡張する。
  - B. Schedule Aggregate内に直接実装する。
- 決定: A。
- 理由: 依頼書§56(承認前Preview)、§40(管理画面の「勤怠データを確認→再判定」導線)
  の両方から同じロジックを呼ぶ必要があり、また前回changesetの`AllocationPlanner`と
  同様の「副作用のないDomain Logicは独立サービスへ」という設計方針(依頼書§55)に揃える。
- 未確定・要確認事項: なし。

### 論点10: 既存`GrantScheduledPaidLeaveHandler`(日次全件評価バッチ)の扱い

- 選択肢:
  - A. 新しいSchedule/Assessmentドメインが「事前生成→Eligible→管理者一括付与」の
    フローを完全に代替するため、`GrantScheduledPaidLeaveHandler`と
    `paid-leave:grant-scheduled`コマンドを削除する。既存の`WarnExpiringPaidLeave`/
    `WarnFiveDayObligation`(Grant期限警告・5日取得義務警告)は本変更セットの対象外
    (別ドメイン相当のバッチのため無変更で存続)。
  - B. 新旧を並行稼働させる。
- 決定: A。
- 理由: 前回のcutoverと同じ考え方(旧実装は残さない、ユーザーの既存指示に整合)。
  日次全件評価と月次ローリングSchedule生成は同じ責務(Grant付与判定)を二重に持つと
  矛盾したGrantが発生しうるため、並行稼働は不可。
- 未確定・要確認事項: なし。

### 論点11: 既存`paid_leave_grant_rules`管理UIとの統合

- 選択肢:
  - A. `PaidLeaveAdminPage.tsx`に新しいタブ/セクションとして「付与予定」
    (Scheduleエントリ一覧)を追加し、既存の「付与ポリシー」(現行`PaidLeaveGrantRulesCard`)
    と並置する。依頼書§38の管理画面構成(概要/付与予定/残高/付与ポリシー)に合わせて
    ページを分割する。
  - B. 既存ページに全て詰め込む。
- 決定: A(依頼書§38の構成に合わせて`frontend/src/pages/paidLeave/`配下を
  `PaidLeaveOverviewPage`/`PaidLeaveScheduleSchedulePage`/`PaidLeaveBalancePage`
  (社員別、前回changesetのAllocation関連UIは別途A案件として保留中)/
  `PaidLeavePolicyPage`(現行`PaidLeaveAdminPage`をリネーム・分割)へ再編する)。
- 理由: 依頼書§38の管理画面構成そのものが「概要/付与予定/残高/付与ポリシー」という
  4分割を明示しており、今回「付与予定」画面を追加するタイミングでページ構成を
  仕様通りに揃えておかないと、後から残高画面(A案件)を追加する際に再度ページ構成を
  作り直すことになる。
- 未確定・要確認事項: 「概要」「残高」ページの中身は前回保留にしたA案件(Allocation
  確認画面)の範囲であり、本変更セットでは「付与予定」「付与ポリシー」の2画面のみ
  実装し、「概要」「残高」は空/最小限のプレースホルダーとするか、そもそもページを
  作らずナビゲーションだけ用意するか。→ **決定**: 本変更セットでは「付与予定」
  「付与ポリシー」の2画面のみ実装し、「概要」「残高」ページ・ナビ項目は追加しない
  (依頼書の管理画面構成を将来の指針として記録するに留め、無い機能のためのリンクは
  作らない。UIのIA変更は必要最小限に留める、という一般的なUI原則に従う)。

### 論点12: 付与予定画面のフィルタ・一括付与のUI挙動

- 選択肢:
  - A. 依頼書§39の通り「すべて/付与対象/対象外/要確認/変更あり」の5フィルタタブ+
     テーブル(社員/付与予定日/区分/付与候補日数/出勤率/判定状態/変更・要確認)を実装し、
     行選択→「一括付与」ボタンで選択行のEligibleエントリのみ`GrantPaidLeave`を
     一括発行する(Not Eligible/NeedsReview行は一括付与ボタンから除外・選択不可)。
  - B. フィルタ無しの単純な一覧のみ。
- 決定: A。
- 理由: 依頼書§39の明示的な要求。既存`ui-interaction-patterns`スキルの一覧画面パターン
  (フィルタタブ+選択+一括操作)に沿う。
- 未確定・要確認事項: なし。

### 論点13: NotEligible Override導線のUI挙動

- 選択肢:
  - A. 依頼書§40の通り、行の詳細を開くと「勤怠データを確認」(Assessmentの分母/分子
    内訳をドリルダウン表示)→「再判定」(Assessorを同一条件で再実行)→
    「判定結果を上書き」(理由必須の入力欄+確定ボタン)の3ステップを縦に並べた
    詳細パネルを表示する。
  - B. 単純な「Override」ボタン1つのみ。
- 決定: A。
- 理由: 依頼書§40の明示的な要求。「なぜこの判定になったか」を管理者が追跡できることが
  完了条件15に明記されている。
- 未確定・要確認事項: なし。

## 仕様確定事項(まとめ)

### ドメインモデル

- 新設: `App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate`
  (AggregateId=userId)。内部状態: `entries: array<ScheduleEntryId, {scheduledOn, category,
  candidateGrantDays, status, manualOverride: {reason, byUserId, at}|null, assessments:
  array<AssessmentId, {periodStart, periodEnd, denominatorDays, attendanceDays,
  excludedDays, attendanceRate, policyVersion, automaticResult, finalResult,
  overrideReason|null}>}>`。
- Command: `EnsureFutureScheduleGenerated`(userId、対象タイムゾーングループ、
  1年先まで存在保証、べき等)/`RecalculateFutureSchedule`(userId、変更検知の理由文字列)/
  `RunAttendanceRateAssessment`(scheduleEntryId)/`OverrideScheduleAssessment`
  (scheduleEntryId, finalResult, reason, operatorUserId)/`ManuallyEditScheduleEntry`
  (scheduleEntryId, 変更内容, reason, operatorUserId)/`ApplyScheduledGrants`
  (scheduleEntryIds[], operatorUserId — 管理者の一括付与操作。内部で対象各エントリについて
  `App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave`を発行し、Schedule Entryを
  `Granted`へ遷移させる)。
- Event: `PaidLeaveScheduleEntryCreated`/`PaidLeaveScheduleEntrySuperseded`
  (再計算により置き換えられたことを記録、監査用に古いエントリの内容も保持)/
  `PaidLeaveScheduleAssessmentRecorded`/`PaidLeaveScheduleAssessmentOverridden`/
  `PaidLeaveScheduleEntryManuallyEdited`/`PaidLeaveScheduleEntryGranted`/
  `PaidLeaveScheduleEntryCancelled`。
- 状態: `Scheduled`→`AssessmentPending`→(`Eligible`|`NotEligible`|`NeedsReview`)→
  (`Granted`|`Cancelled`)。命名は依頼書§30のものをそのまま採用。
- Assessment判定(`App\Domain\PaidLeaveSchedule\Support\AttendanceRateAssessor`):
  現行`meetsAttendanceRate()`と同一の分母/分子定義を踏襲。分母日数が0、または
  Assessment期間が対象社員の`usage_start_date`より前を含みかつ旧システムからの
  移行データが無い場合は`NeedsReview`(自動80%未満判定にしない)。
- 通常/比例/シフト区分判定(`App\Domain\PaidLeaveSchedule\Support\GrantCategoryClassifier`):
  `WorkStyle.weekly_scheduled_days >= 5 OR WorkStyle.prescribed_weekly_minutes >= 1800(30h) OR
  WorkStyle.annual_scheduled_days >= 217` → 通常付与。`WorkStyle.is_shift_based = true` →
  シフト勤務区分(依頼書§35: 初回6ヶ月は実労働日数×2、1.5年以降は前年実労働日数、
  または合意された目安勤務日数から算出。目安勤務日数は`WorkStyle`に
  `agreed_scheduled_days_per_year`(nullable)として追加保持)。上記いずれの判定にも
  必要な列が未入力の場合は`NeedsReview`。
- 通常付与表・比例付与表(`paid_leave_grant_policies`/`paid_leave_proportional_grant_policies`、
  `paid_leave_grant_expiry_policy`): version管理、シードで依頼書§34の法定値を投入
  (6か月10日/1.5年11日/2.5年12日/3.5年14日/4.5年16日/5.5年18日/6.5年以降20日、
  比例付与表は週所定日数区分×継続勤務年数)。
- `resolveGrantDays`は「対象社員に一致する`work_style_id`の`paid_leave_grant_rules`が
  `is_active=true`で存在すればそちらを優先、無ければ`GrantCategoryClassifier`の区分に
  対応する法定Policyを適用」という優先順位で日数を決定する。

### Schedule生成・再計算

- 新設artisanコマンド`paid-leave:roll-schedules`(既存`paid-leave:grant-scheduled`を置換、
  日次cron)。全アクティブ社員について現在から1年先までのScheduleエントリ存在を保証
  (べき等、`GenerateCompanyCalendarYearsCommand`と同じ設計思想)。
- `hire_date`/`usage_start_date`/`work_style_id`割当/独自ルール変更/`GrantCategoryClassifier`
  判定に影響する`WorkStyle`列変更を検知するReactorを追加し、対象社員の
  `RecalculateFutureSchedule`を発行。過去確定(`Granted`/`Cancelled`)エントリと
  `is_manually_overridden`エントリは再計算対象から除外し、後者は新算出結果と食い違えば
  `NeedsReview`へ遷移。
- 新入社員登録時も同じ`EnsureFutureScheduleGenerated`ロジックで1年先まで展開。

### 既存実装の置換

- `GrantScheduledPaidLeaveHandler`/`GrantScheduledPaidLeaveCommand`/
  `paid-leave:grant-scheduled`cron登録を削除。`WarnExpiringPaidLeave`/
  `WarnFiveDayObligation`は無変更で存続。
- 既存`paid_leave_grant_rules`/`paid_leave_grant_rule_steps`とその管理API
  (`PaidLeaveController::indexRules/storeRule/targetUsers`)は存続。保存時バリデーションへ
  「法定Policyの最低日数を下回るstepを拒否する」チェックを追加。

### 管理画面

- `frontend/src/pages/paidLeave/`を以下へ再編(既存`PaidLeaveAdminPage.tsx`を
  `PaidLeavePolicyPage.tsx`へリネームし、現行の付与ポリシー管理機能はそのまま移設):
  - `PaidLeaveSchedulePage.tsx`(新規): 付与予定一覧。フィルタタブ
    (すべて/付与対象/対象外/要確認/変更あり)、テーブル列(社員/付与予定日/区分
    (通常・比例・シフト)/付与候補日数/出勤率/判定状態/変更・要確認バッジ)、
    行選択+「一括付与」ボタン(Eligible行のみ選択可能)。
  - 行詳細パネル: 「勤怠データを確認」(Assessment内訳の分母/分子/除外日をドリルダウン)→
    「再判定」(同条件でAssessor再実行)→「判定結果を上書き」(理由必須)の3ステップ導線。
  - `PaidLeavePolicyPage.tsx`(リネーム): 既存の付与ポリシー(`paid_leave_grant_rules`)
    管理機能をそのまま移設。
  - 「概要」「残高」ページは本変更セットでは追加しない(論点11参照)。
  - ナビゲーション: `AdminLayout`の休暇管理メニューに「付与予定」を追加
    (既存の有給休暇管理メニューの構成を確認し、依頼書§38の並び
    「概要/付与予定/残高/付与ポリシー」のうち今回実装する2項目のみ追加)。

## 対象外

- 「概要」「残高」ページ(前回changesetで保留したAllocation確認画面、A案件)。
- 通常/比例/シフト判定に必要な`WorkStyle`マスタ項目(週所定労働日数・年間所定労働日数・
  目安勤務日数)の**既存社員への一括データ投入**(移行データ整備)。本変更セットでは
  列の追加とUI入力項目の追加のみ行い、未入力社員は`NeedsReview`として扱う
  (一括投入が必要なら別途依頼)。
- Eligibleのまま長期未処理のエントリに対するメール通知(論点8参照、ログ警告のみ)。
- 年5日取得義務・計画的付与(前回changesetと同様、対象外)。
- Allocation Preview API・Grant管理UI(前回changesetのA案件、引き続き保留)。

## ドキュメントへの影響

- `docs/09-usecases-paid-leave.md`: 「付与Schedule/Assessment」節を新規追加。
  UC番号は既存の続番(前回changesetでUC-P010まで使用済みのため、UC-P011以降)を割り当てる。
  `GrantScheduledPaidLeaveHandler`削除・`paid-leave:roll-schedules`への置換を明記。
- `docs/16-database-schema.md`: `paid_leave_schedule_entries`/`paid_leave_schedule_assessments`/
  `paid_leave_grant_policies`/`paid_leave_proportional_grant_policies`/
  `paid_leave_grant_expiry_policy`を追加。`work_styles`テーブルへの追加列
  (`weekly_scheduled_days`/`annual_scheduled_days`/`agreed_scheduled_days_per_year`)も
  反映。
- `docs/17-events.md`: `paid_leave_schedule.*`イベント一覧を追加。
- `docs/08-usecases-calendar-shift.md`: `WorkStyle`への追加列の参照が必要か確認し、
  必要なら追記(実装フェーズで確認)。

## モック・アセット

なし(UIワイヤーフレームは実装フェーズ入り後、Phase Cの詳細設計時に別途用意する)。

## 実装対象

- Phase A: `PaidLeaveScheduleAggregate`・Event・Command・Handler・
  `AttendanceRateAssessor`・`GrantCategoryClassifier`の単体テスト。
- Phase B: 法定Policyマスタ(migration+seed)、`WorkStyle`への列追加、
  `paid_leave_schedule_entries`/`paid_leave_schedule_assessments`Projection。
- Phase C: `paid-leave:roll-schedules`コマンド、Reactor(再計算トリガー)、
  既存`GrantScheduledPaidLeaveHandler`関連の削除。
- Phase D: 管理API(付与予定一覧・詳細・再判定・Override・一括付与エンドポイント)。
- Phase E: フロントエンド(`PaidLeaveSchedulePage`・行詳細パネル・
  `PaidLeavePolicyPage`へのリネーム・ナビ追加)。

## 検証方法

- 各Phase完了時: `cd backend && php artisan test --filter=PaidLeave`。
- Phase A完了時点で、依頼書§60のSchedule/Assessment/通常比例の検証項目
  (新入社員、月次ローリング展開、1年先まで存在、条件変更時再計算、過去確定Schedule不変、
  個別修正保護、要確認、80%以上/未満、遅刻/早退、有給取得日、除外日、
  データ不足NeedsReview、usage_start_date跨ぎ、翌年のScheduleがずれない、
  週30時間以上/週5日以上/年217日以上、各比例付与区分、勤務条件変更、シフト勤務)を
  Aggregate単体テストとして自動化する。
- Phase E完了時: `cd frontend && npm run test`および対象コンポーネントのStorybook確認。
  UI操作の実機確認(runスキル使用)。

## レビュー履歴

初版。

## 実装結果

未着手。
