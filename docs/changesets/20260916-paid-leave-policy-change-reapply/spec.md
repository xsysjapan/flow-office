# paid-leave-policy-change-reapply

ステータス: 完了

## 変更要望(原文)
> 展開するデータはそもそもポリシーに合わせて確認のたびに見直しをお願いします。予定はそもそもユーザーの確認以外はできません。
> 何が言いたいかと言えば、法律が変わった場合、未確定の予定は新しいポリシーに追従させてください。ポリシー設定が変わらなければ見直しは必要ないので、最終見直し日的なものを持てばいいと思います。
>
> （やりとりの中での訂正）訂正するとユーザーが承認してもポリシーが変更されて条件や値が変更された場合は再作成してください。既存データについて忖度しなくて良いです
>
> （さらに確認への回答）すみません。付与済みの場合はそのままでお願いします。あくまで未確定の予定の話です

## 背景・目的
現状、法定付与ポリシー(`paid_leave_grant_policies`/`paid_leave_proportional_grant_policies`)や
付与ルール(`paid_leave_grant_rules`)を変更しても、既に生成済みの未確定Schedule
エントリ(`Scheduled`/`AssessmentPending`/`Eligible`/`NotEligible`/`NeedsReview`)は
古い内容のまま放置される。付与済み(`Granted`)・取消済み(`Cancelled`)以外は
「あくまで予定であり、ユーザーが最終確認できるのは実際に付与される時点」という
運用上、ポリシー・ルールが変わった時点で未確定の予定は無条件に新しい内容へ
追従させる必要がある。

## 現状(As-Is)
- `backend/app/Domain/PaidLeaveSchedule/Aggregates/PaidLeaveScheduleAggregate.php`
  - `recalculateFutureSchedule()`(59-137行目付近)は`hire_date`/`usage_start_date`/
    `work_style_id`変更等をトリガーに未来Scheduleを再計算するが、`FINALIZED_STATUSES`
    (`Granted`/`Cancelled`)に加え、`manuallyEditScheduleEntry`済み(`entry.manualOverride`
    がnullでない)エントリも一律スキップする(88-98行目)。
  - `overrideScheduleAssessment()`(判定結果の上書き、いわゆる「NeedsReview確認」)は
    `entry.manualOverride`をセットしないため、現状でも`recalculateFutureSchedule`の
    対象から除外されない(=既にポリシー変更で上書きされうる状態。今回はこの経路には
    変更を加えない)。
- `backend/app/Domain/PaidLeaveSchedule/Reactors/RecalculateScheduleOnUserConditionChangedReactor.php`・
  `RecalculateScheduleOnWorkStyleChangedReactor.php` — `hire_date`/`usage_start_date`/
  `paid_leave_auto_grant_enabled`/`work_style`変更をトリガーに`RecalculateFutureSchedule`を
  発行する。**法定ポリシー・付与ルールの変更はトリガーになっていない。**
- `backend/app/Console/Commands/RollPaidLeaveSchedulesCommand.php` — 日次cronで
  「まだ存在しない未来月」だけを埋める(`ensureFutureScheduleGenerated`)。既存の
  未確定エントリの内容見直しは行わない。
- ポリシー・ルールの変更エンドポイント: `backend/app/Http/Controllers/Api/PaidLeaveController.php`
  の`storeRule`(79)・`updateRule`(125)・`destroyRule`(184)・`storeGrantPolicy`(273)・
  `storeProportionalGrantPolicy`(325)。いずれも変更後にSchedule再計算をトリガーしない。
- `backend/app/Domain/PaidLeaveSchedule/Support/ScheduleCandidateGenerator.php`の
  `candidatesFor()`は、その時点のルール・法定Policyを都度読み直して候補を算出する
  ステートレスな実装であり、最新ポリシーへの追従自体は既にこの関数呼び出し側の問題
  (いつ呼ぶか)に閉じている。

## 仕様検討

### 論点1: 「未確定」の範囲
- 選択肢:
  - A. `FINALIZED_STATUSES`(`Granted`/`Cancelled`)以外の全ステータスを対象とする
       (`Scheduled`/`AssessmentPending`/`Eligible`/`NotEligible`/`NeedsReview`)
  - B. Aに加えて、`NeedsReview`から`overrideScheduleAssessment`で確定させたものは除外する
- 決定: A
- 理由: ユーザー確認により「付与済みの場合はそのままで良い、あくまで未確定の予定の話」と
  明言されている。`overrideScheduleAssessment`は付与実行(`Granted`)そのものではなく、
  あくまで判定結果の確認に過ぎず「未確定」に含まれる。既存実装でもこの経路は
  `manualOverride`を立てておらず元々保護されていないため、Bのような追加の除外条件を
  新設しない(現状維持で仕様上も整合する)。

### 論点2: `manuallyEditScheduleEntry`(個別修正)済みエントリの扱い
- 選択肢:
  - A. ポリシー変更トリガーに限り、個別修正済みエントリも無条件に再作成の対象に含める
       (`manualOverride`保護を無視する)
  - B. 個別修正済みエントリは常に保護対象のまま据え置く(現状の`recalculateFutureSchedule`
       と同じ挙動を維持)
- 決定: A(ポリシー変更トリガーに限り保護を無視する)
- 理由: 「ユーザーが承認しても...再作成してください。既存データについて忖度しなくて
  良い」と明示的に指示されている。ただし`hire_date`等の条件変更トリガー(既存の
  `recalculateFutureSchedule`呼び出し)では、個別修正保護の意味合いが異なる(条件変更は
  対象日そのものが変わりうるため、個別修正内容と衝突しやすい)ため、そちらは現状の
  仕様(保護する)を変更しない。ポリシー変更トリガーのみ、新しい引数
  (`overrideManualEdits: bool`)でこの保護をスキップできるようにする。

### 論点3: トリガーとする変更操作の範囲
- 選択肢:
  - A. `storeRule`・`updateRule`・`storeGrantPolicy`・`storeProportionalGrantPolicy`の
       4エンドポイントすべてを対象とする(`destroyRule`は対象外)
  - B. Aに加えて`destroyRule`も対象とする
- 決定: A
- 理由: `destroyRule`は「既に`is_active=false`のルールのみ削除可能」という制約が
  既にあり(`PaidLeaveController.php:186-188`)、削除前の時点で`ScheduleCandidateGenerator`
  はそのルールを既に無視している(`matchingRuleFor()`は`is_active=true`のみ検索)。
  したがって削除操作自体は将来のSchedule算出結果に一切影響しないため、再計算を
  トリガーする必要がない。

### 論点4: 再計算の対象社員の絞り込み
- 選択肢:
  - A. 変更されたルール・ポリシーが実際に影響する社員だけを絞り込んで再計算する
       (`work_style_id`一致・全社共通ルールのフォールバック関係・比例区分等を
       都度判定する必要があり、実装が複雑になる)
  - B. `RollPaidLeaveSchedulesCommand`と同じ対象条件(`employment_status=active`・
       `hire_date`設定済み・`paid_leave_auto_grant_enabled=true`)の全社員について、
       一律`ScheduleCandidateGenerator::candidatesFor()`を呼び直し、変化が無ければ
       `recalculateFutureSchedule`側の冪等ロジック(内容が同じならno-op)が
       何もしないので実害はない
- 決定: B
- 理由: ポリシー・ルールの変更操作は頻度が低い管理者操作であり、全社員分を
  再計算しても許容できるコストである一方、Aは「どの社員が影響を受けるか」の
  判定ロジック(work_style固有ルール優先・全社共通フォールバック・比例区分の
  週所定労働日数など)を`storeRule`/`storeGrantPolicy`側にも重複実装する必要があり
  複雑さ・不整合のリスクが増す。`candidatesFor()`はステートレスな再計算であり、
  影響を受けない社員に対しては`recalculateFutureSchedule`が「内容一致→no-op」と
  判定するため、Bを採用しても不要な書き込みは発生しない。

### 論点5: 実行方式(同期 or 非同期)
- 選択肢:
  - A. HTTPリクエスト内で同期的に全社員を再計算する
  - B. `App\Jobs\SendNotificationJob`と同じDBキュー経由のJobとして非同期実行する
       (`docs/02-tech-stack.md`: cronから`schedule:run`→`queue:work --stop-when-empty`)
- 決定: B
- 理由: 全社員分の`candidatesFor()`呼び出し+Command発行はデータ量次第で時間が
  かかりうり、管理画面の保存操作をブロックしたくない。リポジトリの既存パターン
  (常駐workerを前提とせず、DBキュー+cronで処理する。`backend/CLAUDE.md`
  「常駐プロセスを前提にしない」)に合わせ、Jobとして実行する。

### 論点6: 「最終見直し日」的な差分管理の要否
- 選択肢:
  - A. ポリシー変更のたびに対象社員全員を即時(非同期)で再計算するため、
       「最終見直し日時」を別途持つ必要はない(変更イベント駆動で常に最新化される)
  - B. ポリシー・ルール側に`updated_at`相当を持たせ、Schedule側にも
       「最後に見直した日時」を持たせて、日次cron等で比較差分実行する
- 決定: A
- 理由: 当初のユーザー提案はBだったが、その後「ユーザーが承認しても再作成して
  ほしい、既存データに忖度しなくて良い」と明確化されたことで、狙いは
  「ポリシー変更を絶対に取りこぼさない即時反映」であると判断した。B(定期バッチでの
  差分検知)は、変更操作の都度Jobを発行するA(イベント駆動)よりも反映が遅れる
  (次回バッチまで待つ)上に、実装(比較対象の日時管理・タイミングのズレ)が複雑になる。
  Aは変更操作をトリガーに毎回確実に反映され、かつ論点4の通り内容が同じ社員には
  no-opなので、Bの目的(不要な処理をしない)もAで達成できる。

## 仕様確定事項(まとめ)
- `PaidLeaveScheduleAggregate::recalculateFutureSchedule()`に第3引数
  `bool $overrideManualEdits = false`を追加する。`true`の場合、既存の
  `manuallyEditScheduleEntry`済み(`entry.manualOverride !== null`)エントリも
  スキップせず、通常の候補比較(内容一致→no-op、不一致→Supersede+新規作成、
  候補消滅→Cancel)の対象に含める。`FINALIZED_STATUSES`(`Granted`/`Cancelled`)は
  従来どおり常に対象外。
- `Commands\RecalculateFutureSchedule`に同名の`bool $overrideManualEdits = false`
  プロパティを追加し、`RecalculateFutureScheduleHandler`から
  `recalculateFutureSchedule($command->candidates, $command->reason, $command->overrideManualEdits)`
  へ渡す。
- 既存の`RecalculateScheduleOnUserConditionChangedReactor`・
  `RecalculateScheduleOnWorkStyleChangedReactor`はデフォルト値(`false`)のまま呼び出し、
  挙動を変更しない(個別修正保護は維持)。
- 新規: `App\Jobs\ReapplyPaidLeaveSchedulePolicyJob implements ShouldQueue`を追加する。
  - `handle(CommandBus $commandBus, ScheduleCandidateGenerator $generator)`は
    `RollPaidLeaveSchedulesCommand`と同じ対象社員条件
    (`employment_status=active` かつ `hire_date`設定済み かつ
    `paid_leave_auto_grant_enabled=true`)で全社員を取得し、各社員について
    `candidatesFor($user, today, today+1年)`を算出した上で、
    `RecalculateFutureSchedule(userId, candidates, reason: '法定付与ポリシー/付与ルールの変更', overrideManualEdits: true)`
    を発行する。1社員の失敗が他社員に影響しないよう、
    `RollPaidLeaveSchedulesCommand`と同じtry/catch(`report($e)`)で継続する。
- `PaidLeaveController`の`storeRule`・`updateRule`・`storeGrantPolicy`・
  `storeProportionalGrantPolicy`は、DBトランザクションのコミット後に
  `ReapplyPaidLeaveSchedulePolicyJob::dispatch()`を呼ぶ(`DB::transaction()`の
  クロージャ内ではなく、戻り値を受けた後段で呼び、コミット前にJobが実行されて
  古いデータを読む競合を避ける)。
- `destroyRule`はJobをdispatchしない(論点3参照。既に`is_active=false`のルール
  削除のみ許可されており、削除時点でSchedule算出への影響がないため)。
- 「最終見直し日時」のような差分管理列は追加しない(論点6の決定通り)。

## 受け入れ条件
- 法定付与ポリシー(通常・比例)の新バージョンを作成すると、未確定
  (`Scheduled`/`AssessmentPending`/`Eligible`/`NotEligible`/`NeedsReview`)の
  Scheduleエントリが新しいポリシー内容(`candidate_grant_days`等)に更新される。
  `Granted`/`Cancelled`のエントリは一切変更されない。
- 付与ルール(`paid_leave_grant_rules`)を作成・編集すると、同様に未確定エントリが
  新しいルール内容に追従する。
- `manuallyEditScheduleEntry`(個別修正)済みの未確定エントリも、ポリシー・
  ルール変更時には他の未確定エントリと同様に再作成される(保護されない)。
- `hire_date`/`usage_start_date`/`work_style`変更等、既存トリガーによる再計算では、
  個別修正済みエントリは従来どおり保護される(本変更による回帰がない)。
- ポリシー・ルール変更操作(POST/PUT)のHTTPレスポンスは、全社員分の再計算完了を
  待たずに返る(非同期)。
- 関連するテスト(バックエンド全体・新規追加分)がすべてPASSする。

## 対象外
- `overrideScheduleAssessment`(判定結果の上書き)の保護強化・仕様変更は行わない
  (論点1の通り、現状維持)。
- 「最終見直し日時」を持たせる差分管理の仕組みは導入しない(論点6)。
- ポリシー変更の影響を受ける社員だけに絞り込む最適化は行わない(論点4)。
- `destroyRule`(ルール削除)のJob発行は対象外(論点3)。
- フロントエンドの表示変更は無し(バックエンドの再計算ロジックのみの変更)。

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`: 付与ポリシー・付与ルール変更時に未確定Scheduleが
  自動的に再作成される旨を追記する。
- `docs/17-events.md`: 変更なし(既存の`PaidLeaveScheduleEntrySuperseded`等の
  イベントを流用し、新規イベント種別は追加しないため)。
- 他のdocsファイルは変更なし。

## モック・アセット
なし

## 実装対象
- `backend/app/Domain/PaidLeaveSchedule/Aggregates/PaidLeaveScheduleAggregate.php`
  (`recalculateFutureSchedule`に`overrideManualEdits`引数を追加)
- `backend/app/Domain/PaidLeaveSchedule/Commands/RecalculateFutureSchedule.php`
  (`overrideManualEdits`プロパティ追加)
- `backend/app/Domain/PaidLeaveSchedule/Handlers/RecalculateFutureScheduleHandler.php`
  (引数を渡す)
- `backend/app/Jobs/ReapplyPaidLeaveSchedulePolicyJob.php`(新規)
- `backend/app/Http/Controllers/Api/PaidLeaveController.php`
  (`storeRule`/`updateRule`/`storeGrantPolicy`/`storeProportionalGrantPolicy`から
  Job dispatch)
- テスト: `backend/tests/Feature/PaidLeaveSchedule/`配下に
  `ReapplyPaidLeaveSchedulePolicyJobTest.php`(新規)、既存の
  `PaidLeaveGrantRuleAdminTest.php`・`PaidLeaveGrantPolicyAdminTest.php`系に
  Job dispatchの回帰テストを追加

## 検証方法
- `cd backend && php artisan test --filter=PaidLeaveSchedule`
- `cd backend && php artisan test`(フルスイート)

## レビュー履歴
初版。ユーザーとの往復(「最終見直し日」案→「ユーザー承認済みでも再作成」への訂正→
「付与済みは対象外、未確定のみ」の確認)を経て、論点1・2・6の決定に反映済み。

## 実装結果

- `backend/app/Domain/PaidLeaveSchedule/Aggregates/PaidLeaveScheduleAggregate.php`:
  `recalculateFutureSchedule()`に第3引数`bool $overrideManualEdits = false`を追加。
  `true`時は個別修正済み(`manualOverride !== null`)エントリの保護をスキップする。
  `Granted`/`Cancelled`は従来通り常に対象外。
- `backend/app/Domain/PaidLeaveSchedule/Commands/RecalculateFutureSchedule.php`:
  同名の`overrideManualEdits`プロパティ(既定`false`)を追加。
- `backend/app/Domain/PaidLeaveSchedule/Handlers/RecalculateFutureScheduleHandler.php`:
  追加引数をaggregateへ橋渡し。
- `backend/app/Jobs/ReapplyPaidLeaveSchedulePolicyJob.php`(新規): DBキュー経由で
  対象社員全員(`RollPaidLeaveSchedulesCommand`と同条件)の`candidatesFor()`を再計算し、
  `overrideManualEdits: true`で`RecalculateFutureSchedule`を発行する。
- `backend/app/Http/Controllers/Api/PaidLeaveController.php`: `storeRule`・`updateRule`・
  `storeGrantPolicy`・`storeProportionalGrantPolicy`から、DB更新後に
  `ReapplyPaidLeaveSchedulePolicyJob::dispatch()`を呼ぶよう変更。`destroyRule`は対象外
  (論点3の通り)。
- テスト追加:
  - `backend/tests/Unit/PaidLeaveSchedule/PaidLeaveScheduleAggregateTest.php`:
    `overrideManualEdits`によって個別修正済みエントリが再作成されること・
    `Granted`エントリは`overrideManualEdits`でも不変であることの単体テスト2件。
  - `backend/tests/Feature/PaidLeaveSchedule/ReapplyPaidLeaveSchedulePolicyJobTest.php`(新規):
    Job実行により、個別修正済みの未確定エントリがルール変更後の内容に再作成されること、
    Grant済みエントリは一切変更されないことをEnd-to-End(実DB経由)で検証する2件。
- ドキュメント: `docs/09-usecases-paid-leave.md`にUC-P011bを追加し、本仕様(トリガー・
  対象社員・個別修正の扱い)を記載。`docs/17-events.md`は新規イベント種別を追加していない
  ため変更なし(受け入れ条件・「ドキュメントへの影響」の通り)。

### 受け入れ条件の充足確認

- 法定通常/比例付与ポリシーの新バージョン作成・付与ルールの作成/編集で未確定エントリが
  新内容に再作成される → `ReapplyPaidLeaveSchedulePolicyJobTest`で確認(◯)
- `Granted`/`Cancelled`エントリは変更されない →
  `test_it_does_not_touch_granted_entries`・`PaidLeaveScheduleAggregateTest::test_granted_entry_is_still_immutable_even_with_override_manual_edits`で確認(◯)
- 個別修正済み未確定エントリもポリシー変更時は保護されない →
  `test_it_recreates_unconfirmed_entries_including_manually_edited_ones`・
  `PaidLeaveScheduleAggregateTest::test_manually_edited_entry_is_recreated_when_override_manual_edits_is_true`で確認(◯)
- 既存トリガー(`hire_date`変更等)では個別修正保護が維持される(回帰なし) →
  既存の`test_manually_edited_entry_is_not_silently_overwritten_by_recalculation`が
  デフォルト引数のまま変更なくPASSすることで確認(◯)
- ポリシー・ルール変更APIはJob完了を待たず返る(非同期) → `ShouldQueue`実装・
  `dispatch()`呼び出しで確認(◯)。既定の`QUEUE_CONNECTION=sync`のテスト環境では
  同期実行されるが、本番相当の`database`ドライバでは非同期になる(`docs/02-tech-stack.md`)
- 関連テストが全てPASSする →
  `cd backend && php artisan test`: 1084/1084 pass(新規4件含む)

### 検証コマンド実行結果

- `cd backend && php artisan test --filter=PaidLeaveSchedule`: 83/83 pass
- `cd backend && php artisan test`: 1084/1084 pass
- `vendor/bin/pint`(変更ファイルのみ): 適用済み(braces_position/ordered_imports等)

### 副次対応: CI(`migrate-mysql`ジョブ)のエラー修正

本タスク着手時、PR#112のCI `migrate-mysql`ジョブが失敗していた
(`2026_09_13_000000_create_paid_leave_grant_policies_table.php`のunique制約の
自動生成インデックス名`paid_leave_grant_policies_version_continuous_service_months_unique`が
68文字でMySQLの識別子長制限64文字を超過)。本変更セットのスコープには含まれないが、
CI通過に必須のため合わせて修正した。`unique(['version', 'continuous_service_months'])`に
明示的な短い名前(`paid_leave_grant_policies_version_months_unique`、47文字)を指定。
