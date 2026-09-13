# PR#112(claude/paid-leave-domain-redesign-58ojrn)への差分移植

ステータス: 実装中

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

未着手。
