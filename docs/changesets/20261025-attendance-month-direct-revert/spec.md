# 承認済みの月次勤怠を未提出に戻す経路の見直し(バックオフィスの直接取消・取消依頼の承認で即時取消)

ステータス: レビュー中

## 変更要望(原文)

> バックオフィスの担当者は直接未提出に戻せるようにしてください。その際メッセージと共に未提出に戻すように修正をお願いします。

確認した回答(AskUserQuestion、2026-10-25):
- ボタンの置き場所: 両方(締め処理タスクの詳細と、勤怠参照画面の管理者操作)。
- メッセージの伝え方: 画面表示と通知の両方。
- 既存の「勤怠確定取消依頼」(申請→承認→バックオフィスが実行)の流れ: 最初は「残す」と回答。
  その後、次の追加指示で変更された(論点5)。

> 今ある「勤怠確定取消依頼」（社員が申請→承認→バックオフィスが実行）の流れは残しますか？
> について、イメージが違いました。承認したら、即時未提出に変更してください。

- 権限: 人事担当ロールの初期権限に確定取消(`attendance.confirmation_revert`)を追加する。

設計レビュー後に確認した回答(AskUserQuestion、2026-10-25):
- 勤怠確定取消依頼で、承認者に自分自身を指定すること(自己承認): 許可する。
- 人事部による代理申請: やめて、本人の申請だけにする(人事部は直接取消を使う)。
- 直接取消を実行できる範囲: 制限なし。確定取消の権限があれば、全社員の月と自分の月を戻せる。
- バックオフィス担当ロールの閲覧権限: 追加しない。初期設定では人事担当ロールで運用する。

## 背景・目的

承認済みの月次勤怠を未提出に戻す方法は、現在は社員の「勤怠確定取消依頼」を経由するものしかない
(UC-A018、`docs/changesets/20260829-attendance-confirmation-revert-ui/`・
`20261024-attendance-confirmation-revert-entry/`)。バックオフィス担当者が内容の誤りに気づいた場合でも、
社員に申請させる必要がある。また、社員が申請した場合も、承認の後にバックオフィス担当者の操作が
もう一度必要になる。次の2点を変える。
- バックオフィス担当者が申請なしで、社員へのメッセージを添えて直接未提出に戻せるようにする。
- 勤怠確定取消依頼は、承認された時点で即時に未提出に戻す。

## 現状(As-Is)

- 確定取消の書き込み経路: `RevertApprovedAttendanceMonth`(Command、`workflowRequestId`必須)
  → `RevertApprovedAttendanceMonthHandler`(statusが`approved`か検証し、`WorkflowRequest::findOrFail`で
  申請の存在だけを確認する。36行目)→ `AttendanceMonthAggregate::revertConfirmation`(154-176行目)。
  集約は`AttendanceMonthConfirmationReverted`(`revertedByUserId`・`reason`・`workflowRequestId`)と
  `AttendanceMonthUnlocked`を記録する。
- API: `POST /attendance-months/{id}/revert-confirmation`(`permission:attendance.confirmation_revert,any`)。
  `reason`と`workflow_request_id`(`exists:workflow_requests,id`)が必須
  (`AttendanceController.php`972-987行目)。
- Projection: `AttendanceMonthProjector.php`97-105行目は、確定取消でstatusを`not_submitted`にし、
  `submitted_at`・`approved_at`・`return_comment`をnullにする。取消理由はどの列にも残さない
  (イベントにだけある)。差戻し(52-59行目)は`return_comment`に理由を入れる。再提出(27-42行目)で
  `return_comment`はnullに戻る。
- `attendance_months`の列: `return_comment`は`2026_07_26_000000_add_return_comment_to_attendance_months_table.php`で
  追加されたが、`docs/16-database-schema.md`987-1000行目に記載がない。
- 通知: 差戻しの通知は`ReturnWorkflowRequestHandler.php`41-51行目が`SendNotificationJob::enqueue`で
  送る。確定取消では通知を送っていない。
- 元の提出の申請(`subject_type=attendance_month`の`workflow_requests`)は、確定取消の後も`approved`の
  ままになる。再提出すると、新しい申請が作られる(`AttendanceController::submitMonth`、
  `DraftWorkflowRequestHandler`)。
- 締め処理タスク(`attendance_month_confirmation`): 月次勤怠の承認を受けて
  `CreateBackOfficeTaskFromAttendanceMonthApprovalHandler`が作る。同じ月のタスクがすでにあれば、
  新しくは作らない(再承認では既存のタスクが使われる)。
- 画面:
  - `BackOfficeTaskDetailPage.tsx`の`AttendanceMonthConfirmationSection`(34-50行目)は、実績の参照
    タブだけを表示し、操作は無い。
  - `AttendanceReferencePage.tsx`の「管理者操作」カード(273行目以降)は、締め済みなら締め取消、
    それ以外なら「締める」を表示する(`canReopenMonth`=`attendance.month_reopen`、
    `canCloseMonth`=`backoffice_task.execute`、168-169行目)。
  - 社員の月次勤怠画面(`AttendanceMonthDetailPage.tsx`233-236行目)は、`returned`のときに
    「差戻し理由」を表示する。
- 権限(`AccessControlSeeder.php`49-54行目):
  - `attendance.confirmation_revert`はバックオフィス担当とシステム管理者にある。
  - 人事担当には`attendance.read`はあるが、`attendance.confirmation_revert`は無い。
  - バックオフィス担当には`attendance.read`が無い。そのため他の社員の月(`GET /attendance-months/{id}`、
    `showMonth`の847-852行目)を表示できず、確定取消の画面も使えない。
  - シーダーは、ロールの権限行がまだ無い場合にだけ設定する(既存の環境は変わらない)。
- ドキュメント: `docs/07-usecases-attendance.md` UC-A018(手順4〜6と「この操作を実行できるのはバックオフィス担当者のみ」)、
  `docs/17-events.md`169-171行目、`docs/11-usecases-backoffice.md`95-106行目。
- テスト: `backend/tests/Feature/Attendance/AttendanceMonthRescueCommandsTest.php`(確定取消は4件)。

## 設計前提の検証

- 正データと派生データ: 正データは`stored_events`の`attendance.month_confirmation_reverted`で、
  取消理由(`reason`)もすでにここにある。社員に見せるための取消理由は、Projectorでこのイベントから
  `attendance_months`の新しい列に写す(派生データ)。イベントの外で列を直接書かない(原則2)。
  通知はHandlerから送る(差戻しと同じやり方)。
- 定義と実態: `return_comment`は「差戻し理由」(`returned`のときだけ意味を持つ)として使われている。
  確定取消の理由を同じ列に入れると、1つの列が2つの意味を持つ。そのため列を分ける(論点3)。
  `return_comment`がdocs/16に無いのはドキュメントの漏れなので、今回あわせて記載する。
- 原則13: 承認を取り消すのではなく、確定取消という新しい状態遷移として追記する。既存の承認イベントは
  変えない。締め後(`closed`)は対象外のまま(締め取消は管理者専用、UC-A017)。
- 原則14・15: 取消依頼の承認で即時に戻す経路は、既存の`AttendanceMonthReturnOnWorkflowRequestReturnedReactor`
  (ワークフローのイベントを受けて勤怠のCommandを発行する同期Reactor)と同じ形にする。ワークフロー側の
  Reactorは自分の`workflow_requests`(申請者・申請種別・`form_data`)だけを読み、対象の月の特定は
  勤怠側のHandlerが行う(勤怠のテーブルをワークフロー側で読まない)。
- 原則14: 申請なしの経路は、ワークフローを経由しない業務側の操作として扱う。統合ワークフローに
  申請を作ったりはしない。
- 既存イベントとの互換: 既存の`AttendanceMonthConfirmationReverted`は、全件`workflowRequestId`を
  持っている。nullを許すように変えても、既存イベントの再生結果は変わらない。

## 仕様検討

### 論点1: 申請なしの確定取消をどう記録するか

- 選択肢:
  - A. 既存のCommand・Event(`RevertApprovedAttendanceMonth`/`AttendanceMonthConfirmationReverted`)の
    `workflowRequestId`をnull許容にする。申請があるときだけ存在を確かめる。
  - B. 新しいCommand・Event(例: `AttendanceMonthRevertedByBackOffice`)を作る。
- 決定: A。
- 理由: 起きる状態遷移(approved→not_submitted、ロック解除)と、残すべき事実(誰が・なぜ)は
  同じで、違いは「根拠の申請があるかどうか」だけ。Bではイベント・Projector・集約メソッドが2つずつに
  なり、片方だけ直すずれが起きやすい。既存イベントの再生への影響は無い(設計前提の検証)。
- 未確定・要確認事項: なし。

### 論点2: APIをどうするか

- 選択肢:
  - A. 既存の`POST /attendance-months/{id}/revert-confirmation`で、`workflow_request_id`を任意にする
    (`nullable`・`exists`)。
  - B. 直接取消用の新しいエンドポイントを作る。
- 決定: A。
- 理由: 権限(`attendance.confirmation_revert`)も処理も同じ。`reason`は従来どおり必須で、これを
  社員へのメッセージとして使う。
- 未確定・要確認事項: なし。

### 論点3: メッセージ(取消理由)を社員の画面にどう出すか

- 選択肢:
  - A. `attendance_months`に`revert_comment`(text, nullable)を追加する。Projectorが確定取消イベントの
    `reason`を入れ、提出(`AttendanceMonthSubmitted`)でnullに戻す。
  - B. 既存の`return_comment`に入れる。
  - C. 列を持たず、画面表示のたびに`stored_events`から最新の確定取消を探す。
- 決定: A。
- 理由: Bは「差戻し理由」と「確定取消のメッセージ」で列の意味が二重になる(設計前提の検証)。
  Cは画面からイベントストアを直接読むことになり、Projectionで読む既存の方式と合わない。
  AならProjectorのリビルドで同じ結果を再現できる。
- 表示する経路: 申請ありの取消も、申請なしの取消も、同じように表示する。直接取消ではバックオフィス担当者の
  メッセージ、申請経由では申請者本人が書いた取消理由になる。本人の理由が本人に見えるだけなので問題ない。
  ラベルは、どちらにも当てはまる「未提出に戻した理由」にする。
- 既存データ: migrationで列を追加した後、`php artisan projections:rebuild AttendanceMonthProjector`を
  実行する。過去に確定取消されて未提出のままの月にも、イベントの`reason`が入る。ReadModelを
  直接書き換えるデータ補正はしない(原則2、`data-correction`)。
- 未確定・要確認事項: なし。

### 論点4: 通知

- 選択肢:
  - A. `RevertApprovedAttendanceMonthHandler`で、集約の保存後に月の持ち主へ
    `SendNotificationJob::enqueue`する(差戻しと同じ方式、`add-notification`スキル)。
  - B. 確定取消イベントを購読するReactorで通知する。
- 決定: A。
- 理由: 既存の差戻し・タスク作成の通知はどれもHandlerから送っている。Bはリビルド(再生)のときに
  通知が再送されないよう、別の対策が要る。
- 内容:
  - title: `月次勤怠が未提出に戻されました`
  - summary: `{year_month}の月次勤怠が未提出に戻されました。理由: {reason}`
  - detailUrl: `FrontendUrl::path("/attendance/months/{year_month}")`
- 申請ありの取消(論点5の即時取消と、既存タスクからの取消)でも、同じ通知を送る。
- 未確定・要確認事項: なし。

### 論点5: 勤怠確定取消依頼の承認で即時に未提出に戻す

- 選択肢:
  - A. ワークフロー側に同期Reactor(`AttendanceMonthRevertOnConfirmationRevertRequestApprovedReactor`)を
    追加する。`WorkflowRequestApproved`を受け、申請種別が`attendance_confirmation_revert`のときに勤怠側の
    Commandを発行する。承認と同じトランザクションで実行する。
  - B. 承認後にバックオフィスタスクを作り、自動で処理する。
- 決定: A。
- 理由: 既存の差戻し・承認の連携(`AttendanceMonthReturnOnWorkflowRequestReturnedReactor`・
  `AttendanceMonthApprovalOnWorkflowRequestApprovedReactor`)と同じ形で、原則15の同期Reactorに沿う。
  Bは不要なタスクを経由するだけになる。
- 申請の対象: 申請者本人の月だけ(ユーザー回答)。form_schemaに対象社員の欄は加えない。
  UC-A018の「(または代理で人事部)」を削る。人事部が戻すときは論点6の直接取消を使う。
- Commandの形: 勤怠側に`RevertApprovedAttendanceMonthByRequest`を新しく作る。
  - 項目: `userId`(申請者)・`yearMonth`(`form_data.target_year_month`)・`reason`(`form_data.reason`)・
    `workflowRequestId`・`revertedByUserId`(承認者。`WorkflowRequestApproved::$approvedByUserId`)。
  - Handlerは`user_id`と`year_month`で`AttendanceMonth`を検索する。無ければ`DomainRuleException`
    (`findOrFail`の404にはしない)。`AttendanceMonth::resolveIdFor`は、行が無いと新しいUUIDを採番するので
    使わない。見つかれば、既存の確定取消と同じ集約操作
    (`revertConfirmation`)を行う。
  - ワークフロー側で`attendance_months`を読まないために、既存のCommand(月のIDを受け取る)とは別にした。
- 月の状態ごとの扱い:
  - `approved`: 確定を取り消す(未提出に戻す)。
  - `not_submitted`・`returned`: 何もせず正常終了する。すでに編集できる状態なので目的は達している。
    同じ月を、承認待ちの間にバックオフィスが直接戻した場合などがこれに当たる(原則15の冪等性)。
  - `submitted`: `DomainRuleException`。文言は「対象の月次勤怠(YYYY-MM)は承認待ちのため、確定を取り消せません。承認者に差戻しを依頼してください。」
  - `closed`: `DomainRuleException`。文言は「対象の月次勤怠(YYYY-MM)は締め済みです。先に管理者による締め取消が必要です。」
  - 月が無い、または`target_year_month`が`YYYY-MM`形式でない: `DomainRuleException`。文言は「対象の月次勤怠(YYYY-MM)が見つかりません。」
- 例外のときは、同期Reactorなので承認ごとロールバックされ、APIは422を返す(`CommandBus::dispatch`の
  `DB::transaction`、`DomainRuleException`→422)。承認者は差戻しで社員に直させる。
  承認されたのに戻らない、という状態を作らない。
- 承認は1回で確定する(`WorkflowRequestAggregate::approve`。複数段の承認は無い)。
- 自己承認(ユーザー回答で許可): 承認者に本人を指定すると、提出と同時に承認が記録される
  (`SubmitWorkflowRequestHandler`64-72行目)。そのため即時取消も提出の時点で起き、エラーは提出API
  (`/submit`)で返る。
- 承認者が自動承認(`approvedByUserId`がnull)になることは、この申請種別では無い。nullの場合は
  Handlerが`DomainRuleException`を投げる。
- 権限: 承認できること自体が根拠になるため、`attendance.confirmation_revert`は確かめない
  (差戻しの連携と同じ)。
- バックオフィスタスク: この申請種別は、承認してもタスクを作らないようにする。
  - `RequestTypeSeeder`の`requires_backoffice_task`を`false`にし、`backoffice_task_type`・
    `backoffice_department`をnullにする。`description`も「承認されると、締め前(承認済み)の月次勤怠が
    未提出に戻り、日次実績を修正できるようになる依頼」に変える。
  - 既存の環境の`request_types`の行は、migrationで同じ値に更新する(`request_types`はイベントで管理しない
    マスタで、管理画面からも直接更新している)。
  - すでに作られて未処理の確定取消タスクのために、`BackOfficeTaskDetailPage`の
    `AttendanceConfirmationRevertSection`は残す。
- 通知: 申請者には、ワークフローの承認通知が届く。それに加えて「月次勤怠が未提出に戻されました」も送る。
  経路によって通知が変わらないようにするため(論点4)。
- 社員の画面の案内文(前回の変更セットで追加)を、「勤怠確定取消依頼を申請してください（承認されると
  未提出に戻ります）。」に変える。
- 未確定・要確認事項: なし。

### 論点6: 画面(バックオフィス側)

- 決定(ユーザー回答): 締め処理タスクの詳細と、勤怠参照画面の「管理者操作」の両方に置く。
- 共通部品: 確認ダイアログを`frontend/src/components/RevertMonthConfirmationDialog/`に新しく作り、
  両方の画面で使う(`add-frontend-component`)。既存の`ReopenMonthDialog`と同じ構成にする
  (`ConfirmActionDialog`、メッセージの入力が必須、破壊的操作として確認を挟む)。
  - ボタン: 「未提出に戻す」(secondary)
  - タイトル: 「月次勤怠を未提出に戻しますか?」
  - 説明: 「{yearMonth}の月次勤怠を未提出に戻します。社員は日次実績を編集して再提出できるようになります。入力したメッセージは社員の月次勤怠画面に表示され、通知でも送られます。」
  - 入力欄: 「社員へのメッセージ」(必須、Textarea)
  - 確定ボタン: 「未提出に戻す」
  - 送信: `workflow_request_id`なしで`revertMonthConfirmation`を呼ぶ。
- 表示条件:
  - 締め処理タスクの詳細: 月が`approved`で、`attendance.confirmation_revert`がある場合。
  - 勤怠参照画面: 同じ条件で、「管理者操作」カードに「締める」と並べて表示する。カードの表示条件に
    この場合を加える。
- 締め処理タスクの状態は変えない。再承認されると、同じタスクが引き続き使われる(既存の重複防止)。
- 未確定・要確認事項: なし。

### 論点7: 権限

- 決定(ユーザー回答): 人事担当ロールの初期権限に`attendance.confirmation_revert`を追加する
  (`AccessControlSeeder`)。
- 既存の環境: シーダーは権限行がまだ無いロールにだけ設定するため、既存の環境には反映されない。
  リリース手順として、管理画面のロール権限設定で追加する旨を記載する。
- 実行できる範囲(ユーザー回答): 制限しない。権限があれば、全社員の月と自分の月を戻せる。ルートの
  `permission:attendance.confirmation_revert,any`のままとし、Handlerで対象の社員を確かめることはしない。
  ただし、画面に月を表示するには、従来どおり対象社員の`attendance.read`が要る。
  原則13の「申請者自身には行わせない」は締め後(ロック後)の巻戻しが対象で、締め前の`approved`には当たらない。
- バックオフィス担当ロールの`attendance.read`は追加しない(ユーザー回答)。バックオフィス担当ロールだけの利用者は、
  これまでどおり他の社員の月を表示できない(既知の制約)。
- 未確定・要確認事項: なし。

## 仕様確定事項(まとめ)

### backend

1. `RevertApprovedAttendanceMonth::$workflowRequestId`を`?string`にする。
   `AttendanceMonthConfirmationReverted::$workflowRequestId`と
   `AttendanceMonthAggregate::revertConfirmation`の引数も`?string`にする。
2. `RevertApprovedAttendanceMonthHandler`:
   - `workflowRequestId`がnullでないときだけ`WorkflowRequest::findOrFail`する。
   - statusの検証(`approved`のみ)は従来どおり。
   - 保存後、月の持ち主に通知する(論点4の文言)。
3. `AttendanceController::revertMonthConfirmation`: `workflow_request_id`を
   `['nullable', 'string', 'exists:workflow_requests,id']`にする。OpenAPIの記述も合わせる。
4. migration: `attendance_months.revert_comment`(text, nullable、`return_comment`の後)を追加する。
   down()で削除する。
5. `AttendanceMonthProjector`:
   - 確定取消で`revert_comment = $event->reason`にする。
   - `AttendanceMonthSubmitted`(新規作成・再提出)で`revert_comment = null`にする。
   - 差戻し・締め取消など他のイベントでは変えない。
6. `AttendanceMonth`モデル・`AttendanceMonthResource`に`revert_comment`を加える。
7. `AccessControlSeeder`: `Role::HR_STAFF`に`attendance.confirmation_revert`を追加する。
8. 論点5:
   - `RevertApprovedAttendanceMonthByRequest`(Command・Handler)を新しく作る。
   - `app/Domain/Workflow/Reactors/AttendanceMonthRevertOnConfirmationRevertRequestApprovedReactor.php`を
     新しく作る。Reactorは自動検出・同期で動く(`config/event-sourcing.php`)。新しいCommand→Handlerは
     `config/domain.php`に登録する。
   - 申請種別のコードは、Reactorの定数`REQUEST_TYPE_CODE = 'attendance_confirmation_revert'`で判定する。
   - `RequestTypeSeeder`を変更し、既存の行を更新するmigrationを追加する(論点5)。
9. テスト(`AttendanceMonthRescueCommandsTest`に追加。`domain-test`):
   - 申請なしで、バックオフィス担当者が承認済みの月を戻せる。statusが`not_submitted`になり、
     `revert_comment`がメッセージになり、日次のロックが外れる。
   - 戻した後、日次を編集して再提出できる。再提出で`revert_comment`がnullになる。
   - 申請なしで戻すと、通知が月の持ち主宛てに1件積まれる(申請ありでも同じ)。テストは`QUEUE_CONNECTION=sync`
     (`phpunit.xml`)で動くので、`Queue::fake()`でジョブを確かめるか、`notifications`の行で確かめる。
   - 自分の月も戻せる(実行範囲を制限しない、ユーザー回答)。
   - `approved`以外(`submitted`・`closed`・`not_submitted`)は422などで拒否される。既存のテストを流用してよい。
   - 従業員だけのユーザーは403になる。
   - 存在しない`workflow_request_id`を渡すと422になる。
   - 申請ありの既存の経路が引き続き動く(既存テスト)。
   - `projections:rebuild`(またはProjectorの再生)の後も、`revert_comment`が同じ値になる。
   - 人事担当ロール(シーダーの初期権限)で戻せる。
10. シナリオテスト(新規`tests/Feature/Attendance/AttendanceConfirmationRevertRequestFlowTest.php`):
   - 提出→承認済みの月について、社員が勤怠確定取消依頼を作成・提出し、承認者が承認する
     (APIで操作する)。その結果、次のようになること。
     - 月が`not_submitted`になる。
     - `revert_comment`が申請の取消理由になる。
     - 日次のロックが外れる。
     - バックオフィスタスクは作られない。
     - 申請は`approved`になる。
     - 確定取消イベントの`workflowRequestId`が申請のIDになる。
   - 対象の月が`submitted`・`closed`・存在しない・`target_year_month`が形式外の場合:
     - 承認APIが422と論点5の文言を返す。
     - 申請は`submitted`のまま、月の状態も変わらない。
     - `stored_events`・通知・`backoffice_tasks`が増えていない。
   - 対象の月がすでに`not_submitted`(承認待ちの間に直接取消された)の場合: 承認は成功し、月は変わらず、
     確定取消イベントは追加されない。
   - 自己承認(承認者=申請者)で提出すると、提出APIの時点で月が`not_submitted`になる。
     対象の月が`closed`なら、提出APIが422を返して何も変わらない。
   - 他の申請種別の承認では、勤怠に何も起きない。

### frontend

11. `api/attendance.ts`: `revertMonthConfirmation(id, reason, workflowRequestId?: string)`にする。
   未指定ならbodyに`workflow_request_id`を含めない。`useRevertMonthConfirmation`の引数も任意にする。
12. `api/types.ts`の`AttendanceMonth`に`revert_comment: string | null`を追加する。
13. `components/RevertMonthConfirmationDialog/`を、component・story・testの一式で新しく作る(論点6)。
14. `BackOfficeTaskDetailPage`の`AttendanceMonthConfirmationSection`: 論点6の条件で、参照タブの上に
    ダイアログのボタンを表示する。
15. `AttendanceReferencePage`の「管理者操作」: 論点6の条件でボタンを表示する。
16. `AttendanceMonthDetailPage`: 承認済みの月の案内文を論点5の文言に変える(テストの期待値も直す)。
    `month.status === 'not_submitted'`かつ`revert_comment`があるとき、
    差戻し理由と同じ位置・同じ見た目(warning)で「未提出に戻した理由: {revert_comment}」を表示する。

## 受け入れ条件

- バックオフィス担当者(`attendance.confirmation_revert`と、対象社員の`attendance.read`を持つ)が、
  承認済みの月を申請なしで未提出に戻せる。締め処理タスクの詳細と、勤怠参照画面の両方から操作できる。
- 戻すにはメッセージの入力が必須。空では実行できない(画面とAPIの両方)。
- 戻した後、社員の月次勤怠画面に「未提出に戻した理由」が表示され、社員に通知が届く(通知ジョブが積まれる)。
  再提出すると、理由の表示は消える。
- 承認済み以外の月では、ボタンが出ず、APIも拒否する。
- 勤怠確定取消依頼が承認されると(自己承認では提出の時点で)、その時点で月が未提出に戻り、バックオフィスタスクは作られない。
  対象の月が承認待ち・締め済み・見つからないときは、承認がエラーになり何も変わらない。すでに未提出・差戻し中なら、承認は成功し月は変わらない。
- 人事担当・バックオフィス担当など確定取消の権限を持つ人は、自分の月を含め全社員の月を戻せる(画面に月を表示するには、その社員の勤怠閲覧権限が要る)。
- 人事担当ロールの初期権限に`attendance.confirmation_revert`が含まれる。
- Projectorのリビルドの後も、`revert_comment`が同じになる。
- backendの確定取消のテスト・シナリオテスト(仕様確定事項9・10)と、frontendの関連テスト・型チェック・lintがPASSする。

## 対象外

- 締め済み(`closed`)の月を直接未提出に戻すこと(締め取消→未提出の2段階のまま)。
- 元の提出の申請(`subject_type=attendance_month`)の状態を変えること(`approved`のまま。再提出で新しい
  申請が作られる)。
- 締め処理タスクの状態の自動変更。
- バックオフィス担当ロールへの`attendance.read`の付与。
- 既存の、承認済みで未処理の確定取消タスクの自動処理(タスク画面から従来どおり処理する)。
- 申請作成時(提出時)の`target_year_month`の検証(承認時に検証する)。
- 管理画面での申請種別の変更への備え。`code`を変えると即時取消は動かなくなり、`requires_backoffice_task`を
  `true`に戻すとタスクが作られる(そのタスクの取消は、すでに戻っているため422になる)。運用で変更しない。
- 承認済みの確定取消依頼の取消: できない(`cancellable()`はdraft・submitted・returnedだけ)。
  差戻し: 勤怠には何も起きない(`subject_type`がnull)。MCPには承認・取消のツールが無い。いずれも対応不要。
- 承認者への通知。

## ドキュメントへの影響

- `docs/07-usecases-attendance.md` UC-A018: 次の点を追記・修正する。
  - 手順を「承認されると即時に未提出に戻る」に書き換える。取り消せないときは承認がエラーになることも書く。
  - 申請なしでバックオフィス担当者が直接戻す経路(メッセージ必須・社員への表示と通知)。
  - 実行できるロールの記述(確定取消の権限を持つ人。初期設定ではバックオフィス担当・人事担当・システム管理者)。
  - 手順1の「(または代理で人事部)」を削り、本人の申請だけにする。人事部は直接取消を使う。
  - 前回の変更セットで手順1に追記した文(月次勤怠画面からの導線)は残す。
- `docs/11-usecases-backoffice.md`95-106行目: 確定取消依頼でタスクが作られなくなったことを反映し、
  締め処理タスクから未提出に戻せることを追記する。
- `docs/10-usecases-workflow.md`: 勤怠確定取消依頼の承認で、勤怠が即時に未提出に戻ることを追記する
  (申請種別の説明がある箇所)。
- `docs/16-database-schema.md` `attendance_months`: `return_comment`(漏れていた分)と`revert_comment`を追加する。
- `docs/17-events.md` `attendance.month_confirmation_reverted`: `workflow_request_id`は申請なしのときnullになる、と追記する。
- `docs/13-usecases-notification.md`: 通知の種類に「月次勤怠が未提出に戻された」を追加する(一覧があれば)。

## モック・アセット

なし(既存の`ReopenMonthDialog`・差戻し理由ブロックと同じ構成のため)。

## 実装対象

- backend:
  - `app/Domain/Attendance/Commands/RevertApprovedAttendanceMonth.php`
  - `app/Domain/Attendance/Events/AttendanceMonthConfirmationReverted.php`
  - `app/Domain/Attendance/Aggregates/AttendanceMonthAggregate.php`
  - `app/Domain/Attendance/Handlers/RevertApprovedAttendanceMonthHandler.php`
  - `app/Domain/Attendance/Projectors/AttendanceMonthProjector.php`
  - `app/Http/Controllers/Api/AttendanceController.php`
  - `AttendanceMonth`モデル、`AttendanceMonthResource`
  - 新しいmigration
  - `database/seeders/AccessControlSeeder.php`
  - `tests/Feature/Attendance/AttendanceMonthRescueCommandsTest.php`
  - 新規: `app/Domain/Attendance/Commands/RevertApprovedAttendanceMonthByRequest.php`・Handler、
    `app/Domain/Workflow/Reactors/AttendanceMonthRevertOnConfirmationRevertRequestApprovedReactor.php`、
    request_typesを更新するmigration、`tests/Feature/Attendance/AttendanceConfirmationRevertRequestFlowTest.php`
  - `database/seeders/RequestTypeSeeder.php`
  - コメント・OpenAPIの記述の更新: `RevertApprovedAttendanceMonthHandler`・Command・Eventの説明、
    `AttendanceMonthProjector`の確定取消の箇所、`AttendanceController`のOpenAPI summary(「バックオフィス担当者専用」の表現)、
    `routes/api.php`397行目付近のコメント
  - `config/domain.php`
- frontend:
  - `api/attendance.ts`、`api/types.ts`、`hooks/useAttendance.ts`
  - `components/RevertMonthConfirmationDialog/`(新規)
  - `pages/backOffice/BackOfficeTaskDetailPage.tsx`・`.test.tsx`
  - `pages/attendance/AttendanceReferencePage.tsx`・`.test.tsx`
  - `pages/attendance/AttendanceMonthDetailPage.tsx`・`.test.tsx`
- docs: 上記「ドキュメントへの影響」のファイル。

## 検証方法

```
cd backend
./vendor/bin/phpunit tests/Feature/Attendance/AttendanceMonthRescueCommandsTest.php
./vendor/bin/phpunit tests/Feature/Attendance
cp .env.example .env && php artisan key:generate && php artisan test && rm .env database/database.sqlite   # CIと同じ条件で全件

cd ../frontend
npx tsc -p tsconfig.app.json --noEmit
npm run lint
npm test -- src/components/RevertMonthConfirmationDialog src/pages/backOffice src/pages/attendance
```

リリース手順(「実装結果」に記載する):
1. migrationを実行する。
2. `php artisan projections:rebuild AttendanceMonthProjector`を実行する。`stored_events`の全件を再生するため、
   事前に件数を見て、所要時間を見積もっておく。
3. 既存の未処理の確定取消タスクは、タスク画面から従来どおり処理する。
4. 既存の環境では、人事担当ロールに`attendance.confirmation_revert`を管理画面から追加する。

## レビュー履歴

- 初版。
- 2026-10-25 ユーザーの追加指示(「承認したら、即時未提出に変更してください」)で、論点5を追加した。
  既存の流れを「残す」としていた回答を変更し、申請の承認で即時に戻す形にした。
- 2026-10-25 独立設計レビュー(general-purposeエージェント)。即時取消で例外を投げたときに承認ごと
  ロールバックされることは、コードで確認できた。指摘への対応:
  - ユーザーに確認して決めたもの(must-fix):
    - 代理申請 → 本人の申請だけにする。
    - 自己承認 → 許可する。提出APIの時点で戻るケースをテストに追加。
    - 直接取消の範囲 → 制限しない。
  - 仕様に反映したもの(should-fix):
    - 冪等性(`not_submitted`・`returned`なら何もしない)。
    - 状態ごとのエラー文言(締め済みの案内を含む)。
    - 申請種別の`description`の更新。
    - 理由の表示が経路によって誰の入力かの明記。
    - テストでの通知の確かめ方とロールバックの確認。
    - 複数段承認の記述の削除。
    - `config/domain.php`・コメントの更新。
    - 月の検索方法。
    - リビルドの所要時間。
  - 対象外として記録したもの:
    - 管理画面での申請種別の変更。
    - 承認済み依頼の取消・差戻し・MCP(影響なし)。
  - バックオフィス担当ロールの閲覧権限 → ユーザー回答で人事担当で運用。

## 実装結果

未着手。
