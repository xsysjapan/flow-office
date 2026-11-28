# grant-rules-events(有給・特別休暇の付与ルール・付与表をイベント化し、既存の行を移行する)

ステータス: レビュー中

## 変更要望(原文)と決定
> 同様に修正をお願いします。問題があれば聞いてください
- 有給・特別休暇の付与ルールと付与表をイベント化する(ユーザーの回答 2026-11-28「今回あわせてイベント化する」)。既存の行はデータ移行(管理画面の運用コマンド)でイベントを作り直す。
- 新しい DB で初期のルールを直接書く過去の2つのマイグレーションは、新しい DB ではデータを書かないようにし、初期の全社のルールはシーダーとオンボーディングが Command で作る(ユーザーの回答「提案どおり」。本番は既に実行済みで影響なし)。
- 移行のイベントの記録の順は、data-correction の規則どおり振り直す(ユーザーの回答)。

## 背景・目的
付与ルール・付与表は、イベントを使わずに Controller が直接 DB を書いている(原則1・2に反する)。作り直し(ReadModelRebuilder)の対象にもならず、変更の履歴も残らない。Command → イベント → Projector にし、既存の行をイベントで作り直す。

## 現状(As-Is)(調査 2026-11-28、investigator)
| テーブル | 主キー | 書き手(Controller) | 読み手 |
|---|---|---|---|
| `paid_leave_grant_rules` | 連番 | `PaidLeaveController::storeRule`(:81-112)・`updateRule`(:129-161)・`destroyRule`(:181-192。有効なルールは削除不可)。トランザクション無し。保存の後に `ReapplyPaidLeaveSchedulePolicyJob`(DB キュー、全利用者の付与予定の作り直し)を dispatch(削除は dispatch しない) | `PaidLeaveGrantRuleResolver`(勤務形態のルール→全社のルール)、`ScheduleCandidateGenerator`、`PaidLeaveScheduleAssessmentPlanner`、画面 |
| `paid_leave_grant_rule_steps` | 連番 | 上の保存でルールの子として全削除→作成 | `ScheduleCandidateGenerator::resolveGrantDays` |
| `paid_leave_grant_policies`(法定の付与表・通常) | 連番 | `storeGrantPolicy`(版を `v<最大+1>` で採番して行を追加。版の更新・削除は無い) | `PaidLeaveController` の法定の最低日数の検査と表示だけ(付与予定の計算は読まない) |
| `paid_leave_proportional_grant_policies`(比例) | 連番 | `storeProportionalGrantPolicy`(同上) | 同上 |
| `special_leave_grant_rules`・`special_leave_grant_rule_steps` | 連番 | `SpecialLeaveController::storeRule`(:130-153。作成だけ。更新・削除の API は無い) | `GrantScheduledSpecialLeaveHandler` |
| `paid_leave_grant_expiry_policy` | 連番 | 書き手・読み手とも無い(モデル・シーダー・テストだけ) | — |
- 付与予定・付与はルール・付与表の ID を参照しない(候補の日数の値だけを持つ)。
- フロントはルールの ID を数値として扱う(`api/paidLeave.ts` の `deletePaidLeaveGrantRule(id: number)` 等)。
- `ScheduleCandidateGenerator.php:26-28` のコメント「該当のルールが無ければ法定の付与表を適用する」は実装と合わない(実装はルールが無ければ候補が無い。ルールの段に該当が無ければ日数未確定)。`PaidLeaveGrantPolicy` の docblock も存在しないクラスを参照している。
- 前例: 集約ルート自体を Projector 化する場合は主キーを UUID にする(docs/03 3.4「実装上のポイント」)。親から一括で置き換える子データは UUID にせず、親のイベントから全件を作り直す(docs/29)。データ移行の手順は `.claude/skills/data-correction`(作り直し、管理画面の運用コマンド、`MAINTENANCE_COMMANDS`、移行前のデータがあると作り直しを拒否する検出器 `CorrectionPendingService`)。
- 作り直しの対象は、Projector が書くテーブルが自動で入る(`ReadModelTableLister`)。イベント化の前に Projector を足すと、作り直しでルール・表が消える。

## 設計前提の検証
- 正データと派生データ: 付与ルール・付与表を、イベントを正にした派生データ(Projector が書く)にする。直接の書き込みを無くす。
- 定義と実態: 法定の付与表は付与予定の計算に使われていない(最低日数の検査と表示だけ)。コメントの誤りを直す。期限の表(`paid_leave_grant_expiry_policy`)は使われていない。
- 補正・移行の実行経路: 移行は管理画面の運用コマンドから試し実行・実行する。移行の前に管理者がログインして管理画面を使えること(付与ルールは認証に関わらない)。マイグレーションでデータを補正しない(列の追加・型の変更だけ)。
- 原則17: 各入口は1つの Command。付与予定の作り直しは、ルールのイベントを受けて行う。

## 仕様検討

### 論点1: 集約・主キー・文脈
- 決定:
  - 文脈: 付与ルール・付与表は、読み手(付与予定の計算)と同じ `PaidLeaveSchedule` の文脈に置く(別の文脈にすると原則15に反する)。特別休暇の付与ルールは `SpecialLeave` の文脈。
  - 有給の付与ルール・特別休暇の付与ルール: 1ルール=1集約。主キーを UUID にする(docs/03 の規則。docs/29 で撤回した別列方式は採らない)。段(子)は親のイベントから全件を作り直す(段の主キーは連番のまま、`rule_id` は親の UUID を参照)。
  - 有給の法定の付与表(通常・比例): 表ごとに1つの集約(ID は固定の uuid5)。集約の状態に公開済みの版を持ち、版の採番(最大+1)を集約の中で守る。行は版の公開のイベントから作る(行は子データ、主キーは連番)。
  - uuid5 の名前空間は固定し、名前に種別を入れる(`paid-leave-grant-rule:{旧id}`・`special-leave-grant-rule:{旧id}`・`paid-leave-grant-policy`・`paid-leave-proportional-grant-policy`。前例 `ConfigureAssetNumberRuleHandler.php:46`)。
- 影響: ルールの ID の型が文字列(UUID)になる。frontend の型(`types.ts` の `PaidLeaveGrantRule.id`・特別休暇のルールの `id`、`api/paidLeave.ts`・`api/specialLeave.ts`、stories・テストのモック)、`RebuildPaidLeaveScheduleCommand` の `--rule-id`(数値への変換をやめる)を直す。OpenAPI は既に `format: uuid`。
- `PaidLeaveGrantRuleResolver` の同じ順位(作成時刻が同じ)の扱い: 同じ勤務形態に有効なルールを複数持てないことを Handler で検査する(並びに依存しない)。Projector はルールの `created_at` にイベントの発生時刻を入れる。
- 未確定・要確認事項: なし

### 論点2: Command とイベント
| 対象 | Command | イベント |
|---|---|---|
| 有給の付与ルール | `CreatePaidLeaveGrantRule`・`UpdatePaidLeaveGrantRule`・`DeletePaidLeaveGrantRule` | `paid_leave_grant_rule.created`・`.updated`・`.deleted`(段の一覧を含む) |
| 有給の法定の付与表(通常・比例) | `PublishPaidLeaveGrantPolicyVersion`・`PublishProportionalGrantPolicyVersion` | `paid_leave_grant_policy.version_published`・`paid_leave_proportional_grant_policy.version_published`(行の一覧・効力の開始日を含む) |
| 特別休暇の付与ルール | `CreateSpecialLeaveGrantRule` | `special_leave_grant_rule.created`(段の一覧を含む) |
- イベントは全項目と段の全件を明示的に持つ(差分にしない)。作成時に省いた項目の既定値(80・6・12 等)と、更新で送られなかった項目の補いは Handler で決める(DB の列の既定値に頼らない。後のマイグレーションで既定値を変えても作り直しの結果が変わらないように)。付与表の版の公開は `is_active`・`effective_from`(null を許す)を持つ。
- 検査(法定の最低日数・区分×月の重複・有効なルールの削除の禁止・同じ勤務形態の有効なルールの重複)は Handler と集約に移す。削除の可否は集約の状態の `is_active` で判断する。
- 付与予定の作り直し: 有給の付与ルール・付与表のイベントを受ける Reactor が `ReapplyPaidLeaveSchedulePolicyJob` を `->afterCommit()` 付きで dispatch する(Controller からの dispatch をやめる。前例 `ReapplyScheduleOnFiscalYearStartChangedReactor.php:58`)。非同期のままにする(全利用者を条件で処理する一括で、docs/03 3.10 の「非同期にするのは条件で数千件を処理する一括」に当たる)。ルールの削除でも dispatch する。作り直し(replay)では Reactor は走らないので Job は積まれない。Reactor のテストは `Queue::fake()` で「積んだこと」を確かめる。
- 未確定・要確認事項: なし

### 論点3: 新しい DB の初期のルール
- 決定(ユーザーの回答「提案どおり」):
  - 過去の2つのマイグレーション(`2026_09_14_000001_seed_global_mass_grant_paid_leave_rule`・`2026_10_21_000004_*`)を、新しい DB ではデータを書かないようにする(本番は実行済みで影響なし)。
  - 初期の全社のルール(年度はじめの一斉付与・法定の日数の段)は、シーダー(開発・テスト・E2E)とオンボーディング(本番の新規導入)が `CreatePaidLeaveGrantRule` で作る。法定の付与表の初期の版は `PaidLeaveGrantPolicySeeder` が Command で公開する。期限の表は対象外なので直接書くまま。
  - テストのデータは Command で作る補助(trait)を用意し、付与ルール・付与表を直接書く既存のテスト(21ファイル。例 `PaidLeaveScheduleAssessmentPlannerTest.php:38`、`ScheduleCandidateGeneratorTest.php:232`、`PaidLeaveGrantRuleAdminTest.php:388`)・シーダー(`ScenarioSeeder.php:211-220`)を書き直す。`DevSeedersEventSourcedTest`(シーダーの後の作り直しで差分0)が通ること。
  - `PaidLeaveFiscalMassGrantMigrationsTest`(古いマイグレーションの `up()` を流し直す)は、データを書かなくなったマイグレーションに合わせて書き直す(古いマイグレーションが既存の DB では今どおりに動く部分だけを確かめる)。
- 未確定・要確認事項: なし

### 論点3b: 既存の行の移行(本番・既存の開発 DB)
- 決定:
  - マイグレーションは列の型の変更だけ: 有給・特別休暇のルールの主キーと子の `rule_id` を文字列の型に変える(値は '1','2' のまま。DB の型の変換で、PHP での計算は無い。外部キーの付け外しを含む)。MySQL での予行演習と SQLite のマイグレーションのテストを行う。
  - 運用コマンド `events:backfill-grant-rules`(`AdminExecutable`、試し実行が既定、`MAINTENANCE_COMMANDS` に登録)を作る。1つのトランザクションで:
    1. 既存の行(ルール・段・付与表の版。付与表は元の id の順)から、uuid5 を集約の ID にしたイベント(作成・版の公開)を作り、`stored_events` に記録する。記録の順は data-correction の規則どおり振り直す(過去への挿入: 行の `created_at` の位置に入るよう `stored_events` の id・版を振り直す。`EventStoreRebuildWriter` を使う。前例 `RebuildDeterminismMigrator`)。集約の persist は使わない(Projector・Reactor を走らせない)。
    2. `ReadModelRebuilder::rebuild` で表を作り直す(行の ID が UUID になる)。
    3. 移行前の行と比べて検証する(旧 ID→uuid5 の対応で比べる)。合わなければすべて戻す。
    - 試し実行は同じ処理をしてからロールバックし、旧 ID→UUID の対応表と差分を出す。
    - 「更新された後の今の内容を、作成の時点の内容として記録する」のは、更新の履歴が残っていない(過去の事実が決められない)部分として扱う(data-correction の判断手順4)。
    - 2回目の実行は、イベントの無い行が0件なら何もしない(冪等)。子の行は親のイベントがあるかで判定する。
  - 検出器 `LegacyGrantRuleDetector`(表に行があるのに親のイベントが無い)を `CorrectionPendingService::detect()` に加える。作り直しの拒否は `ReadModelRebuilder::rebuild` の側に置き、移行のコマンドだけが外せるようにする(既存の補正のコマンドが `rebuild(false)` を直接呼んで、付与ルールを消したまま確定するのを防ぐ)。補正待ちの間もログインと運用コマンドが動くことを `CorrectionPendingMaintenanceTest` に足す。
  - `ReadModelTableListerTest` に、6つの表が作り直しの対象に入ることを足す。
- 未確定・要確認事項: なし

### 論点4: そのほか
- 期限の表(`paid_leave_grant_expiry_policy`): 書き手・読み手とも無いので、イベント化の対象にしない。削除するかは別の候補として記録する。
- `ScheduleCandidateGenerator` と `PaidLeaveGrantPolicy` のコメントを実装に合わせて直す(法定の付与表は付与予定の計算に使わない)。
- 特別休暇の付与ルールの更新・削除の API は今も無いので作らない。
- 特別休暇の種別(`special_leave_types`)も直接書かれている(`SpecialLeaveController::storeType`・`updateType`)。本変更の対象外とし、別の候補として記録する。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
(設計レビューの後に確定する)
- backend: 論点2の Command・Handler・イベント・集約・Projector、Reactor(付与予定の作り直しの Job の dispatch)、Controller は1つの Command の dispatch だけ。論点3の移行のコマンド・検出器・マイグレーション。`config/event-sourcing.php` の別名、`docs/17-events.md`、履歴の型の書き出し。
- frontend: ルールの ID の型(文字列)。
- テスト: 各 Command(作成・更新・削除・版の公開)のイベントと Projector の結果、検査(最低日数・重複・有効なルールの削除の禁止)、Reactor が Job を dispatch する、移行のコマンド(試し実行で何も変えない・本実行で全行のイベントができる・作り直しで同じ内容・2回目の実行で何もしない)、検出器が移行の前に作り直しを拒否する、入口1回=1つの Command。

## 受け入れ条件
- 付与ルール・付与表の作成・更新・削除・版の公開がイベントで記録され、表は Projector が書く(直接の書き込みが無い)。
- 既存の行が管理画面の運用コマンドでイベントになり、作り直しで同じ内容になる。移行の前は作り直しが拒否される。
- 付与予定の作り直しが今と同じ条件で行われる。
- 上のテストと全件の単体テスト、変えた画面を通る E2E の spec が通る。

## 対象外
- 期限の表の削除、特別休暇の付与ルールの更新・削除の API、特別休暇の種別のイベント化

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`(付与ルール・付与表の保存がイベントであること)、`docs/16-database-schema.md`(主キーの型)、`docs/17-events.md`、`docs/27-release-runbook.md`(リリースの後に移行のコマンドを実行する手順)、`docs/03-architecture.md`(直接の書き込みの例外からの削除があれば)

## モック・アセット
なし

## 実装対象
- backend: `app/Domain/PaidLeaveGrantRule`(新しい文脈。名前は実装時に確かめる)・`app/Domain/SpecialLeave`、`app/Domain/PaidLeaveSchedule`(Reactor・コメント)、`app/Domain/EventSourcing`(検出器)、`app/Console/Commands`(移行)、Controller、migration、テスト
- frontend: `api/paidLeave.ts`・`api/specialLeave.ts` の型、付与ルールの画面

## 検証方法
- backend: CI と同じ条件で全件。移行のコマンドは、既存の行を入れた DB で試し実行→本実行→作り直しの比較のテスト
- frontend: tsc、単体テスト全件
- E2E: 付与ルール・付与表の画面を通る spec(grep で特定)

## レビュー履歴
- 2026-11-28 初版。
- 2026-11-28 実装前の独立設計レビュー(general-purpose)を反映: 新しい DB で初期のルールが直接書かれる問題(高-1・2。ユーザーの回答で過去のマイグレーションを新しい DB では書かないようにし、シーダー・オンボーディングが Command で作る)、子の表の `rule_id`(高-3)、主キーの UUID 化はマイグレーションでは型の変更だけ・UUID は移行のコマンドの作り直しで(高-4 の案 b)、移行は `stored_events` への直接の書き込み・作り直し・検証を1つのトランザクションで(高-5)、記録の順は規則どおり振り直す(中-1。ユーザーの回答)、作り直しの拒否を `ReadModelRebuilder` に(中-2)、付与表は表ごとの集約(中-3)、全項目のイベントと Handler の既定値(中-4)、文脈は `PaidLeaveSchedule`(中-5)、数値の ID の前提・Resolver の同順位(中-6)、uuid5 の名前(中-7)、`afterCommit`(中-8)、特別休暇の種別は対象外(低-1)。

## 実装結果
未着手
