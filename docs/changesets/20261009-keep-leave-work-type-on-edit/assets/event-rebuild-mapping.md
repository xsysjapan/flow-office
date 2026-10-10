# 休暇まわりの`stored_events`の作り直し(論点18)

調査: 旧イベント・新イベント・集約IDの対応(2026-10-10、investigator)、独立設計レビュー(2026-10-10、blocker8・major9)。
方針: `data-correction`スキルの「`stored_events`の作り直し」。旧イベント列から新しいイベント構成の列を決定的に生成し、
元の発生順にid・版を振り直した表を作り、複製DBで検証してから入れ替える。移行イベント・補正イベントは作らない。

## 1. 前提となる事実

- 集約の再構築は`aggregate_version`順(`config/event-sourcing.php:723`)、`event-sourcing:replay`は`id`順。一意制約は
  `(aggregate_uuid, aggregate_version)`。スナップショットは未使用。
- 本番のイベントはorigin/main系のコードが書いたもの。旧コードは版ごとに挙動が違う(例: 申請時の`*.usage_designated`は14b64bc以前に
  無い、cutover(d5a726b)の前後で有給の記録先が違う)。変換は「あるはずのイベントが無い」並びにも対応する。
- 旧コードは勤怠日をイベントなしで直接作り、`work_type`・全休の`status=clocked_out`を直接書いていた(勤怠のイベントは`calculated`
  だけ)。これらの事実はReadModel(`attendance_days`)にしか無い。
- 旧の承認は充当できる付与があるときだけ`*.used`を書く。旧の取消は承認済みのときだけ`*.usage_reversed`を書く。cutover後の有給の
  差戻しは口座イベントを書かず(Projectorが`workflow_request.returned`を購読)、差し戻された申請は取り消せず、再提出のイベントも無い。
- cutoverの`paid_leave_account.migrated`の付与IDは、入力JSONの`grant_id`が無ければ乱数(`MigratePaidLeaveAccountHandler.php:22`)。
  cutover後のイベントはその付与IDを参照する。

## 2. 変換の単位: 集約・ワークフローごとの状態機械

イベント種別の1対1の対応ではなく、**申請1件(とそのワークフロー)・付与1件・勤怠日1件ごとに旧イベント列を読み、状態機械で新しい
イベント列を組み立てる**。各新イベントは、その事実を表す旧イベントの位置(id順)に置き、`created_at`を引き継ぐ。旧イベントに
無い事実を補う新イベントは、その事実が起きた旧イベントの直後(または直前)に置く。状態機械で扱えない並びが1つでもあれば、試し
実行でその申請・付与・勤怠日を一覧にして失敗させる(推測で埋めない)。

### 2.1 休暇の申請(有給・特別休暇・代休共通)

申請ごとに、申請のイベント(旧`paid_leave.*`/`special_leave.*`/`compensatory_leave.*`の申請系、cutover後の有給は口座イベント)と、
その申請のワークフロー(`leave_request_workflow_links`の元になる`workflow_request.drafted`と`*.request_shared`で特定)の
`workflow_request.*`を合わせて時系列に読む。

| 事実(元にする旧イベント) | 申請集約に置く新イベント | 口座集約に置く新イベント |
|---|---|---|
| 申請(`*.requested`、cutover後の有給は`paid_leave_account.usage_designated`) | `*.requested`(有給は`paid_leave_request.requested`) | `usage_designated`(旧に無ければ申請から合成) |
| 共有(`*.request_shared`) | `*.shared` | — |
| 承認(`*.request_approved`、cutover後は`usage_confirmed`。旧に申請側が無ければ`workflow_request.approved`) | `*.approved` | `usage_confirmed`(**`*.used`の有無にかかわらず必ず**)。続けて充当(`*.used`・`usage_allocated`があれば付与ごとの充当。無ければ未充当量=申請日数) |
| 差戻し(`*.request_returned`、cutover後は`workflow_request.returned`) | `*.returned` | `usage_cancelled`(承認済みの充当があれば先に解放) |
| 差戻しからの再提出(差戻し後の`workflow_request.submitted`) | `*.resubmitted` | 新しいusageIdで`usage_designated`(以後の確定・充当はこの新しいusageIdに付け替える) |
| 取消(`*.request_cancelled`、cutover後の`usage_cancelled`で差戻し中でないもの、`workflow_request.cancelled`) | `*.cancelled` | `usage_cancelled`(**`*.usage_reversed`の有無にかかわらず**、有効な消化があれば。充当済みなら付与ごとに解放) |
| 却下(`workflow_request.rejected`、申請中のもの) | `*.cancelled` | `usage_cancelled` |

- 値の導出(全項目の一覧は5章)。usageIdは`UUIDv5(名前空間, "{種類}:{申請ID}:{再提出の回数}")`(cutover後の乱数のusageIdは
  そのまま引き継ぎ、再提出のときだけ新しく作る)。以後の新規採番も同じ規則にする(申請集約の再提出の回数から決まる)。
- 特別休暇・代休の`*.used`(付与ごとの複数件)は、承認の`usage_confirmed`のallocationsにまとめる。有給は`usage_allocated`を付与ごとに置く。

### 2.2 付与

| 旧イベント | 新イベント(口座集約) |
|---|---|
| `paid_leave.granted` / `grant_revoked` / `warning_raised` | `paid_leave_account.grant_created` / `grant_revoked` / `grant_warning_raised`(grantId=旧付与ID) |
| `special_leave.granted` / `grant_revoked` | `special_leave_account.grant_registered` / `grant_revoked` |
| `compensatory_leave.grant_synced` / `manually_granted` / `grant_removed` / `grant_confirmed` / `grant_cancelled` | `compensatory_leave_account.grant_*`(userIdは同じ付与の作成イベントから) |

### 2.3 有給のcutover(`paid_leave_account.migrated`)

利用者ごとに、cutoverの付与と旧付与(`paid_leave.granted`)を**付与IDで**突き合わせる。

- 付与IDが一致し、かつ「旧付与日数 − Σ旧`used` + Σ旧`usage_reversed` = `remainingDaysAtCutover`」なら、その付与は旧イベントで
  完全に再現できるので、cutoverの行は作らない(cutover前の消化は2.1で充当まで再現される)。
- 付与IDが一致しない付与(システム外から持ち込んだ残高)は`grant_created`(source=`carried_over`、grantId=cutoverの付与ID、
  grantedDays=`remainingDaysAtCutover`、grantedOn=`originalGrantedOn`(nullならcutover日)、cutoverの`mode`・`notes`は`meta_data`)にする。
- 付与IDは一致するが残数が合わない付与は、試し実行で一覧にして失敗させる(リハーサルで原因を確認し、変換規則を決めてから進む)。
- 結果として論点13の8件は通常の承認済み申請と同じく取消できる(2026-10-10 ユーザー決定)。`PaidLeaveRequestAggregate`の
  「移行前の申請の取消を拒否」は削除する。

### 2.4 勤怠(論点12の補正候補(2)〜(4))

旧コードが直接書いた勤怠の事実はReadModelにしか無いため、**ReadModel(`attendance_days`)の現在値を事実の入力として使う**
(この範囲に限る)。

| 状況 | 新しい列 |
|---|---|
| `attendance_day.created`が無い勤怠日 | その勤怠日の最初のイベント(多くは`calculated`)の直前に`attendance_day.created`を置く。source=休暇の処理が作った日なら`leave`、それ以外は現在値。calendarEntryId・utcOffsetMinutesは現在値、createdByUserIdは休暇の申請者(無ければ利用者)、reasonは固定文言「既存データの引き継ぎ」 |
| `created`/`edited`のpayloadの`workType`が休暇値 | その日の作業内容の値に書き換える(休暇値の前の値。無ければnull) |
| ReadModelの`status=clocked_out`が全休のために書かれたもの(実績・打刻なし) | 置く`created`のstatusを実績から決まる値(`not_started`)にする |
| 休暇の解除で空になった勤怠日 | **後続のイベントが無い場合だけ**、解除の直後に`attendance_day.deleted`を置く(punchLogActionは打刻ログを変えない値、deletedByUserIdは解除の操作者)。後続のイベントがあれば置かない |

日次計算は作り直しの中では変換しない(計算に当時のReadModelが要るため)。作り直しの後に、候補(3)で休暇値を直した日と休暇の
ある日の日次計算を`attendance:recalculate-days`で再計算する。**締め・提出済みの月も再計算する**(2026-10-10 ユーザー決定)。
月次の提出イベントに記録されたスナップショット・週40時間配賦とは差が出るため、差のある月を一覧にして報告する。

### 2.5 既定値nullの項目の補完

作り直しで、新しいコードが既定値nullで受けている項目(特別休暇・代休の申請イベントの`userId`・`targetDate`・`requiresGrant`、
勤怠の計算イベントの`userId`・`workDate`ほか)を全て補完し、作り直し後は既定値・nullガードを削除する。

## 3. 本番データの補正(論点12)

候補(1)(差し戻された休暇の未取消の消化記録)は2.1の差戻しの規則で、候補(2)〜(4)は2.4で、作り直しの中で直す。
`attendance_day.corrected`・`CorrectAttendanceDay`・補正候補の検出コマンドは不要になるので削除する(少数のイベントを直す
`StoredEventCorrector`と補正コマンドの基底`StoredEventCorrectionCommand`・補正ログは、部分的な書き換え用に残す)。

## 4. 作り直し後に削除するもの

- 旧イベントクラスと別名: `paid_leave.*`(11件)、`special_leave.granted`・`grant_revoked`・`usage_designated`・`used`・`usage_reversed`、
  `compensatory_leave.grant_*`・`manually_granted`・`usage_designated`・`used`・`usage_reversed`、`paid_leave_account.migrated`、
  `special_leave_account.migrated`、`compensatory_leave_account.migrated`、`paid_leave_request.migrated`、`attendance_day.corrected`
- 旧集約: `SpecialLeaveGrantAggregate`ほか旧イベントを記録するクラス
- 旧系統を読む処理: `SpecialLeaveGrantProjector`・`SpecialLeaveUsageProjector`・`CompensatoryLeaveGrantProjector`の旧イベント処理と
  `stored_events`の直接読み取り、`PaidLeaveRequestProjector`・`AttendanceDayLeaveProjector`・出勤率ビューの系統の切り替え
  (`input_source`列)、各口座集約の`migrate()`・`migrateGrants()`、`PaidLeaveRequestAggregate`の移行前申請の取消拒否、既定値nullの
  ガード、`LeaveCorrectionCandidateDetector`・`LeaveCorrectionReportCommand`・`onAttendanceDayCorrected`
- 移行の入口: 移行コマンド4つ(`special-leave:migrate-to-account`・`compensatory-leave:migrate-to-account`・`paid-leave:migrate-requests`・
  `paid-leave:migrate-accounts`)、`/paid-leave/migrate` API と `MigratePaidLeaveAccount` のCommand/Handler
- 休暇ビュー(`attendance_day_leaves`)・出勤率ビューの系統を表す`source`列の値(`legacy_paid`・`paid_account`等)と系統の切り替え
  (休暇申請のイベントだけを入力にする。差戻し・取消で行を残し`request_status`を更新する方式は、履歴を画面で追えるため維持する)
- 列: 消化記録の`stored_event_id`、`paid_leave_grants`の移行監査列(cutoverの情報はイベントの`meta_data`に残す)とその表示
- フロントエンドの履歴表示の旧`event_type`名

## 5. 新イベントの項目の導出

| 項目 | 導出元 |
|---|---|
| 申請者(各イベントの`userId`) | 同じ申請の申請イベント |
| 承認者・差戻し者・取消者 | 旧payloadの`approvedByUserId`等、cutover後は`confirmedByUserId`・`cancelledByUserId`、ワークフローのイベントの操作者 |
| 有給申請の`workflowRequestId` | 同じ申請の`*.request_shared`(後続を先読み) |
| `hours`(時間休) | 旧`usedMinutes`÷60 |
| 特別休暇の`requiresGrant`・未充当量 | 承認時点の種別の設定は履歴が無いため、`*.used`があれば残数を要する、無ければ要しないと判定する。未充当量=申請日数−充当合計(残数を要しない種別は0) |
| 代休の充当の分数・未充当の分数 | 旧`used`の`usedMinutes`、申請の`requestedMinutes` |
| 有給付与の`source` | 旧付与は`manual`(定期付与のバッチが記録した付与は`scheduled_batch`。旧payloadの`grantReason`で判定できなければ`manual`)、cutoverの持ち込みは`carried_over`(新しい値。Projector・画面で表示を追加) |
| 取消・差戻しの`reason` | 旧に値があればその値、無ければ固定文言(「差戻しによる取消」等) |
| 勤怠日の`created`の各項目 | 2.4(ReadModelの現在値) |

## 6. id・版の振り直しで影響するもの

- `stored_events.id`を参照する列: `workflow_request_history_entries.stored_event_id`・`expense_claim_history_entries.stored_event_id`
  (一意。リビルドで作り直す)、補正ログ`stored_event_corrections`(作り直しの前に補正ログが0件であることを確認。あれば旧id→新idの
  対応表を残す)、監査ログAPIの`event_id`(外部に返すidが変わることをリリースノートに記載)、`LeaveHistoryQuery`。
- Projectorは`replay`の前に状態を消さない(`resetState`を持つものが0件)。入れ替えの後、全ReadModelのテーブルを空にしてから全
  Projectorを`replay`する(空にするテーブルの一覧はProjectorの書き込み先から機械的に作り、手順書に載せる)。
- `meta_data`: 元のイベントの独自キーを引き継ぎ、`aggregate-root-version`を新しい版に更新する。分割で作るイベントは元のイベントの
  `meta_data`を写し、補った事実であることを示すキー(`rebuilt_from`=元のイベントのid)を付ける。

## 7. 実行手順

変換コマンド`leave:rebuild-event-store`(仮称)は、旧イベントクラスに依存せず`stored_events`の生のJSONを読む。

1. 事前条件の確認(試し実行で自動): 本番に新しい種類のイベント(`paid_leave_request.*`・`*_account.*`の新種類)が0件、補正ログ0件、
   スナップショット0件。
2. 試し実行(既定): 申請・付与・勤怠日ごとの変換結果の件数、扱えない並びの一覧(あれば失敗)、cutoverの付与の突き合わせ結果
   (再現・`carried_over`・不一致)、差戻し/取消/却下の判定結果を出す。
3. 本番の書き込みを止める: メンテナンスモード、スケジューラ・キューワーカーの停止、打刻端末・API・MCPの受付停止。
4. `--apply`: 新しい表`stored_events_rebuilt`に書く(休暇以外のイベントも同じ順で写す。id・版を振り直す)。
5. 検証は**複製DB**で行う: 複製に`stored_events_rebuilt`を`stored_events`として入れ、全ReadModelを空にして全Projectorをリビルドし、
   本番のReadModel(作り直し前)と比較する。差が「意図した差」(差し戻された休暇の消化記録の取消、休暇値の削除等)だけであることを
   確認する。
6. 入れ替え(`--swap`): `RENAME TABLE stored_events TO stored_events_before_rebuild_YYYYMMDDHHMMSS, stored_events_rebuilt TO stored_events`
   (1文で原子的)。AUTO_INCREMENTを新しい最大id+1にする。
7. 旧系統を削除した新しいコードを配備し、全ReadModelを空にして全Projectorをリビルドする。日次計算を再計算する(2.4)。
8. 確認の後、書き込みを再開する。
- ロールバック: 7までに問題があれば、表を元に戻し(`RENAME TABLE`)、ReadModelを作り直し前のバックアップから戻し、旧コードを
  再配備する(新しいコードは旧イベントを読めないため、表と同時に戻す)。

## 8. リハーサルで確認すること

- 試し実行の結果(扱えない並び0件、cutoverの不一致0件)
- cutoverの付与の`carried_over`の件数と内容
- 差戻し/取消/却下の判定結果をワークフローの状態と突き合わせる
- 複製DBでの比較の差分が意図した差だけであること
- 日次計算の再計算で値が変わる日と、締め・提出済みの月のスナップショットとの差
