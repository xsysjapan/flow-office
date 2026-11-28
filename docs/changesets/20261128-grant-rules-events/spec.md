# grant-rules-events(有給・特別休暇の付与ルール・付与表をイベント化し、既存の行を移行する)

ステータス: 検討中

## 変更要望(原文)と決定
> 同様に修正をお願いします。問題があれば聞いてください
- 有給・特別休暇の付与ルールと付与表をイベント化する(ユーザーの回答 2026-11-28「今回あわせてイベント化する」)。既存の行はデータ移行(管理画面の運用コマンド)でイベントを作り直す。

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

### 論点1: 集約と主キー
- 選択肢:
  - A. 主キーを UUID に変える(集約の ID と同じ)。子(段)は親のイベントから全件を作り直す。
  - B. 連番の主キーを残し、集約の ID を別の列に持つ。
- 決定: A(有給のルール・特別休暇のルール)。付与表(版ごとの行の集まり)は「版」を1つの集約(ID は `uuid5('paid-leave-grant-policy:'.version)` 等、版の文字列から決まる)とし、行はその版のイベントから作る(行の主キーは連番のまま。行は子データ)。
- 理由: B は作り直しで連番が変わり、画面の URL・API のルールの ID が変わる。docs/03 の規則(集約ルートを Projector 化するなら UUID)に合わせる。
- 影響: ルールの ID の型が数値から文字列(UUID)になる。API・画面の型を直す。既存の行には、移行で UUID を割り当てる(既存の連番から決まる uuid5。移行の試し実行と本実行で同じ値になる)。
- 未確定・要確認事項: なし

### 論点2: Command とイベント
| 対象 | Command | イベント |
|---|---|---|
| 有給の付与ルール | `CreatePaidLeaveGrantRule`・`UpdatePaidLeaveGrantRule`・`DeletePaidLeaveGrantRule` | `paid_leave_grant_rule.created`・`.updated`・`.deleted`(段の一覧を含む) |
| 有給の法定の付与表(通常・比例) | `PublishPaidLeaveGrantPolicyVersion`・`PublishProportionalGrantPolicyVersion` | `paid_leave_grant_policy.version_published`・`paid_leave_proportional_grant_policy.version_published`(行の一覧・効力の開始日を含む) |
| 特別休暇の付与ルール | `CreateSpecialLeaveGrantRule` | `special_leave_grant_rule.created`(段の一覧を含む) |
- 検査(法定の最低日数・区分×月の重複・有効なルールの削除の禁止・版の採番)は Handler に移す。版の採番は Handler でロックをかけて行う。
- 付与予定の作り直し: 有給の付与ルール・付与表のイベントを受ける Reactor が `ReapplyPaidLeaveSchedulePolicyJob` を dispatch する(Controller からの dispatch をやめる)。非同期のままにする(全利用者を条件で処理する一括で、docs/03 3.10 の「非同期にするのは条件で数千件を処理する一括」に当たる)。ルールの削除でも dispatch する(今は dispatch しない。削除は無効のルールだけなので結果は変わらないが、そろえる)。
- 未確定・要確認事項: なし

### 論点3: 既存の行の移行
- 決定:
  - 運用コマンド `events:backfill-grant-rules`(`AdminExecutable`、試し実行が既定、`MAINTENANCE_COMMANDS` に登録)を作る。既存の行(ルール・段・付与表の版)から、作成・版の公開のイベントを1件ずつ作り、`stored_events` に記録する(過去の事実の補完。移行イベントは作らない。原則2)。記録の時刻は行の `created_at`(更新の履歴は残っていないので、今の内容を作成のイベントとして記録する)。
  - 移行の前に作り直しをすると表が消えるため、検出器(`LegacyGrantRuleDetector`: 表に行があるのにイベントが無い)を `CorrectionPendingService` と作り直しの拒否に加える(前例: `LegacyBooleanPropertyDetector`)。補正待ちの間は API が 503(変更セット`20261124-correction-pending-maintenance`)になり、管理者は管理画面から移行のコマンドを実行する。
  - マイグレーションは列の追加と主キーの型の変更だけを行う(データは移行のコマンドで作る)。主キーの UUID 化は、移行のコマンドの中で、イベントの作成と同じトランザクションで既存の行の ID を uuid5 に置き換える…のではなく、マイグレーションで `uuid` 列を足して既存の連番から uuid5 を入れ(決定的な値)、主キーを入れ替える。段・付与予定の勤務形態の参照は ID を参照しないので影響は無い。※ マイグレーションの中で uuid5 を入れるのは「データの補正」ではなく、列の型の変更に伴う値の写し(元の値から機械的に決まる)として扱う。この扱いが data-correction の規則に照らして疑わしければ、設計レビューで指摘を受けて決め直す。
  - 移行の後、作り直しで表がイベントから同じ内容になることを確かめる(`ReadModelFingerprinter` の比較。連番の主キーは比較から除外される)。
- 未確定・要確認事項: 主キーの入れ替えをマイグレーションで行うことの是非(上の※。設計レビューで確かめる)

### 論点4: そのほか
- 期限の表(`paid_leave_grant_expiry_policy`): 書き手・読み手とも無いので、イベント化の対象にしない。削除するかは別の候補として記録する。
- `ScheduleCandidateGenerator` と `PaidLeaveGrantPolicy` のコメントを実装に合わせて直す(法定の付与表は付与予定の計算に使わない)。
- 特別休暇の付与ルールの更新・削除の API は今も無いので作らない。
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
- 期限の表の削除、特別休暇の付与ルールの更新・削除の API

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
初版

## 実装結果
未着手
