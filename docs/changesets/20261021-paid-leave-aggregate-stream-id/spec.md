# 付与予定と有給口座のAggregateIdを社員から分離する

ステータス: 検討中

## 変更要望(原文)
> このタスクをここで実行してください:「付与予定と有給口座のAggregateIdを分離する」

確認への回答(2026-10-21):
- 分離の範囲: 「付与予定と有給口座の両方」
- 本番のイベント履歴の作り直し(`leave:rebuild-event-store`の`--apply`/`--swap`): 最初は「まだ実行していない」との
  回答で、その後「実行済みでした」と訂正があった。

## 背景・目的
社員(`UserAggregate`)・有給口座(`PaidLeaveAccountAggregate`)・付与予定(`PaidLeaveScheduleAggregate`)の3つの集約が、
どれも社員IDをそのままAggregateIdに使っている。spatie/laravel-event-sourcingはaggregate_uuid単位で版を管理し、
集約のクラスを区別しない。そのため、同じ社員について複数の集約へ続けて書くと、楽観的排他
(`CouldNotPersistAggregate`)が誤って発火する。

今は一括付与(`ApplyScheduledGrantsHandler`)だけが、集約を読み直す応急処置で回避している。PR#109(`d256bd7`)では
付与予定のIDを分離していたが、9/14の競合解消(`ff64862`)で失われた。

代休・特別休暇の口座と同じ方式で、付与予定と有給口座のIDを分ける。本番の過去のイベントは、付け替え専用の
手順で付け替える(`data-correction`の方法2: 履歴そのものを直す)。

## 現状(As-Is)
- 社員IDを共有している集約(他には無い)
  - `UserAggregate`
  - `PaidLeaveAccountAggregate`(Handler 10本)
  - `PaidLeaveScheduleAggregate`(Handler 5本: Ensure・ManuallyEdit・Override・Recalculate・RunAssessment、
    および`ApplyScheduledGrantsHandler:41,64`)
- 分離済みの前例
  - 代休・特別休暇の口座は、`UserManagementStreamId::for('{kind}_account', userId)`(uuid5)でストリームIDを作る
    (`CompensatoryLeaveAccountAggregate::streamIdFor:87-90`)。
  - これらのイベントは`userId`を持ち、Projectorは`$event->userId`を使う。
  - 履歴は`additionalAggregateIds: [streamIdFor($userId)]`で読む(`SpecialLeaveController:581`、
    `CompensatoryLeaveController:530`)。
- spatieの動き(7.15.0)
  - `retrieve`は、aggregate_uuidだけで読む。
  - 対応するapplyが無いイベントでも、版を進める。
  - 版が衝突すると、unique(aggregate_uuid, aggregate_version)により`CouldNotPersistAggregate`になる。
  - スナップショットは使っていない。
- イベントの形
  - `paid_leave_account.*`(11種)・`paid_leave_schedule.*`(7種)には`userId`が無い。
  - 社員IDを`aggregateRootUuid()`から取っているもの
    - Projector: `PaidLeaveScheduleProjector:29`、`PaidLeaveBalanceProjector:39-90`、
      `PaidLeaveUsageAllocationProjector:48,100`
    - Reactor: `PaidLeaveAccount/Reactors/UnallocatedUsageOnPaidLeaveGrantReactor:22`
  - 有給口座・付与予定のイベントを購読しているのは、上の4つだけ。
- aggregate_uuid=社員IDを前提に読んでいるもの
  - 有給の履歴(`PaidLeaveController:845-848`、`Leave/Support/LeaveHistoryQuery:35-58,73-74`)。
  - 監査ログ(`AuditLogController:124`の`aggregate_id`での絞り込み、`:142-146`の社員での絞り込み)。
    - 社員での絞り込みは、payloadの`ACTOR_KEYS`(`user_id`を含む。`:37-41`)のLIKEでも拾う。
- 作り直しの仕組み(`app/Domain/EventSourcing/Rebuild/`)は、作り直し済みの履歴にはもう一度かけられない。
  - 事前条件の検査(`EventStoreRebuildWriter:249-262`)は、`paid_leave_request.*`・`special_leave_account.*`・
    `compensatory_leave_account.*`が1件でもあると違反にする。
  - 変換(`EventStoreRebuilder:47-51,142-143`)も、これらを未対応として扱う。
  - 仮に止めを外しても、休暇申請や充当の変換器がもう一度動き、イベントが二重になる。行のidもずれる。
- 作り直しで、aggregate=社員IDで書くもの
  - `PaidLeaveGrantRebuilder:95`
  - `UnallocatedUsageAllocator:120`(入力側でも`:163`で社員IDとして使う)
  - `PaidLeaveScheduleRebuilder:76`
  - `PaidLeaveRequestRebuilder:180,189,582,686,707,713`
- 本番では、作り直しを実行済み。本番の履歴は、作り直した後の形になっている。手順は
  `docs/27-release-runbook.md:114-148`(3.1)。
- 有給口座・付与予定に書き込む、HTTP以外の経路
  - スケジューラ
    - `paid-leave:roll-schedules`
    - `paid-leave:warn-expiring`・`warn-five-day-obligation`(`RaisePaidLeaveGrantWarning`)
    - `apply-membership-changes`・`users:sync-ms365`(社員のイベント→Reactor→付与予定)
  - キュー: `ReapplyPaidLeaveSchedulePolicyJob`
  - 運用コマンド: `paid-leave:schedule:rebuild`

## 設計前提の検証
- 正データと派生データ
  - 3つの集約のイベント(`stored_events`)が正。残高・付与・付与予定のテーブルはProjectionで、イベントから作り直せる。
  - IDの付け替えは、正データそのものの変更になる。`data-correction`に従い、補正イベントを足すのではなく履歴を直す。
    移行イベントは作らない。
  - 付け替えの前後で、全てのProjectionをイベントから作り直した結果が一致することで確かめる(原則2)。
- 定義と実態
  - 「集約=1つのストリーム」が前提なのに、3つの集約が1つのストリームを共有している。設計の誤り。
  - 社員IDをaggregate_uuidから逆算する作りは、分離すると壊れる。イベントに`userId`を持たせる。
- 原因の分類: 設計の誤り(PR#112の実装)と、その修正の競合解消での消失。応急処置は対症療法なので外す。
- 作り直しの前提: 既存の作り直しの仕組みは、作り直しの前の形の履歴を入力にする作り。作り直し済みの本番には
  使えない(現状を参照)。付け替えは、行を1対1で写す専用の手順にする(論点3)。

## 仕様検討

### 論点1: IDの導出方式
- 決定: `UserManagementStreamId::for('paid_leave_account', userId)` / `for('paid_leave_schedule', userId)`。
  各集約に`streamIdFor(string $userId): string`を置く。代休・特別休暇の口座と同じ方式。
- 理由: 方式をそろえると、履歴や監査ログの読み方も前例に合わせられる。
- 未確定・要確認事項: なし

### 論点2: イベントに社員IDを持たせる
- 決定
  - `paid_leave_account.*`・`paid_leave_schedule.*`の全イベントに`userId`を足す。
  - 集約に`forUser(string $userId)`を足す(代休口座の形)。
  - Projector 3本とReactor(`UnallocatedUsageOnPaidLeaveGrantReactor`)は、`$event->userId`を使う。
  - 過去のイベントには付け替えで`userId`を入れるので、`userId`が無いイベントを読む互換処理は作らない。
- 理由: 前例と同じ形で、逆算が要らなくなる。
- 未確定・要確認事項: なし

### 論点3: 本番の過去のイベントの付け替え
- 選択肢:
  - A. 既存の作り直し(`leave:rebuild-event-store`)を、もう一度かける。作り直し済みの履歴では事前条件で止まり、
    止めを外してもイベントが二重になるので、使えない。
  - B. 付け替え専用の手順を作る。行を1対1で写し、有給口座・付与予定の行の3点だけを変える。
  - C. stored_eventsを、その場でUPDATEする。戻せない。
- 決定: B。
  - 変換の本体: `PaidLeaveStreamRekeyer`(`app/Domain/EventSourcing/Rebuild/`)。
    - 入力は`stored_events`の全行(並びはid順)。
    - `paid_leave_account.*`・`paid_leave_schedule.*`の行について、次の3点だけを変える。
      - payloadに`userId`を入れる(元のaggregate_uuid)。
      - aggregate_uuidを新しいストリームIDに替える。
      - metaの`aggregate-root-uuid`を新しいストリームIDに替える。
    - すでに新しいストリームIDになっている行(payloadの`userId`から作ったIDとaggregate_uuidが一致する行)は、変えない。
      2回かけても結果は同じになる。
    - 版は、影響するストリームだけを振り直す。影響するのは、新しい有給口座、新しい付与予定、行が抜けて詰まる
      社員の集約の3種類。
      - meta、payloadの版に当たる値も合わせる。
    - 行の数と並びは変えない。そのため`id`・`created_at`・metaの`created-at`・`stored-event-id`は変わらない。
  - 実行口: `leave:rekey-paid-leave-streams`。
    - 既存の作り直しと同じ書き込み・`verify`・`swap`を使う。
    - 既定は試し実行(件数と、旧ID→新IDの対応の件数を出す)。`--apply`(別表へ書く)・`--verify`・`--swap`。
    - この口専用の事前条件を持たせる。休暇申請・新しい口座の種類があっても止めない。
  - 既存の作り直しの出力にも、最後にこの変換をかける(`EventStoreRebuilder`の最後の工程にする)。
    - 古いバックアップから作り直す場合にも、新しいIDになる。
    - 作り直しのRebuilder 4本は、個別に直さない。ただし`UnallocatedUsageAllocator`は入力側でも社員IDを使うので、
      変換をかける前の段階で動くことを確かめる。
  - `verify`に足す検査
    - 社員IDをaggregate_uuidに持つ行は、`user.*`だけであること。
    - 有給口座・付与予定の行で、payloadの`userId`から作ったIDがaggregate_uuidと一致すること。
    - 付け替えの前後で、有給口座・付与予定・社員以外の行は、id・版・payload・meta・created_atがすべて同じであること。
    - `EventRestorabilityChecker`が通ること(全行が新しいイベントのクラスで復元できる)。
- 理由: 作り直し済みの本番に対して、他のイベントを一切変えずに付け替えられる。idが変わらないので、`stored_event_id`を
  持つProjectionにも影響せず、戻し方も簡単になる。
- 未確定・要確認事項: なし

### 論点4: aggregate_uuid=社員IDを前提に読んでいる箇所
- 決定
  - 有給の履歴: 特別休暇・代休と同じく、`additionalAggregateIds: [PaidLeaveAccountAggregate::streamIdFor($userId)]`で読む。
    `userScopedEventClassPrefixes`のうち有給口座の分はやめる。
    - 応答の`aggregate_id`・`version`の値は変わる(画面は表示しているだけで、比較には使っていない)。
  - 監査ログ
    - 社員での絞り込み: payloadに`userId`が入るので、今の`ACTOR_KEYS`のLIKEで、有給口座・付与予定も拾える。
      直さない。
    - `userId`を持たない特別休暇の3イベント(`SpecialLeaveAccountGrantRevoked`・`UsageCancelled`・`UsageConfirmed`)の
      漏れは、今回は扱わない(対象外)。
    - `aggregate_id`での絞り込み(`:124`)に社員IDを入れたとき、有給口座・付与予定が出なくなる。この挙動の変更を
      docsに書く。
  - 上記以外の読み手は、実装の最初に`grep -rn "aggregate_uuid\|aggregateRootUuid\|whereAggregateRoot" app`で
    全て洗い出す。一覧は実装結果に残す。
  - `StoredEventHistoryNormalizer:157,170,226`は、旧い社員のイベントがあるときだけ動く1回限りの処理なので、
    対象外とする。
- 理由: 前例に合わせれば、新しい読み方を作らずに済む。
- 未確定・要確認事項: なし

### 論点5: 一括付与の応急処置
- 決定: `ApplyScheduledGrantsHandler`で、エントリごとの読み直し(64行)だけを外す。41行で取った`$aggregate`で
  persistする。エントリごとのtry/catchの扱いは変えない。
- 回帰テストは、修正前のコード(応急処置を外しただけの状態)で`CouldNotPersistAggregate`が起きることを確かめてから、
  修正後にPASSさせる。
- 理由: ストリームが分かれれば衝突しない。応急処置を外すのは、衝突が無くなったことをテストで示した後にする。
- 未確定・要確認事項: なし

### 論点6: 社員の集約のID
- 決定: 社員の集約のIDは、社員IDのまま変えない。
- 理由: 有給口座・付与予定が分かれれば、社員の集約は自分だけのストリームになる。
- 未確定・要確認事項: なし

### 論点7: リリースの手順
- 決定: `docs/27`の3.1と同じ細かさで、新しい節を書く。
  - 止めるもの
    - `php artisan down`
    - cronの`schedule:run`
    - キュー(空になるまで処理してから止める。`userId`の無いイベントを持つ古いジョブを残さない)
    - 打刻端末・API・MCPの受付
  - 実行の順
    1. 本番の複製DBでリハーサルする(下の確認を含む)。
    2. 本番のDB全体とReadModelをバックアップする。
    3. 新しいコードを配備する。
    4. 付け替えの試し実行を行い、件数を確かめる。
    5. `--apply`・`--verify`を行う。
    6. `--swap`を行う。
    7. ReadModelを空にして、全Projectorを再生する。
    8. 指紋を比べる。
    9. 受付を再開する。
  - 原則2の確認(リハーサルと本番で同じことをする)
    - 付け替えの前に、全ReadModelを空にして再生し、`--readmodel-fingerprint`を取る(基準A)。
    - 付け替えと`swap`の後に、もう一度空にして再生し、指紋を取る(B)。
    - AとBの差が0件であること。差があれば`--readmodel-diff`で行を特定して止める。
    - 本番のReadModelと基準Aの差は、別件として報告する。
  - 戻し方
    - 表をRENAMEで戻す。
    - ReadModelを戻す(または旧コードで再生する)。
    - 旧コードを再配備する。
    - 表とコードは同時に戻す。idが変わらないので、戻すのは簡単。
  - 本番で実行する前に、試し実行の結果をユーザーに示して許可を取る。日時と内容は、この変更セットの「レビュー履歴」に
    残す(`data-correction`)。
- 理由: 新しいコードは、付け替えの前の履歴を正しく読めない。付け替えが終わる前に1件でも書かれると、空のストリームを
  読んだ状態で記録される。付け替えの「そのまま写す」規則でも直らない。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- 集約: 2つの集約に`streamIdFor`・`forUser`を足す。全イベントに`userId`を足す。
- Handler: 有給口座10本、付与予定5本、`ApplyScheduledGrantsHandler`を、
  `Aggregate::retrieve(Aggregate::streamIdFor($userId))->forUser($userId)`の形にする。応急処置を外す(論点5)。
- 購読側
  - Projector 3本: `$event->userId`を使う。
  - `UnallocatedUsageOnPaidLeaveGrantReactor`: `$event->userId`を使う。
- 読み手: 有給の履歴(論点4)と、grepで見つかったもの。
- 付け替え
  - `PaidLeaveStreamRekeyer`。
  - `leave:rekey-paid-leave-streams`(試し実行・`--apply`・`--verify`・`--swap`)。
  - 作り直しの最後の工程に組み込む。
  - `verify`の4つの検査。
- テスト
  - 回帰
    - 同じ社員の複数の付与予定を、1回の一括付与で付与しても衝突しない(修正前に衝突することを確かめる)。
    - 社員の更新→Reactorによる付与予定の再計算→付与を、同じ社員に続けて行っても衝突しない。
    - 未充当の消化がある社員に付与すると、新しいストリームに`usage_allocated`が記録される(Reactorの修正)。
  - 付け替え
    - 入力は「旧い形の行→作り直し→`swap`→新しいコードで少し操作」の後の`stored_events`(本番の形を再現する)。
    - これに付け替えをかけ、`verify`が通ることを確かめる。他の行が変わらないことも確かめる。
    - 全ReadModelを空にして再生し、付け替えの前の再生結果と指紋が一致することを確かめる。
    - 2回かけても結果が同じであること。
  - 既存のテストの修正(実装者が直す)
    - 集約の単体テスト: `PaidLeaveAccountAggregateTest`・`PaidLeaveScheduleAggregateTest`。`streamIdFor`・`forUser`・
      `userId`を足す。
    - 生の行を使うテスト: Rebuild系ほか12ファイル程度。
    - `aggregate_uuid=$user->id`を直接見るテスト: `PaidLeaveGrantTimeAllocationTest`・`PaidLeaveUsageIdempotencyTest`・
      `PaidLeaveRequestScenarioTest`。
    - `retrieve($userId)`を直接呼ぶテスト: `LeaveAttendanceRateScenarioTest`・`ReapplyPaidLeaveSchedulePolicyJobTest`・
      `AttendanceRateAssessorTest`。

## 受け入れ条件
- 有給口座・付与予定のイベントが、社員IDとは別のaggregate_uuidで記録され、payloadに`userId`を持つ。
- 一括付与が応急処置なしで動き、回帰テストがPASSする(修正前に失敗することを確かめている)。
- 付与のときの未充当の充当が、分離した後も動く。
- 有給の履歴・残高・付与予定が、分離した後も今と同じ結果を返す(テストで確かめる)。
- 付け替えの後に`verify`の4つの検査が通る。本番の形を再現した入力で、付け替えの前後の再生結果の指紋が一致する。
- backendのテスト一式がPASSする(既知の`ReadModelFingerprintCommandTest`の失敗を除く)。e2eの`scenario-03`・
  `scenario-15`がPASSする。
- docs(下記)が実装と一致する。リリースの手順が`docs/27`にある。

## 対象外
- 社員の集約のIDの変更。
- 本番での付け替えの実行そのもの(リリース時に、論点7の手順でユーザーの許可を取って行う)。
- 監査ログで、特別休暇の3イベントが社員での絞り込みから漏れる件。
- `StoredEventHistoryNormalizer`の社員IDの前提。
- 年度はじめ一斉付与・自動判定、Reactorのテスト(別の変更セット)。

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`: AggregateId=userIdの記述(4行、431-432行、517行、UC-P011)を直す。
- `docs/17-events.md`
  - 249行・397行の「AggregateId = userId」を直す。
  - 有給口座・付与予定のイベントに`userId`を足す。
- `docs/32-stored-event-history-normalization.md`: 付け替えの手順、作り直しの最後の工程、`verify`の検査。
- `docs/27-release-runbook.md`: 論点7のリリースの手順。
- 監査ログの`aggregate_id`での絞り込みの挙動の変更を、監査ログのドキュメント(該当する章)に書く。
- `docs/changesets/20260906-paid-leave-schedule-assessment/spec.md`の論点14は、過去の記録なので書き換えない。
- `docs/changesets/README.md`

## モック・アセット
なし

## 実装対象
- `backend/app/Domain/PaidLeaveAccount/`(Aggregates・Events・Handlers・Projectors・Reactors)
- `backend/app/Domain/PaidLeaveSchedule/`(Aggregates・Events・Handlers・Projectors)
- `backend/app/Domain/EventSourcing/Rebuild/`(`PaidLeaveStreamRekeyer`、`EventStoreRebuilder`の最後の工程、
  `EventStoreRebuildWriter::verify`と事前条件)
- `backend/app/Console/Commands/RekeyPaidLeaveStreamsCommand.php`
- `backend/app/Http/Controllers/Api/PaidLeaveController.php`、`backend/app/Domain/Leave/Support/LeaveHistoryQuery.php`
- `backend/tests/`
- docs: 上記

## 検証方法
- `cd backend && php artisan test`(全件)
- 付け替えの試し実行と`--verify`を、本番の形を再現したテスト用のDBで行う(開発用のDBには、作り直し後の種類のイベントが
  無いので使わない)。本番の前に、本番の複製DBでリハーサルする(論点7)。
- e2e: `npm run test:e2e -- scenario-03-paid-leave scenario-15-paid-leave-schedule`

## レビュー履歴
- 初版(2026-10-21): 未実行の作り直しに付け替えを組み込む案。
- 2026-10-21 ユーザーの訂正(本番の作り直しは実行済み)で、論点3の前提が覆った。作り直し済みの履歴に、作り直しを
  もう一度かける案に変えた。
- 2026-10-21 独立設計レビュー(同等モデル)で、その案も成り立たないと分かった(事前条件で止まる、止めを外すと
  イベントが二重になる)。前提が覆ったので、「現状」「設計前提の検証」から作り直した。
  - 要修正
    - 論点3: 付け替え専用の手順(行を1対1で写す)に決めた。
    - 読み手の漏れ(`UnallocatedUsageOnPaidLeaveGrantReactor`)を足した。
    - 作り直しのRebuilderの漏れ(`PaidLeaveRequestRebuilder`)を足した。最後の工程にかける変換にして、個別には直さない。
    - テストの入力を本番の形に合わせた。
    - 原則2の確認を指紋の比較(A/B)にした。
    - リリースの手順(止める範囲、戻し方、許可の取り方)を論点7として足した。
  - 推奨
    - 監査ログ・履歴の扱いを、事実に合わせて決め直した。
    - `verify`の検査を足した。
    - 論点5の書き方を直した。
    - 回帰テストは、修正前に失敗することを確かめる。
    - 既存のテストへの影響を見積もった。
  - 軽微: Handlerの本数、docsの箇所、Normalizerは対象外、キューを空にする。

## 実装結果
未着手
