# 付与予定と有給口座のAggregateIdを社員から分離する

ステータス: 検討中

## 変更要望(原文)
> このタスクをここで実行してください:「付与予定と有給口座のAggregateIdを分離する」

確認への回答(2026-10-21):
- 分離の範囲: 「付与予定と有給口座の両方」
- 本番のイベント履歴の作り直し(`leave:rebuild-event-store`の`--apply`/`--swap`): 「まだ実行していない」

## 背景・目的
社員(`UserAggregate`)・有給口座(`PaidLeaveAccountAggregate`)・付与予定(`PaidLeaveScheduleAggregate`)の
3つの集約が、どれも社員IDをそのままAggregateIdに使っている。spatie/laravel-event-sourcingはaggregate_uuid単位で
版を管理し、集約のクラスを区別しない。そのため、同じ社員について複数の集約へ続けて書くと、楽観的排他
(`CouldNotPersistAggregate`)が誤って発火する。

今は一括付与(`ApplyScheduledGrantsHandler`)だけが、集約を読み直す応急処置で回避している。PR#109(`d256bd7`)では
付与予定のIDを分離していたが、9/14の競合解消(`ff64862`)で失われた。

代休・特別休暇の口座と同じ方式で、付与予定と有給口座のIDを分離する。過去のイベントは、まだ本番で実行していない
イベント履歴の作り直しの中で付け替える(`data-correction`の方法2)。

## 現状(As-Is)
- 同じ社員IDを共有している集約
  - `UserAggregate`(例: `SetUserUsageStartDateHandler:22`)
  - `PaidLeaveAccountAggregate`(Handler 10本: GrantPaidLeave、RevokePaidLeaveGrant、ChangePaidLeaveGrantDate/Amount/Expiry、
    Designate/Confirm/CancelPaidLeaveUsage、AllocateUnallocatedPaidLeaveUsages、RaisePaidLeaveGrantWarning)
  - `PaidLeaveScheduleAggregate`(Handler 7本と、`ApplyScheduledGrantsHandler:41,64`)
- 分離済みの集約: 代休・特別休暇の口座は`UserManagementStreamId::for('{kind}_account', userId)`(uuid5)で
  IDを導出する(`CompensatoryLeaveAccountAggregate::streamIdFor:87-90`)。イベントに`userId`を持ち、Projectorは
  `$event->userId`を使う。user-membership / user-identityも同じ方式。
- spatieの挙動(7.15.0)
  - `retrieve`はaggregate_uuidだけで読む(`EloquentStoredEventQueryBuilder:31-34`)。
  - 対応するapplyが無いイベントも版を進める(`AggregateRoot:277-300`)。
  - 版の衝突は、unique(aggregate_uuid, aggregate_version)によって`CouldNotPersistAggregate`になる。
  - スナップショットは使っていない。
- イベントの形
  - `paid_leave_schedule.*`(7種)・`paid_leave_account.*`(11種)のどれにも`userId`が無い。
  - Projectorは社員IDを`aggregateRootUuid()`から取る(`PaidLeaveScheduleProjector:29`、
    `PaidLeaveBalanceProjector:39-90`、`PaidLeaveUsageAllocationProjector:48,100`)。
- aggregate_uuid=社員IDを前提に読んでいる箇所
  - 有給の履歴(`PaidLeaveController:845-848`、`Leave/Support/LeaveHistoryQuery:35-58`)
  - 監査ログの社員での絞り込み(`AuditLogController:142-146`)
  - `PaidLeaveBalanceProjector:90`
- イベント履歴の作り直し(`app/Domain/EventSourcing/Rebuild/`、`leave:rebuild-event-store`)
  - `PaidLeaveScheduleRebuilder:76`・`PaidLeaveGrantRebuilder:95`・`UnallocatedUsageAllocator:120`は
    aggregate_uuid=社員IDで書く。
  - 今の形のイベントは`EventStoreRebuilder:149-152`(passThrough)でそのまま写す。
  - `renumber`(600-660付近)は、集約ごとに版を連番で振り直す。
  - `verify`(`EventStoreRebuildWriter:312-345`)は、IDの付け方を検査していない。
- 本番では、作り直しはまだ実行していない(ユーザー回答)。手順は`docs/27-release-runbook.md:114-148`、
  `docs/32-stored-event-history-normalization.md`。

## 設計前提の検証
- 正データと派生データ
  - 3つの集約のイベント(`stored_events`)が正。残高・付与・付与予定のテーブルはProjectionで、イベントから作り直せる。
  - IDの付け替えはイベントの正データそのものの変更なので、`data-correction`に従い、補正イベントを足すのではなく
    履歴を作り直す(移行イベントは作らない)。
  - 作り直しのあと、Projectorを再生した結果が今のProjectionと一致することで確かめる(原則2)。
- 定義と実態
  - 「集約=1つのストリーム」が前提なのに、3つの集約が1つのストリームを共有している。設計の誤り。
  - 社員IDをaggregate_uuidから逆算している箇所は、分離すると壊れる。イベントに`userId`を持たせ、
    逆算をやめる(代休口座と同じ)。
- 原因の分類: 設計の誤り(PR#112の実装)と、その修正(`d256bd7`)の競合解消での消失。応急処置(都度の読み直し)は
  対症療法なので外す。

## 仕様検討

### 論点1: IDの導出方式
- 選択肢:
  - A. `UserManagementStreamId::for('paid_leave_account', userId)` / `for('paid_leave_schedule', userId)`
    (代休・特別休暇の口座と同じ方式)。
  - B. `d256bd7`の固有の名前空間によるuuid5。
- 決定: A。各集約に`streamIdFor(string $userId): string`を置き、Handlerはこれで`retrieve`する。
- 理由: 同じ方式に揃えると、監査ログ・作り直しの処理を共通化できる(論点4)。
- 未確定・要確認事項: なし

### 論点2: イベントに社員IDを持たせる
- 選択肢:
  - A. `paid_leave_account.*`と`paid_leave_schedule.*`の全イベントに`userId`を足し、Projectorは`$event->userId`を
    使う。
  - B. Projectorで、ストリームIDから社員を逆引きする表を持つ。
- 決定: A。
  - 集約に`forUser(string $userId)`を足して社員IDを束ね、イベントに入れる(代休口座の形)。
  - 過去のイベントには、作り直しで`userId`を入れる(論点3)。そのため、`userId`の無いイベントを読む互換処理は
    作らない。
- 理由: 代休口座と同じ形で、逆算が要らなくなる。
- 未確定・要確認事項: なし

### 論点3: 過去のイベントの付け替え
- 選択肢:
  - A. 未実行の作り直し(`leave:rebuild-event-store`)の中で付け替える。
  - B. 付け替え専用のコマンドを新しく作る。
- 決定: A。
  - `PaidLeaveGrantRebuilder`・`UnallocatedUsageAllocator`・`PaidLeaveScheduleRebuilder`が書くイベントのaggregate_uuidを、
    新しいストリームIDにする。payloadに`userId`を入れる。
  - 今の形の`paid_leave_account.*`・`paid_leave_schedule.*`の行は、passThroughではなく変換対象にする。
    aggregate_uuid(=社員ID)を`userId`としてpayloadに入れ、aggregate_uuidを新しいストリームIDに替える。
    metaの`aggregate-root-uuid`も替える。
  - 版の振り直しは、既存の`renumber`に任せる(ストリームが分かれるので、社員の集約側の版も詰め直される)。
  - `verify`に、次の検査を足す。
    - `paid_leave_account.*`・`paid_leave_schedule.*`の行で、aggregate_uuidが`users.id`と同じものが無いこと。
    - payloadの`userId`から導出したストリームIDが、aggregate_uuidと一致すること。
- 理由: ユーザー回答によると、作り直しはまだ本番で実行していない。同じ作り直しに組み込めば、本番の作業は
  1回で済む。
- 制約: このコードは、作り直しと同じリリースで本番に出す必要がある。作り直しの前に出すと、新しいIDで読むコードが、
  付け替え前の履歴を読めない。`docs/27-release-runbook.md`の手順に、この依存を明記する。
- 未確定・要確認事項: なし

### 論点4: aggregate_uuid=社員IDを前提に読んでいる箇所
- 決定
  - 有給の履歴(`PaidLeaveController:845-848`、`LeaveHistoryQuery`): `streamIdFor(userId)`で読む。代休・特別休暇で
    すでに同じことをしていれば、それに合わせる。
  - 監査ログの社員での絞り込み(`AuditLogController:142-146`)
    - 「aggregate_uuid=社員ID、または社員から導出したストリームID(有給口座・付与予定・代休口座・特別休暇口座・
      user-membership・user-identity)のどれか」で絞る。
    - 導出の一覧は`UserManagementStreamId`の隣に`UserStreamIds::allFor(userId)`として置く。代休・特別休暇が今は
      漏れているなら、ここで一緒に直る。
  - `PaidLeaveBalanceProjector:90`など、Projectorの中でaggregate_uuidを社員IDとして使う箇所: `$event->userId`か、
    `streamIdFor`に替える。
  - 上記以外の読み手は、実装時に`grep -rn "aggregate_uuid" app`と`aggregateRootUuid()`の全箇所を確かめ、一覧を
    実装結果に残す。
- 理由: 漏れると、履歴の表示や残高の計算が空になる。
- 未確定・要確認事項: なし

### 論点5: 一括付与の応急処置
- 決定: `ApplyScheduledGrantsHandler`の都度の読み直し(57-66行)を外し、1回の`retrieve`で処理する。
- 理由: ストリームが分かれれば、版の衝突は起きない。
- 未確定・要確認事項: なし

### 論点6: 社員の集約(UserAggregate)のID
- 決定: 変えない(社員IDのまま)。
- 理由: 有給口座と付与予定が分かれれば、社員の集約は自分だけのストリームになる。社員IDのままで問題が無い。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- 集約
  - `PaidLeaveAccountAggregate`と`PaidLeaveScheduleAggregate`に、`streamIdFor(userId)`(`UserManagementStreamId::for('paid_leave_account'|'paid_leave_schedule', userId)`)と
    `forUser(userId)`を足す。
  - 全イベントに`public readonly string $userId`を足す。順番は代休口座のイベントに合わせる。
- Handler: 17本と`ApplyScheduledGrantsHandler`を、`Aggregate::retrieve(Aggregate::streamIdFor($userId))->forUser($userId)`の形にする。
  `ApplyScheduledGrantsHandler`の応急処置を外す。
- Projector: 社員IDは`$event->userId`から取る(`PaidLeaveScheduleProjector`、`PaidLeaveBalanceProjector`、
  `PaidLeaveUsageAllocationProjector`ほか、該当する全箇所)。
- 読み手: 有給の履歴、監査ログの社員の絞り込み(`UserStreamIds::allFor`)、そのほか`aggregate_uuid`を読む箇所。
- 作り直し
  - 3つのRebuilderの出力を新しいIDにし、`userId`を入れる。
  - 今の形の有給口座・付与予定のイベントを変換対象にする。
  - `verify`に論点3の検査を足す。
  - `docs/32`と`docs/27`を更新する。
- テスト
  - 回帰
    - 同じ社員について、付与予定の複数行を1回の一括付与で付与しても`CouldNotPersistAggregate`にならない
      (応急処置なしで)。
    - 社員の更新(例: 利用開始日の変更)→Reactorによる付与予定の再計算→有給の付与を、同じ社員に続けて行っても
      衝突しない。
  - 作り直し
    - 今の形の有給口座・付与予定のイベントを含む履歴を作り直すと、aggregate_uuidが新しいIDになり、
      payloadに`userId`が入る。`verify`が通る。
    - 作り直した履歴でProjectorを再生した結果(残高・付与・付与予定)が、作り直し前のProjectionと一致する。
  - 既存のテスト一式(イベントを直接組み立てているテストは、`userId`を足して直す)。

## 受け入れ条件
- 有給口座・付与予定のイベントが、社員IDとは別のaggregate_uuidで記録され、payloadに`userId`を持つ。
- 一括付与が応急処置なしで動き、回帰テストがPASSする。
- 有給の履歴・残高・付与予定・監査ログの社員の絞り込みが、分離後も今と同じ結果を返す(テストで確かめる)。
- 作り直しで過去のイベントが付け替えられ、`verify`の新しい検査が通る。再生の結果が作り直し前と一致する。
- backendのテスト一式がPASSする(既知の`ReadModelFingerprintCommandTest`の失敗を除く)。e2eの`scenario-03`・
  `scenario-15`がPASSする。
- `docs/09`(UC-P011のAggregateIdの記述)・`docs/17`(`userId`の追加)・`docs/32`・`docs/27`が実装と一致する。

## 対象外
- 社員の集約(UserAggregate)のIDの変更。
- 本番での作り直しの実行そのもの(リリース時に`docs/27`の手順で利用者が行う)。
- 年度はじめ一斉付与・自動判定(`20261021-paid-leave-fiscal-mass-grant-and-auto-assessment`)、Reactorのテスト
  (`20261021-paid-leave-schedule-reactor-tests`)。

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`: UC-P011のAggregateIdの記述を、導出したストリームIDに直す。
- `docs/17-events.md`: `paid_leave_account.*`・`paid_leave_schedule.*`に`userId`を足す。
- `docs/32-stored-event-history-normalization.md`: 作り直しでの付け替えと、`verify`の検査。
- `docs/27-release-runbook.md`: このコードは作り直しと同じリリースで出すこと。
- `docs/changesets/20260906-paid-leave-schedule-assessment/spec.md`の論点14は、過去の記録なので書き換えない。
- `docs/changesets/README.md`: 一覧に追加する。

## モック・アセット
なし

## 実装対象
- `backend/app/Domain/PaidLeaveAccount/`(Aggregates・Events・Handlers・Projectors)
- `backend/app/Domain/PaidLeaveSchedule/`(Aggregates・Events・Handlers・Projectors)
- `backend/app/Domain/UserManagement/Support/`(`UserStreamIds`)
- `backend/app/Domain/EventSourcing/Rebuild/`(3つのRebuilder、`EventStoreRebuilder`、`EventStoreRebuildWriter::verify`)
- `backend/app/Http/Controllers/Api/PaidLeaveController.php`、`AuditLogController.php`、
  `backend/app/Domain/Leave/Support/LeaveHistoryQuery.php`
- `backend/tests/`(回帰・作り直し・既存の修正)
- docs: 上記

## 検証方法
- `cd backend && php artisan test`(全件)
- 作り直しの試し実行を、開発用のDB(`database/database.sqlite`)に対して行う(`leave:rebuild-event-store`の
  既定の試し実行、`--verify`)。
- e2e: `npm run test:e2e -- scenario-03-paid-leave scenario-15-paid-leave-schedule`

## レビュー履歴
初版(2026-10-21)

## 実装結果
未着手
