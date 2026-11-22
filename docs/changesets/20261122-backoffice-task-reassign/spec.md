# バックオフィスタスクの担当者を変更(付け替え)できるようにする

ステータス: レビュー中

## 変更要望(原文)
> どういうことでしょうか？担当者の付け替えとステータスにどのような関係がありますか？単純に担当者を変更したいです。

(前の変更セット`20261122-backoffice-others-tasks`で「担当者の付け替え」を対象外にしたことへの回答)

## 背景・目的
割当済みのバックオフィスタスクの担当者を、処理ステータスを変えずに別の人へ変更できるようにする
(担当者の不在・異動時の引き継ぎ)。

## 現状(As-Is)
- 割当はUC-B002(`docs/11-usecases-backoffice.md`)。手順5「ステータスを確認中にする」により、割当は「担当者を決める」と
  「確認中にする」を同時に行う操作として作られている。
  - `BackOfficeTaskAggregate::assign`→イベント`backoffice_task.assigned`(`BackOfficeTaskAssigned{assignedUserId, assignedByUserId}`)。
  - `BackOfficeTaskProjector::onBackOfficeTaskAssigned`(:35-41)が`assigned_user_id`を更新し、**statusを常に`in_review`に上書き**する。
  - `AssignBackOfficeTaskHandler`は担当者の有無を確認せず、新担当者にだけ通知する。
- このため、処理中(`processing`・`payment_scheduled`等)のタスクを同じ割当操作で別の人に変えると、ステータスが「確認中」に戻る。
  ステータス遷移の規則(`ChangeBackOfficeTaskStatus`で検証)も迂回してしまう。
- 画面: 詳細画面`BackOfficeTaskDetailPage.tsx`:183の割当フォームは未割当のときだけ表示。一覧画面の一括割当も未割当のみ
  (前の変更セットで決定)。API`POST /backoffice-tasks/{id}/assign`自体は割当済みでも受け付ける(上記の巻き戻りが起きる)。
- 詳細画面の「状態を変更する」フォームと`ChangeBackOfficeTaskStatusHandler`は担当者の有無を見ないため、「未割当のまま処理中」の
  タスクも作れる。この状態で割当すると、同じくステータスが確認中に戻る。
- 一覧の一括割当は一括APIではなく、単体APIを`Promise.all`で並列に呼ぶ(`BackOfficeTaskListPage.tsx`)。1件でも失敗すると一部だけ
  変わった状態が残り得る(エラーは表示される)。
- 業務ルール違反は`DomainRuleException`(`bootstrap/app.php`で422の`{message}`)で返すのが慣例。
- イベント一覧: `docs/17-events.md`:73-78。登録: `backend/config/event-sourcing.php`:593。

## 設計前提の検証
- 正データと派生データ: 担当者・ステータスとも`stored_events`が正で、`backoffice_tasks`はProjection。担当者の変更も
  Command→Event→Projectorで記録する(設計原則1・2)。
- 定義と実態: `backoffice_task.assigned`は「初回割当(=確認中へ進める)」の意味で使われている。担当者の変更は別の事実であり、
  同じイベントで表すとProjectorでステータスの扱いを分岐させる必要が生じる。
- 原因の分類: 機能の欠落(担当者変更の操作が無い)。既存の割当イベントの意味(初回割当で確認中へ)は正しいので変えない。
- 横展開: 担当者の概念を持つタスクはバックオフィスタスクのみ。
- 一覧のページング: 一覧の変更は一括操作の可否のみで、ページングに影響なし。

## 仕様検討

### 論点1: 担当者変更の記録方法
- 選択肢:
  - A. 新しいイベント`backoffice_task.reassigned`(`BackOfficeTaskReassigned{previousAssignedUserId, assignedUserId, reassignedByUserId}`)を
    追加し、Projectorは`assigned_user_id`だけを更新する(statusは変えない)
  - B. 既存の`assigned`イベントを使い、Projectorで「未着手のときだけ確認中にする」と分岐させる
- 決定: A
- 理由: 「初回の割当」と「担当者の変更」は別の業務上の事実で、履歴にも区別して残せる。Bは過去の`assigned`イベントの再生結果が
  変わり得る(リビルドで同じ結果になる保証が崩れる)うえ、Projectorに業務判断が入る。
- 未確定・要確認事項: なし

### 論点1-2: 「確認中にする」初回割当と、ステータスを変えない記録の境目
- 選択肢:
  - A. 担当者が未割当なら常に従来の`assigned`(確認中にする)
  - B. 「未割当かつ`not_started`(未着手)」のときだけ従来の`assigned`。それ以外(割当済み、または未割当でも未着手以外)は
    ステータスを変えない`reassigned`(`previousAssignedUserId`はnull可)
- 決定: B
- 理由: Aでは「未割当のまま処理中」のタスクに担当者を付けると確認中へ巻き戻る問題が残る(設計レビューで判明)。
  「確認中にする」は未着手のタスクを着手させる意味なので、未着手のときだけ行う。
- 未確定・要確認事項: なし

### 論点2: 操作の入口(API)
- 選択肢:
  - A. 既存の`POST /backoffice-tasks/{id}/assign`をそのまま使い、Handlerが現在の担当者の有無で「割当」と「変更」を出し分ける
  - B. 担当者変更専用のAPI(`/reassign`)を新設する
- 決定: A
- 理由: 利用者から見れば「担当者を設定する」1つの操作。一覧の一括操作で未割当と割当済みが混在しても同じAPIで扱える。
  出し分けはHandler(業務ロジック側)で行い、画面・APIに判断を持たせない(設計原則9)。
- 未確定・要確認事項: なし

### 論点3: 変更できる条件
- 決定:
  - 完了・取消のタスクは担当者を設定・変更できない(`DomainRuleException`→422「完了・取消済みのタスクは担当者を変更できません。」)。
  - 現在と同じ担当者を指定した場合は何もしない(イベントを記録せず、通知もしない。200で現在のタスクを返す)。
  - 担当者を外す(未割当に戻す)操作は提供しない。
  - 権限は既存の割当と同じ(`backoffice_task.execute`)。
- 理由: 完了・取消済みの担当を変える業務上の必要がない。同じ担当者の指定をエラーにしないことで、一括操作(単体APIの並列呼び出し)で
  一部が既にその担当者でもその分がエラーにならない(冪等)。一括の途中失敗で一部だけ変わる点は現状どおり(対象外)。
- 未確定・要確認事項: なし

### 論点4: 通知
- 選択肢: A. 新担当者にだけ通知 / B. 新担当者と前の担当者の両方に通知
- 決定: B。新担当者へは既存と同じ「タスク割当」(「「{title}」が割り当てられました。」。既存どおり操作者本人でも送る)、
  前の担当者へは「タスク担当変更」(「「{title}」の担当が{新担当者名}さんに変更されました。」)。前の担当者が操作者本人のとき・
  前の担当者がいない(未割当からの`reassigned`)ときは送らない。新担当者名が取れない場合は「別の担当者」とする。
- 理由: 前の担当者が、自分の手を離れたことを知らないまま作業を続けるのを防ぐ。
- 未確定・要確認事項: なし

### 論点5: 画面
- 詳細画面: 完了・取消以外のタスクで常に担当者フォームを出す。見出し・ボタンは未割当なら「担当者を割り当てる」/「割り当てる」、
  割当済みなら「担当者を変更」/「変更する」。UserPickerの初期値は空。
- 一覧画面の一括操作: 「割り当てる」ボタンを「担当者を設定」に改め、完了・取消以外の行(選択可能な行)ならどの担当者状態でも押せる
  (「未割り当てのタスクのみ割り当てられます」の制約を削除。担当者未選択ならdisabled+「担当者を選択してください」は維持)。
  一括完了の「自分のタスクのみ」は変えない。
- 理由: 「単純に担当者を変更したい」に応える。未割当・割当済みとも同じ操作でよい。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

### backend
- イベント`App\Domain\BackOffice\Events\BackOfficeTaskReassigned`(`?string previousAssignedUserId`, `string assignedUserId`, `string reassignedByUserId`)を追加し、
  `config/event-sourcing.php`に`'backoffice_task.reassigned'`で登録。
- `BackOfficeTaskAggregate::reassign(?string $previousAssignedUserId, string $assignedUserId, string $reassignedByUserId)`を追加。
- `BackOfficeTaskProjector::onBackOfficeTaskReassigned`: `assigned_user_id`のみ更新(statusは変えない)。
- `AssignBackOfficeTaskHandler`(Commandは既存`AssignBackOfficeTask`のまま):
  1. Projectionの現在値(`assigned_user_id`・`status`)を読み、判定する。
  2. completed/cancelled → `DomainRuleException`(上記文言)。
  3. 同じ担当者 → 何もしない(noop)。
  4. 未割当かつ`not_started` → 既存どおり`assign()`(確認中になる)、新担当者に「タスク割当」通知。
  5. それ以外(割当済みで別の担当者、または未割当で未着手以外) → `reassign(現在の担当者(null可), 新担当者, 操作者)`、
     新担当者に「タスク割当」、前の担当者(非nullかつ操作者以外)に「タスク担当変更」通知。
  判定は、テストで直接呼べる純粋な判定クラス`App\Domain\BackOffice\Support\BackOfficeTaskAssignmentDecision::decide(?string $currentAssigneeId, string $status, string $requestedAssigneeId): string`
  ('assign'|'reassign'|'noop'、完了・取消は`DomainRuleException`)に置く。
- 表示名: バックオフィスタスクには履歴画面が無く、表示名ガード(`20261122-history-event-label-guard`)の対象外のため、表示名の追加は不要。
- テスト(domain-test): 判定の単体テスト(未割当+not_started→assign、未割当+processing→reassign、割当済み+別担当者→reassign、
  同一→noop、completed/cancelled→例外)と、
  APIのFeatureテスト(未割当を割当→status=in_review・assignedイベント/`processing`のタスクを別担当者へ→statusはprocessingのまま・
  reassignedイベント・assigned_user_id更新/同一担当者→イベントが増えない/完了済み→422/未割当+processingに割当→statusはprocessingのまま・reassignedイベント/
  通知(新担当者・前担当者。前担当者=操作者なら前担当者へは送らない)/`stored_events.event_class`が`backoffice_task.reassigned`/
  リビルド: `backoffice_tasks`を空にして`BackOfficeTaskProjector`を再生(`projections:rebuild`相当)した後、`assigned_user_id`・statusが一致する)。
  状態はCommand経由で作る。

### frontend
- `BackOfficeTaskDetailPage.tsx`: 担当者フォームの表示条件を「未割当」から「完了・取消以外」に変更し、見出し・ボタン文言を未割当/割当済みで出し分け。
  成功後はUserPickerを空に戻す。
- `BackOfficeTaskListPage.tsx`: 一括の「割り当てる」→「担当者を設定」、未割当のみの制約と理由文言を削除。
- テスト・story: 詳細画面(割当済みでも変更フォームが出る/完了済みでは出ない/文言の出し分け)、一覧(割当済みを含む選択でも担当者を設定できる)。
- e2e: 詳細画面の「割り当てる」ボタンを使う箇所(`scenario-04`/`05`/`09`)は未割当のタスクなので文言は変わらない。一覧の一括ボタン名を
  参照しているe2eがあれば追随する(`grep`で確認)。

## 受け入れ条件
- 割当済みで処理中のタスクの担当者を、詳細画面・一覧の一括操作から別の人に変更でき、ステータスは変わらない。
- 未割当のタスクへの割当は従来どおり確認中になる。
- 完了・取消のタスクは担当者を変更できない(フォームが出ず、APIは422)。
- 新旧の担当者に通知が届く。
- `stored_events`に`backoffice_task.reassigned`が記録され、Projectorのリビルドで同じ結果になる。
- 判定の単体テスト・FeatureテストとVitestがPASSする。

## 対象外
- 一括の担当者設定を1トランザクションで行う一括API(途中失敗で一部だけ変わる点は現状どおり)
- 未割当のタスクのステータス変更を禁止すること
- 担当者を外して未割当に戻す操作
- 過去に`assign`APIで割当済みタスクを付け替え、ステータスが確認中に戻ったデータの補正(画面からは付け替えできなかったため発生していない想定。
  必要なら`data-correction`で別途扱う)
- 担当部署の変更

## ドキュメントへの影響
- `docs/17-events.md`: BackOfficeに`backoffice_task.reassigned`を追加。
- `docs/11-usecases-backoffice.md`: UC-B002手順3(未割当のみの選択)・手順5(確認中にするのは未着手のタスクへの初回割当のみ)を修正し、
  担当者の変更(完了/取消以外、ステータスは変えない、新旧担当者へ通知)を追記。「担当者の付け替えは提供しない」の削除、一括操作の条件の更新。
- `docs/13-usecases-notification.md`:21付近: 「タスク担当変更(前担当者へ)」を追記。
- `docs/16-database-schema.md`: 変更なし。

## モック・アセット
なし

## 実装対象
- backend: `app/Domain/BackOffice/{Events,Aggregates,Projectors,Handlers}`、判定クラス、`config/event-sourcing.php`、`tests/`
- frontend: `pages/backOffice/BackOfficeTaskDetailPage.{tsx,test.tsx,stories.tsx}`、`BackOfficeTaskListPage.{tsx,test.tsx,stories.tsx}`
- docs: 上記

## 検証方法
- `cd backend && ./vendor/bin/phpunit --filter=BackOffice`、CI同条件の全件`php artisan test`
- `cd frontend && npx vitest run --project unit src/pages/backOffice`、`npx tsc -p tsconfig.app.json --noEmit`、`npx oxlint src/pages/backOffice`

## レビュー履歴
- 初版(2026-11-22)
- 2026-11-22 独立設計レビュー(general-purpose)の指摘を反映: 未割当でも処理中のタスクがあり得るため論点1-2を追加し、
  初回割当(確認中にする)は「未割当かつ未着手」のみに限定(`previousAssignedUserId`はnull可)/例外は`DomainRuleException`に統一/
  一括は単体APIの並列呼び出しで途中失敗は対象外と明記/通知の自分宛て・名前不明時の扱い、docs/13への追記を確定/表示名ガード対象外を明記/
  リビルド検証の手順/docs/11の更新範囲を具体化。

## 実装結果
未着手
