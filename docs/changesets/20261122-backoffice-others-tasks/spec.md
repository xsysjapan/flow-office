# バックオフィスタスク一覧で他の担当者に割り当てたタスクを検索できるようにする

ステータス: レビュー中

## 変更要望(原文)
> バックオフィスタスクで自分以外の人に割り当てたタスクが全くみられなくなります。
> 検索はできるように変更をお願いします。

## 背景・目的
バックオフィスタスク一覧画面には「未割り当てタスク」「自分のタスク」の2つの一覧しかなく、
他の担当者に割り当てた時点でそのタスクは一覧から辿れなくなる。割り当て後の進捗確認・
担当者不在時の引き継ぎ確認のため、他の担当者のタスクも一覧・検索できるようにする。

## 現状(As-Is)
- API: `backend/app/Http/Controllers/Api/BackOfficeTaskController.php`
  - `indexUnassigned`(`GET /backoffice-tasks/unassigned`、`assigned_user_id IS NULL`)
  - `indexMine`(`GET /backoffice-tasks/mine`、`assigned_user_id = 自分`)
  - 他人に割り当てたタスクを返す一覧APIは存在しない(原因)。詳細`show`は担当者を問わず取得可能。
  - 検索は`applyListSearch`(タイトル・種別・担当部署・担当者名のLIKE)、ページングは`per_page`(既定20)。
- ルート: `backend/routes/api.php` の `feature:backoffice.tasks` + `permission:backoffice_task.execute,any` グループ。
- 画面: `frontend/src/pages/backOffice/BackOfficeTaskListPage.tsx`(検索カード+未割り当て+自分のタスク。
  検索語`q`は共有、ページは`unassignedPage`/`minePage`で独立しURLに保持)。
- クライアント: `frontend/src/api/backOfficeTasks.ts`、`frontend/src/hooks/useBackOfficeTasks.ts`。
- ユースケース: `docs/11-usecases-backoffice.md` UC-B002/UC-B003。
- テーブル: `backoffice_tasks`(Projection)。

## 設計前提の検証
- 正データと派生データ: 参照のみの変更。`backoffice_tasks`はProjectionで、読み取りのみ追加する。
  書き込み経路(Command→Event→Projector)は変更しない。
- 定義と実態: `assigned_user_id`は「現在の担当者」の意味で一貫して使われている。ずれなし。
- 原因の分類: 実装(機能)の欠落。一覧APIが「未割当」「自分」の2種しかない。設計原則上の問題はない。
- 横展開: 同種の「割り当て型タスク一覧」は本画面のみ。承認待ち一覧等は担当の概念が異なるため対象外。
- 一覧のページング: 他担当者のタスクは件数が増え得る(完了済みも含む)ため、既存2一覧と同様にページングする。

## 仕様検討

### 論点1: 他の担当者のタスクをどう見せるか
- 選択肢:
  - A. 3つ目の一覧「他の担当者のタスク」を追加し、常時表示(検索語は既存2一覧と共有)
  - B. 「すべてのタスク」一覧に置き換え、担当者フィルタで絞り込む
  - C. 検索語を入力したときだけ他担当者のタスクを結果に表示する
- 決定: A
- 理由: 既存の2一覧・一括操作の挙動を崩さず追加できる。Cは「全く見られない」状態が検索前に残り、
  何を検索すればよいか分からない。Bは一括割当/一括完了の対象範囲の扱いが変わり変更が大きい。
- 未確定・要確認事項: なし

### 論点2: API
- 選択肢:
  - A. 新エンドポイント `GET /backoffice-tasks/assigned-to-others`(`assigned_user_id IS NOT NULL AND <> 自分`)
  - B. 既存一覧に`scope`パラメータを追加
- 決定: A
- 理由: 既存の`unassigned`/`mine`と同じ粒度で揃い、既存APIに影響しない。
- 未確定・要確認事項: なし

### 論点3: 権限・操作
- 選択肢:
  - A. 既存グループ(`backoffice_task.execute`)と同じ権限で閲覧可。一覧は閲覧専用(行選択・一括操作なし)、行クリックで詳細へ
  - B. 他担当者タスクにも一括操作を付ける
- 決定: A
- 理由: 詳細APIは既に担当者を問わず同権限で取得できるため権限範囲は広がらない。一括完了APIは自分の
  タスクのみ受け付ける仕様(UC-B003)であり、他人のタスクへの一括操作は要望外。
- 未確定・要確認事項: なし

### 論点4: ページング・並び順・表示項目
- 決定: 既存2一覧と同じ(`latest()`=作成日時の新しい順、1ページ20件、列はタイトル/種別/担当者/期限/ステータス)。
  ページはURLパラメータ`othersPage`で独立に保持し、検索・クリア時は他の2つと同様に1ページ目へ戻す。
  空の時の表示: 検索中は「条件に一致するタスクがありません。」+「検索条件をクリア」、未検索時は
  「他の担当者に割り当てられたタスクはありません。」。
- 理由: 一覧間の操作感を統一する。並び順(処理中を先頭に等)の変更は既存2一覧にも関わるため今回は行わない。
- 未確定・要確認事項: なし

### 論点5: 読み込み中・エラー時の表示範囲
- 選択肢:
  - A. 既存2一覧と同じく画面全体をLoading/ErrorMessageに置き換える
  - B. 「他の担当者のタスク」Card内だけでLoading/ErrorMessageを出す(403のみ画面全体をPermissionDeniedにする)
- 決定: B
- 理由: 他担当者の一覧は完了済みを含み件数が多くなり得るため、その取得待ち・失敗で、主作業である
  未割り当て/自分のタスクの操作を妨げない。403は権限が共通なので画面全体で扱う。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- backend
  - `BackOfficeTaskController::indexAssignedToOthers`を追加。`validateListRequest`/`applyListSearch`を再利用し、
    `whereNotNull('assigned_user_id')->where('assigned_user_id', '<>', 自分のID)`、`with('assignee')`、`latest()`、
    `paginate(per_page ?? 20)`、`BackOfficeTaskResource::collection`を返す。OpenAPI属性を既存と同形式で付与
    (path `/backoffice-tasks/assigned-to-others`、operationId `backofficeTasks.assignedToOthers`、summary「他の担当者のタスク一覧を取得する」)。
  - ルート: 既存グループ内、`/backoffice-tasks/{backOfficeTask}`より前に`GET /backoffice-tasks/assigned-to-others`を登録。
  - Featureテスト: 未割当・自分・他人のタスクが混在するとき他人のタスクのみ返る(未割当・自分のタスクが含まれないことを明示的に確認)/検索語で絞り込める
    (担当者名で検索できる)/ページングmeta/権限なしで403。
- frontend
  - `fetchTasksAssignedToOthers(options)`(api)、`useBackOfficeTasksAssignedToOthers(options)`(hook。キー
    `['backoffice-tasks','assigned-to-others']`、`OTHERS_KEY`として定義)。`useBackOfficeTasks.ts`の既存の
    invalidate処理(キー個別)に`OTHERS_KEY`を1行追加する(配下全体へのinvalidateにはしない)。
  - `BackOfficeTaskListPage`に3つ目のCard「他の担当者のタスク」を「自分のタスク」の下に追加(閲覧専用、行選択なし)。
    - 画面全体のLoading判定には含めない。Card内で`LoadingState`/`ErrorMessage`(fallback「他の担当者のタスクの取得に失敗しました。」)を出す。
      403は既存の`permissionDeniedError`判定の候補に含めて画面全体をPermissionDeniedにする。
    - `othersPage`は既存と同様`isValidPageParam`で検証し、不正値はURLから削除して1ページ目扱い。
    - `handleSearch`/`clearSearch`の`updateParams`に`othersPage: null`を追加。
    - ページ先頭JSDoc(「未割り当て / 自分のタスク」)と「2つの一覧」コメントを3一覧に更新。
  - test: 既存の全テストケースで`fetchTasksAssignedToOthers`もspyする(beforeEachにまとめ、既定は空ページ)。
    追加ケース: 他担当者のタスクが表示される/検索語が`fetchTasksAssignedToOthers`にも渡る/`othersPage`でページング/
    空状態(未検索・検索中)/既存の不正ページテストを`othersPage=abc`も含むよう拡張/一括割当後に`OTHERS_KEY`がinvalidateされる。
  - story: 実際のクエリキー(`[...KEY, {search, page}]`)でseedするよう修正し(既存2一覧の不一致も併せて修正)、他担当者タスクのデータを追加。
  - e2e: 一覧画面で検索せずに行を探す箇所(`frontend/e2e/scenario-04-expense-claim.spec.ts`:139付近、
    `scenario-05-business-card.spec.ts`:56付近、`scenario-09-cross-domain.spec.ts`:345/441/481付近)は、
    探索範囲を対象Card(未割り当て/自分のタスク)内に限定し、3つ目の一覧の同名行と重複しないようにする。

## 受け入れ条件
- バックオフィスタスク一覧画面に「他の担当者のタスク」一覧が表示され、自分以外に割り当てたタスクが確認できる。
- 検索欄のキーワード(タイトル・種別・担当部署・担当者名)で他担当者のタスクも絞り込める。
- 未割り当て/自分のタスクの既存挙動(一括割当・一括完了)は変わらない。
- `GET /backoffice-tasks/assigned-to-others`のFeatureテストと、画面のVitestテストがPASSする。

## 対象外
- 他担当者タスクへの一括操作・担当者の付け替え(詳細画面の既存機能で行う)
- ステータス・担当者などの絞り込みフィルタの追加、並び順の変更
- 他画面の一覧

## ドキュメントへの影響
- `docs/11-usecases-backoffice.md`: UC-B002に「他の担当者に割り当てたタスクも一覧・検索できる(閲覧専用)」旨を追記。
- `docs/16-database-schema.md`・`docs/17-events.md`: 変更なし(テーブル・イベントの変更なし)。

## モック・アセット
なし(既存Cardと同じ構成の一覧を1つ追加するのみ)

## 実装対象
- `backend/app/Http/Controllers/Api/BackOfficeTaskController.php`、`backend/routes/api.php`、`backend/tests/Feature/BackOffice/`
- `frontend/src/api/backOfficeTasks.ts`、`frontend/src/hooks/useBackOfficeTasks.ts`、
  `frontend/src/pages/backOffice/BackOfficeTaskListPage.{tsx,test.tsx,stories.tsx}`(MSWハンドラがあれば追加)
- `frontend/e2e/scenario-04-expense-claim.spec.ts`、`scenario-05-business-card.spec.ts`、`scenario-09-cross-domain.spec.ts`(該当箇所のみ)
- `docs/11-usecases-backoffice.md`

## 検証方法
- `cd backend && php artisan test --filter=BackOffice`
- `cd frontend && npx vitest run src/pages/backOffice src/hooks` / `npx tsc -b` / lint

## レビュー履歴
- 初版(2026-11-22)
- 2026-11-22 提示前の独立設計レビュー(general-purpose)の指摘を反映: 既存Vitestで新fetchのspy必須化、
  `othersPage`の不正値処理、e2eの行探索をCard内に限定、invalidateは`OTHERS_KEY`個別追加に一本化、
  読み込み/エラーをCard内に限定(論点5新設)、storyのseedキー修正、Featureテストで未割当の除外を明示、
  JSDoc/updateParamsの付随修正を明記。並び順変更は対象外とし理由を記載。前提(原因・権限・ルート順序)は覆らず。

## 実装結果
未着手
