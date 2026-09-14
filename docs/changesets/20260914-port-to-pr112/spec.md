# PR#112(claude/paid-leave-domain-redesign-58ojrn)への差分移植

ステータス: 完了

## 変更要望(原文)

> claude/paid-leave-domain-redesign-58ojrnに変更内容を統合してください。PRも#112に統合してください。

## 背景・目的

`docs/changesets/20260913-paid-leave-fiscal-grant-and-nav/spec.md`(完了済み)の作業は
`claude/determined-pasteur-cw1q0b`ブランチ上で行ったが、そのベースとなった
`App\Domain\PaidLeaveSchedule`ドメイン自体は、同じ仕様検討ドキュメント
(`docs/changesets/20260906-paid-leave-schedule-assessment/spec.md`)を元に**別の担当者
(chameleonhead氏)が独立して一から再実装したもの**が`claude/paid-leave-domain-redesign-58ojrn`
(PR #112)として別途存在することが判明した。両ブランチは共通の祖先コミット(`8806ca6`、
仕様検討ドキュメントのみ)から分岐しており、実装(クラス構成・ファイル名)が全く異なる。

マージをプレビューした結果、51ファイルが競合し、その大半が「両ブランチが同じパスに
別内容のファイルを追加した(add/add)」という機械的に解決不可能な競合だった。
ユーザーに確認の上、**PR#112(chameleonhead氏の実装)を正としてそのまま残し、
`20260913-paid-leave-fiscal-grant-and-nav`で追加した4つの機能を、PR#112のコード構成
(`ScheduleCandidateGenerator`等)に合わせて手作業で移植する**方針とした。

## 現状(As-Is)/移植対象の詳細

investigatorエージェントによる比較調査の結果(要点):

1. **`grant_cycle_type`/`mass_grant_month`列 + 前倒しアルゴリズム**: PR#112に無し。
   `ScheduleCandidateGenerator.php`はhire_date起算の周年cyclingのみ。
2. **ルール不在時のスキップ + NeedsReview化**: PR#112に無し。
   `RollPaidLeaveSchedulesCommand.php`はルール有無を見ずに候補日があれば無条件で
   dispatchする。`resolveGrantDays()`も確定不可ケースを無言の0.0で返す。
3. **ナビゲーション再編**(「付与予定」の独立ナビ削除): PR#112に無し。
   `adminNavGroups.ts`は両項目が並列のまま。
4. **`usage_start_date`ガード**: PR#112に無し。候補日生成ロジックが
   `usage_start_date`を一切参照していない。
5. **法定付与日数テーブルの編集UI**: PR#112には**読み取り専用の表示のみ**
   (`PaidLeaveGrantPolicyMatrix.tsx`)存在し、新バージョン作成・編集機能は無い
   (POSTエンドポイント無し)。

## 仕様確定事項(移植方針)

- 各機能の「仕様・決定事項」自体は`20260913-paid-leave-fiscal-grant-and-nav/spec.md`の
  該当論点をそのまま踏襲する(再検討しない)。本changesetは「同じ仕様をPR#112の
  コード構造にどう実装し直すか」のみを扱う。
- 移植順序(ファイル競合を避けるため):
  1. (並行) 機能1・2・4(バックエンドのSchedule生成ロジック、密結合のため一括で
     `ScheduleCandidateGenerator`/`RollPaidLeaveSchedulesCommand`等に実装)
  2. (並行) 機能3(ナビ再編、フロントエンドのnav設定のみ)
  3. (1・2完了後) 機能5(法定付与日数テーブルの編集UI追加。`PaidLeavePolicyPage.tsx`を
     機能3と同じファイルで触るため、競合を避けて最後に実施)
- 移植後、PR#112のテスト方針(`php artisan test --filter=PaidLeave`等)に沿って
  全体回帰を確認し、`origin/claude/paid-leave-domain-redesign-58ojrn`へpushしてPR#112を
  更新する。

## 対象外

- PR#112のコード構造自体のリファクタリング(`ScheduleCandidateGenerator`を
  `EnsureFutureScheduleGeneratedHandler`方式に書き換える等)は行わない。あくまで
  PR#112の既存構造に機能を追加する形で実装する。
- `claude/determined-pasteur-cw1q0b`ブランチ・その変更セット
  (`20260913-paid-leave-fiscal-grant-and-nav`)自体の削除・変更は行わない
  (履歴として残す)。

## 実装対象

- Feature 1+2+4(バックエンド): migration、`ScheduleCandidateGenerator`、
  `GrantDaysResolver`相当、`RollPaidLeaveSchedulesCommand`、`PaidLeaveController`
  バリデーション。
- Feature 3(フロントエンド): `adminNavGroups.ts`、`PaidLeavePolicyPage.tsx`、
  `PaidLeaveSchedulePage.tsx`のナビ導線。
- Feature 5(バックエンド+フロントエンド): `PaidLeaveController`への
  `storeGrantPolicy`/`storeProportionalGrantPolicy`、`PaidLeavePolicyPage.tsx`への
  編集UI。

## 検証方法

- `cd backend && php artisan test --filter=PaidLeave`(全件green)。
- `cd backend && php artisan test`(フルスイート回帰無し)。
- `cd frontend && npx tsc -b`・`npm run test`。
- 可能であれば`scenario-15`相当のE2E確認。

## レビュー履歴

初版。

## 実装結果

Feature 1・2・4(バックエンド)を移植した。

- migration: `paid_leave_grant_rules`へ`grant_cycle_type`/`mass_grant_month`列を追加
  (`2026_09_14_000000_...`)、全社共通の一斉付与ルールを冪等にシード
  (`2026_09_14_000001_...`)。
- `ScheduleCandidateGenerator`: work_style固有ルールが無ければ全社共通ルール
  (`work_style_id`がnull)へフォールバックするよう修正(従来は未実装で無視されていた)。
  いずれのルールも無ければ候補を一切生成しない。`grant_cycle_type`が
  `mass_grant_month`の場合は前倒しアルゴリズムで日付を算出。`usage_start_date`より前の
  候補は生成しない(null許容の防御的チェック込み)。
- `resolveGrantDays`の戻り値を`GrantDaysResult`(days + isDeterminate)に変更し、
  「ルールはあるがstep未整備」「NeedsReview区分」「法定Policy未整備」を確定不可として
  区別。`PaidLeaveScheduleEntryCreated`イベント・`PaidLeaveScheduleAggregate`・
  Projectorに`isDeterminate`を伝播し、確定不可の場合はScheduledではなくNeedsReview状態で
  エントリを作成する。
- `PaidLeaveController::storeRule`/`updateRule`に`grant_cycle_type`/`mass_grant_month`の
  バリデーションを追加。

移植に伴う既存テストへの影響: マイグレーションでシードした全社共通ルールが常に
フォールバック適用されるようになったため、`RollPaidLeaveSchedulesCommandTest`・
`RecalculateScheduleOnConditionChangedReactorTest`の既存テストにwork_style固有の
anniversary型ルール(法定Policyと同じsteps)を明示的に追加し、従来の周年ベースの
挙動・アサーションを維持した。

テスト結果:
- `php artisan test --filter=PaidLeave`: 移植前187件→移植後199件(12件追加)、
  全件green(assertions 497)。
- `php artisan test`(フルスイート): 1070件中1044件pass・8件failだが、
  いずれもこの変更と無関係(ワークツリーの`.env`に`APP_KEY`が設定されておらず、
  暗号化を使うExternalIntegration/SSO/Onboarding系テストが
  `MissingAppKeyException`で失敗する既存の環境起因の問題。PaidLeave関連の失敗は無し)。

Feature 3(ナビ再編)・Feature 5(法定付与日数テーブル編集UI)は別エージェントの
担当範囲のため本作業では未着手。

### Feature 3(ナビ再編)

- `adminNavGroups.ts`から「付与予定」の独立ナビ項目を削除。
- `PaidLeavePolicyPage.tsx`へ「付与予定を確認」ボタンを追加、
  `PaidLeaveSchedulePage.tsx`へ「← 付与ポリシー」の戻る導線を追加。
  スケジュール画面のルート登録(`App.tsx`)自体は変更せず、URL直接アクセスは維持。
- テスト: `npx tsc -b`クリーン、変更した3ファイル(`PaidLeavePolicyPage.test.tsx`/
  `PaidLeaveSchedulePage.test.tsx`/`AdminLayout.test.tsx`)19/19 pass。フルスイートは
  既存の無関係な`ApprovalsPage`/`useAuth`失敗6件のみで新規失敗無し。
- コミット: `46c32d6`。

### Feature 5(法定付与日数テーブル編集UI)

- バックエンド: `PaidLeaveController`へ`storeGrantPolicy`/`storeProportionalGrantPolicy`
  (`POST /paid-leave/grant-policies`・`POST /paid-leave/proportional-grant-policies`)を
  追加。既存の`permission:leave.manage,any`グループに登録。新バージョンはトランザクション内で
  一括insertし既存行は変更しない。
- フロントエンド: `PaidLeaveGrantPolicyMatrix.tsx`に`normalAction`/`proportionalAction`
  スロットを追加し、`PaidLeavePolicyPage.tsx`から「新しいバージョンを作成」ボタン
  (Sheet、行の追加/削除/編集、固定警告文「この表の変更は法令に基づく設定です。
  保存前に社労士等の専門家に確認してください。」)を表示。
- **仕様からの逸脱**: 依頼時点の想定と異なり、本ブランチの比例付与表は
  `weekly_scheduled_days`(1-7の生の整数)ではなく`weekly_scheduled_days_category`
  (`'1'|'2'|'3'|'4'`のカテゴリコード)を使用しており、既存のGET側・Matrix
  コンポーネントもこちらに合わせて実装されていたため、バリデーション・
  フロントエンドSelectともこのカテゴリコードに合わせた。また`version`列は数値の
  連番ではなく`v1`/`v2`...という文字列のため、`+1`ではなく専用の
  `nextGrantPolicyVersion()`ヘルパーでバージョンを算出する形にした。
- テスト: `backend/tests/Feature/PaidLeaveAccount/PaidLeaveGrantPolicyAdminTest.php`
  (新規9件)。`php artisan test --filter=PaidLeave`: 199→208件、全件green。
  `php artisan test`(フルスイート): 1079/1079 green(無回帰)。`npx tsc -b`クリーン、
  `npm run test`は既存の無関係な失敗6件のみ(新規`PaidLeavePolicyPage.test.tsx`
  10件は全pass)。
- コミット: `10d61a7`。

### 全体の最終確認

3コミット(`403f4d9`/`46c32d6`/`10d61a7`)を積んだ状態で、ワークツリーに
`.env`・`APP_KEY`・DBマイグレーションを独立して用意した上で再度
`php artisan test`を実行し、**1079/1079 全green**(バックエンドが報告した
「.envのAPP_KEY未設定による8件の無関係な失敗」がこのセットアップにより解消された
ことを確認)。コード上も、前倒しアルゴリズム(`ScheduleCandidateGenerator::
massGrantScheduledDates()`)・NeedsReview化(`isDeterminate`の伝播)が仕様通り
実装されていることを目視確認済み。

ステータスを`完了`とし、`origin/claude/paid-leave-domain-redesign-58ojrn`へpushして
PR#112を更新する。

### 追加修正(2026-09-14): コードレビューで発覚した2件のバグ修正

`code-review`スキル(effort: high、対象`1ffb587..HEAD`)によるレビューで、テストでは
検出されていなかった2件の実際のバグが見つかったため修正した。

1. **`PaidLeaveController::updateRule`のデータ損失バグ**: `grant_cycle_type`/
   `mass_grant_month`がリクエストに含まれない場合でも無条件に既定値
   (`anniversary`/`null`)で上書きしていたため、既存の付与ルール編集フォーム
   (これらのフィールドを送信しない)経由で一斉付与ルールを編集すると、
   一斉付与設定が黙って消えていた。リクエストに`grant_cycle_type`が含まれる場合
   のみこれらのフィールドを更新するpartial update方式に修正
   (`backend/app/Http/Controllers/Api/PaidLeaveController.php`)。
2. **前倒し付与エントリが常にNeedsReviewになるバグ**: `ScheduleCandidateGenerator`が
   `continuousServiceMonths`を「入社日から実際のスケジュール日までの暦月数」で
   計算していたため、前倒しされた初回付与日(6ヶ月未満で付与)では
   `first_grant_after_months`(通常6)を下回り、`steps`のどの段も一致せず
   常に確定不可(NeedsReview・0日)になっていた。`nominalMonths`
   (名目上の継続勤務月数。初回=`first_grant_after_months`、以降+12ずつ)を
   実際の暦月数とは別に算出し、`resolveGrantDays`の参照キーとして使うよう修正
   (`backend/app/Domain/PaidLeaveSchedule/Support/ScheduleCandidateGenerator.php`の
   `anniversaryScheduledDates`/`massGrantScheduledDates`が`{scheduledOn, nominalMonths}`
   を返すよう変更)。

テスト: `PaidLeaveGrantRuleAdminTest`に2件(未送信時の設定保持・明示送信時の
切替が両方効くこと)、`ScheduleCandidateGeneratorTest`に2件(前倒し・6ヶ月後
分岐それぞれで候補日数が確定しstepsの正しい段を参照すること)を追加。
`php artisan test --filter=PaidLeave`: 212/212 green(208→212、+4)。
`php artisan test`(フルスイート): 1083/1083 green(無回帰)。

### 追加対応(2026-09-14): ドキュメントへの影響の反映漏れを解消

changesetスキルの完了前チェック(本skill自体もこの時点で見直し・強化した)に
照らして再確認したところ、当初の「ドキュメントへの影響」で予定していた
docs/09・16・17への反映が未実施のままステータスを`完了`にしていたことが判明したため
実施した。

- `docs/09-usecases-paid-leave.md`(UC-P002): 削除済みの`GrantScheduledPaidLeaveHandler`
  による旧フロー説明を、現行の`ScheduleCandidateGenerator`ベースのフロー
  (事前生成→Assessment→管理者一括付与、一斉付与月方式、ルール不在時スキップ、
  NeedsReview化、法定付与日数テーブルの編集UI)に置き換えた。
- `docs/16-database-schema.md`: `paid_leave_grant_rules`へ`grant_cycle_type`/
  `mass_grant_month`列を追記。加えて、元のPR#112実装時点から未記載だった
  `paid_leave_schedule_entries`/`paid_leave_grant_policies`/
  `paid_leave_proportional_grant_policies`の3テーブルも(今回のPOSTエンドポイント
  追加を機に)新規追記した。
- `docs/17-events.md`: `PaidLeaveScheduleEntryCreated`イベントへの
  `isDeterminate`フィールド追加を反映。

コミット: `1e8bb8a`。
