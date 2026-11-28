# remaining-entrypoints(原則17・15の残りの入口と、見つかった候補の是正)

ステータス: 検討中

## 変更要望(原文)
> 同様に修正をお願いします。問題があれば聞いてください

対象(2026-11-28 の「残っている項目」): 原則17の残りの入口と、作業の途中で見つかった候補。月次勤怠の承認・差戻しの古い月の移行と、付与ルール・付与表のイベント化は、データ移行を含むので別の変更セット(`20261128-data-migrations`)で扱う。期間の休暇の申請は段階③(`20261128-period-leave-request`)。

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
| 8 | 休暇の取消の置き場所 | 各休暇の画面(有給・特別休暇・代休)の申請一覧は申請中・差戻しだけ、勤怠の日の画面は申請中・承認済みだけを取り消せる。backend は申請中・差戻し・承認済みを取り消せる | 承認済みの休暇は勤怠の日の画面からしか取り消せない |
- 確認の結果、候補から外したもの: ワークフローの Reactor が `workflow_requests` を読む件は、ワークフローの文脈が自分のテーブルを読んでいるので原則15に反しない。

## 設計前提の検証
- 正データと派生データ: どの件も記録するイベントは今と同じ(#6 は Feature の付与のイベントを Reactor から記録する、#5・#7 はイベントに項目を足す)。
- 原則12(端末・認証キー・連携は別の文脈)、原則15、原則17。
- 横展開: 同じ「画面が2つの API を順に呼ぶ」作りが他にあるかを実装時に grep で確かめ、見つかれば実装結果に記録する(本変更で直すか別の候補か)。

## 仕様検討

### 論点1: 利用者の手動作成と所属(#1)
- 決定: `CreateUser` に任意の `groupId` を足し、Handler が利用者の作成と所属の追加を記録する(どちらも UserManagement の文脈。所属の検査は今の `AddMembership` の処理を共通にして使う)。1つでも拒否なら全体を戻す。
- 未確定・要確認事項: なし

### 論点2: カレンダーの作成と年度(#2)・年度の作り直し(#3)
- 決定:
  - `CreateCompanyCalendar` に任意の `fiscalYear` を足し、Handler がカレンダーと年度を記録する。
  - `RegenerateCompanyCalendarYear`(年度1つ)を新しく作り、Handler が日の生成(`CalendarWeekdayPatternDayGenerator`)と日の更新、休日のソースがあればその同期の記録まで行う(同じ文脈)。
- 未確定・要確認事項: なし

### 論点3: 端末の管理者の初回の登録と認証キー(#4)
- 決定: `StartDeviceAdminSessionBootstrap` を1つの入口にし、そのイベントを認証キーの文脈の Reactor が受けて `IssueAuthenticationKey`(`viaReactor`、冪等)を発行する(原則12・15)。生のキーの値はイベントに入れない(実装の最初に `IssueAuthenticationKey` がハッシュで保存するかを確かめ、Command に渡すのはハッシュ、応答に必要な値は入口の処理の中で返す)。確かめた結果、生の値が無いと発行できない作りなら、ユーザーに報告する。
- 未確定・要確認事項: なし(上の確認の結果が想定と違う場合のみ報告)

### 論点4: グループの作成と初期ロール(#5)
- 決定: `CreateGroup` に任意の `initialRoleId` を足し、グループの作成のイベントに初期ロールを記録する。AccessControl の文脈の Reactor がそのイベントを受け、`CreateRoleAssignment`(`viaReactor`、`initiatedByUserId`)を発行する。全体が1つのトランザクションで、割当が拒否されるとグループの作成も戻る。
- 認可: 入口で `group.create` に加え、初期ロールがあるときは `role.assign` も確かめる(今の2つの API の権限と同じ)。
- 画面は1回の API(本文に `initial_role_id`)にする。失敗は項目のエラー・まとめての表示に出る。
- 未確定・要確認事項: なし

### 論点5: グループへの Feature の同期(#6)
- 決定: AccessControl の文脈に Reactor を作り、ロールの割当の作成・更新・削除、ロールの Feature の変更(`RoleFeaturesChanged`)のイベントを受けて、対象のグループの差分を `AssignFeatureToGroup`・`RemoveFeatureFromGroup` の Command で記録する。両方の Command に `viaReactor` を足し、既に目的の状態なら何もしない(冪等)。5つの Handler からサービスの呼び出しを外す。運用コマンドの再同期は Command を発行する形にする。
- 差分の計算(`GroupFeatureSyncService` の今の計算)は Reactor から使う判定のクラスとして残す。
- 未確定・要確認事項: なし

### 論点6: 経費の明細と添付(#7)
- 決定: 明細の追加の API(1件 `POST /expense-claims/{id}/items`・まとめて `…/items/bulk`)で、明細ごとの添付のファイルを一緒に受け取る(multipart)。
  - ファイルは、Command の前に今と同じ置き場所へ保存する(今の添付の API と同じ)。Command(`AddExpenseItem`・`AddExpenseItems`)は保存したファイルの参照を持ち、Handler が明細の追加を記録する。添付の文脈の Reactor が明細の追加のイベント(添付の参照を含む)を受けて、`UploadAttachment`(`viaReactor`)で添付を記録する。全体が1つのトランザクション。
  - Command が拒否されたら、保存したファイルを消す(入口の後処理)。
  - 明細の追加のイベントに添付の参照(ファイルの ID・名前・種類・大きさ)を足す。旧イベント(参照なし)はそのまま読める(既定値は空)。
- 画面: 明細と添付を1回で送る。添付だけ失敗して明細が残る、という状態が無くなる。
- 添付の単独の API(後から添付を足す・消す)は残す。
- 未確定・要確認事項: なし

### 論点7: 休暇の取消の置き場所(#8)
- 決定: 各休暇の画面(有給・特別休暇・代休)の申請一覧で、承認済みの休暇も取り消せるようにする(backend と同じく申請中・差戻し・承認済み)。確認の文は勤怠の日の画面と同じ(承認済み「取り消すと、消化した残数が戻ります。」、申請中・差戻し「取り消すと、承認の依頼も取り消されます。」)。締め済みの日は押せず理由を出す。
- 勤怠の日の画面は今のまま(差戻しは休暇ビューに無いので出ない)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- backend: 論点1〜6の Command・Handler・Reactor と入口の変更。新しいイベントの項目(論点4の初期ロール、論点6の添付の参照)は既存のイベントに任意の項目として足し、`docs/17-events.md` を更新する。`docs/03-architecture.md` 3.10 の既存の例外から是正したものを外す。
- frontend: グループの作成(初期ロールを1回で)、経費の明細の保存(添付を1回で)、休暇の3画面の取消。
- テスト(`domain-test`):
  - 各入口: 入口1回=1つの Command(`assertSingleTopLevelCommand`)、2つ目の処理が拒否されると何も残らない。
  - グループの作成: 初期ロールの割当が拒否されるとグループも残らない、`role.assign` の無い利用者は初期ロール付きで作れない。
  - Feature の同期: ロールの割当・ロールの Feature の変更で、グループの Feature が今と同じ結果になる(`RoleFeatureAutoApplyTest` ほかの回帰)、同じイベントの2回の処理で変わらない。
  - 経費: 明細と添付が1回で記録される、添付の検証の失敗で明細も残らずファイルも消える。
  - 休暇の取消: 各画面で承認済みを取り消せる。
  - 作り直し: 代表のシナリオの後に ReadModel を作り直して同じ状態。

## 受け入れ条件
- #1〜#4 の入口が1回の呼び出しで1つの Command を dispatch し、途中で失敗すると何も残らない。
- グループの作成と初期ロール、経費の明細と添付が、それぞれ1回の API で全部か無しかになる。
- Feature の同期が Reactor で行われ、Handler から他の Handler を直接呼ばない。
- 各休暇の画面で承認済みの休暇を取り消せる。
- 上のテストと全件の単体テスト、変えた画面を通る E2E の spec が通る。

## 対象外
- 月次勤怠の承認・差戻しの古い月の移行、付与ルール・付与表のイベント化(`20261128-data-migrations`)
- 期間の休暇の申請(`20261128-period-leave-request`)

## ドキュメントへの影響
- `docs/03-architecture.md` 3.10、`docs/17-events.md`(イベントの項目の追加)
- 利用者・グループ(docs/15)、カレンダー(docs/08)、端末(docs/23)、経費(docs/12 等)、休暇(docs/09)のユースケースの該当の節(実装時に grep で特定)

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
初版

## 実装結果
未着手
