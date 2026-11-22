# バックオフィスタスク一覧を汎用検索画面にし、他の担当者のタスクも検索できるようにする

ステータス: 検討中

## 変更要望(原文)
> バックオフィスタスクで自分以外の人に割り当てたタスクが全くみられなくなります。
> 検索はできるように変更をお願いします。

(初版レビュー時の追加要望)
> 微妙な気がします。汎用的な検索画面として設計しつつ現在のような使い勝手を維持できますか？

(AskUserQuestionでの回答: 画面構成=プリセットタブ、既定のステータス=未完了のみ)

## 背景・目的
一覧画面には「未割り当て」「自分のタスク」の2つの固定一覧しかなく、他の担当者に割り当てたタスクは
一覧・検索のどちらからも辿れない。固定一覧を足すのではなく、担当者・ステータスを条件に持つ汎用の
検索画面1つに作り替え、既存の2つの一覧は「条件のプリセット(タブ)」として同じ使い勝手で残す。

## 現状(As-Is)
- API: `backend/app/Http/Controllers/Api/BackOfficeTaskController.php`
  - `indexUnassigned`(`GET /backoffice-tasks/unassigned`、`assigned_user_id IS NULL`)
  - `indexMine`(`GET /backoffice-tasks/mine`、`assigned_user_id = 自分`)
  - 他人に割り当てたタスクを返す一覧APIは無い(原因)。詳細`show`は担当者を問わず取得できる。
  - 検索は`applyListSearch`(タイトル・種別・担当部署・担当者名のLIKE)、`per_page`既定20、`latest()`順。完了・取消も含めて返す。
  - `bulkComplete`は自分のタスクのみ受け付ける(1件でも他人のタスクがあれば422)。
- ルート: `backend/routes/api.php`:258-265(`feature:backoffice.tasks` + `permission:backoffice_task.execute,any`)。
- 画面: `frontend/src/pages/backOffice/BackOfficeTaskListPage.tsx`(検索Card+未割り当てCard(一括割当)+自分のタスクCard(一括完了))。
  URLは`q`・`unassignedPage`・`minePage`。
- クライアント: `frontend/src/api/backOfficeTasks.ts`、`frontend/src/hooks/useBackOfficeTasks.ts`(キー`UNASSIGNED_KEY`/`MINE_KEY`)。
- 他の利用箇所: `frontend/src/pages/home/HomeDashboardPage.tsx`:102,109(`useMyBackOfficeTasks({})`の1ページ目(20件)から
  未完了件数をクライアント側で数えている。21件以上あると件数が過少になる既存の不具合)。
- 詳細画面`BackOfficeTaskDetailPage.tsx`は担当者の割当・付け替えが可能(ドメイン上も再割当に制約はない。
  `BackOfficeTaskAggregate::assign`)。
- ユースケース: `docs/11-usecases-backoffice.md` UC-B002/UC-B003。テスト記述: `docs/testing/scenario-tests.md`:178。
- テーブル: `backoffice_tasks`(Projection)。

## 設計前提の検証
- 正データと派生データ: 参照(検索)の変更が中心。`backoffice_tasks`はProjectionで、読み取りの条件を増やすだけ。
  割当・完了の書き込みは既存Command(`AssignBackOfficeTask`/`ChangeBackOfficeTaskStatus`)をそのまま使う。
- 定義と実態: `assigned_user_id`は「現在の担当者」、`status`は処理ステータスで一貫している。ずれなし。
- 原因の分類: 機能の欠落(一覧が固定の2種類のみ)。設計原則上の誤りはないが、固定一覧を増やす対症療法ではなく、
  条件で絞れる検索1本に統一する(ユーザー要望)。
- 横展開: 同種の「担当割当型タスク一覧」は本画面のみ。ダッシュボードの件数は同じAPIを使うため併せて移行する。
- 一覧のページング: 件数は増え得る(完了済みが蓄積する)ため、API側でページングする(既存どおり)。

## 仕様検討

### 論点1: 画面構成
- 選択肢:
  - A. 一覧1つ+担当者のプリセットタブ(未割り当て/自分のタスク/他の担当者/すべて、件数付き)
  - B. 条件なしのときは今の2一覧を並べ、条件ありで検索結果1つに切り替える
- 決定: A(ユーザー回答)
- 理由: 画面の状態が1種類で単純。タブ1クリックで今と同じ一覧が出るため使い勝手を保てる。
- 未確定・要確認事項: なし

### 論点2: 既定の絞り込み
- 選択肢: A. ステータス「未完了」(完了・取消を除く)を既定 / B. すべて(現行と同じ)
- 決定: A(ユーザー回答)。既定のタブは「未割り当て」(現画面で先頭にある一覧)。
- 理由: 他の担当者の完了済みタスクに埋もれない。完了済みは「すべて」や個別ステータスに切り替えれば検索できる。
- 未確定・要確認事項: なし

### 論点3: API
- 選択肢:
  - A. 汎用の`GET /backoffice-tasks`(条件パラメータ付き)を新設し、`/unassigned`・`/mine`は削除して利用箇所を移行
  - B. 汎用APIを新設し、既存2本も残す
- 決定: A
- 理由: 一覧の取得経路を1本にし、条件の解釈がずれないようにする。既存2本の利用箇所はfrontend(本画面と
  ダッシュボード)とbackendのテストのみで、mcp/からは使われていない。
- 未確定・要確認事項: なし

### 論点4: タブの件数の取り方
- 選択肢:
  - A. 一覧のレスポンスの`meta.assignee_counts`に、同じ検索語・ステータス条件での担当者区分ごとの件数を含める
  - B. 件数専用API(`/backoffice-tasks/counts`)を別に用意
  - C. タブごとに一覧APIを4回呼ぶ
- 決定: A
- 理由: 1往復で済み、件数と一覧の条件が必ず一致する。Cは無駄な取得が多い。
- 未確定・要確認事項: なし

### 論点5: 一括操作
- 選択肢:
  - A. どのタブでも同じ選択バー。完了・取消以外の行を選択でき、「担当者を割り当てる」(付け替えを含む)と
    「選択したタスクを完了」を出す。完了は選択がすべて自分のタスクのときのみ押せ、そうでなければdisabled+理由表示
  - B. タブごとに操作を固定(未割り当て=割当のみ、自分=完了のみ、他=操作なし)
- 決定: A
- 理由: 汎用画面として、条件(タブ)ではなく選んだタスクの性質で操作可否を決める(OOUI §2.24)。「すべて」タブでも
  一貫して動く。割当の付け替えは詳細画面で既に可能でドメイン上の制約もないため権限は広がらない。
  一括完了APIの「自分のタスクのみ」の制約は維持し、画面側でも押せない理由を示す(§2.14)。
- 未確定・要確認事項: なし

### 論点6: 絞り込み条件の範囲
- 選択肢: A. キーワード・担当者区分・ステータスのみ / B. 種別・担当部署・期限などのフィルタも追加
- 決定: A
- 理由: 種別・担当部署・担当者名はキーワードで検索できる。種別の選択肢を出すには種別一覧の取得が別途必要になり
  要望の範囲を超える。APIは条件パラメータを足せる形にしておき、追加は将来対応とする。
- 未確定・要確認事項: なし

### 論点7: ダッシュボードの件数
- 決定: `HomeDashboardPage`は汎用APIを`assignee=me&status=open&per_page=1`で呼び、`meta.total`を件数として使う。
- 理由: 削除する`/mine`の移行が必要。あわせて21件以上で件数が過少になる既存の不具合も解消する。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

### backend
- `GET /backoffice-tasks`(`BackOfficeTaskController::index`)を追加。既存ルートグループ内、`/backoffice-tasks/{backOfficeTask}`より前に登録。
  OpenAPI属性は既存と同形式(operationId `backofficeTasks.index`、summary「バックオフィスタスクを検索する」、各クエリパラメータを記載)。
- クエリパラメータ(すべて任意):
  - `search`: 文字列(最大100)。既存`applyListSearch`と同じ対象。
  - `assignee`: `unassigned` | `me` | `others` | `all`。省略時は`all`。
    `unassigned`=`assigned_user_id IS NULL`、`me`=自分、`others`=`assigned_user_id IS NOT NULL AND <> 自分`。それ以外の値は422。
  - `status`: `open` | `all` | 各ステータス値(`BackOfficeTaskStatus`の定数)。省略時は`all`(APIの既定は絞り込まない。画面側で`open`を既定として送る)。
    `open`=`completed`・`cancelled`以外。それ以外の値は422。
  - `page`(1以上)、`per_page`(1〜100、既定20)。
- 並び順: 既存どおり`latest()`(作成日時の新しい順)。
- レスポンス: `BackOfficeTaskResource::collection`のページング形式に、`meta.assignee_counts`
  (`{unassigned, me, others, all}`の整数。`search`・`status`を適用し、`assignee`は適用しない件数)を追加する。
- 絞り込みの組み立て(担当者区分・ステータス)は、テストで直接呼べるクエリ用のメソッド(例: `BackOfficeTask`のローカルスコープ
  `scopeAssigneeScope(Builder, string $assignee, string $userId)`・`scopeStatusFilter(Builder, string $status)`)に置き、
  一覧と件数の両方で使う。
- `indexUnassigned`/`indexMine`とそのルートを削除する。`bulkComplete`・`show`・`assign`・`changeStatus`は変更しない。
- Featureテスト(`backend/tests/Feature/BackOffice/`。既存の`/unassigned`・`/mine`のテストは新APIへ書き換える):
  - 未割当・自分・他人のタスク(完了・取消を含む)が混在するとき、`assignee`の4値それぞれで期待どおりの集合だけが返る
    (`others`に未割当・自分が含まれないことを明示)。
  - `status=open`で完了・取消が除外される/個別ステータスで絞れる/省略時は全ステータス。
  - `search`(担当者名を含む)と`assignee`・`status`を組み合わせられる。
  - `meta.assignee_counts`が`search`・`status`を反映し、`assignee`に依存しない。
  - 不正な`assignee`・`status`で422。権限なしで403。ページングの`meta`。

### frontend
- api: `fetchBackOfficeTasks(options: { search?, assignee?, status?, page?, perPage? })`を追加し、
  `fetchUnassignedTasks`/`fetchMyTasks`を削除。型`BackOfficeTaskAssigneeScope = 'unassigned'|'me'|'others'|'all'`、
  `BackOfficeTaskStatusFilter = 'open'|'all'|BackOfficeTaskStatus`、レスポンス型に`meta.assignee_counts`を追加。
- hooks: `useBackOfficeTasks(options, enabled = true)`(キー`['backoffice-tasks','list', options]`)を追加し、
  `useUnassignedBackOfficeTasks`/`useMyBackOfficeTasks`を削除。割当・ステータス変更・一括完了の成功時は
  `['backoffice-tasks','list']`をinvalidateする(既存の`UNASSIGNED_KEY`/`MINE_KEY`のinvalidateを置き換え)。
- `BackOfficeTaskListPage`を作り替える:
  - 上部Card「タスク検索」: キーワード入力+ステータスSelect(「未完了」(既定)/「すべて」/各ステータスのラベル
    (`backOfficeTaskStatusLabel`))+検索/クリア。ステータスは選択した時点で反映する(キーワードは検索ボタン/Enterで反映)。
  - その下に担当者タブ「未割り当て」(既定)/「自分のタスク」/「他の担当者」/「すべて」。各タブに`meta.assignee_counts`の件数を表示。
    タブは`components/ui/tabs.tsx`を使う。
  - 一覧1つ(既存`BackOfficeTaskTable`を流用。列は同じ)+`Pagination`。
  - URL(§2.10): `q`、`assignee`(`unassigned`は既定なので書かない。`me`/`others`/`all`)、`status`(`open`は既定なので書かない。
    `all`/各ステータス)、`page`。不正な`assignee`・`status`・`page`は既定扱いにしてURLから削除する。
    旧パラメータ`unassignedPage`/`minePage`は扱わない(無視)。
  - タブ・ステータス・キーワード変更時は`page`を消して1ページ目へ戻し、選択を解除する。ページ変更時も選択を解除する。
  - 選択と一括操作(§2.21、モバイル2行構成は`AttendanceSelectionActionBar`のパターン): 完了・取消以外の行を選択可能。
    選択時のみバーを表示し、「n件を選択中」・担当者UserPicker+「割り当てる」(担当者未選択ならdisabled+「担当者を選択してください」)・
    「選択したタスクを完了」(選択に自分以外のタスク(未割当を含む)があればdisabled+「自分のタスクのみ一括完了できます」)・
    キャンセル(選択解除)。割当は既存どおり1件ずつ`useAssignBackOfficeTask`を並列実行、完了は`useBulkCompleteBackOfficeTasks`。
  - 空状態: 検索語またはステータスが既定以外なら「条件に一致するタスクがありません。」+「検索条件をクリア」。
    既定条件ではタブ別に「未割り当てのタスクはありません。」「担当中のタスクはありません。」
    「他の担当者に割り当てられたタスクはありません。」「タスクはありません。」。
  - Loading・403(PermissionDenied)・エラー(fallback「タスクの取得に失敗しました。」)は既存と同じ扱い。
    タブ・条件切替中は前の結果を残して表示してよい(React Queryの`placeholderData: keepPreviousData`)。
  - ページ先頭JSDocを新仕様に更新。
- `HomeDashboardPage`: `useBackOfficeTasks({ assignee: 'me', status: 'open', perPage: 1 }, canSeeBackOfficeTasks)`の
  `meta.total`を未完了件数として使い、`OPEN_BACKOFFICE_STATUSES`によるクライアント集計を削除する(他で未使用なら定数も削除)。
- test(Vitest): 一覧ページは既定で`assignee: 'unassigned', status: 'open'`で取得する/タブ切替でURL`assignee`と取得条件が変わる/
  件数バッジ表示/ステータスSelectで`status`が変わる/検索語が渡る/不正なURLパラメータの削除/一括割当(付け替え含む)/
  一括完了が自分のタスクのみのとき押せ、他人・未割当が混ざるとdisabled+理由/条件変更で選択解除/空状態(タブ別・検索中)。
  ダッシュボードのテストを`meta.total`ベースに更新。
- story: 実際のクエリキー(options込み)でseedし、各タブ・選択中・空状態のstoryを用意。ダッシュボードstoryも更新。
- e2e: 一覧画面を使う箇所(`frontend/e2e/scenario-04-expense-claim.spec.ts`:139付近、`scenario-05-business-card.spec.ts`:56付近、
  `scenario-09-cross-domain.spec.ts`:345/441/481付近)を新UIに合わせる(「自分のタスク」の行を探す箇所はタブを切り替えてから探す、
  割当ボタン等の名前の変更に追随)。

## 受け入れ条件
- 一覧画面を開くと「未割り当て」タブ・未完了で表示され、従来の未割り当て一覧と同じ操作(複数選択→担当者を割り当てる)ができる。
- 「自分のタスク」タブで従来どおり複数選択→一括完了ができる。
- 「他の担当者」「すべて」タブで、自分以外に割り当てたタスクを一覧・キーワード検索でき、各タブに件数が表示される。
- ステータスを「すべて」や個別ステータスにすると完了・取消済みも検索できる。
- 検索条件・タブ・ページがURLに保持され、詳細→戻るで復元される。
- ダッシュボードのバックオフィスタスク件数が21件以上でも正しい。
- 新APIのFeatureテスト、一覧画面・ダッシュボードのVitest、型チェック・lintがPASSする。

## 対象外
- 種別・担当部署・期限・特定担当者などの追加フィルタ、並び替え、CSV出力
- 詳細画面の変更
- 一括割当を1リクエストで行うAPIの追加(既存どおり1件ずつ)

## ドキュメントへの影響
- `docs/11-usecases-backoffice.md`: UC-B002/UC-B003の一覧の説明を「担当者タブ(未割り当て/自分/他の担当者/すべて)と
  ステータスで絞り込める汎用検索画面」に更新し、一括操作の可否(割当は完了・取消以外、完了は自分のタスクのみ)を記載。
- `docs/testing/scenario-tests.md`:178: `GET /backoffice-tasks/unassigned`の記述を新APIに更新。
- `docs/16-database-schema.md`・`docs/17-events.md`: 変更なし(テーブル・イベントの変更なし)。

## モック・アセット
- [assets/list-wireframe.md](./assets/list-wireframe.md): 一覧画面のワイヤーフレーム

## 実装対象
- backend: `app/Http/Controllers/Api/BackOfficeTaskController.php`、`app/Models/BackOfficeTask.php`(スコープ)、`routes/api.php`、
  `tests/Feature/BackOffice/`
- frontend: `src/api/backOfficeTasks.ts`、`src/api/types.ts`、`src/hooks/useBackOfficeTasks.ts`、
  `src/pages/backOffice/BackOfficeTaskListPage.{tsx,test.tsx,stories.tsx}`、`src/pages/home/HomeDashboardPage.{tsx,test.tsx,stories.tsx}`、
  MSWハンドラ(あれば)、`e2e/scenario-04/05/09`の該当箇所
- docs: `docs/11-usecases-backoffice.md`、`docs/testing/scenario-tests.md`

## 検証方法
- `cd backend && php artisan test --filter=BackOffice`(および全体`php artisan test`)
- `cd frontend && npx vitest run src/pages/backOffice src/pages/home src/hooks`、`npx tsc -b`、`npm run lint`
- e2eは環境依存のため、変更箇所の型チェック・lintのみ確認(実行可能なら該当シナリオを実行)

## レビュー履歴
- 初版(2026-11-22)
- 2026-11-22 提示前の独立設計レビュー(general-purpose)の指摘を反映: 既存Vitestで新fetchのspy必須化、
  `othersPage`の不正値処理、e2eの行探索をCard内に限定、invalidateは`OTHERS_KEY`個別追加に一本化、
  読み込み/エラーをCard内に限定(論点5新設)、storyのseedキー修正、Featureテストで未割当の除外を明示、
  JSDoc/updateParamsの付随修正を明記。並び順変更は対象外とし理由を記載。前提(原因・権限・ルート順序)は覆らず。
- 2026-11-22 ユーザーレビュー「汎用的な検索画面として設計しつつ現在のような使い勝手を維持できるか」を受け、前提(固定一覧を
  1つ足す)が覆ったため「設計前提の検証」から作り直した。AskUserQuestionで画面構成=プリセットタブ、既定ステータス=未完了を確定。
  旧論点(3つ目のCard追加・`/assigned-to-others`新設)は破棄。

## 実装結果
未着手
