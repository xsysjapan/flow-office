# paid-leave-schedule-rebuild

ステータス: レビュー中

## 変更要望(原文)
> また、過去の予定を完全に削除して再作成できるコマンドを追加してください。

(補足質問への回答)
- 対象: 有給休暇の付与予定(`PaidLeaveSchedule`ドメインの`paid_leave_schedule_entries`)
- 削除方式: `stored_events`は追記のみの原則を維持するため、物理削除ではなく
  「取消イベントを追記して既存エントリを無効化し、新規の付与予定イベントを追記して
  再作成する」方式とする

## 背景・目的
`docs/changesets/20260916-paid-leave-policy-change-reapply/`で、法定付与ポリシー・
付与ルールの変更時に**未来分**の未確定Scheduleエントリを自動再作成する仕組みを
実装済み(`PaidLeaveScheduleAggregate::recalculateFutureSchedule()`)。しかし対象は
`candidatesFor($user, today, today+1年)`であり、**過去日付のエントリ**は対象外。
過去分のScheduleエントリにデータ不整合(誤ったCommand再生・移行時の取り込みミス等)が
見つかった場合、既存の仕組みでは是正できず、管理者が過去分を明示的に指定して
洗い替え(取消+再作成)できるコマンドが必要。

なお、本要望と並行して、フロントエンドの「付与予定」管理画面
(`/admin/paid-leave/schedule`)は本セッションの別対応で削除済み。よって本コマンドは
UIを持たない運用者向けのArtisanコマンドとして提供する。

## 現状(As-Is)
- `backend/app/Domain/PaidLeaveSchedule/Aggregates/PaidLeaveScheduleAggregate.php`
  - `recalculateFutureSchedule(array $candidates, string $reason, bool $overrideManualEdits = false)`:
    候補配列と既存の未確定エントリ(`FINALIZED_STATUSES`=`Granted`/`Cancelled`以外)を
    比較し、内容一致→no-op、内容不一致→既存エントリをSupersede(取消)して新規候補で
    再作成、候補消滅→Cancel、という洗い替えロジックを既に持つ。ただし呼び出し元
    (`RecalculateFutureScheduleHandler`)は常に`today`以降の候補のみを渡すため、
    過去日付のエントリはこのロジックの対象になったことがない。
  - `FINALIZED_STATUSES`チェックのみで過去/未来の日付そのものによる除外は行っていない
    ため、aggregateのメソッド自体は過去日付候補を渡しても動作する見込み(要実装時検証)。
- `backend/app/Domain/PaidLeaveSchedule/Commands/RecalculateFutureSchedule.php` /
  `Handlers/RecalculateFutureScheduleHandler.php`: 対象ユーザー・候補配列・reason・
  overrideManualEditsを受け取り、aggregateへ橋渡しするだけの薄いハンドラ。
- `backend/app/Domain/PaidLeaveSchedule/Support/ScheduleCandidateGenerator.php`の
  `candidatesFor($user, $from, $to)`: 期間を指定してその時点のルール・法定Policyから
  候補を再計算するステートレスな実装。`$from`/`$to`に過去日付を渡すこと自体は
  シグネチャ上制限されていない(要実装時確認)。
- 既存の取消・再作成イベント(Supersede実行時に発行されるイベント。命名・実装は
  `PaidLeaveScheduleAggregate`内、`docs/17-events.md`の該当箇所を実装時に確認)を
  そのまま流用できる想定。
- 既存の類似運用コマンド: `backend/app/Console/Commands/MigratePaidLeaveAccountsCommand.php`
  (社員ごと一度限りの移行用Artisanコマンド、JSON入力)、
  `backend/app/Console/Commands/RollPaidLeaveSchedulesCommand.php`(日次cronで未来月分の
  Schedule生成のみを行う。既存エントリの見直しはしない)。
- `Granted`(付与済み・確定済み)エントリは原則1・14により無条件では書き換えない
  (取消は別途`PaidLeaveGrantRevoked`等、Account側の専用取消操作が既に存在する)。

## 仕様検討

### 論点1: 対象とするエントリの範囲(ステータス)
- 選択肢:
  - A. `FINALIZED_STATUSES`(`Granted`/`Cancelled`)以外の未確定エントリのみを
       洗い替え対象とする(`recalculateFutureSchedule`と同じ考え方)
  - B. `Granted`(付与済み)も含めて全ステータスを洗い替え対象とする
- 決定: A
- 理由: 原則1・14により、付与済み(`Granted`)は既にAccount側の残高・使用実績に
  紐づく確定済みの権利であり、Schedule側からの一括書き換え対象にすべきではない。
  付与済みエントリの是正が必要な場合は既存の`RevokePaidLeaveGrant`
  (`leave.manage`権限、最新Grantのみ取消可)を使う運用とし、本コマンドの対象外とする。

### 論点2: 対象範囲の指定方法
- 選択肢:
  - A. 社員ID(複数可)+対象期間(開始日・終了日)を必須指定させる
  - B. 全社員一括・期間指定なし(全期間)で洗い替えを行う
- 決定: A
- 理由: 過去分の洗い替えは既存データを取消+再作成する破壊力の大きい操作であり、
  対象を明示的に絞らせることで誤操作の影響範囲を限定する。全社員・無期限の
  一括実行を許すと、意図しない大量の取消イベントが`stored_events`に追記される
  リスクがある。

### 論点3: 実行経路
- 選択肢:
  - A. Artisanコマンド(`php artisan paid-leave:schedule:rebuild`)として、
     サーバー運用者がCLIから実行する
  - B. 管理者向けAPI(`leave.manage`権限)+フロントエンド操作画面として提供する
- 決定: A
- 理由: 対応する「付与予定」管理画面(`/admin/paid-leave/schedule`)は本セッションの
  別対応で削除済みであり、Bには新規UIの追加が必要になる。過去分の洗い替えは
  データ不整合是正のための低頻度な運用者向け操作であり、
  `MigratePaidLeaveAccountsCommand`と同様にArtisanコマンドとして提供する方が
  既存パターンに沿う。将来、頻度が上がりUIが必要になった場合は別変更セットで
  API化を検討する。

### 論点4: 取消+再作成に使うイベント
- 選択肢:
  - A. 既存の`recalculateFutureSchedule`が内容不一致時に使っているSupersede
       (取消+新規作成)の仕組みをそのまま流用する(新規イベント種別を追加しない)
  - B. 過去分洗い替え専用の新規イベント(例: `PaidLeaveScheduleEntryRebuilt`)を追加する
- 決定: A
- 理由: 「取消イベントを追記して無効化し、再作成イベントを追記する」という
  ユーザー要望は、既存のSupersede実装(内容不一致時の洗い替え)と同じ設計。
  対象が過去日付であること以外に業務的な違いはないため、新規イベント種別を
  増やさず既存の仕組みを再利用する(原則2の「Projectionは再生成可能な派生データ」
  にも合致し、イベント種別の重複を避けられる)。

### 論点5: 「完全に削除」の対象(候補が消滅した場合の扱い)
- 選択肢:
  - A. 再計算後の新しい候補と一致しない既存エントリは全て取消(Cancel/Supersede)し、
       新しい候補で完全に置き換える(既存の`recalculateFutureSchedule`と同じ挙動)
  - B. 新しい候補が0件になった場合は既存エントリを取消するだけで再作成しない
- 決定: A
- 理由: ユーザー要望が「完全に削除して再作成」であり、対象期間・対象社員に対する
  Scheduleエントリをその時点の最新ルール・ポリシーに基づいて洗い替える操作として
  一貫させる。候補が0件(例: 対象外の期間)であれば結果的に取消のみになるのは
  `recalculateFutureSchedule`の既存挙動と同じであり、特別扱いしない。

## 仕様確定事項(まとめ)
- 新規Artisanコマンド`paid-leave:schedule:rebuild`を追加する。
  - 引数/オプション: `--user=<userId>`(複数指定可、必須。少なくとも1件)、
    `--from=<YYYY-MM-DD>`(必須)、`--to=<YYYY-MM-DD>`(必須、`from`以降)、
    `--reason=<text>`(必須、取消イベントの理由として記録)。
  - 処理: 指定された各ユーザーについて、`ScheduleCandidateGenerator::candidatesFor($user, $from, $to)`
    で対象期間の最新候補を算出し、`RecalculateFutureSchedule($userId, $candidates, $reason, overrideManualEdits: true)`
    を発行する(`overrideManualEdits: true`により個別修正済みエントリも洗い替え対象に含める)。
  - `Granted`エントリは対象外(論点1)。`recalculateFutureSchedule`の既存ロジックが
    `FINALIZED_STATUSES`として自動的に除外するため、コマンド側で追加のフィルタは不要。
  - 対象期間に過去日付を含められるよう、`recalculateFutureSchedule`/`candidatesFor`が
    過去日付を理由に候補生成・比較をスキップしていないことを実装時に確認し、
    もしガードが存在する場合はコマンド呼び出し経路に限り除去する
    (`RollPaidLeaveSchedulesCommand`等、既存の未来月生成用途の挙動は変更しない)。
  - 1ユーザーの失敗が他ユーザーに影響しないよう、`RollPaidLeaveSchedulesCommand`と
    同様にユーザー単位でtry/catchし、失敗はログ(`report($e)`)+コンソール出力で
    継続する。
  - 実行前に対象ユーザー数・期間をコンソールに表示し、`--force`オプションが
    無い場合は確認プロンプト(`$this->confirm()`)を出す(破壊力の大きい操作のため、
    誤操作防止。CI/バッチ実行用に`--force`で確認をスキップ可能にする)。
- 新規イベント種別・新規Command種別は追加しない(既存の`RecalculateFutureSchedule`
  Command・既存のSupersede/Cancelイベントを流用する。論点4)。

## 受け入れ条件
- `php artisan paid-leave:schedule:rebuild --user=<id> --from=<過去日> --to=<過去日> --reason=<text> --force`
  を実行すると、指定ユーザー・期間内の`Granted`/`Cancelled`以外のScheduleエントリが
  取消され、最新のルール・ポリシーに基づく新しいエントリが作成される
  (`stored_events`に取消イベント・作成イベントが追記される。既存イベントの削除・
  書き換えは発生しない)。
- 同一コマンドを再実行し、内容に変化が無い場合は取消・再作成が発生しない(冪等)。
- `Granted`エントリは本コマンド実行前後で一切変更されない。
- `--user`・`--from`・`--to`・`--reason`のいずれかが未指定の場合、コマンドはエラー
  終了し何も変更しない。
- `--force`未指定時は確認プロンプトが表示され、拒否すると何も変更されない。
- 対象ユーザーの一部でエラーが発生しても、他ユーザーの処理は継続され、最終的に
  成功/失敗の内訳がコンソールに表示される。
- 関連する自動テスト(新規追加分・既存のPaidLeaveSchedule関連)が全てPASSする。

## 対象外
- 管理者向けAPI・フロントエンド画面としての提供(論点3。将来必要になれば別変更セット)。
- `Granted`(付与済み)エントリの洗い替え・取消(論点1。既存の`RevokePaidLeaveGrant`を使う)。
- 新規イベント種別の追加(論点4)。
- スケジューリング(cron等での定期自動実行)。本コマンドは運用者が都度手動実行する
  想定であり、自動トリガーは設けない。

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`: 過去分Scheduleエントリの洗い替え用Artisanコマンドの
  存在・実行方法・対象範囲(`Granted`除外)を追記する。
- `docs/17-events.md`: 変更なし(新規イベント種別を追加しないため)。
- 他のdocsファイルは変更なし。

## モック・アセット
なし

## 実装対象
- `backend/app/Console/Commands/RebuildPaidLeaveScheduleCommand.php`(新規、
  シグネチャ`paid-leave:schedule:rebuild`)
- `backend/app/Console/Kernel.php`(コマンド登録が自動発見でない場合のみ確認・追記)
- テスト: `backend/tests/Feature/Console/RebuildPaidLeaveScheduleCommandTest.php`(新規)
  - 過去日付エントリの取消+再作成、冪等性(2回目no-op)、`Granted`除外、
    未指定オプションでのエラー終了、`--force`無しの確認プロンプト拒否ケースを検証
- `docs/09-usecases-paid-leave.md`の追記

## 検証方法
- `cd backend && php artisan test --filter=RebuildPaidLeaveScheduleCommand`
- `cd backend && php artisan test --filter=PaidLeaveSchedule`
- `cd backend && php artisan test`(フルスイート)

## レビュー履歴
初版。AskUserQuestionでの確認により、対象=有給休暇付与予定(Scheduleドメイン)、
削除方式=取消イベント追記+再作成イベント追記、の2点を確定済み。

## 実装結果
未着手
