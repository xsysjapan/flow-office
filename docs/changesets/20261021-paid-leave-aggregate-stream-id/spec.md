# 付与予定と有給口座のAggregateIdを社員から分離する

ステータス: 完了

## 変更要望(原文)
> このタスクをここで実行してください:「付与予定と有給口座のAggregateIdを分離する」

確認への回答(2026-10-21):
- 分離の範囲: 「付与予定と有給口座の両方」
- 本番のイベント履歴の作り直し(`leave:rebuild-event-store`の`--apply`/`--swap`): 最初は「まだ実行していない」との
  回答で、その後「実行済みでした」と訂正があった。
- 付与予定(自動付与)の機能について: 「現在自動付与機能は使用していません。もし、イベントを見直すのであれば、
  スケジュールの生成をなかったことにしてください。」(2026-10-21)
- 補足: 「あくまで使用していないのは自動付与であって、手動の付与はしています。」(2026-10-21)

## 背景・目的
社員(`UserAggregate`)・有給口座(`PaidLeaveAccountAggregate`)・付与予定(`PaidLeaveScheduleAggregate`)の3つの集約が、
どれも社員IDをそのままAggregateIdに使っている。spatie/laravel-event-sourcingはaggregate_uuid単位で版を管理し、
集約のクラスを区別しない。そのため、同じ社員について複数の集約へ続けて書くと、楽観的排他
(`CouldNotPersistAggregate`)が誤って発火する。

今は一括付与(`ApplyScheduledGrantsHandler`)だけが、集約を読み直す応急処置で回避している。PR#109(`d256bd7`)では
付与予定のIDを分離していたが、9/14の競合解消(`ff64862`)で失われた。

代休・特別休暇の口座と同じ方式で、付与予定と有給口座のIDを分ける。本番の過去のイベントは、専用の手順で直す
(`data-correction`の方法2: 履歴そのものを直す)。
- 有給口座のイベント: 新しいIDに付け替える。手動の付与(`source='manual'`)・移行データ(`carried_over`)・消化・
  充当など、有給口座の記録はすべて残す。
- 付与予定のイベント: 自動付与の機能は本番で使われていないので、ユーザーの指示どおり履歴から取り除く(生成は無かった
  ことにする)。新しいコードを出した後に、新しいIDで作り直される。

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

### 論点3: 本番の過去のイベントの直し方
- 選択肢:
  - A. 既存の作り直し(`leave:rebuild-event-store`)をもう一度かける。作り直し済みの履歴では事前条件で止まり、止めを
    外してもイベントが二重になるので、使えない。
  - B. 専用の手順を作る。行を1対1で写し、有給口座の行は付け替え、付与予定の行は取り除く。
  - C. stored_eventsを、その場でUPDATE・DELETEする。戻せない。
- 決定: B。
  - 変換の本体: `PaidLeaveStreamRekeyer`(`app/Domain/EventSourcing/Rebuild/`)。入力は`stored_events`の全行で、
    id順に処理する。
    - `paid_leave_account.*`の行は、次の3点だけを変える。
      - payloadに`userId`(元のaggregate_uuid)を入れる。
      - aggregate_uuidを、新しいストリームIDに替える。
      - metaの`aggregate-root-uuid`を、新しいストリームIDに替える。
    - すでに新しいストリームIDになっている行(payloadの`userId`から作ったIDと、aggregate_uuidが一致する行)は変えない。
      2回かけても結果が同じになる。
    - `paid_leave_schedule.*`の行は取り除く(ユーザーの指示。自動付与の機能は使われておらず、生成の事実は業務上の事実では
      ない)。
    - 版を振り直すのは、影響するストリーム(新しい有給口座と、行が抜けて詰まる社員の集約)だけ。meta・payloadの版も
      合わせる。
    - 残る行の`id`・`created_at`・metaの`created-at`・`stored-event-id`は変えない。付与予定の行を取り除いた分は、idが
      飛び飛びになる。それでよい(付与予定のイベントのidを指すReadModelの列は無い)。
  - 付与予定の行を取り除く前提(事前条件)
    - 次のどれかが1件でもあれば、付与予定は実際に使われていたことになる。取り除かずに止めて、件数を報告する。
      - `paid_leave_schedule.entry_granted`(付与済み)
      - `paid_leave_schedule.assessment_overridden`(判定の上書き)
      - `paid_leave_schedule.entry_manually_edited`(個別修正)
    - 有給口座に、付与予定からの一括付与(`paid_leave_account.grant_created`で`source='scheduled_batch'`。
      `ApplyScheduledGrantsHandler:54`)が1件でもあれば、同じく止める。
    - 手動の付与(`source='manual'`、`PaidLeaveController:479`)は、付与予定と関係が無いので止める理由にしない。
      付け替えて残す。
  - 実行口: `leave:rekey-paid-leave-streams`。
    - 既定は試し実行。出す件数: 付け替える行、取り除く付与予定の行(種類ごと)、旧ID→新IDの対応、事前条件の結果。
    - `--apply`(別表へ書く)・`--verify`・`--swap`は、既存の作り直しの書き込み・切り替えを使う。
    - この口専用の事前条件と`verify`を持たせる。既存の`verify`のうち、idが連番であることの検査は、この口では行わない
      (付与予定の行を取り除くとidが飛ぶため)。
  - 既存の作り直しの出力にも、最後にこの変換をかける(`EventStoreRebuilder`の最後の工程)。古いバックアップから作り直す
    場合にも、同じ結果になる。作り直しのRebuilder 4本は個別に直さない。
  - この口の`verify`
    - 社員IDをaggregate_uuidに持つ行は、`user.*`だけであること。
    - `paid_leave_account.*`の行で、payloadの`userId`から作ったIDが、aggregate_uuidと一致すること。
    - `paid_leave_schedule.*`の行が0件であること。
    - 有給口座・付与予定・社員以外の行は、付け替えの前後でid・版・payload・meta・created_atがすべて同じであること。
    - `EventRestorabilityChecker`が通ること。
  - 取り除いた後
    - 付与予定のProjection(`paid_leave_schedule_entries`・`paid_leave_schedule_assessments`)は、作り直すと空になる。
    - 自動付与が有効な社員(`paid_leave_auto_grant_enabled=true`)がいれば、新しいコードの`paid-leave:roll-schedules`
      (次のcron)が、新しいIDで付与予定を作り直す。
- 理由
  - 作り直し済みの本番について、他のイベントを一切変えずに直せる。
  - 付与予定を取り除けば、付け替えの対象が減る。その後の変更セット(年度はじめ一斉付与)も、空の状態から始められる。
  - 使われていた形跡があるときは、黙って消さずに止める(`data-correction`の安全手順)。
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
    - AとBの差が、付与予定の2つの表(`paid_leave_schedule_entries`・`paid_leave_schedule_assessments`。Bでは空になる)を
      除いて0件であること。差があれば`--readmodel-diff`で行を特定して止める。
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
- 付け替えと取り除き
  - `PaidLeaveStreamRekeyer`(有給口座は付け替え、付与予定は取り除く。事前条件つき)。
  - `leave:rekey-paid-leave-streams`(試し実行・`--apply`・`--verify`・`--swap`)。
  - 作り直しの最後の工程に組み込む。
  - この口の`verify`の5つの検査。
- テスト
  - 回帰
    - 同じ社員の複数の付与予定を、1回の一括付与で付与しても衝突しない(修正前に衝突することを確かめる)。
    - 社員の更新→Reactorによる付与予定の再計算→付与を、同じ社員に続けて行っても衝突しない。
    - 未充当の消化がある社員に付与すると、新しいストリームに`usage_allocated`が記録される(Reactorの修正)。
  - 付け替え
    - 入力は「旧い形の行→作り直し→`swap`→新しいコードで少し操作」の後の`stored_events`(本番の形を再現する)。
    - これに付け替えをかけ、`verify`が通ることを確かめる。他の行が変わらないこと、付与予定の行が無くなることも確かめる。
    - 付与済み・判定の上書き・個別修正の付与予定のイベント、または`source='scheduled_batch'`の付与があると、試し実行が
      事前条件で止まる。
    - 手動の付与(`source='manual'`)があっても止まらず、付け替えられて残る。その社員の残高・付与の一覧が、付け替えの
      前後で同じになる。
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
- 付け替えの後に、この口の`verify`の5つの検査が通る。本番の形を再現した入力で、付け替えの前後の再生結果の指紋が、
  付与予定の2つの表を除いて一致する。
- 付与予定のイベントが本番の履歴から無くなる。使われていた形跡があるときは、事前条件で止まる。
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

- 2026-10-21 ユーザーの指示(自動付与は使っていない。イベントを見直すなら、付与予定の生成は無かったことにする)を
  反映した。論点3を「有給口座は付け替え、付与予定は取り除く」に変えた。付与予定が使われていた形跡があれば止める
  事前条件と、指紋の比較で除く表を足した。

- 2026-10-21 ユーザーの補足(使っていないのは自動付与で、手動の付与は使っている)を反映した。事前条件を、付与予定から
  の一括付与(`source='scheduled_batch'`)で判定するように明確にした。手動の付与は付け替えて残すことと、そのテストを
  明記した。

## 実装結果
### コミット
- `21e69c2` コード本体
  - 対象: 両集約の`streamIdFor`/`forUser`、イベント18種の`userId`、Handler 16本、Projector 3本、Reactor、有給の履歴
  - 応急処置の除去
  - 回帰テスト`PaidLeaveStreamSeparationTest`(R1〜R4)
    - R1は修正前(応急処置だけを外した状態)に`CouldNotPersistAggregate`で失敗することを確かめた。
- (次のコミット) 付け替えの手順
  - `PaidLeaveStreamRekeyer`、`PaidLeaveStreamRekeyVerifier`、`leave:rekey-paid-leave-streams`
  - 作り直しの最後の工程
  - docs/32・docs/27(3.2)・docs/15
### 読み手の一覧(grepの結果)
- 社員IDとして扱っていて直したもの
  - Projector 3本
  - `PaidLeaveBalanceProjector`の`aggregate_uuid`による読み直し
  - `UnallocatedUsageOnPaidLeaveGrantReactor`
  - 有給の履歴(`PaidLeaveController`・`LeaveHistoryQuery`)
- 直さなかったもの
  - 社員の集約・勤務形態・有給申請のIDとして使っているReactor
  - 監査ログ(論点4どおり)
  - `StoredEventHistoryNormalizer`(対象外)
### 実装時の判断
- 作り直しの最後の工程では、止める条件を見ない。
  - 本番では作り直しを実行済みで、もう使わない。付け替え専用の口には、止める条件がある。
- 作り直しでは、最後にidを1から振り直す(既存の`verify`のid連番の検査のため)。付け替え専用の口では、idを変えない。
- 本番の形の入力は、新しい作り直しが付け替え済みの出力を出すため、作り直し後の行を旧い形に戻して作った。
- SQLiteで索引名がぶつかるため、`EventStoreRebuildWriter::write`の索引名に`_next`を付ける場合がある。
- payloadには版に当たる項目が無いため、metaの`aggregate-root-version`だけを合わせた。
### 検証(委譲元で実行)
- backend全件(`./vendor/bin/phpunit`、APP_KEYを指定): 1933件PASS(割当の再計算の変更を含む)
- e2e: `scenario-00`・`scenario-03`・`scenario-15`で13件PASS
### 受け入れ条件
- 別IDとuserId: R4、付け替えのシナリオテスト。
- 応急処置なしの一括付与: R1。
- 充当の継続: R3。
- 履歴・残高: R4、`PaidLeaveHistoryTest`。
- verifyの検査と指紋: `PaidLeaveStreamRekeyScenarioTest`(付与予定の2表を除いて一致)。
- 付与予定の取り除き、止める条件: 同上。
- docs: 09・17・32・27・15を更新した。
### 環境
テストの途中で、e2eのために委譲元が作った`backend/.env`が開発用のDBを指していたため、`php artisan test`が開発用のDBに
対して動き、そのDBファイルが壊れた。`.env`・DBファイルは委譲元が作ったもの(gitの管理外)で、検証後に消した。
### 本番での付け替え
未実施。`docs/27`の3.2の手順で、試し実行の結果をユーザーに示し、許可を取ってから行う。
