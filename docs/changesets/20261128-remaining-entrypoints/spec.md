# remaining-entrypoints(原則17・15の残りの入口と、見つかった候補の是正)

ステータス: 検討中

## 変更要望(原文)
> 同様に修正をお願いします。問題があれば聞いてください

対象(2026-11-28 の「残っている項目」): 原則17の残りの入口と、作業の途中で見つかった候補。付与ルール・付与表のイベント化はデータ移行を含むので別の変更セット(`20261128-grant-rules-events`)で扱う。期間の休暇の申請は段階③(`20261128-period-leave-request`)。

月次勤怠の承認・差戻し(ユーザーの回答 2026-11-28): ワークフローの導入(2026-08-01、`5b2a2488`)の後の8月分から運用しており、ワークフローの無い提出済みの月は無い。古い直接の経路は残っているだけなので、データ移行はせずに分岐を外す。

## 現状(As-Is)(調査 2026-11-28、investigator)
| # | 入口・件 | 今の作り | 問題 |
|---|---|---|---|
| 1 | `UserController::store`(利用者の手動作成) | `CreateUser` →(`group_id` があれば)`AddMembership` を、外側のトランザクション無しで並べる | 所属の追加が失敗すると利用者だけ残る |
| 2 | `CompanyCalendarController::store`(`POST /company-calendars`) | `CreateCompanyCalendar` →(`fiscal_year` があれば)`CreateCompanyCalendarYear`。外側のトランザクション無し | 年度の作成の失敗でカレンダーだけ残る |
| 3 | `CompanyCalendarController::regenerateYear`(`POST /company-calendar-years/{y}/regenerate`) | Controller で日を生成(`CalendarWeekdayPatternDayGenerator`)し、`UpdateCompanyCalendarDays` →(休日のソースがあれば)`SyncHolidayCalendarSource` | 2つ目の失敗で1つ目が残る。日の生成(業務の処理)が Controller にある |
| 4 | `DeviceAdminController::bootstrapRegisterKey`(`POST /devices/me/admin-bootstrap/authentication-keys`) | `StartDeviceAdminSessionBootstrap` → その結果の管理者で `IssueAuthenticationKey`。外側のトランザクション無し。画面からは呼ばれていない | 2つ目の失敗で管理者のセッションだけ残る。端末と認証キーは別の文脈(原則12) |
| 5 | グループの作成と初期ロール(`GroupDetailPage.tsx:258-275`) | 画面が `POST /groups`(`storeGroup`、権限 `group.create`)の成功の後に `POST /role-assignments`(`storeRoleAssignment`、権限 `role.assign`)を呼ぶ | 作成は成功し割当が失敗すると、グループだけ残り、画面に何も出ず移動もしない |
| 6 | `GroupFeatureSyncService`(グループへの Feature の同期) | `CreateRoleAssignmentHandler`・`UpdateRoleAssignmentHandler`・`RemoveRoleAssignmentHandler`・`ChangeRoleFeaturesHandler`・`UpdateRoleDefinitionHandler` が、サービス経由で `AssignFeatureToGroupHandler`・`RemoveFeatureFromGroupHandler` を直接呼ぶ(CommandBus も Reactor も通らない)。運用コマンド `ResyncGroupFeaturesCommand` も使う。`AssignFeatureToGroupHandler` は付与済みなら例外(冪等でない) | 原則15(文脈の中でも、別の集約への連鎖は Reactor で) |
| 7 | 経費の明細と添付(`ExpenseClaimNewPage.tsx`) | 明細の追加(`addItem`/`addItemsBulk`)の成功の後に、添付を別の API(`POST /attachments`)で送る | 添付が失敗すると入力が残り、もう一度保存すると明細が二重になる |
| 9 | 月次勤怠の承認・差戻し(`AttendanceController::approveMonth`・`returnMonth` :885-927) | ワークフローの提出済みの申請があれば `ApproveWorkflowRequest`・`ReturnWorkflowRequest`、無ければ `ApproveAttendanceMonth`・`ReturnAttendanceMonth` を直接(ワークフローの導入前に提出された月のため) | データの有無で入口を選ぶ分岐が Controller にある。古い経路は通知も出ない |
| 8 | 休暇の取消の置き場所 | 各休暇の画面(有給・特別休暇・代休)の申請一覧は申請中・差戻しだけ、勤怠の日の画面は申請中・承認済みだけを取り消せる。backend は申請中・差戻し・承認済みを取り消せる | 承認済みの休暇は勤怠の日の画面からしか取り消せない |
- 確認の結果、候補から外したもの: ワークフローの Reactor が `workflow_requests` を読む件は、ワークフローの文脈が自分のテーブルを読んでいるので原則15に反しない。

## 設計前提の検証
- 正データと派生データ: どの件も記録するイベントは今と同じ(#6 は Feature の付与のイベントを Reactor から記録する、#5・#7 はイベントに項目を足す)。
- 原則12(端末・認証キー・連携は別の文脈)、原則15、原則17。
- 横展開: 同じ「画面が2つの API を順に呼ぶ」作りが他にあるかを実装時に grep で確かめ、見つかれば実装結果に記録する(本変更で直すか別の候補か)。

## 仕様検討

### 論点1: 利用者の手動作成と所属(#1)
- 決定: `CreateUser` に任意の `groupId` を足し、Handler が利用者の作成と所属の追加を記録する(どちらも UserManagement の文脈。今も `CreateUserHandler` は利用者と全員のグループへの所属を記録している)。所属の検査は ReadModel を読むので、全員のグループへの所属を先に persist してから検査する。1つでも拒否なら全体を戻す。`group_id` が全員のグループのときは作成全体を拒否する(今は利用者を作った後に 422 で、利用者が残る)。
- 未確定・要確認事項: なし

### 論点2: カレンダーの作成と年度(#2)・年度の作り直し(#3)
- 決定:
  - `CreateCompanyCalendar` に任意の `fiscalYear` を足し、Handler がカレンダーと年度を記録する。
  - `RegenerateCompanyCalendarYear`(年度1つ)を新しく作り、Handler が下書きの検査(今は Controller)・日の生成(`CalendarWeekdayPatternDayGenerator`)と日の更新、休日のソースがあればその同期の記録まで行う(同じ文脈)。休日のソースの取得・解析の失敗は今と同じく拒否にせず、日の更新は残して同期の失敗を記録する。
  - カレンダーの作成と年度: 年度の検査がカレンダーの ReadModel を読むなら、同じ Handler の中でカレンダーを先に persist する。
- 未確定・要確認事項: なし

### 論点3: 端末の管理者の初回の登録と認証キー(#4)
- 事実(設計レビューで確認): `IssueAuthenticationKey` は生の値を受け、Handler でハッシュ(HMAC-SHA256、純粋な関数)にして、ハッシュだけを保存する。キーの UUID は Handler の中で乱数で作る。重複の検査は利用者で絞らず、同じハッシュの有効なキーがあれば誰のものでも拒否する。
- 決定:
  - 入口で認証キーの ID を作り、`StartDeviceAdminSessionBootstrap` に `authenticationKeyId`・`keyType`・`displayName`・`rawKeyValue` を渡す。Handler がハッシュにし、管理者のセッションの開始のイベントに任意の項目(最後の引数・既定値付き)として `authenticationKeyId`・`keyType`・`displayName`・`keyHash` を記録する(生の値はイベントに入れない)。
  - 認証キーの文脈の Reactor がそのイベントを受け、`IssueAuthenticationKey`(`authenticationKeyId`・`keyHash`・`viaReactor`)を発行する。`IssueAuthenticationKey` にハッシュを直接受ける形を足す(生の値の形は残す)。冪等の判定は「その ID の集約が発行済みか」だけにし、他のキーとの重複は `viaReactor` でも拒否する(全体が戻る)。
  - 応答は `AuthenticationKey::find(authenticationKeyId)` から返す。セッションの `authentication_key_id` に登録したキーを入れる。
- 未確定・要確認事項: なし

### 論点4: グループの作成と初期ロール(#5)
- 決定: `CreateGroup` に任意の `initialRoleId` を足し、グループの作成のイベントに初期ロールを記録する。AccessControl の文脈の Reactor がそのイベントを受け、`CreateRoleAssignment`(`viaReactor`、`initiatedByUserId`)を発行する。全体が1つのトランザクションで、割当が拒否されるとグループの作成も戻る。
- 認可: 入口で `group.create` に加え、初期ロールがあるときは全社の `role.assign`(`hasGlobalPermission`。今の割当の API が全社の割当に要する権限と同じ)を確かめる。
- 範囲: Reactor が作る割当は全社(global)・配下を含まない・期間なしに固定する(今の画面と同じ)。割当の ID はグループの ID から決まる uuid5 にし、`viaReactor` のとき集約が作成済みなら何もしない(冪等)。グループの作成の Projector は初期ロールの項目から割当を作らない(割当は割当のイベントで記録される)。
- 割当の拒否(キーの無い `DomainRuleException`)は `initial_role_id` の項目のエラーに結び付ける。
- 画面は1回の API(本文に `initial_role_id`)にする。失敗は項目のエラー・まとめての表示に出る。
- 未確定・要確認事項: なし

### 論点5: グループへの Feature の同期(#6)
- 決定: `SyncGroupFeatures(groupId)` の Command を1つ作る。Handler は差分の計算(今の `GroupFeatureSyncService` の計算)をして、グループの Feature の集約(1グループ=1集約)に差分を1回で記録する(差分が無ければ何もしない。そのまま冪等)。AccessControl の文脈に Reactor を作り、ロールの割当の作成・更新・削除、ロールの Feature の変更(`RoleFeaturesChanged`)のイベントを受けて、対象のグループごとに `SyncGroupFeatures` を発行する。5つの Handler からサービスの呼び出しを外す。運用コマンドの再同期も `SyncGroupFeatures` を発行する。
- 基準(論点1・2との違い): 1回の利用者の意図で同時に決まる別の集約は Handler で直接記録する(論点1・2、段階①の週の振分)。複数の Command から起きる派生の同期は Reactor にする(本論点)。
- 今と同じく反映しないもの(対象外): 無効のロール、割当の期間の開始・終了の到来、グループの再有効化。運用コマンドの再同期は管理画面の運用コマンドにも出す(`AdminExecutable`)。
- 未確定・要確認事項: なし

### 論点6: 経費の明細と添付(#7)
- 決定: 明細の追加の API(1件 `POST /expense-claims/{id}/items`・まとめて `…/items/bulk`)で、明細ごとの添付のファイルを一緒に受け取る(multipart。まとめてのときは `items` を JSON の文字列で送り、ファイルは `files[{行の番号}][]`)。
  - 添付の大きさ・拡張子の規則(今は `AttachmentController` にある)を、添付の文脈の判定クラスに出す。今の添付の API と新しい入口の両方から、ファイルを保存する前に使う。検証に通らなければ何も保存しない(422)。
  - 入口で明細の ID と添付の ID を作って Command に渡し、ファイルを `attachments/expense_items/{明細の ID}` に保存する(今と同じ置き場所)。Command(`AddExpenseItem`・`AddExpenseItems`)は添付の参照(`attachmentId`・`storedPath`・`fileName`・`mimeType`・`fileSize`・`uploadedByUserId`)を持ち、Handler が明細の追加を記録する。明細の追加のイベントに添付の参照の一覧を任意の項目(最後の引数・既定値は空)として足す。
  - 添付の文脈の Reactor が明細の追加のイベントを受け、`UploadAttachment`(添付の ID を受け取る形を足し、`viaReactor`)で添付を記録する。添付の ID が既にあれば何もしない。全体が1つのトランザクション。
  - Command が拒否された・例外が出たときは、入口が `Throwable` を捕まえて保存したファイルをすべて消してから投げ直す。
- 画面: 明細と添付を1回で送る。添付だけ失敗して明細が残る、という状態が無くなる。
- 添付の単独の API(後から添付を足す・消す)は残す。
- 未確定・要確認事項: なし

### 論点7: 休暇の取消の置き場所(#8)
- ユーザーの判断(2026-11-28): 承認済みの休暇の取消は、休暇の種類の承認の設定に従う(承認不要なら直接、承認が必要なら取消の申請)。差戻しは最終の結果で、再申請は新しい申請。
- 決定: 本論点(取消の置き場所)は、取消の申請・差戻しの扱いと一体で決める必要があるので、段階③の変更セット(`20261128-period-leave-request`)へ移す。本変更では扱わない。
- 未確定・要確認事項: なし

### 論点8: 月次勤怠の承認・差戻し(#9)
- 決定: `approveMonth`・`returnMonth` は、提出済みのワークフローの申請に対する `ApproveWorkflowRequest`・`ReturnWorkflowRequest` だけを dispatch する(直接の `ApproveAttendanceMonth`・`ReturnAttendanceMonth` の分岐を外す)。ワークフローの申請が無い月は 422「この月の申請が見つかりません。」。`ApproveAttendanceMonth`・`ReturnAttendanceMonth` は、ワークフローの承認・差戻しのイベントを受ける Reactor からだけ使う(入口からは使わない)。
- データ移行はしない(ユーザーの回答: 8月分からの運用で、ワークフローの無い提出済みの月は無い)。リリースの手順(docs/27)に、リリースの前に本番で「提出済みでワークフローの無い月」の件数が0であることを確かめる手順を足す(0でなければ、先に承認してもらってからリリースする)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- backend: 論点1〜6の Command・Handler・Reactor と入口の変更。新しいイベントの項目(論点4の初期ロール、論点6の添付の参照)は既存のイベントに任意の項目として足し、`docs/17-events.md` を更新する。`docs/03-architecture.md` 3.10 の既存の例外から是正したものを外す。
- frontend: グループの作成(初期ロールを1回で)、経費の明細の保存(添付を1回で)。
- テスト(`domain-test`):
  - 各入口: 入口1回=1つの Command(`assertSingleTopLevelCommand`)、2つ目の処理が拒否されると何も残らない。
  - グループの作成: 初期ロールの割当が拒否されるとグループも残らない、`role.assign` の無い利用者は初期ロール付きで作れない。
  - Feature の同期: ロールの割当・ロールの Feature の変更で、グループの Feature が今と同じ結果になる(`RoleFeatureAutoApplyTest` ほかの回帰)、同じイベントの2回の処理で変わらない。
  - 経費: 明細と添付が1回で記録される、添付の検証の失敗で明細も残らずファイルも消える。
  - 月次勤怠の承認・差戻し: ワークフローの申請の無い月は 422、ワークフロー経由で業務側まで承認・差し戻される(回帰)。
  - 作り直し: 代表のシナリオの後に ReadModel を作り直して同じ状態。

## 受け入れ条件
- #1〜#4 の入口が1回の呼び出しで1つの Command を dispatch し、途中で失敗すると何も残らない。
- グループの作成と初期ロール、経費の明細と添付が、それぞれ1回の API で全部か無しかになる。
- Feature の同期が Reactor で行われ、Handler から他の Handler を直接呼ばない。
- 月次勤怠の承認・差戻しが、ワークフローの経路だけになる。
- 上のテストと全件の単体テスト、変えた画面を通る E2E の spec が通る。

## 対象外
- 付与ルール・付与表のイベント化(`20261128-grant-rules-events`)
- 期間の休暇の申請、休暇の取消の置き場所(`20261128-period-leave-request`)
- 経費の新規の申請で、精算の作成→明細の追加→題名と複数の API を順に呼ぶ作り(明細が失敗すると空の下書きが残る)。別の候補として記録する。
- 認証キーの管理の権限の docs/23 と実装の食い違い(`authentication_key.manage` と `device.manage`)。別の候補。

## ドキュメントへの影響
- `docs/03-architecture.md` 3.10、`docs/17-events.md`(イベントの項目の追加)
- 利用者・グループ(docs/15)、カレンダー(docs/08)、端末(docs/23)、経費(docs/12 等)、月次勤怠(docs/07)のユースケースの該当の節(実装時に grep で特定)
- `docs/27-release-runbook.md`(論点8のリリース前の確認)

## モック・アセット
なし

## 実装対象
- backend: `app/Domain/{UserManagement,AccessControl,Attendance(カレンダー),Device,AuthenticationKey,ExpenseClaim,Attachment}`、各 Controller、`app/Console/Commands/ResyncGroupFeaturesCommand.php`、テスト
- frontend: `pages/admin/GroupDetailPage`、`pages/expense/ExpenseClaimNewPage`・`components/SingleExpenseItemForm`・`ExpenseItemsTable`、休暇の3画面、api・hooks

## 検証方法
- backend: CI と同じ条件で全件
- frontend: tsc、単体テスト全件
- E2E: 変えた画面を通る spec(grep で特定)

## レビュー履歴
- 2026-11-28 初版。
- 2026-11-28 実装前の独立設計レビュー(general-purpose)を反映: 端末の初回の登録のキーの ID・ハッシュ・冪等(H1)、初期ロールの全社の権限・範囲・冪等(H2)、添付の検査の置き場所・保存先・参照の項目・冪等(H3)、Feature の同期は `SyncGroupFeatures` 1つに(M1)、反映しないものの明記(M2)、休日のソースの取得の失敗の扱い(M3)、multipart の形とファイルの片付け(M5)、所属・年度の検査の順(L1・L2)、イベントの互換(L6)。
- 2026-11-28 ユーザーの回答で、月次勤怠の承認・差戻しの分岐を外す(論点8。データ移行なし)を加え、休暇の取消の置き場所(論点7)は段階③へ移した。

## 実装結果
未着手
