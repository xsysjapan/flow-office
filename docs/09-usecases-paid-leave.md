# 9. 有給管理ユースケース

## UC-P001: 有給付与ルールを作成する

1. 管理者が付与ルールを作成する
2. 正社員、短時間勤務、週4勤務などの対象を設定する
3. 継続勤務期間ごとの付与日数を設定する
4. 出勤率条件を設定する
5. ルールを保存する

年次有給休暇は原則として、雇入れから6か月継続勤務し、全労働日の8割以上出勤した労働者に
付与される。短時間労働者にも所定労働日数に応じた比例付与がある。
(出典: 連合(日本労働組合総連合会))

## UC-P002: 有給を自動付与する

1. バッチが付与対象者を抽出する
2. 入社日、継続勤務期間、出勤率、勤務形態を確認する
3. 付与ルールに基づき付与日数を決定する
4. `paid_leave_grants` を作成する
5. 有効期限を設定する
6. 社員へ通知する
7. `paid_leave.granted` イベントを記録する

有給休暇の請求権は原則2年で時効消滅するため、付与単位ごとに有効期限を管理する
(有効期限 = 付与日 + 2年)。(出典: チェック労働)

`paid-leave:grant-scheduled` コマンドとしてcronから毎日実行する(routes/console.php)。
判定は以下の通り。

1. `users.hire_date`(入社日)が設定済みの社員を対象にする。MS365には入社日に相当する
   属性がないため同期対象外で、`user_profile.update` Permissionを持つ担当者が個別に設定する
   (`PUT /api/users/{user}/hire-date`)。
2. 付与ルール(`paid_leave_grant_rules`)ごとに、`work_style_id` が指定されている場合は
   当日その勤務形態が割り当てられている社員のみに絞り込む。
3. 入社日からの継続勤務期間(完了月数)を求め、今日がその「月次記念日」(入社日と同じ日)
   であり、かつ `first_grant_after_months` 以上かつ `grant_cycle_months` の周期に
   ちょうど合致する月のみ付与対象とする(バッチは毎日実行されるが、実際に付与されるのは
   対象者ごとに年1回程度)。
4. 継続勤務期間に応じた付与日数は `paid_leave_grant_rule_steps` から、条件を満たす最大の
   `continuous_service_months` の行を採用する。
5. 出勤率(`min_attendance_rate`)は、直近 `grant_cycle_months` か月間の
   `employee_calendar_entries`(勤務予定日)を分母、`attendance_days` が退勤済みまたは
   有給消化済み(`work_type` が `paid_leave_` で始まる)の日を分子として計算する
   (有給取得日は出勤したものとして扱う)。期間中に勤務予定日が1件も無い場合は判定不能として
   付与しない。
6. 同一社員に同日重複して付与しないよう、当日すでに付与済みの場合はスキップする。

実際の付与処理(grant作成・イベント記録・Teams通知)は既存のUC-P002手動付与
(`GrantPaidLeave`)と共通のCommandを再利用する。

## UC-P003: 有給を申請する

1. 社員が有給申請を作成する
2. 対象日を選択する
3. 全休、午前半休、午後半休、時間休を選択する
4. `approval.execute` Permissionを持つ任意の社員から承認者を選択する
5. 有給残数を確認する
6. 勤務予定日であることを確認する
7. 申請する

関連イベント: `paid_leave.requested`
関連テーブル: `paid_leave_requests`

申請単位ごとの取得日数は以下のように決まる。

- 全休: 1.0日
- 午前半休・午後半休: 0.5日
- 時間休: 取得時間 ÷ 対象日の所定労働時間(`employee_calendar_entries.work_style.prescribed_daily_minutes`)

手順5(有給残数を確認する)・手順6(勤務予定日であることを確認する)は申請時にAPIで検証し、
不足・対象外の場合は申請自体を拒否する(422)。同一社員・同一対象日への重複申請
(提出中・承認済みが既にある場合)も拒否する。

### 期間指定でまとめて申請する(複数日分)

フロントエンドは日付を1件ずつ選ぶ方法と、期間(開始日〜終了日)を選ぶ方法の2通りで
複数日分をまとめて申請できる。バックエンドAPIは1申請=1対象日のままだが(日ごとの
勤務予定・残数チェックを崩さないため)、同じ申請操作で作成した行は`request_group_id`
(nullable uuid)で束ねる。取得単位(全休/半休/時間休)は対象日が1日のみの場合に限り
選択でき、2日以上をまとめて申請する場合は全休固定になる(半休・時間休は1日単位の
概念のため)。

## UC-P004: 有給を承認する

1. 承認者が有給申請を確認する
2. 問題なければ承認する
3. 対象日の勤怠に有給区分を反映する
4. 有効期限が近い付与分から消化する
5. `paid_leave.used` イベントを記録する

関連イベント: `paid_leave.request_approved`, `paid_leave.used`
関連テーブル: `paid_leave_requests`, `paid_leave_grants`, `paid_leave_usages`, `attendance_days`

手順3(対象日の勤怠に有給区分を反映する)は `attendance_days.work_type` に
`paid_leave_full` / `paid_leave_am_half` / `paid_leave_pm_half` / `paid_leave_hourly`
のいずれかを設定する。全休の場合は出退勤操作が発生しないため、締め忘れ(打刻漏れ)として
警告されないよう `attendance_days.status` を `clocked_out` 扱いにする。

手順4(有効期限が近い付与分から消化する)は、承認時点で有効期限が近い `paid_leave_grants`
から順に取得日数を消し込む。1件の承認が最も近い失効grantの残数だけでは足りない場合、
複数のgrantにまたがって消化し、grantごとに1件の `paid_leave_usages` レコードと
`paid_leave.used` イベントを記録する。承認済みの日次勤怠が既に締め(ロック)済みの場合は
承認できない(修正申請ワークフローを使う)。

承認・差戻し・取消は、汎用申請(workflow_requests)やバックオフィス処理と同様、独立した
ステータス系列(`paid_leave_requests.status`: submitted → approved / returned / cancelled)
で管理する(承認とバックオフィス処理を分ける方針と同じ考え方)。差戻しは承認者のみ行える。
取消は申請者自身のみ行え、提出中(未承認)・承認済みのいずれの申請も取り消せる
(承認済みの取消の詳細は「実装上のポイント」参照)。

期間指定でまとめて申請した複数日分(同じ`request_group_id`を持つ行)は、承認者がそのうち
1件を承認する操作だけで、まだ提出中の残りの日もまとめて承認する(1日ごとに個別承認する
手間を減らすため)。差戻しはこの連鎖の対象外とし、日ごとに個別に行う(1日だけ差戻したい
場合があるため)。承認画面の詳細には、対象社員の直近1年間(申請中・承認済みの合計)の
有給取得日数を表示し、自動付与のルールに依らず承認者が目視で判断できるようにする
(この日数表示はシステムが上限を強制するものではなく、あくまで判断材料)。

## UC-P005: 有給消滅警告を出す

1. バッチが有効期限90日以内の有給を検索する
2. 残日数がある社員を抽出する
3. 社員と管理者へ Teams 通知する
4. 警告履歴を記録する

`paid-leave:warn-expiring` コマンドとしてcronから毎日実行する。対象は
「残日数(`remaining_days`)が0より大きく、有効期限(`expires_on`)が今日から90日以内、
かつまだ警告していない(`paid_leave_grants.expiry_warned_at` が未設定)」付与。警告後は
`expiry_warned_at` を記録し、同じ付与に重複して通知しない。「社員と管理者へ通知する」は
Teamsが通知専用の単一チャンネル(webhook)である現在の実装上、対象者名を含む1件の通知として
送る(docs/03-architecture.md、CLAUDE.md「Teamsは通知専用」)。`paid_leave.warning_raised`
イベント(`warning_type: expiry`)を記録する。

## UC-P006: 年5日取得義務を警告する

1. バッチが年10日以上付与された社員を抽出する
2. 取得義務期間内の取得日数を確認する
3. 5日未満で期限が近い場合に警告する
4. 社員、承認者、管理部へ通知する

年10日以上の年次有給休暇が付与される労働者には、使用者による年5日の取得時季指定義務がある。
(出典: 都道府県労働局所在地一覧)

`paid-leave:warn-five-day-obligation` コマンドとしてcronから毎日実行する。取得義務期間は
付与日(`granted_on`)から1年とし、期限まで60日以内かつ取得日数
(`paid_leave_usages.used_days` の合計)が5日未満の付与を対象にする。警告後は
`paid_leave_grants.five_day_obligation_warned_at` を記録し、重複通知しない。
「承認者」は有給申請ごとに都度指定され固定の対応者を持たないため(CLAUDE.md「承認者は都度
指定」)、通知は社員本人と管理部宛の1件として送る。`paid_leave.warning_raised` イベント
(`warning_type: five_day_obligation`)を記録する。

## UC-P007: 有給履歴を確認する

1. 社員本人が自分の有給履歴を確認する、または管理者・人事担当者が対象社員を選んで
   有給履歴を確認する
2. 付与・申請・承認・差戻し・取消・消化のイベントを日時の新しい順に一覧表示する

関連イベント: `paid_leave.granted`, `paid_leave.requested`, `paid_leave.request_approved`,
`paid_leave.request_returned`, `paid_leave.request_cancelled`, `paid_leave.used`

`paid_leave_grants`/`paid_leave_requests` の現在の残高・ステータス一覧(UC-P001〜UC-P004の
画面)とは別に、`stored_events`(EventStore)を正の記録として直接検索し、対象社員に関する
一連のイベントを時系列で表示する。`paid_leave_grants`/`paid_leave_requests` の現在の残高・
ステータスは日々更新される「現在のスナップショット」であるのに対し、履歴画面は「いつ・何が
起きたか」の記録そのものを見せるものであるため、Projectionを新設するのではなく
`stored_events` を直接参照する(`docs/15-usecases-admin.md` UC-M003の監査ログと同様の考え方)。

対象社員が持つ `paid_leave_grants`/`paid_leave_requests` の id を集約ID
(`aggregate_type` = `paid_leave_grant` / `paid_leave_request`)として絞り込む。
`paid_leave.request_approved`/`request_returned`/`request_cancelled` のpayloadには
実行者(承認者等)のIDのみが含まれ申請者本人の`user_id`を含まないため、payloadの内容ではなく
対象社員が実際に持つgrant/requestのidで絞り込む必要がある点に注意する。

自分の履歴は誰でも閲覧できる。他の社員の履歴は`paid_leave.read` Permissionを持ち、対象Userを
含むScopeが有効な場合だけ閲覧できる(`GET /paid-leave/grants/user/{userId}`等)。

## UC-P008: 有給付与を取り消す

1. 人事担当者・管理者(`leave.manage` Permission)が、発行済みの有給付与(`paid_leave_grants`)
   の中から取り消す対象を選ぶ
2. 取消理由を入力する(任意)
3. 対象付与の`used_days`が0より大きい場合(1日でも消化済みの場合)は取消不可とし、
   「既に消化された分は取り消せません。」というエラーを返す
4. `used_days`が0の場合のみ`paid_leave.grant_revoked`イベントを記録し、`status`を
   `revoked`に、`revoked_at`/`revoked_by_user_id`/`revoke_reason`を設定する

`POST /paid-leave/grants/{grant}/revoke`(`RevokePaidLeaveGrant`
Command/`RevokePaidLeaveGrantHandler`)。入力誤りによる付与の取消を想定し、消化前であれば
何度でも取消・再付与ができるようにする一方、社員が既に消化した分の既得権は保護する。
同じ構造の取消を特別休暇(`special_leave_grants`、`RevokeSpecialLeaveGrant`)にも実装する。

## UC-P009: 管理者が社員の有給申請を取り消す・消化明細を確認する

1. 人事担当者・管理者(`leave.manage` Permission)が対象社員の有給消化明細
   (`paid_leave_usages`)を一覧で確認する(`GET /paid-leave/usages/user/{userId}`。
   `used_on`の新しい順。関連する申請の現在の`status`(`request_status`)も併せて返し、
   `approved`以外(既に取消・返却済み)の明細は取消不可であることを画面側で判別できる
   ようにする)
2. 承認済みの申請を選び、取消理由等を確認したうえで管理者が直接取り消す
   (`POST /paid-leave/requests/{id}/admin-cancel`)

明細(`paid_leave_usages`)は消化の結果生成される派生データであり、消化明細1件だけを
独立して取消・巻き戻すという操作は存在しない(1件の承認済み申請が複数の付与にまたがって
消化することがあり、取消は常に申請単位で行う。ドメインとしての取消は
`CancelPaidLeaveRequestHandler`が対象申請の消化明細をすべて巻き戻す形で実装済み)。

UC-P003で説明した申請者本人による取消(`POST /paid-leave/requests/{id}/cancel`)は
`cancelledByUserId === 対象申請のuser_id`のみ許可する自己申請限定の経路のままとし、
管理者向けにはそれとは別の`admin-cancel`エンドポイントを追加する。内部的には同じ
`CancelPaidLeaveRequest` Command/`CancelPaidLeaveRequestHandler`を使うが、
`isAdminAction: true`を渡すことで本人一致チェックをバイパスする(`cancelledByUserId`には
取消操作を行った管理者自身のIDを渡し、監査上「誰が取り消したか」を正しく記録する)。
承認済み申請のみ取消可能・締め済み月は取消不可等、既存の業務ルールはすべてそのまま適用される。
同じ構造を特別休暇(`POST /special-leave/requests/{id}/admin-cancel`、
`GET /special-leave/usages/user/{userId}`)・代休(`POST /compensatory-leave/requests/{id}/admin-cancel`、
`GET /compensatory-leave/usages/user/{userId}`)にも実装する。

## 実装上のポイント

- 付与ルール (`paid_leave_grant_rules` / `paid_leave_grant_rule_steps`) はマスタ化し、
  継続勤務期間ごとの付与日数・出勤率条件をハードコードしない。
- 消化は有効期限が近い付与分(先に失効するグラント)から優先的に消し込む
  (`paid_leave_usages` が `paid_leave_grant_id` を参照して紐づける)。
- UC-P001〜UC-P006はすべて実装済み。UC-P002(自動付与)・UC-P005(消滅警告)・
  UC-P006(年5日警告)は`paid-leave:grant-scheduled` / `paid-leave:warn-expiring` /
  `paid-leave:warn-five-day-obligation` の3コマンドとしてcronから毎日実行する
  (routes/console.php)。MVP自体は UC-P001(付与ルールマスタ)・付与の手動実行・
  UC-P003(有給申請)・UC-P004(有給承認・消化)までを最小範囲としていたが
  ([21-mvp-scope.md](./21-mvp-scope.md) 参照)、以降のフェーズでバッチ3種も実装した。
- UC-P003/UC-P004は汎用申請(workflow_requests)とは別の独立したCommand/Event
  (`paid_leave_requests` を正データとする専用アグリゲート)として実装する。承認時に
  `attendance_days` / `paid_leave_grants` / `paid_leave_usages` へ副作用を及ぼす必要があり、
  汎用申請の承認(バックオフィスタスク自動生成のみ)とは異なる業務ルールを持つため。
- UC-P002の継続勤務期間・出勤率判定には `users.hire_date`(入社日)を使う。MS365には
  対応する属性がないため同期対象外で、`user_profile.update` Permissionを持つ担当者が個別に
  設定する必要がある
  (未設定の社員は自動付与の対象外になる)。
- UC-P005/UC-P006の警告は同一の `paid_leave.warning_raised` イベントを共有し、
  `warning_type` (`expiry` / `five_day_obligation`) で区別する。重複通知を防ぐため、
  `paid_leave_grants.expiry_warned_at` / `five_day_obligation_warned_at` にそれぞれ
  一度警告した事実を記録し、以降の実行では対象から除外する(一度きりの警告。期限が過ぎても
  再警告はしない)。
- 期間指定の複数日申請・1回の承認でのまとめ承認(`request_group_id`)は特別休暇
  (`special_leave_requests`)・代休(`compensatory_leave_requests`、後述)にも同じ仕組みで
  実装済み。3ドメインとも、同じ`request_group_id`を持つ行のうち1件が承認されると、
  まだ提出中の他の行もまとめて承認される。差戻しは対象外で、日ごとに個別に行う。
- **承認済み申請の取消**: 有給・特別休暇・代休のいずれも、承認済みの申請を申請者本人が
  即座に取り消せる(承認者の再承認は不要)。取消時は、消化済みの付与(`*_grants`)へ
  取消イベント(`paid_leave.usage_reversed`等)を記録して残数を戻し、`paid_leave_usages`等の
  行を削除する。対象日の`attendance_days.work_type`もクリアし、全休で実際の打刻が無い
  場合はステータスも未入力(`not_started`)へ巻き戻す(半休・時間休は実際の打刻由来の
  ステータスをそのまま維持する)。対象日を含む月次勤怠が既に提出・承認・締め済みの場合は
  取消できない(`AttendanceEditGuard::assertMutable`が他の編集操作と同じ基準で拒否する。
  修正が必要な場合は修正申請ワークフローを使う)。期間指定でまとめて承認された申請の取消は
  1件ずつ個別に行う(承認のようなグループ単位のカスケードはない)。
- 既知の制約: `attendance_daily_calculations`(日次集計)は現時点で `work_type` を区別せず
  `actual_start_at`/`actual_end_at` のみから計算する。全休日は労働時間が0分として集計される
  (欠勤ではなく有給消化であることは `attendance_days.work_type` で判別できるが、給与計算上の
  「有給分の賃金換算」は本実装のスコープ外。給与計算ソフト側で `work_type` を見て加算する、
  または後続フェーズで日次集計に有給分を組み込む対応が必要)。

## 代休(`App\Domain\CompensatoryLeave`)

有給・特別休暇とは異なり、代休の付与(Grant)は申請ではなく休日出勤の勤怠実績
(`attendance_days`)から自動導出される。所定休日・法定休日に実働がある日次勤怠が
保存される都度、`AttendanceDayCalculated`/`AttendanceDailyCalculationAdjusted`/
`AttendanceDayDeleted` を購読するReactorが`SyncCompensatoryLeaveGrant`コマンドを発行し、
`compensatory_leave_grants`(status=draft)を1対1(`attendance_day_id`ユニーク)で同期する。
休日出勤でなくなった・実績が削除された場合、draft状態のGrantのみ取り消す
(確定済みGrantは触らない。整合性は月次確認画面の警告`compensatory_leave_warnings`で扱う)。

月次勤怠の提出(`AttendanceMonthSubmitted`)を受けて、対象月のdraft Grantを一括で
`confirmed`に確定する(`system_settings.compensatory_leave_valid_days`が設定されていれば
提出時点からのN日後を`expires_on`とし、未設定なら無期限)。確定後のGrantのみが消化申請
(`compensatory_leave_requests`、特別休暇と同じ申請・承認・消化のフロー)の対象になる。
取得単位(全休/半休/時間単位)は`system_settings.compensatory_leave_unit`
(`daily`/`half_day`/`hourly`)で制限し、半休判定の閾値は
`compensatory_leave_half_day_threshold_minutes`で設定する。未使用の確定済みGrantは
取消申請でき(`compensatory_leave_grant_cancellations`)、`compensatory_leave_requires_approval`
の設定に応じて即時取消/承認要のいずれかになる。詳細は`docs/03-architecture.md`・
`app/Domain/CompensatoryLeave/`を参照。

### 代休の手動付与・管理者直接取消

休日出勤の勤怠実績からの自動導出(上記)とは別に、人事担当者・管理者(`leave.manage`
Permission)が管理者操作として代休を手動付与できる。付与理由の例: 自動導出漏れの事後対応、
制度移行時の付与調整など。任意の日数を自由入力させるのではなく、実際に休日出勤した対象日
(`work_date`)を指定させ、その日の`attendance_days`(実労働時間)から自動導出フローと
**同じ換算ルール**(`system_settings.compensatory_leave_unit`等、
`App\Domain\CompensatoryLeave\Services\CompensatoryLeaveGrantCalculator`)で付与日数を
算出する(`POST /compensatory-leave/grants`、`GrantCompensatoryLeave`
Command/`GrantCompensatoryLeaveHandler`)。対象日に休日出勤の実績が無い場合はエラーになる。

手動付与は承認不要のため、作成と同時に`status=confirmed`となり(月次提出による確定
ステップを経ない)、`compensatory_leave_grants.source`に`manual`を記録して自動導出分
(`attendance`)と区別する。手動付与には元になった`attendance_days`行への1:1紐付けが
無いため`attendance_day_id`はnullのままとなる。

管理者は`POST /compensatory-leave/grants/{grant}/revoke`で代休Grantを直接取り消せる
(`source`が`attendance`/`manual`のどちらでも利用可能)。既存の社員起点の
取消申請→承認フロー(上記、`request-cancellation`/`grant-cancellations/{id}/approve`)とは
別の、承認を経ない管理者専用の即時取消経路であり、既存の`CancelCompensatoryLeaveGrant`
Command/Handlerをそのまま再利用する(`used_days`が0より大きい場合は同様に取消不可)。

有給・特別休暇のUC-P007と同様、`GET /compensatory-leave/history/mine`(本人)・
`GET /compensatory-leave/history/user/{userId}`(`leave.manage` Permission)で
代休の付与・申請・承認・差戻し・取消のstored_eventsを時系列(新しい順)で確認できる
(`App\Domain\Leave\Support\LeaveHistoryQuery`共通実装。手動付与・自動導出のどちらの
Grantも区別なく含まれる)。
