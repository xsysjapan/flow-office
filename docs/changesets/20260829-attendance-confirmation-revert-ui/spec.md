# 月次勤怠の確定取消(UC-A018)フロントエンドUIの追加

ステータス: 完了

## 変更要望(原文)

> 一般社員を想定した、確定の取り消し依頼と、上長の利用を想定した確定取り消し操作を実装してください

(前段の会話で、「確定状態の取り消し」(UC-A018「承認済みの月次勤怠の確定を取り消す(勤怠確定取消依頼)」)
はバックエンドのAPI・権限・申請種別マスタは実装済みだが、フロントエンドのUIが一切無いことを
確認済み。`docs/changesets/20260829-backoffice-task-detail-cleanup/`の作業中に発見した既存の
機能ギャップを埋める、独立した追加機能として本changesetを作成する。)

## 背景・目的

`docs/07-usecases-attendance.md` UC-A018によれば、締め前(承認済み)の月次勤怠であっても
承認済み時点でロックされているため通常の日次編集はできない。日次実績の修正がどうしても
必要な場合は、汎用申請ワークフローの「勤怠確定取消依頼」を使い、承認後にバックオフィス担当者が
確定を取り消す、という手順が定義されている。バックエンド(`RevertApprovedAttendanceMonth`
コマンド・`attendance.confirmation_revert`権限・`revert-confirmation`API・
`attendance_confirmation_revert`申請種別マスタ)は全て実装・テスト済みだったが、
フロントエンドにはこの申請を作成する専用UIも、承認後にバックオフィス担当者が実行するUIも
存在しなかった(`frontend/src/api/attendance.ts`にAPIラッパーすら無い)。

## 現状(As-Is)

- `backend/database/seeders/RequestTypeSeeder.php`89-99行目: 申請種別`attendance_confirmation_revert`
  (「勤怠確定取消依頼」)が`form_schema`(`target_year_month`・`reason`、いずれも`type: 'text'`の
  自由入力)・`requires_backoffice_task: true`・`backoffice_task_type:
  'attendance_confirmation_revert'`・`backoffice_department: '人事部'`で登録済み。
- `frontend/src/pages/workflow/WorkflowRequestNewPage.tsx`: `useRequestTypes()`が返す
  **全ての**アクティブな申請種別をプルダウンに列挙し、選択した種別の`form_schema`を動的に
  フォーム表示する汎用実装。`attendance_confirmation_revert`もこのプルダウンに自動的に
  現れるため、**一般社員向けの申請作成UIは既存の汎用フローで既に動作する**(新規実装不要)。
- `backend/app/Domain/BackOffice/Handlers/CreateBackOfficeTaskFromApprovalHandler.php`:
  承認された申請の`request_type.requires_backoffice_task`が真の場合、
  `source_type: 'workflow_request'`・`source_id: <workflow_request.id>`のバックオフィスタスクを
  自動生成する(`attendance_month_confirmation`タスクとは異なり、`source_type`は
  `attendance_month`ではなく`workflow_request`)。
- `backend/routes/api.php`: `POST /attendance-months/{attendanceMonth}/revert-confirmation`
  (`permission:attendance.confirmation_revert,any`)。ボディは`reason`(必須)・
  `workflow_request_id`(必須、`workflow_requests`に存在すること)。
- `frontend/src/pages/backOffice/BackOfficeTaskDetailPage.tsx`: `task_type ===
  'attendance_month_confirmation'`のみ専用セクションを表示しており、
  `attendance_confirmation_revert`タスクには汎用の「状態を変更する」フォーム
  (`backoffice_tasks.status`変更)しか表示されず、実際に確定を取り消す手段が無かった。

## 仕様検討

### 論点1: 一般社員向け「確定の取り消し依頼」をどう作るか

- 選択肢:
  - A. 既存の汎用申請作成フロー(`WorkflowRequestNewPage`、申請種別プルダウンから
    「勤怠確定取消依頼」を選択)をそのまま使う。追加実装なし。
  - B. 専用の申請作成画面(年月ピッカー・自分の月次勤怠一覧からの選択等)を新規に作る。
- 決定: A。
- 理由: `attendance_confirmation_revert`申請種別は既にactiveな状態でマスタ登録されており、
  `WorkflowRequestNewPage`は申請種別を限定せず全て列挙する汎用実装のため、**既に動作する**。
  Bは他の汎用申請種別(名刺申請・証明書発行等)が同じ汎用フォームで運用されていることとの
  一貫性を崩し、過剰実装になる(CLAUDE.mdの「その場で使わない将来のための抽象化を避ける」
  方針にも反する)。`target_year_month`が自由入力(`YYYY-MM`のフォーマットチェック無し)である
  点は他の汎用申請種別と同水準であり、今回はそのまま許容する。
- 未確定・要確認事項: なし(確定)。

### 論点2: 上長(バックオフィス担当者)向け「確定取消操作」をどこに実装するか

- 選択肢:
  - A. `BackOfficeTaskDetailPage`に、`task_type === 'attendance_confirmation_revert'`
    専用の新セクションを追加する。既存の`AttendanceMonthConfirmationSection`
    (`task_type === 'attendance_month_confirmation'`用)と同じ配置パターンに倣う。
  - B. `WorkflowRequestDetailPage`(申請詳細)側に確定取消の実行ボタンを追加する。
- 決定: A。
- 理由: 確定取消は「バックオフィスタスクの処理」(UC-B001〜のバックオフィスタスク処理フロー)の
  一環として行われる操作であり、既存の`attendance_month_confirmation`(締め処理)と全く同じ
  位置づけ。`WorkflowRequestDetailPage`は申請そのものの閲覧・提出・承認・差戻し・取消のための
  画面であり、バックオフィス処理(業務ドメイン側の確定操作)を混在させると設計原則14
  (「申請(ワークフロー)」と「業務」は分離する)に反する。既に承認された申請から生成された
  バックオフィスタスクの処理はバックオフィスタスク詳細で行う、という既存の一貫した設計(Aと
  同じ判断が`attendance_month_confirmation`タスクで既になされている)に揃える。
- 未確定・要確認事項: なし(確定)。

### 論点3: バックオフィスタスクから対象の月次勤怠(`attendance_month.id`)をどう特定するか

`revert-confirmation` APIは`attendance_months.{id}`をパスパラメータに要求するが、
`attendance_confirmation_revert`タスクの`source_type`は`workflow_request`であり、
`source_id`は`workflow_request.id`であって`attendance_month.id`ではない
(論点2の背景の通り、`attendance_month_confirmation`タスクとは`source_type`が異なる)。

- 選択肢:
  - A. タスクの`source_id`(=`workflow_request.id`)から申請(`useWorkflowRequest`)を取得し、
    その`form_data.target_year_month`と`applicant.id`から`useAttendanceMonth(yearMonth,
    userId)`(`AttendanceMonthReferenceTabs`が内部で使うものと同じフック)で対象の
    `attendance_month`を解決する。
  - B. バックエンド側に、`workflow_request_id`から直接確定取消できる新エンドポイントを追加する
    (`attendance_month.id`を経由しない)。
- 決定: A。
- 理由: バックエンドの`revert-confirmation`APIは変更せず(既存のテスト済みAPIをそのまま使う
  という今回のchangesetの前提=「そのまま流用」の方針に合致)、フロントエンド側で
  `form_data.target_year_month`(申請時にユーザーが入力した対象年月の文字列)と申請者IDから
  対象月を解決する。Bは既にテスト済みのバックエンドAPIに手を入れることになり、
  影響範囲が不必要に広がるため不採用。
- 未確定・要確認事項: なし(確定)。`target_year_month`が不正な形式・存在しない月だった場合は
  `useAttendanceMonth`が`month: null`を返すため、対象月が見つからない旨を表示し確定取消
  ボタン自体を出さない(実装対象参照)。

## 仕様確定事項(まとめ)

1. **一般社員向け「確定の取り消し依頼」は追加実装しない**(既存の`/requests/new`汎用フローが
   `attendance_confirmation_revert`申請種別を自動的に選択肢へ含めるため、既に動作する)。
2. **`frontend/src/api/attendance.ts`**: `revertMonthConfirmation(id: string, reason: string,
   workflowRequestId: string): Promise<AttendanceMonth>`を追加
   (`POST /attendance-months/{id}/revert-confirmation`、body:
   `{ reason, workflow_request_id: workflowRequestId }`)。
3. **`frontend/src/hooks/useAttendance.ts`**: `useRevertMonthConfirmation()`を追加
   (`useCloseMonth`/`useReopenMonth`と同じパターンで`useInvalidateMonths()`によりキャッシュを
   無効化する)。
4. **`frontend/src/pages/backOffice/BackOfficeTaskDetailPage.tsx`**:
   - 新規`AttendanceConfirmationRevertSection`コンポーネントを追加。
     `useWorkflowRequest(task.source_id)`で申請を取得し、`form_data.target_year_month`・
     `applicant.id`から`useAttendanceMonth`で対象月を解決する。
   - 申請者名・対象年月・申請時の取消理由を表示し、`AttendanceMonthReferenceTabs`
     (他画面と共通のコンポーネント)で実際の実績を確認できるようにする。
   - `attendance.confirmation_revert`権限を持つ場合のみ、`ConfirmActionDialog`
     (`ReopenMonthDialog`と同じ構成: 取消理由の入力必須、破壊的操作として確認を挟む)で
     「確定を取り消す」を実行できるようにする。
   - `task.task_type === 'attendance_confirmation_revert' && task.source_type ===
     'workflow_request'`の場合にこのセクションを表示する
     (既存の`attendance_month_confirmation`タスクの分岐と並列)。
5. 対象月が見つからない(`target_year_month`不正・存在しない月)場合は「対象年月・申請者を
   特定できませんでした。」と表示し、実行ボタンは出さない。

## 対象外

- 一般社員向けの専用申請作成UI(年月ピッカー化等)。既存の汎用フローをそのまま使う(論点1)。
- バックエンドAPI・権限・ドメインロジックの変更。全て実装・テスト済みの既存コードをそのまま
  利用する。
- `WorkflowRequestListPage`の「まとめて取消」等、確定取消申請に関する一覧側の表示調整。
- `target_year_month`フィールドをテキストから年月ピッカー(`YearMonthPicker`)に変更すること。
  他の汎用申請種別のフォームと同水準の入力方式のまま揃える。

## ドキュメントへの影響

変更なし。UC-A018の手順・権限は既存ドキュメント通りで、今回はそのUIをフロントエンドに
追加しただけであり、ユースケースや状態遷移自体に変更はない。

## モック・アセット

なし。

## 実装対象

- `frontend/src/api/attendance.ts`: `revertMonthConfirmation`を追加。
- `frontend/src/hooks/useAttendance.ts`: `useRevertMonthConfirmation`を追加。
- `frontend/src/pages/backOffice/BackOfficeTaskDetailPage.tsx`:
  `AttendanceConfirmationRevertSection`を追加し、`attendance_confirmation_revert`タスクの
  分岐に組み込む。
- `frontend/src/pages/backOffice/BackOfficeTaskDetailPage.test.tsx`: 申請者・対象年月・
  取消理由の表示、確定取消の実行、の2テストを追加。

## 検証方法

```
cd frontend
npx tsc --noEmit
npm run lint
npm test -- src/pages/backOffice/BackOfficeTaskDetailPage.test.tsx

cd ../backend
php artisan test --filter=AttendanceMonthRescueCommandsTest
```

## レビュー履歴

初版。ユーザーからの要望を受けて作成し、そのまま実装・検証まで完了した。

## 実装結果

実装済み。変更ファイル:
- `frontend/src/api/attendance.ts`
- `frontend/src/hooks/useAttendance.ts`
- `frontend/src/pages/backOffice/BackOfficeTaskDetailPage.tsx`
- `frontend/src/pages/backOffice/BackOfficeTaskDetailPage.test.tsx`

テスト結果:
- `npx tsc --noEmit` — エラーなし
- `npm run lint`(oxlint) — エラーなし
- `BackOfficeTaskDetailPage.test.tsx` — 15 passed(新規2件含む)
- フロントエンド全体`npm test` — 803 passed / 2 skipped(既存の無関係な失敗6件は
  `docs/changesets/20260829-backoffice-task-detail-cleanup/spec.md`に記載の既存不具合と同一)
- バックエンド`php artisan test --filter=AttendanceMonthRescueCommandsTest` — 6 passed
  (今回フロントエンドのみの変更のため、既存のバックエンドテストに変更なし)
