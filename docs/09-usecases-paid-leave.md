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
   属性がないため同期対象外で、管理者(admin/hr_staff)が個別に設定する
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
   `employee_shift_assignments`(勤務予定日)を分母、`attendance_days` が退勤済みまたは
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
4. 承認者を任意の社員から選択する
5. 有給残数を確認する
6. 勤務予定日であることを確認する
7. 申請する

関連イベント: `paid_leave.requested`
関連テーブル: `paid_leave_requests`

申請単位ごとの取得日数は以下のように決まる。

- 全休: 1.0日
- 午前半休・午後半休: 0.5日
- 時間休: 取得時間 ÷ 対象日の所定労働時間(`employee_shift_assignments.work_style.prescribed_daily_minutes`)

手順5(有給残数を確認する)・手順6(勤務予定日であることを確認する)は申請時にAPIで検証し、
不足・対象外の場合は申請自体を拒否する(422)。同一社員・同一対象日への重複申請
(提出中・承認済みが既にある場合)も拒否する。

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
で管理する(承認とバックオフィス処理を分ける方針と同じ考え方)。差戻しは承認者のみ、
取消は申請者自身の提出中(未承認)の申請のみ行える。

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

自分の履歴は誰でも閲覧できる。他の社員の履歴を閲覧できるのは管理者・人事担当者のみ
(`GET /paid-leave/grants/user/{userId}` 等、他の管理者向けエンドポイントと同じロール制限)。

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
  対応する属性がないため同期対象外で、管理者(admin/hr_staff)が個別に設定する必要がある
  (未設定の社員は自動付与の対象外になる)。
- UC-P005/UC-P006の警告は同一の `paid_leave.warning_raised` イベントを共有し、
  `warning_type` (`expiry` / `five_day_obligation`) で区別する。重複通知を防ぐため、
  `paid_leave_grants.expiry_warned_at` / `five_day_obligation_warned_at` にそれぞれ
  一度警告した事実を記録し、以降の実行では対象から除外する(一度きりの警告。期限が過ぎても
  再警告はしない)。
- 既知の制約: `attendance_daily_calculations`(日次集計)は現時点で `work_type` を区別せず
  `actual_start_at`/`actual_end_at` のみから計算する。全休日は労働時間が0分として集計される
  (欠勤ではなく有給消化であることは `attendance_days.work_type` で判別できるが、給与計算上の
  「有給分の賃金換算」は本実装のスコープ外。給与計算ソフト側で `work_type` を見て加算する、
  または後続フェーズで日次集計に有給分を組み込む対応が必要)。
