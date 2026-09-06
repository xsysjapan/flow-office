# 年次有給休暇ドメイン再設計(PaidLeaveAccountAggregate中心の再構築)

ステータス: 実装中

## 変更要望(原文)

> 年次有給休暇ドメイン再設計・実装指示書
>
> ## 1. 目的
> 既存の年次有給休暇機能を再設計する。今回の変更では、単純な「自動付与機能」ではなく、
> 以下を一貫して扱える年次有給休暇ドメインを構築する。
> - 社員ごとの有給付与予定 / 法定基準に基づく付与判定 / 通常付与・比例付与・シフト勤務への対応
> - 実際に確定した有給Grant / 有給Usage / UsageとGrantのAllocation
> - 残高不足時の未充当Usage / Grant変更・取消 / 既存システムからの移行
> - 日単位・半日単位の有給取得 / 管理者による確認・例外判断
> - Event Sourcingによる監査可能な履歴
>
> 最も重要な設計変更として、社員単位の `PaidLeaveAccountAggregate` を年次有給休暇の
> Write ModelのAggregate Rootとする。Grant / Usage / Allocationを独立Aggregateとして
> 整合させるのではなく、社員一人の年休台帳全体について `PaidLeaveAccountAggregate` が
> 不変条件を保証する。将来の付与予定Scheduleは実際の権利とは性質が異なるため、
> PaidLeaveAccountとは分離する。
>
> (以下、詳細仕様 §2〜§65。全文は本タスクの依頼メッセージを参照。要点は下記
> 「仕様検討」「仕様確定事項」に転記。特に重要な制約: 社員単位Aggregate/Grantは時系列
> スタック/最新Grantのみ変更・取消可能/同日Grant禁止/過去Grant挿入禁止/Allocationは
> 自動・種別なし・最適化なし・手動再Allocationなし/Scheduleは権利ではない/残高不足でも
> 承認は人間が判断/日・半日のみ/時間単位有給は対象外/年5日取得義務管理は対象外)

## 背景・目的

現行の有給休暇機能は「Grant Aggregateが自身の残高を自己完結的に計算し、Handler側が
Eloquent Projectionを読んで業務ルール(FIFO消化・重複申請チェック・取消可否)を判定する」
構造になっている。`PaidLeaveRequestAggregate`のクラスコメント自身が「Projectionが
正でないとテストが通らないため、Aggregate replay stateは信用できない」と明記しており、
Event Sourcingの原則(Aggregateがreplayで不変条件を保証する)が崩れている。

今回、Grant取消・未充当Usageの復活・複数Grant消化・Schedule分離・出勤率判定の監査可能化
など、既存構造では条件分岐が発散するルールを追加するにあたり、「社員単位の
`PaidLeaveAccountAggregate`が年休台帳全体の不変条件を保証する」設計へ本体を作り直す。
Phase 1(本変更セット)では、実装に入る前に現行実装との差分を明確にし、新Aggregate境界・
Event/Command一覧・Projection変更・移行方針・段階的実装計画を確定させる。

## 現状(As-Is)

Explore調査結果(要点)。詳細ファイルパスは各項目内に記載。

1. **`PaidLeaveGrantAggregate`**(`backend/app/Domain/PaidLeave/Aggregates/PaidLeaveGrantAggregate.php`)
   - `grant()`/`use()`/`reverseUsage()`/`raiseWarning()`/`revoke()`。
   - **remaining_days/used_daysはaggregate自身では保持せず、Projectorがstored_eventsから
     都度再計算**(クラスdoc L12-16で明言)。
2. **`PaidLeaveRequestAggregate`**(同ディレクトリ)
   - `request()`/`designateUsage()`/`approve()`/`returnRequest()`/`cancel()`/`share()`。
   - クラスdoc(L13-18)で「業務ルール判定(残高十分性・承認者一致)はHandlerが
     Eloquent Projectionを読んで行う。テストがEvent無しで`PaidLeaveRequest`行を直接
     作ることがあるためaggregate replay stateは信用できない」と明記。
   - `workflow_requests.subject_type = 'paid_leave_request'` / `subject_id = paid_leave_requests.id`
     で連携。
3. **Event**: `PaidLeaveGranted`/`PaidLeaveGrantRevoked`/`PaidLeaveUsed`/`PaidLeaveUsageReversed`/
   `PaidLeaveUsageDesignated`/`PaidLeaveWarningRaised`/`PaidLeaveRequested`/
   `PaidLeaveRequestApproved`/`PaidLeaveRequestReturned`/`PaidLeaveRequestCancelled`/
   `PaidLeaveRequestShared`(`app/Domain/PaidLeave/Events/`、`config/event-sourcing.php`で
   `paid_leave.*`エイリアス)。
4. **Command/Handler**: `GrantPaidLeave`/`GrantScheduledPaidLeave`/`RequestPaidLeave`/
   `ApprovePaidLeaveRequest`/`ReturnPaidLeaveRequest`/`CancelPaidLeaveRequest`/
   `RevokePaidLeaveGrant`/`WarnExpiringPaidLeave`/`WarnFiveDayObligation`。
   **全Handlerが業務判定にEloquent Projectionを直接クエリしている**:
   - `ApprovePaidLeaveRequestHandler::planConsumption()`(L105-127): `PaidLeaveGrant::query()`
     から`remaining_days > 0`かつ`expires_on >= target_date`をorderBy('expires_on')で
     FIFO消化プランを作成。
   - `RequestPaidLeaveHandler::handle()`(L69-87): 同日重複申請チェックを
     `PaidLeaveRequest`/`SpecialLeaveRequest`のEloquentクエリで実施。
   - `CancelPaidLeaveRequestHandler`(L48-51): `PaidLeaveUsage::query()->where('is_confirmed', true)`
     で取消対象Grantを特定。
   - `RevokePaidLeaveGrantHandler`(L27-33): projectionの`status`/`used_days`で取消可否判定。
   - `GrantScheduledPaidLeaveHandler`: `User`/`EmployeeCalendarEntry`/`AttendanceDay`/
     `PaidLeaveGrant`を直接クエリ(§9参照)。
5. **Projector**: `PaidLeaveGrantProjector`(`paid_leave_grants`書込み。stored_eventsから
   used_days/remaining_daysを都度再集計)/`PaidLeaveRequestProjector`(`paid_leave_requests`)/
   `PaidLeaveUsageProjector`(`paid_leave_usages`。Designated→未確定行作成、初回Used→確定
   更新、複数Grant分割時は追加行insert、Reversed→行削除、Cancelled→未確定行削除)。
6. **DB**: `paid_leave_grants`(id uuid, user_id, granted_on, expires_on, granted_days,
   used_days, remaining_days, grant_reason, status, revoked_at, revoked_by_user_id,
   revoke_reason 等)/`paid_leave_usages`(id bigint, stored_event_id, user_id,
   attendance_day_id, paid_leave_grant_id, paid_leave_request_id, used_on, used_days,
   used_minutes, usage_type, is_confirmed)/`paid_leave_grant_rules`(name, work_style_id,
   min_attendance_rate既定80, first_grant_after_months, grant_cycle_months, is_active)/
   `paid_leave_grant_rule_steps`(rule_id, continuous_service_months, grant_days)/
   `paid_leave_requests`(id uuid, user_id, approver_user_id, status, leave_type, target_date,
   hours, requested_days, request_group_id 等)。
7. **Workflow連携**: `DraftWorkflowRequest`(subject_type=paid_leave_request)→
   `PaidLeaveRequestOnWorkflowRequestDraftedReactor`→`RequestPaidLeave`実行(この時点で
   `PaidLeaveUsageDesignated`発火・`attendance_days.work_type`即時反映)→
   `PaidLeaveRequestShared`→workflow submit。承認は
   `PaidLeaveApprovalOnWorkflowRequestApprovedReactor`→`ApprovePaidLeaveRequest`
   (request_group_id共有の同時申請へカスケード)。差戻し/取消も同様にReactor経由。
   `attendance_days.work_type`は**申請時点**で反映され、承認/取消/差戻しの都度
   `AttendanceCalculator`で日次再計算。
8. **残高計算**: 単一の残高クエリファイルは無く、`paid_leave_grants.remaining_days`を
   Projectorがstored_events集計で維持。消化順は`ApprovePaidLeaveRequestHandler::planConsumption()`
   のexpires_on昇順FIFO。承認前の参考値として`LeaveUsageQuery::usageBreakdownWithinPastYear()`
   がある(過去1年のrequested_days合計、権威的な残高ではない)。
9. **`GrantScheduledPaidLeaveCommand`**(artisan)→`GrantScheduledPaidLeaveHandler`:
   タイムゾーングループ×ルール単位で**日次全件評価**方式。`eligibleUsers()`→
   `monthsOfServiceOnAnniversary()`(anniversary当日のみ)→`first_grant_after_months`/
   `grant_cycle_months`判定→`resolveGrantDays()`(ステップ表から日数決定)→
   `meetsAttendanceRate()`(EmployeeCalendarEntry×AttendanceDayから出勤率計算、既定80%)→
   `GrantPaidLeave`実行(`expiresOn = today + 2年`固定、Policy化されていない)。
10. **users列**: `hire_date`(付与判定の起算日として`eligibleUsers`/`monthsOfServiceOnAnniversary`
    で使用)/`usage_start_date`(`eligibleUsers`のゲート条件のみ、法定基準日ではない)/
    `paid_leave_auto_grant_enabled`(`eligibleUsers`フィルタ、`SetPaidLeaveAutoGrantEnabled`
    Command/Event/Projector経由でUI公開済み)。
11. **Calendar/WorkStyle**: `WorkStyle`モデル(`prescribed_daily_minutes`/
    `prescribed_weekly_minutes`/`is_shift_based`/`work_time_system`等)、
    `EmployeeCalendarEntry`(`is_working_day`/`work_style_id`/`work_date`の日次展開)。
    出勤率計算は`GrantScheduledPaidLeaveHandler::meetsAttendanceRate()`に**インライン実装**
    されており、共有サービスは存在しない。比例付与判定(週所定日数・週所定時間・年間所定
    日数からの通常/比例判定)は**現状未実装**(`paid_leave_grant_rule_steps`は
    `work_style_id`単位の付与テーブルのみで、通常/比例の自動判定ロジックは無い)。
12. **既存テスト**(`backend/tests/Feature/PaidLeave/`):
    `PaidLeaveTest`/`PaidLeaveRequestTest`(最多、FIFO複数Grant消化・残高不足承認可・
    月締め後取消禁止等)/`PaidLeaveAdminCancelRequestTest`/`PaidLeaveGrantRevocationTest`/
    `PaidLeaveGrantRuleTargetUsersTest`/`PaidLeaveScheduledBatchTest`(バッチ最多、
    anniversary判定・二重付与防止・出勤率閾値・トグルOFF時スキップ等)/
    `PaidLeaveHistoryTest`。**これらはProjection直接作成に依存する箇所が多く、
    新Aggregate構造では前提が崩れるテストが相当数ある**(詳細はテスト計画節)。

## 仕様検討

### 論点1: PaidLeaveAccountAggregateの集約境界と保持データ
- 選択肢:
  - A. Grant/Usage/AllocationをAggregate内部Entity/Valueとして保持し、氏名・所属等の
    整合性維持に不要な情報は持たない(依頼書§3の指示通り)。
  - B. 既存`PaidLeaveGrantAggregate`/`PaidLeaveRequestAggregate`を維持し、整合性判定用の
    Read-through Aggregateを別途追加する。
- 決定: A。
- 理由: 依頼書の最重要方針であり、既存構造(Handlerがprojectionを読んで判定)の問題を
  解消する唯一の方法。BはAggregateを増やすだけで既存の「Projectionが正」問題を残す。
- 未確定・要確認事項: なし(依頼書で確定済み)。

### 論点2: 既存Aggregateの扱い
- 選択肢:
  - A. `PaidLeaveGrantAggregate`/`PaidLeaveRequestAggregate`を新規実装完了後に削除し、
    既存イベントストリームは新Aggregateへのcutover migrationでのみ参照する
    (replayし続けない)。
  - B. 新旧Aggregateを並行稼働させ、段階的に新Aggregateへ寄せる。
- 決定: A。cutover時点で既存Aggregateのevent streamから現在有効な状態
  (Grant一覧・確定Usage一覧・Allocation)をProjectionから読み取り、新Aggregateへ
  **専用migration Command**で「事実」として記録し直す。以降は新Command/Handlerのみ
  が`stored_events`へ書き込み、旧Aggregateクラスはコード上削除する。
- 理由: 依頼書§58で「新旧ロジックを混在させない」と明記。既存Event
  (`paid_leave.granted`等)は監査目的でstored_eventsに残すが、以後replay対象としない
  (旧Aggregate UUIDとは別のAggregateId=userIdで新ストリームを開始する)。
- 未確定・要確認事項: なし。

### 論点3: Grant/Usage/AllocationのID体系
- 選択肢:
  - A. Grant/Usage/Allocationとも独立UUID(`grant_id`/`usage_id`)を払い出し、Allocationは
    `(usage_id, grant_id)`の複合キー+`allocated_days`。
  - B. 配列インデックスのみで管理し、外部キー的なIDを持たない。
- 決定: A。
- 理由: Projection側で個々のGrant/Usageを参照・表示・履歴追跡する必要があるため
  (依頼書§41 社員別画面でGrant一覧・Usage履歴・Allocation内訳を個別に見せる要件)、
  安定したIDが必須。
- 未確定・要確認事項: なし。

### 論点4: Grantスタックの「最新」判定基準
- 選択肢:
  - A. 取消されていないGrantの中で`grantedOn`が最大のものを「最新」とする
     (`grantedOn`同士の比較。依頼書§9で"Grant日付変更は最新Grantのみ・前のGrantより
     後であること"と明記されているため`grantedOn`基準が自然)。
  - B. 作成順(Aggregate内配列の末尾)を「最新」とする。
- 決定: A(`grantedOn`基準)。ただし通常運用では新規Grant追加時に`grantedOn`昇順を
  強制するため(§6.1不変条件)、AとBは同じ結果になる。Migration専用パスのみ例外的に
  複数Grantを一括構築するため、その際も最終的に`grantedOn`昇順になるよう構築する。
- 理由: 依頼書の時系列スタック規定はすべて`grantedOn`基準の文言(前のGrantより後の日付、
  同日禁止等)。
- 未確定・要確認事項: なし。

### 論点5: Allocationの直近未来Grant判定(§20 Step 2)の「1件だけ」の対象範囲
- 選択肢:
  - A. `usedOn`より後に`grantedOn`を持つ、取り消されていないGrantのうち`grantedOn`が
    最も近い1件のみを対象とする(Step 1の消化順=expiresOn近い順とは無関係に、
    Step 2は`grantedOn`近さで1件選ぶ)。
  - B. Step 1で使ったGrant集合を除外して、残り全Grantから`expiresOn`近い順に複数件へ
    充当する。
- 決定: A。
- 理由: 依頼書§20 Step2で「最も近い付与済みGrant1件だけ」「そのGrantで不足しても
  さらに次の未来Grantへは進まない」と明記。
- 未確定・要確認事項: なし。

### 論点6: 未充当Usageの自動Allocationのトリガー実装方式
- 選択肢:
  - A. Aggregate内の各Command処理(Grant追加/増額/expiry延長/Usage取消によるAllocation
    解除)の末尾で、都度「未充当Usageのうち影響を受けたGrantを利用可能なもの」を
    `usedOn`昇順に再評価し、Allocationを追加実行する(同一Command実行内でイベントを
    複数発行)。
  - B. 別途非同期ジョブ・Reactorで未充当Usageの再Allocationをバッチ的に行う。
- 決定: A。
- 理由: 依頼書§23-24は「Grantに空きが発生した契機」で自動Allocationすると明記しており、
  かつ同一Aggregate内の不変条件(残高が空きを超えてAllocationされない)を守るには
  同一トランザクション・同一Aggregateバージョン内で完結させる必要がある。非同期化すると
  楽観的排他(§57)との整合が複雑になる。
- 未確定・要確認事項: なし。

### 論点7: Projectionテーブル構成
- 選択肢:
  - A. `paid_leave_grants`/`paid_leave_usages`/`paid_leave_usage_allocations`の3テーブルを
    Event Projectorで新規に作り直す(既存の同名テーブルはカラム構成を作り直すため、
    新テーブル名にリネームするか、既存テーブルをdropして再作成するか要検討)。
  - B. 既存`paid_leave_grants`/`paid_leave_usages`のカラムを流用し、差分カラムのみ
    追加・削除するAlter migrationにする。
- 決定: A(実体としては既存テーブルを一度truncateしてProjector再生成する運用とし、
  テーブル名は変えない。ただし`paid_leave_usages`は列構成を大きく変える
  ―`paid_leave_grant_id`単一列を廃止し、`paid_leave_usage_allocations`テーブルへ
  移す―ため、事実上作り直しに近いAlter migrationとする)。加えて集計用
  `paid_leave_balances`(社員単位の現在残高キャッシュ)を新設する。
- 理由: 依頼書§4「Projectionは用途別に正規化してよい」「正規化されたRead Modelを
  Aggregateの整合性判定の唯一の根拠にしない」の通り、Projectionは表示専用として
  作り直す。テーブル名を変えないことで既存の管理画面向けAPIエンドポイントの参照を
  最小改修で済ませる。
- 未確定・要確認事項: `paid_leave_grants.used_days`/`remaining_days`は
  `paid_leave_balances`相当の集計を個別Grant単位でも引き続き載せるか、それとも
  Allocationテーブルからの都度JOIN集計に統一するか。→ 表示パフォーマンスのため
  `paid_leave_grants`にも`allocated_days`/`remaining_days`をProjector側で
  非正規化キャッシュとして持たせ、Source of TruthはAllocationテーブルとする
  (依頼書§7「意味上のSource of Truthを二重化しない」に従い、あくまでキャッシュ
  である旨をProjectorのコメントで明記する)。

### 論点8: Scheduleと既存`paid_leave_grant_rules`/`paid_leave_grant_rule_steps`の関係
- 選択肢:
  - A. 既存の`paid_leave_grant_rules`/`paid_leave_grant_rule_steps`(work_style単位の
    カスタムルール)を「法定基準の比例付与表」を表現するPolicyマスタへ役割変更し、
    加えて新規`paid_leave_schedules`(社員×次回付与予定日)と
    `paid_leave_assessments`(出勤率判定履歴)を新設する。
  - B. 既存ルールテーブル群を廃止し、法定表をコード定数として実装する。
- 決定: A。
- 理由: 依頼書§8「法務判断が必要な値はマスタ化する」「ハードコードしない」という
  プロジェクト全体の設計原則(CLAUDE.md原則8)と、依頼書§34-36の法定比例付与表・
  通常/比例判定・シフト勤務判定をversion管理可能なPolicyとして表現する要求に合致。
  既存の`work_style_id`単位カスタムルールという概念は「法定基準に加えて会社が独自に
  上乗せする付与ルール」の余地として残す(法定基準を下回る設定はバリデーションで拒否)。
- 未確定・要確認事項: 独自上乗せルールの具体的なUI・バリデーション仕様はPhase 7の
  詳細設計で確定させる(Phase 1では「法定基準未満を拒否するPolicy構造にする」ことのみ
  ここで確定させ、詳細画面仕様は先送りする)。

### 論点9: 出勤率Assessmentの実装場所
- 選択肢:
  - A. `App\Domain\PaidLeave\Support\AttendanceRateAssessor`のような独立ドメインサービス
    として切り出し、`PaidLeaveAssessment`という監査可能なEntity/Projectionを新設、
    ScheduleのAssessment実行時にこれを呼ぶ。
  - B. 現状通り`GrantScheduledPaidLeaveHandler`にインライン実装したまま、出力する
    判定結果の記録先だけ増やす。
- 決定: A。
- 理由: 依頼書§30-32でAssessmentを独立概念として監査可能に保持することを明記。
  現状のインライン実装は共有性が無く(依頼書調査§11で確認)、Preview機能(§56)や
  管理画面の「勤怠データを確認→再判定→上書き」導線(§40)からも同じロジックを
  呼ぶ必要があるため、独立サービス化が必須。
- 未確定・要確認事項: なし。

### 論点10: Workflow層と`PaidLeaveRequestAggregate`廃止後の申請導線
- 選択肢:
  - A. `RequestPaidLeave`相当のCommandは維持しつつ、内部で
    `PaidLeaveAccountAggregate::designateUsage()`を呼ぶ形に差し替える。
    `PaidLeaveRequest`という申請自体の状態(submitted/approved/returned/cancelled)は
    既存の`workflow_requests`の汎用ステータスへ完全に寄せ、
    `paid_leave_requests`固有ステータス列は非正規化キャッシュ(表示専用)として残すか
    削除するかは移行時に精査する。
  - B. `PaidLeaveRequest`集約・テーブルを即座に全廃し、`workflow_requests`のみで
    申請状態を持つ。
- 決定: A(ただしdays/leave_type/target_date/hoursなど有給固有の申請パラメータは
  `paid_leave_requests`に残置し、あくまで「進行状況(status)」の正はworkflow側、
  「有給固有の申請内容」の正はPaidLeaveドメイン側、という分離をCLAUDE.md原則14
  ―申請ワークフローと業務ロジックの分離―通りに保つ)。
- 理由: 依頼書§13「PaidLeaveRequestAggregateの責務は廃止またはWorkflowへ移行」かつ
  CLAUDE.md原則14「進行状況はドメイン横断で統合的に扱うが、業務ロジックは専用ドメインに
  留める」。Bは既存の`request_group_id`による期間バッチ申請等、有給固有の情報を置く場所を
  失う。
- 未確定・要確認事項: `request_group_id`(複数日一括申請の同時操作)の扱いは
  Phase 4詳細設計で確定(Usageは日ごとに作成されるため、`request_group_id`は
  workflow_request側の「関連申請グループ」概念として維持する想定)。

### 論点11: 移行(migration)の入力モードとタイミング
- 選択肢:
  - A. 依頼書§46の3モード(Grant単位で完全に分かる/前年度繰越+当年度残高/残高と
    有効期限のみ)をすべて受け付けるMigration Command群
    (`MigratePaidLeaveGrant`のような専用Command、通常の`GrantPaidLeave`とは別)を用意し、
    社員ごとの`usage_start_date`時点でcutoverする。
  - B. 移行は全社員一律の`usage_start_date`(全社共通日)を前提にシンプル化する。
- 決定: A。
- 理由: 依頼書§44/§48で「usage_start_dateは社員ごとに異なりうる」
  「flow-office側で過去期間をbackfillしない」と明記。既存`users.usage_start_date`列は
  既に社員単位で持てる設計になっている(現状調査§10)。
- 未確定・要確認事項: 実際の移行データ(旧システムのエクスポート形式)がどのモードに
  該当するかは、実装フェーズ(Phase 9)着手時に人事・労務担当へ確認する。Phase 1では
  3モードを受け付けられるCommand/Event構造を用意するところまでとする。

### 論点12: 楽観的排他・冪等性の実装方式
- 選択肢:
  - A. 既存の`spatie/laravel-event-sourcing`が提供する`AggregateRoot`の標準バージョン
    管理(`aggregate_versions`テーブル + 楽観ロック例外)をそのまま利用し、Reactor/Handler
    側でWorkflow再送時の冪等性は「同一`workflowRequestId`に対し既にUsageが確定済みなら
    早期リターンする」ガードをHandler内に実装する。
  - B. 独自の冪等性キー管理テーブルを新設する。
- 決定: A。
- 理由: 既存インフラ(spatie event-sourcing)がAggregateごとの楽観的排他を標準提供して
  おり、依頼書§57もそれを前提にした文言(「Event StoreのAggregate versionを利用した
  楽観的排他」)。独自テーブルは過剰。
- 未確定・要確認事項: なし。

## 仕様確定事項(まとめ)

### Aggregate
- 新設: `App\Domain\PaidLeave\Aggregates\PaidLeaveAccountAggregate`(AggregateId = `userId`)。
- 内部状態(replayで再構築、Projectionには依存しない):
  - `grants: array<GrantId, GrantState>`(`grantId, grantedOn, expiresOn, grantedDays,
    revoked, grantReason/source, allocations: array<UsageId, allocatedDays>`)
  - `usages: array<UsageId, UsageState>`(`usageId, workflowRequestId, attendanceDayId,
    usedOn, usedDays, confirmed, cancelled, allocations: array<GrantId, allocatedDays>`)
- 公開メソッド: `grant()`/`changeGrantAmount()`/`changeGrantDate()`/`changeGrantExpiry()`/
  `revokeGrant()`/`designateUsage()`/`confirmUsage()`/`cancelUsage()`。
- 内部ロジック: `allocateUsage(UsageId)`/`allocateUnallocatedUsages()`/`availableDays(date)`/
  `latestActiveGrant()`/`assertGrantIsLatestAndActive(GrantId)`。
- 不変条件(Aggregate内で必ず保証、Handlerに分散させない):
  1. 新規Grantは非取消の現在最新Grantより`grantedOn`が後(同日不可)。
  2. Grant変更・取消は現在最新の非取消Grantのみ許可。
  3. Grant減額は`allocated`合計を下回れない。
  4. Grant有効期限短縮はAllocation件数0のGrantのみ許可。
  5. Grant取消時は当該Grantの全Allocationを解除(Usage自体は取消しない)。
  6. Allocationは`usedOn`基準で有効なGrントのみ対象(処理日・承認日は不使用)。
  7. 空き発生時は未充当Usageを`usedOn`昇順に自動Allocation、既存Allocationは
     組み替えない。

### Event(`App\Domain\PaidLeave\Events\`、`config/event-sourcing.php`に`paid_leave_account.*`
で新規登録。既存`paid_leave.*`は廃止せずstored_eventsに残すが以後未使用)
- `PaidLeaveGrantCreated`(grantId, grantedOn, expiresOn, grantedDays, grantReason, source)
- `PaidLeaveGrantAmountChanged`(grantId, newGrantedDays, reason, changedByUserId)
- `PaidLeaveGrantDateChanged`(grantId, newGrantedOn, reason, changedByUserId)
- `PaidLeaveGrantExpiryChanged`(grantId, newExpiresOn, reason, changedByUserId)
- `PaidLeaveGrantRevoked`(grantId, revokedByUserId, reason)
- `PaidLeaveUsageDesignated`(usageId, workflowRequestId, attendanceDayId, usedOn, usedDays)
- `PaidLeaveUsageConfirmed`(usageId, confirmedByUserId)
- `PaidLeaveUsageCancelled`(usageId, cancelledByUserId, reason)
- `PaidLeaveUsageAllocated`(usageId, grantId, allocatedDays)
- `PaidLeaveUsageAllocationReleased`(usageId, grantId, releasedDays)
- Migration専用: `PaidLeaveAccountMigrated`(cutoverDate, grants: [{originalGrantedOn?,
  originalGrantedDays?, remainingDaysAtCutover, expiresOn, source, cutoverMetadata}])
  ―通常の`grant()`不変条件チェックを経由しない専用Aggregateメソッド
  `migrateGrants()`から発行する。

### Command / Handler
- `GrantPaidLeave` / `ChangePaidLeaveGrantAmount` / `ChangePaidLeaveGrantDate` /
  `ChangePaidLeaveGrantExpiry` / `RevokePaidLeaveGrant` / `DesignatePaidLeaveUsage` /
  `ConfirmPaidLeaveUsage` / `CancelPaidLeaveUsage` / `MigratePaidLeaveAccount`
  (Migration専用、管理者権限限定)。
- `GrantScheduledPaidLeave`はSchedule確定(Assessment Eligible)時に`GrantPaidLeave`を
  発行するだけの薄いCommandへ縮小。
- Handlerはprojectionへの業務ルール問い合わせを行わない
  (`aggregateRoot(...)->handle()`パターンで既存イベントをreplayしてから判定)。
  重複申請チェック等、有給ドメインの外側(Workflow側)の判定はWorkflow側に残す。

### Schedule / Assessment(既存Aggregateとは別系統)
- 新設: `App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate`
  (AggregateId = `userId`、または社員×基準日のEntity集合を1 Aggregateとして保持)。
- Projection: `paid_leave_schedules`(user_id, scheduled_on, category[通常/比例/シフト],
  candidate_days, status[Scheduled/AssessmentPending/Eligible/NotEligible/NeedsReview/
  Granted/Cancelled], manual_override_reason, overridden_by_user_id, overridden_at)、
  `paid_leave_assessments`(schedule_id, assessment_period_start/end, denominator_days,
  attendance_days, excluded_days, attendance_rate, policy_version, automatic_result,
  final_result, override_reason)。
- 月次バッチ`paid-leave:roll-schedules`(既存`GrantScheduledPaidLeaveCommand`を置換)が
  全社員について「現在から1年先までSchedule存在」をローリング保証。新入社員登録時も
  同ロジックで1年先まで展開(Reactorで`UserCreated`等をトリガー)。
- `hire_date`/`usage_start_date`/Policy/WorkStyle等の変更検知で未来Scheduleのみ再計算
  (過去の確定Assessment/Grantには triggeredな再計算をかけない)。個別修正済み
  Scheduleは自動再計算で上書きせず「要確認」フラグを立てる。

### Policy(法定値マスタ)
- 新設: `paid_leave_grant_policies`(通常付与テーブル: 継続勤務月数→付与日数、
  version管理)、`paid_leave_proportional_grant_policies`(比例付与区分×継続勤務月数→
  付与日数)、`paid_leave_grant_expiry_policy`(既定2年、version管理)。
- 既存`paid_leave_grant_rules`/`paid_leave_grant_rule_steps`は「会社独自の上乗せルール」
  として存続させ、Policyで定めた法定最低日数を下回る設定を保存時にバリデーションで拒否。
- 通常/比例判定・シフト勤務判定は`App\Domain\PaidLeaveSchedule\Support\GrantCategoryClassifier`
  として独立実装し、WorkStyle(週所定日数・週所定時間・年間所定日数・is_shift_based)から
  判定する。

### Projection変更
- `paid_leave_grants`: 既存列を維持しつつ`allocated_days`/`remaining_days`を
  Allocationテーブルからの非正規化キャッシュへ位置づけ直す。`revoked`は既存`status`列を
  流用。
- `paid_leave_usages`: `paid_leave_grant_id`単一列を廃止。`confirmed`/`cancelled`を
  ブール列として明示。
- 新設`paid_leave_usage_allocations`(usage_id, grant_id, allocated_days, timestamps)。
- 新設`paid_leave_balances`(user_id, available_days, pending_days, unallocated_days,
  next_grant_scheduled_on 集計、社員別画面用)。
- `paid_leave_requests`は「有給固有の申請パラメータ」テーブルとして存続(status列は
  workflow_requests側のstatusのキャッシュ表示用に留める)。

### DB migration方針
- 既存`paid_leave_grants`/`paid_leave_usages`はAlter migrationでカラム整理
  (Projectorが再生成する前提のため、実装時に一度truncate→event replayで再構築)。
- 新規テーブル: `paid_leave_usage_allocations`/`paid_leave_balances`/
  `paid_leave_schedules`/`paid_leave_assessments`/`paid_leave_grant_policies`/
  `paid_leave_proportional_grant_policies`/`paid_leave_grant_expiry_policy`。

### 既存データ移行
- cutoverは社員ごとの`usage_start_date`時点。旧`paid_leave_grants`/`paid_leave_usages`
  Projectionから、各社員の`usage_start_date`時点で有効なGrant状態を読み取り、
  `MigratePaidLeaveAccount` Commandで`PaidLeaveAccountMigrated`イベントとして1回で記録。
  移行前の個別Usage履歴の完全再現は行わない(依頼書§45)。

### Workflow連携
- `DraftWorkflowRequest`(subject_type=paid_leave_request)→Reactorが
  `DesignatePaidLeaveUsage`を発行(Usageは1日ごとに作成、`confirmed=false`)。
- Workflow承認→Reactorが`ConfirmPaidLeaveUsage`発行(Aggregate内でAllocation実行)。
- Workflow差戻し/取消(承認前)→`CancelPaidLeaveUsage`(未確定Usageを取消、再提出時は
  新規Usageを作成)。
- 承認済みUsageの取消申請→取消申請中はUsage/Allocationを維持し、Workflowで取消承認
  された時点でのみ`CancelPaidLeaveUsage`発行(Allocation解除・Grant空き復元)。
- 承認画面には`AllocationPlanner`(副作用なしのDomain Service、Aggregateと同一ロジックを
  共有)によるPreview(Grant別充当予定・不足日数)を表示。

## 対象外

- 時間単位有給(依頼書§50)。
- 年5日取得義務の監視・警告機能(既存`WarnFiveDayObligation`はPhase移行後、当面は
  現行のまま残置し、新Aggregateへの本格統合は別Featureとして対象外とする)。
- 計画的付与(依頼書§52)。
- 「繰越実行」画面・繰越バッチ処理(依頼書§42、既存に無い機能なので新設もしない)。
- 半日以外の柔軟な時間区分(AM/PM境界時刻の変更等、既存Attendance/Request側の責務のまま
  据え置き)。
- 本変更セットの実装スコープでの`GrantScheduledPaidLeaveHandler`の即時削除
  (Phase 7でSchedule/Assessment実装完了後に置換。Phase 1〜6期間中は現行バッチと
  新Aggregateが並行稼働する期間があることを許容する。ただし新規Grantは必ず新
  `GrantPaidLeave` Command経由で`PaidLeaveAccountAggregate`へ記録される)。
- UI実装の詳細(画面ワイヤーフレーム等)はPhase 6着手時に別途モックを用意する
  (本変更セットでは対象外)。

## ドキュメントへの影響

- `docs/09-usecases-paid-leave.md`: 全面改訂が必要(現行の自動付与・申請・承認フローの
  記述を新Aggregate構造・Schedule/Assessment分離・Allocationルールに合わせて書き直す)。
  実装Phase進行に合わせて段階的に更新する。
- `docs/16-database-schema.md`: `paid_leave_*`テーブル群の定義を新構成に合わせて更新。
- `docs/17-events.md`: 新Event一覧(`PaidLeaveGrantCreated`等)を追加、旧Event
  (`PaidLeaveGranted`等)は「廃止(監査目的でstored_eventsに残存)」と明記。
- `docs/03-architecture.md`: 変更なし(既存の原則1〜14に矛盾する変更ではなく、
  むしろ原則1・2・14により忠実にする再設計のため)。
- `docs/20-implementation-notes.md`: 実装Phase進行時に、Aggregate境界・Migration手順の
  実装メモを追記する想定(Phase 2着手時に追記)。

## モック・アセット

なし。

## 実装対象(Phase構成、依頼書§61に準拠)

- Phase 1(本変更セット): 設計ドキュメントとユーザーレビュー。
- Phase 2: `PaidLeaveAccountAggregate`本体・Grant/Usage/Allocation Domain Model・単体テスト。
- Phase 3: Projection(新テーブルmigration・Projector)・Event登録。
- Phase 4: 既存有給申請Workflowを`DesignatePaidLeaveUsage`/`ConfirmPaidLeaveUsage`へ接続、
  `attendance_days`連携の付け替え。
- Phase 5: `AllocationPlanner` Preview・承認画面向けAPI。
- Phase 6: Grant管理UI・社員別有給画面。
- Phase 7: `PaidLeaveScheduleAggregate`/Assessment・Policy(法定表)・通常/比例/シフト判定。
- Phase 8: 月次ローリングSchedule展開バッチ、旧`GrantScheduledPaidLeaveHandler`置換。
- Phase 9: Migration Command・移行データ投入。
- Phase 10: 管理UI仕上げ、Query/帳票。

各Phaseは単独でビルド・テスト可能な単位でコミットする(依頼書§61-62)。

## 検証方法

- 各Phase完了時: `cd backend && php artisan test --filter=PaidLeave` (Phase進行に応じて
  対象ディレクトリ/フィルタを拡張)。
- Phase 2完了時点で、依頼書§60のGrant/Usage/Allocationテスト項目
  (初回Grant/後続Grant/同日拒否/過去挿入拒否/最新変更/取消スタック/増額/減額拒否/
  expiry延長/短縮拒否/overlapping validity/直近未来Grant/古いUsage追加時の非組替え等)を
  Aggregate単体テスト(Event→状態→次のCommandの結果を検証、Projection不使用)として
  全て自動化する。
- 既存`backend/tests/Feature/PaidLeave/*`は新構造前提で書き換えが必要な箇所を洗い出し、
  Phase 2〜4で「Projection行を直接作成してHandlerに読ませる」形式のテストを
  「Event→Aggregate replay→Command」形式へ移行する(依頼書§59)。
- Phase 9完了時: 移行3モード(A/B/C)それぞれのサンプルデータでcutoverし、
  cutover後の残高・次回Schedule日が手計算と一致することを確認する。

## レビュー履歴

初版。

## 実装結果

未着手。
