# バックオフィスタスクの「担当者の割当」と「ステータス変更」を別イベントに分け、担当者を変更できるようにする

ステータス: 完了

## 変更要望(原文)
> どういうことでしょうか？担当者の付け替えとステータスにどのような関係がありますか？単純に担当者を変更したいです。

(初版レビュー時)
> 既存の割り当てイベントの意味を変更してください。ステータスと割り当ては別個のイベントとしてください。
> 既存イベントのマイグレーションも検討をお願いします

## 背景・目的
`backoffice_task.assigned`は「担当者を決める」と「ステータスを確認中にする」の2つの事実を1イベントで兼ねており、
担当者を変えるとステータスまで巻き戻る。割当イベントを「担当者が設定された」だけの意味に改め、ステータスの変化は
`backoffice_task.status_changed`で記録する。過去のイベントもこの構成に作り直し、担当者を自由に変更できるようにする。

## 現状(As-Is)
- `BackOfficeTaskAggregate::assign`→`backoffice_task.assigned`(`BackOfficeTaskAssigned{assignedUserId, assignedByUserId}`)。
  `BackOfficeTaskProjector::onBackOfficeTaskAssigned`(:35-41)が`assigned_user_id`を更新し、**statusを直前の値に関係なく`in_review`に上書き**する。
- `AssignBackOfficeTaskHandler`は担当者・ステータスを確認せず、新担当者に通知する。呼び出し元は`POST /backoffice-tasks/{id}/assign`のみ
  (シーダー・Reactorからの記録は無い)。
- ステータス変更は`ChangeBackOfficeTaskStatusHandler`→`backoffice_task.status_changed{previousStatus,newStatus,changedByUserId,comment}`
  (完了は`backoffice_task.completed`)。許可遷移は申請種別の`allowed_status_transitions`で検証(`assertAllowedTransition`:55-78。
  シードでは`not_started`→`in_review`は許可)。割当による確認中への変化はこの検証を通らない。
- 「未割当のまま処理中」のタスクも作れる(状態変更フォーム・Handlerは担当者を見ない)。
- 画面: 詳細画面の割当フォームは未割当のときだけ(`BackOfficeTaskDetailPage.tsx`:183)。一覧の一括割当も未割当のみ。
  一括割当は単体APIを`Promise.all`で並列に呼ぶ。
- 作り直しの基盤: `app/Domain/EventSourcing/Rebuild/EventStoreRebuildWriter`(`readRowArrays`/`write`→`stored_events_rebuilt`/`verify`/`swap`。
  swapは元表を`stored_events_before_rebuild_YYYYMMDDHHMMSS`として残す)。単純なストリーム変換の前例は`PaidLeaveStreamRekeyer`+
  `RekeyPaidLeaveStreamsCommand`(`--apply`/`--verify`/`--swap`、既定は試し実行)と`tests/Feature/EventSourcing/PaidLeaveStreamRekeyScenarioTest.php`。
  `backoffice_task.*`は既存の作り直し(`EventStoreRebuilder`)の対象外。
- イベント一覧: `docs/17-events.md`:73-78。登録: `backend/config/event-sourcing.php`:593。

## 設計前提の検証
- 正データと派生データ: 担当者・ステータスとも`stored_events`が正で`backoffice_tasks`はProjection(設計原則1・2)。
- 定義と実態: `assigned`イベントが2つの事実(担当者・ステータス)を持ち、ステータスの事実は`status_changed`と二重の経路になっている。
  ステータスの経路を`status_changed`/`completed`に一本化するのが正しい。
- 原因の分類: **設計の誤り**(1イベントに2つの事実を持たせた)。対症療法(担当者変更専用イベントの追加)ではなく、イベントの意味を正し、
  過去のイベントも作り直す(ユーザー指示)。
- 横展開: 担当者の概念を持つタスクはバックオフィスタスクのみ。
- 一覧のページング: 影響なし。

## 仕様検討

### 論点1: イベント構成
- 選択肢:
  - A. `assigned`を「担当者が設定された」の意味に改め(初回・変更とも同じイベント)、ステータスの変化は`status_changed`で別に記録する
  - B. `assigned`は初回のみ、変更用に`reassigned`を追加(前版の案)
- 決定: A(ユーザー指示)
- 理由: 担当者とステータスの事実を分離し、ステータスの経路を1つにする。初回と変更を区別する必要は`previousAssignedUserId`で足りる。
- 新しい`BackOfficeTaskAssigned`: `assignedUserId`, `assignedByUserId`, `?previousAssignedUserId`(追加。直前の担当者。初回はnull)。
  Projectorは`assigned_user_id`だけを更新し、statusに触れない。
- 未確定・要確認事項: なし

### 論点2: 割当時に「確認中」へ進める業務ルール(UC-B002手順5)の扱い
- 選択肢:
  - A. 残す。担当者設定の操作で、ステータスが`not_started`(未着手)なら同じCommand内で`status_changed`(`not_started`→`in_review`)も記録する
    (遷移マスタで許可されている場合のみ。許可されていなければステータスは変えない)
  - B. 廃止し、確認中への変更は利用者が状態変更で行う
- 決定: A
- 理由: 現在の使い勝手(割り当てると確認中になる)を保つ。イベントは分かれるので、担当者の変更でステータスが戻ることはない。
  遷移マスタを通すことで、割当がステータス規則を迂回する問題も解消する。
- 未確定・要確認事項: なし(変更したい場合はレビューで指摘してもらう)

### 論点3: 担当者を設定・変更できる条件
- 決定:
  - 完了・取消のタスクは設定・変更できない(`DomainRuleException`→422「完了・取消済みのタスクは担当者を変更できません。」)。
  - 現在と同じ担当者を指定した場合は何もしない(イベント・通知なし、200で現在のタスクを返す)。
  - 担当者を外す(未割当に戻す)操作は提供しない。権限は既存どおり`backoffice_task.execute`。
- 理由: 完了・取消済みの担当を変える必要はない。同一指定を冪等にし、一括操作(単体APIの並列呼び出し)で一部が既にその担当者でも失敗させない。
- 未確定・要確認事項: なし

### 論点4: 通知
- 決定: 新担当者へは既存の「タスク割当」(「「{title}」が割り当てられました。」。操作者本人でも送る=既存どおり)。前の担当者がいて
  操作者本人でなければ「タスク担当変更」(「「{title}」の担当が{新担当者名}さんに変更されました。」。名前が取れなければ「別の担当者」)。
- 理由: 前の担当者が、手を離れたことを知らずに作業を続けるのを防ぐ。
- 未確定・要確認事項: なし

### 論点5: 画面
- 詳細画面: 完了・取消以外のタスクで常に担当者フォームを出す。未割当なら見出し「担当者を割り当てる」/ボタン「割り当てる」、
  割当済みなら「担当者を変更」/「変更する」。成功後はUserPickerを空に戻す。
- 一覧画面の一括操作: 「割り当てる」→「担当者を設定」。完了・取消以外の行ならどの担当者状態でも押せる(「未割り当てのタスクのみ
  割り当てられます」の制約を削除。担当者未選択のdisabled+理由は維持)。一括完了の「自分のタスクのみ」は変えない。
- 未確定・要確認事項: なし

### 論点6: 既存イベントの移行(イベント構成の作り直し)
- 選択肢:
  - A. `stored_events`の作り直し(`data-correction`の方法2): 旧`assigned`1件を、新`assigned`+必要なら`status_changed`の2件に分割して過去に挿入し、
    id・版を振り直して新しい表に書き、検証後に入れ替える
  - B. 移行イベント・補正イベントを今の時点に追記する
  - C. Projectorに旧形式の読み替え(payloadに`previousAssignedUserId`が無ければ旧形式としてin_reviewにする)を残す
- 決定: A
- 理由: `data-correction`の規定(イベントの分割は必ず作り直し。移行イベント・旧系統の読み替えは作らない)。過去の事実(誰がいつ割り当て、
  その結果ステータスが何から確認中になったか)は旧イベント列から機械的に決まるので、方法3は不要。
- 対象: `event_class`=`backoffice_task.assigned`の全件(集約=`backoffice_task`)。件数は試し実行で出す。
- 変換規則(集約ごとに`aggregate_version`順に走査し、直前の担当者・ステータスを追跡する):
  - 追跡: `created`→status=`not_started`、担当者=null。`status_changed`→status=`newStatus`。`completed`→status=`completed`。
    `assigned`→担当者=`assignedUserId`、status=`in_review`(旧Projectorの挙動の再現)。
  - 旧`assigned`1件 → 新`assigned`(`assignedUserId`・`assignedByUserId`はそのまま、`previousAssignedUserId`=直前の担当者)。
    直前のstatusが`in_review`以外なら、その直後に`status_changed`(`previousStatus`=直前のstatus、`newStatus`=`in_review`、
    `changedByUserId`=`assignedByUserId`、`comment`=null)を1件挿入する。`created_at`は元の`assigned`と同じ。
  - `meta_data`: 全行で`stored-event-id`を振り直した`id`に、`aggregate-root-version`を振り直した版に更新する。挿入する行は
    `aggregate-root-uuid`=元の`assigned`と同じ集約ID、`created-at`=元の`assigned`と同じ値、それ以外のキーは元の`assigned`の`meta_data`を写す
    (前例: `PaidLeaveStreamRekeyer.php`:116,133)。
  - 変換できない並び(`created`より前の`assigned`など、直前のstatusが決まらないもの)は未対応として報告し、1件でもあれば何も書かずに失敗する
    (`EventStoreRebuilder`の方針と同じ)。
  - 旧Projectorは直前が`completed`等でも`in_review`にしていたため、それも忠実に`status_changed`として再現する(最終状態を変えない)。
    試し実行では直前のstatus別の件数を出し、`completed`/`cancelled`からの変化があれば一覧で示す。
  - その他のイベントは変更しない。全体を元の発生順(元のid順、分割分は直後)に並べ、`stored_events.id`を振り直し、集約ごとに
    `aggregate_version`を1から連番にする。
  - 旧イベント列の`backoffice_tasks`のProjectionと、作り直し後をリビルドしたProjectionは完全に一致する(`assigned_user_id`・`status`・`completed_at`)。
- 冪等性: 判定はイベント単位。payloadに`previousAssignedUserId`キーがある`assigned`は変換済みとしてそのまま写し、キーの無い`assigned`だけ変換する。
  直前の担当者・statusの追跡は変換済みの行も含めて行う(変換済みの`assigned`はstatusを変えず、続く`status_changed`で追跡する)。
  変換対象が0件なら何も書かない。
- 実行のタイミング: 新コードの配備から`--swap`・`read-models:rebuild`の確認までを1回のメンテナンス(全APIの受付停止。`docs/27-release-runbook.md`の
  作り直しの手順と同じ)で行う。新Projectorは旧`assigned`でstatusを書かないため、作り直し前にリビルドすると状態がずれ、`--apply`後に書かれた
  イベントは`--swap`で失われるため。
- 事前条件(試し実行で自動確認。`EventStoreRebuildWriter::preconditionViolations`を流用・拡張): `stored_events_rebuilt`が存在しない、
  `snapshots`が空。違反があれば`--apply`しない。
- `stored_events.id`を参照するもの: Projection(`workflow_request_history_entries`・`expense_claim_history_entries`の`stored_event_id`、
  `paid_leave_fiscal_year_settings.last_changed_event_id`)は作り直し後の`read-models:rebuild`で直る。リビルドで直らないもの
  (補正ログ`stored_event_corrections.stored_event_id`、meta`history-normalization.source_event_id`)は過去の作業記録としてそのまま残し、
  作り直し前の表(`stored_events_before_rebuild_*`)のidを指すことを手順書に記載する。
- 他の作り直しとの関係: 休暇の作り直し(`leave:rebuild-event-store`)・有給口座の付け替え(`leave:rekey-paid-leave-streams`)と同じ`stored_events_rebuilt`を使うため、
  同じリリースで複数行う場合は片方の`--swap`が終わってから次の`--apply`を行う(順序は休暇の作り直し→付け替え→本移行)。
  古いバックアップから休暇の作り直しをやり直す場合は、`EventStoreRebuilder`が`backoffice_task.*`をそのまま写すため、その後に必ず本移行を
  かける(手順書・`docs/32`に記載)。
- 削除するもの: 旧Projectorの「statusをin_reviewにする」処理(新Projectorでは書かない)。作り直し用のコマンド・変換クラスは
  本番実行後の別変更で削除する(`data-correction`の「同じ変更セットで削除」からの例外。リハーサル・本番実行に同じリリースのコードが必要で、
  既存の休暇の作り直し・付け替えコマンドも同様に残しているため。理由は「対象外」にも記載)。
- 未確定・要確認事項: なし(本番実行のタイミング・担当は実装後に確認する)

## 仕様確定事項(まとめ)

### backend(業務ロジック)
- `BackOfficeTaskAssigned`に`?string $previousAssignedUserId = null`を追加(コンストラクタ引数の末尾)。
- `BackOfficeTaskAggregate::assign(string $assignedUserId, string $assignedByUserId, ?string $previousAssignedUserId)`に変更。
- `BackOfficeTaskProjector::onBackOfficeTaskAssigned`: `assigned_user_id`のみ更新(status更新を削除)。
- 判定クラス`App\Domain\BackOffice\Support\BackOfficeTaskAssignmentDecision`(純粋。DBに触れない):
  `decide(?string $currentAssigneeId, string $status, string $requestedAssigneeId): string`→`'noop'|'assign'`、completed/cancelledは`DomainRuleException`。
  `shouldMoveToInReview(string $status, bool $transitionAllowed): bool`→status=`not_started`かつ許可なら true。
- 遷移の許可判定: `ChangeBackOfficeTaskStatusHandler::assertAllowedTransition`の判定部分を、例外を投げない判定
  (`App\Domain\BackOffice\Support\BackOfficeTaskTransitionPolicy::isAllowed(BackOfficeTask $task, string $newStatus): bool`)に切り出し、
  ChangeStatus(許可されなければ従来どおり例外)とAssign(許可されなければ確認中にしない)の両方で使う。
- `decide`の判定順: 1) completed/cancelled→例外(同じ担当者の指定でも422) 2) 同じ担当者→noop 3) assign。
- `AssignBackOfficeTaskHandler`: Projectionの現在値を`with('source')`で読み(`ChangeBackOfficeTaskStatusHandler`:34と同じ)、`decide`→noopなら終了。assignなら同一トランザクション(CommandBusの既存の
  トランザクション境界に従う)で `assign(新, 操作者, 現在の担当者)` を記録し、`shouldMoveToInReview`が真なら
  `changeStatus('not_started', 'in_review', 操作者, null)`も同じ集約に記録して`persist()`。通知は論点4。
- `docs`の表示名: バックオフィスタスクには履歴画面が無く、表示名ガード(`20261122-history-event-label-guard`)の対象外のため表示名の追加は不要。

### backend(移行)
- 変換クラス`App\Domain\EventSourcing\Rebuild\BackOfficeTaskAssignmentSplitter`(純粋。`PaidLeaveStreamRekeyer`を前例に):
  入力は`EventStoreRebuildWriter::readRowArrays()`の行配列、出力は作り直し後の行配列+レポート(対象件数、挿入した`status_changed`件数、
  直前status別件数、作り直し済みでスキップしたストリーム数)。論点6の変換規則を実装。
- 運用コマンド`backoffice:rebuild-assignment-events`(`RekeyPaidLeaveStreamsCommand`を前例に): 既定は試し実行(レポート出力のみ)、
  `--apply`(`stored_events_rebuilt`へ書く)、`--verify`、`--swap`(検査通過時のみ入れ替え。元表は
  `stored_events_before_rebuild_YYYYMMDDHHMMSS`)。
- 専用の検証クラス`BackOfficeTaskAssignmentRebuildVerifier`(前例: `PaidLeaveStreamRekeyVerifier`): `stored_events_rebuilt`について
  (1) `EventStoreRebuildWriter::verify`(id・版の連番)、(2) キーの無い旧形式の`assigned`が0件、(3) `backoffice_task`以外の行がidと
  `stored-event-id`を除いて元の表と一致、(4) `EventRestorabilityChecker`が通る、を検査。`--verify`と`--swap`の前に実行する。
- 検証手順(手順書として変更セットの実装結果に記載): 旧コードで`backoffice_tasks`の基準値(id・assigned_user_id・status・completed_at)を取得 →
  新コード配備 → 試し実行 → `--apply` → `--verify` → `--swap` → `read-models:rebuild --dry-run`で`backoffice_tasks`に差分が無いことを確認。

### テスト(domain-test)
- 単体: `BackOfficeTaskAssignmentDecision`(未割当+not_started→assign、割当済み+別担当者→assign、同一→noop、completed/cancelled→例外)、
  `shouldMoveToInReview`(not_started+許可→true、not_started+不許可→false、processing→false)、`BackOfficeTaskTransitionPolicy`。
- 単体: `BackOfficeTaskAssignmentSplitter`(created→assigned:status_changed挿入・previous=null / in_review中の再割当:status_changed無し・
  previous=前担当者 / processing中の割当:processing→in_reviewのstatus_changed挿入(旧挙動の再現)/ completed後の割当 / 他のイベントは不変 /
  id・版・`meta_data`(`stored-event-id`・`aggregate-root-version`・挿入行の`aggregate-root-uuid`・`created-at`)の振り直し /
  変換済みと旧形式が同じストリームに混在するケース(旧形式だけ変換) / 2回目の実行で対象0件 / `created`より前の`assigned`は失敗)。
- 単体: `decide`で完了済み+同じ担当者→例外。Verifier(旧形式の残存・対象外の行の改変を検出)。
- Feature: API`POST /assign` — 未割当+not_startedを割当→assigned(previous=null)とstatus_changed(not_started→in_review)の2イベント・status=in_review /
  processingのタスクを別担当者へ→assignedのみ・statusはprocessingのまま・previous=前担当者 / 未割当+processingに割当→statusは変わらない /
  遷移マスタで`not_started`→`in_review`が不許可の申請種別→担当者だけ設定されstatusはnot_started / 同一担当者→イベントが増えない /
  完了済み→422 / 通知(新担当者・前担当者。前担当者=操作者なら前担当者へは送らない)。状態はCommand経由で作る。
- シナリオ(replayまで): 旧形式の`assigned`(payloadに`previousAssignedUserId`無し)を含む生の`stored_events`行を入力に、試し実行→`--apply`→
  `--verify`→`--swap`→ReadModelを空にして全Projectorをreplay→`backoffice_tasks`が移行前(旧Projectorの結果を期待値として固定)と一致、
  かつ`status_changed`が挿入され`aggregate_version`が連番であること。他の文脈のイベント(例: 申請の履歴を持つ`workflow_request.*`)も含め、
  replay後の`*_history_entries.stored_event_id`が衝突しないこと(前例: `PaidLeaveStreamRekeyScenarioTest.php`)。
- 既存の`BackOfficeTaskTest`・`BackOfficeTaskStatusTransitionTest`はそのまま通ること(割当後statusがin_reviewになる期待は維持される)。

### frontend
- `BackOfficeTaskDetailPage.tsx`: 担当者フォームの表示条件を「完了・取消以外」に変更し、文言を未割当/割当済みで出し分け。
- `BackOfficeTaskListPage.tsx`: 一括「割り当てる」→「担当者を設定」、未割当のみの制約と理由文言を削除。
- テスト・story: 詳細(割当済みでも変更フォームが出る/完了・取消では出ない/文言の出し分け)、一覧(割当済みを含む選択でも担当者を設定できる)。
  変更する文言を参照するテスト・story・e2eを`grep`で探して直す(e2eの詳細画面「割り当てる」は未割当のタスクなので変わらない)。

## 受け入れ条件
- 割当済みで処理中のタスクの担当者を、詳細画面・一覧の一括操作から別の人に変更でき、ステータスは変わらない。
- 未着手のタスクへの割当は従来どおり確認中になり、`stored_events`には`assigned`と`status_changed`の2件が記録される。
- 完了・取消のタスクは担当者を変更できない(フォームが出ず、APIは422)。新旧の担当者に通知が届く。
- 移行コマンドで旧形式のイベント列を作り直すと、`assigned`イベントがステータスを持たない新構成になり、リビルド後の`backoffice_tasks`が移行前と一致する
  (シナリオテストで確認)。
- 単体・Feature・シナリオテスト、Vitest、型チェック・lintがPASSする。

## 対象外
- 担当者を外して未割当に戻す操作、担当部署の変更
- 一括の担当者設定を1トランザクションで行う一括API(途中失敗で一部だけ変わる点は現状どおり)
- 未割当のタスクのステータス変更を禁止すること
- 本番環境での移行の実行(実装後、試し実行の結果を示してユーザーの許可を得てから行う)
- 移行コマンド・変換クラスの削除(本番実行後の別変更。`data-correction`の規定からの例外で、リハーサル・本番実行に同じリリースのコードが必要なため)

## ドキュメントへの影響
- `docs/17-events.md`: `backoffice_task.assigned`の意味(担当者の設定のみ。`previousAssignedUserId`)を明記。
- `docs/11-usecases-backoffice.md`: UC-B002手順3・5(未着手なら確認中へ。`status_changed`として記録、遷移マスタに従う)、担当者の変更
  (完了/取消以外、ステータスは変えない、新旧担当者へ通知)を追記し、「担当者の付け替えは提供しない」を削除、一括操作の条件を更新。
- `docs/13-usecases-notification.md`:21付近: 「タスク担当変更(前担当者へ)」を追記。
- `docs/27-release-runbook.md`: 本移行の手順(メンテナンス中に配備→試し実行→apply→verify→swap→read-models:rebuild、他の作り直しとの順序)を追記。
- `docs/32-stored-event-history-normalization.md`: 古いバックアップから休暇の作り直しをやり直す場合は、その後に本移行をかける旨を追記。
- `docs/16-database-schema.md`: 変更なし。

## モック・アセット
なし

## 実装対象
- backend: `app/Domain/BackOffice/{Events,Aggregates,Projectors,Handlers,Support}`、`app/Domain/EventSourcing/Rebuild/BackOfficeTaskAssignmentSplitter.php`、
  `app/Console/Commands/`(移行コマンド)、`tests/Unit`・`tests/Feature`
- frontend: `pages/backOffice/BackOfficeTaskDetailPage.{tsx,test.tsx,stories.tsx}`、`BackOfficeTaskListPage.{tsx,test.tsx,stories.tsx}`
- docs: 上記

## 検証方法
- `cd backend && ./vendor/bin/phpunit --filter='BackOffice|BackOfficeTaskAssignmentSplitter'`、CI同条件の全件`php artisan test`
- `cd frontend && npx vitest run --project unit src/pages/backOffice`、`npx tsc -p tsconfig.app.json --noEmit`、`npx oxlint src/pages/backOffice`

## レビュー履歴
- 初版(2026-11-22)
- 2026-11-22 独立設計レビュー(general-purpose)の指摘を反映: 未割当でも処理中のタスクがあり得るため論点1-2を追加し、
  初回割当(確認中にする)は「未割当かつ未着手」のみに限定(`previousAssignedUserId`はnull可)/例外は`DomainRuleException`に統一/
  一括は単体APIの並列呼び出しで途中失敗は対象外と明記/通知の自分宛て・名前不明時の扱い、docs/13への追記を確定/表示名ガード対象外を明記/
  リビルド検証の手順/docs/11の更新範囲を具体化。
- 2026-11-22 ユーザーレビュー「既存の割り当てイベントの意味を変更し、ステータスと割り当ては別個のイベントに。既存イベントのマイグレーションも検討」
  を受け、前提(既存の`assigned`の意味は正しい)が覆ったため「設計前提の検証」から作り直した。`reassigned`イベント案は破棄し、`assigned`を
  担当者の設定のみに改め、確認中への変化は`status_changed`で記録、過去のイベントは`stored_events`の作り直しで移行する。
- 2026-11-22 作り直し版の独立設計レビュー(general-purpose)の指摘を反映: 配備〜swapを1回のメンテナンスで行う/冪等判定をイベント単位に/
  meta_data(stored-event-id・版・挿入行)の決め方/事前条件とstored_event id参照列の扱い/専用Verifier/他の作り直しとの順序・docs/27・32への追記/
  移行コマンドを残す理由(規定からの例外)/変換できない並びは失敗/判定順(完了・取消を先に)と`with('source')`。

## 実装結果
- コミット: `fe05ad7`(backend 業務ロジック)、`5ea7352`(backend 移行コマンド)、`05147f1`(frontend)、`87c3803`・`c23a102`(docs)。
- backend(業務ロジック): `BackOfficeTaskAssigned`に`previousAssignedUserId`、`BackOfficeTaskAggregate::assign`、Projectorは`assigned_user_id`のみ更新、
  `Support/BackOfficeTaskAssignmentDecision`・`Support/BackOfficeTaskTransitionPolicy`(新規)、`AssignBackOfficeTaskHandler`(未着手かつ許可なら
  同じ集約に`status_changed`を記録、前担当者への「タスク担当変更」通知)、`ChangeBackOfficeTaskStatusHandler`(判定をPolicyに委譲。挙動・文言は不変)。
- backend(移行): `Rebuild/BackOfficeTaskAssignmentSplitter`・`SplitResult`・`RebuildVerifier`、`backoffice:rebuild-assignment-events {--apply} {--verify} {--swap}`。
  既存の作り直しクラスは変更なし。事前条件は休暇固有の条件が混ざらないよう`stored_events_rebuilt`が無い・`snapshots`が空の2つをコマンド内で判定。
- frontend: 詳細画面は完了・取消以外で担当者フォーム(未割当「担当者を割り当てる」/割当済み「担当者を変更」)、一覧の一括は「担当者を設定」で担当者の有無を問わない。
- docs: `docs/17-events.md`・`docs/11-usecases-backoffice.md`・`docs/13-usecases-notification.md`・`docs/27-release-runbook.md`(3.6)・
  `docs/32-stored-event-history-normalization.md`(「ドキュメントへの影響」の全件を更新済み)。
- 検証結果:
  - 委譲元で再実行: `./vendor/bin/phpunit --filter='BackOfficeTaskAssignment|BackOfficeAssignmentEventRebuild|BackOfficeTask'` 53件pass。
    `npx vitest run --project unit src/pages/backOffice` 39件pass、`npx tsc -p tsconfig.app.json --noEmit` OK。
  - implementer実行: CI同条件の`php artisan test`全件 2331件pass(移行コマンド追加後)。
  - 移行の試し実行の出力例(シナリオ入力): 旧形式assigned 3件、挿入するstatus_changed 3件、直前status not_started 2/processing 1。
  - Storybookのplay・e2eは未実行。
- 受け入れ条件: 担当者変更でstatus不変・未着手の割当でassigned+status_changedの2件・完了/取消は422・新旧担当者への通知はFeatureテスト、
  画面の変更フォーム・一括の担当者設定はVitest、移行で旧形式が新構成になりリビルド後の`backoffice_tasks`が移行前と一致することはシナリオテストで確認。
- 未実施: 本番での移行の実行(試し実行の結果を示し、許可を得てから`docs/27` 3.6の手順で行う)。
