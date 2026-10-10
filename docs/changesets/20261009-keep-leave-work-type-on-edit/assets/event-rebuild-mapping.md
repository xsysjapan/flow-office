# 休暇まわりの`stored_events`の作り直し(論点18)

調査: 旧イベント・新イベント・集約IDの対応(2026-10-10、investigator。根拠はコードの行番号付きで確認済み)。
方針: `data-correction`スキルの「`stored_events`の作り直し」。旧イベント列全体を新しいイベント構成へ決定的に
変換し、元の発生順(`id`順)にid・版を振り直した新しい表を作り、検証後に入れ替える。移行イベントは作らない。

## 前提となる事実

- 集約の再構築(retrieve)は`aggregate_version`順(`config/event-sourcing.php:723`)、`event-sourcing:replay`は
  `id`順。作り直しでは両者を元の発生順にそろえる。
- `stored_events`の一意制約は`(aggregate_uuid, aggregate_version)`。スナップショットは未使用。
- 新しい集約ID: 有給申請・特別休暇申請・代休申請=申請ID(旧と同じ)、有給口座=利用者ID、特別休暇口座・代休口座=
  `UserManagementStreamId::for('special_leave_account'|'compensatory_leave_account', userId)`。
- 消化記録ID(usageId)は現在ランダム採番。作り直しでは旧イベントから決定的に作る
  (`UUIDv5(名前空間, "{種類}:{申請ID}:{その申請での消化の通し番号}")`。以後の新規採番も同じ規則にする)。

## 変換規則(旧イベント1件 → 新イベント)

新イベントは元のイベントの位置(id順)に置き、`created_at`は元の値を引き継ぐ。1件を複数件に分ける場合は
下表の並び順で連続して置く。複数件を1件にまとめる場合は、まとめる最初のイベントの位置に置く。

### 有給

| 旧イベント(旧集約ID) | 新イベント(新集約ID) | 値の決め方 |
|---|---|---|
| `paid_leave.granted`(付与ID) | `paid_leave_account.grant_created`(利用者ID) | grantId=旧付与ID、source=`grant`(通常付与。旧の付与理由はそのまま) |
| `paid_leave.grant_revoked`(付与ID) | `paid_leave_account.grant_revoked`(利用者ID) | grantId=旧付与ID |
| `paid_leave.warning_raised`(付与ID) | `paid_leave_account.grant_warning_raised`(利用者ID) | grantId=旧付与ID |
| `paid_leave.requested`(申請ID) | `paid_leave_request.requested`(申請ID) | workflowRequestId=null(後続の`shared`で設定) |
| `paid_leave.request_shared` / `_approved` / `_returned` / `_cancelled`(申請ID) | `paid_leave_request.shared` / `approved` / `returned` / `cancelled`(申請ID) | approved・returned・cancelledのuserId=同じ申請の`requested`の申請者 |
| `paid_leave.usage_designated`(申請ID) | `paid_leave_account.usage_designated`(利用者ID) | usageId=決定的ID、paidLeaveRequestId=申請ID、その他は同じ申請の`requested`から |
| `paid_leave.used`(付与ID。付与ごとに1件) | 同じ消化の最初の1件の位置に`paid_leave_account.usage_confirmed` 1件、続けて付与ごとに`usage_allocated` | confirmedByUserId=同じ申請の`approved`の承認者 |
| `paid_leave.usage_reversed`(付与ID。付与ごと) | 付与ごとに`usage_allocation_released`、最後に`usage_cancelled` 1件 | cancelledByUserId=同じ申請の`cancelled`/`returned`の操作者 |
| `paid_leave_account.migrated`(cutover時、利用者ID) | 旧イベントで再現できる付与は削除(上の`grant_created`で表す)。旧イベントの無い付与(システム外から持ち込んだ残高)だけ`grant_created`に変換 | grantedDays=`remainingDaysAtCutover`、grantedOn=`originalGrantedOn`(nullならcutover日)、source=`carried_over`。cutover前の消化記録は旧イベントから`usage_*`として再現されるため、移行の特例(残高だけの引き継ぎ)が不要になる |
| cutover後の`paid_leave_account.usage_designated`(利用者ID) | 直前に`paid_leave_request.requested`(申請ID)を置き、続けて元の`usage_designated` | requestedの値は同じイベントのpayload(usedOn・usageType・usedDays・hours・approverUserId・reason・requestGroupId・workflowRequestId)から |
| cutover後の`paid_leave_account.usage_confirmed` | 直前に`paid_leave_request.approved`、続けて元のイベント | approvedByUserId=confirmedByUserId |
| cutover後の`paid_leave_account.usage_cancelled` | 直前に`paid_leave_request.returned`または`cancelled`、続けて元のイベント | 同じワークフローの`workflow_request.returned`がこのイベントより前にあり、その後に再提出・承認が無ければ`returned`、それ以外は`cancelled` |
| cutover後の`paid_leave_account.usage_allocated` / `usage_allocation_released` / `grant_*` | そのまま | 版のみ振り直し |

### 特別休暇

| 旧イベント(旧集約ID) | 新イベント(新集約ID) | 値の決め方 |
|---|---|---|
| `special_leave.granted`(付与ID) | `special_leave_account.grant_registered`(特別休暇口座) | grantId=旧付与ID |
| `special_leave.grant_revoked`(付与ID) | `special_leave_account.grant_revoked`(特別休暇口座) | |
| `special_leave.requested` / `request_*`(申請ID) | そのまま(同じ申請ストリーム・同じ名前) | 使用のイベントが抜けるので版を振り直す |
| `special_leave.usage_designated`(申請ID) | `special_leave_account.usage_designated`(特別休暇口座) | usageId=決定的ID、specialLeaveTypeIdは同じ申請の`requested`から |
| `special_leave.used`(付与ID。付与ごとに1件) | 最初の1件の位置に`special_leave_account.usage_confirmed` 1件(allocations=付与ごとの充当、unallocatedDays=申請日数−充当合計) | |
| `special_leave.usage_reversed`(付与ID。付与ごと) | 最初の1件の位置に`usage_cancelled` 1件(releasedAllocations) | |

### 代休

| 旧イベント(旧集約ID) | 新イベント(新集約ID) | 値の決め方 |
|---|---|---|
| `compensatory_leave.grant_synced` / `manually_granted` / `grant_removed` / `grant_confirmed` / `grant_cancelled`(付与ID) | `compensatory_leave_account.grant_*`(代休口座) | grantId=旧付与ID、userId=同じ付与の`grant_synced`/`manually_granted`から、workDate→sourceWorkDate |
| `compensatory_leave.requested` / `request_*`(申請ID) | そのまま | 版を振り直す |
| `compensatory_leave.usage_designated`(申請ID) | `compensatory_leave_account.usage_designated`(代休口座) | usageId=決定的ID |
| `compensatory_leave.used` / `usage_reversed`(付与ID。付与ごと) | `usage_confirmed` / `usage_cancelled` 1件にまとめる | 特別休暇と同じ |

### 本変更の開発中に記録された移行イベント

`special_leave_account.migrated`・`compensatory_leave_account.migrated`・`paid_leave_request.migrated`は本番に
まだ記録されていない(移行コマンドは未実行)。作り直し後は不要なので、イベント・Handler・コマンドごと削除する。

## 本番データの補正(論点12)も同じ作り直しで行う

論点12の補正候補(1)〜(4)は、補正イベント(`attendance_day.corrected`)を追記せず、この作り直しの変換規則に含めて
過去の履歴として正しく記録し直す(`data-correction`スキル改訂)。

| 候補 | 作り直しでの直し方 |
|---|---|
| (1) 差し戻された休暇の未取消の消化記録 | 差戻しのイベント(`*.request_returned`、cutover後は`workflow_request.returned`)の直後に、その消化の`usage_cancelled`(充当済みなら`usage_allocation_released`も)を置く |
| (2) 休暇の処理が直接作った勤怠日(`attendance_day.created`が無い) | その勤怠日を作った休暇のイベント(申請・承認)の直後に`attendance_day.created`(`source=leave`、実績なし)を置く。休暇の解除で空になった日は、解除のイベントの直後に`attendance_day.deleted`を置く(論点15) |
| (3) 編集イベントに入った休暇値 | `attendance_day.created`/`edited`のpayloadの`workType`の休暇値を、その日の作業内容(休暇の前の値。無ければnull)に書き換える |
| (4) 勤怠日に残る休暇値・全休の`status` | (3)と、休暇の処理が記録した勤怠のイベントのうち`status`を書き換えたもの(全休の`clocked_out`)を、実績から決まる状態に書き換える |

日次計算(`attendance_day.calculated`)は、作り直し後に`attendance:recalculate-days`で記録し直すのではなく、作り直しの中で
休暇を反映した値に変換する(変換できない場合だけ、作り直し後に再計算する)。どちらにするかは実装時に`AttendanceCalculator`を
変換処理から呼べるかで決め、変更セットに記録する。

これにより`attendance_day.corrected`・`CorrectAttendanceDay`・補正候補の検出コマンドは不要になるため削除する
(補正の枠組み`StoredEventCorrector`は、少数のイベントを直す部分的な書き換え用に残す)。

## 作り直し後に削除するもの

- 旧イベントクラスと別名: `paid_leave.*`(11件)、`special_leave.granted`・`grant_revoked`・`usage_designated`・`used`・
  `usage_reversed`、`compensatory_leave.grant_*`・`manually_granted`・`usage_designated`・`used`・`usage_reversed`、
  `paid_leave_account.migrated`、`special_leave_account.migrated`、`compensatory_leave_account.migrated`、
  `paid_leave_request.migrated`
- 旧系統を読むProjector・処理: `SpecialLeaveGrantProjector`・`SpecialLeaveUsageProjector`の旧イベント処理、
  `CompensatoryLeaveGrantProjector`の旧イベント処理、`PaidLeaveRequestProjector`・`AttendanceDayLeaveProjector`・
  出勤率ビューの「旧系統/cutover後/新系統」の切り替え(`input_source`列を含む)、口座集約の`migrate()`、
  Projectorが`stored_events`を旧`event_class`で直接読む箇所(`SpecialLeaveGrantProjector.php:73-83`、
  `CompensatoryLeaveGrantProjector.php:231-246`)
- 移行コマンド: `special-leave:migrate-to-account`、`compensatory-leave:migrate-to-account`、`paid-leave:migrate-requests`
  (`paid-leave:migrate-accounts`も、作り直しでcutover移行を表し直すため不要)
- 消化記録テーブルの`stored_event_id`列(旧Projectorの冪等キー)

## 作り直しの実行(運用コマンド)

`leave:rebuild-event-store`(仮称):
1. 試し実行(既定): 変換結果の件数(旧イベントの種類ごと→新イベントの種類ごと)、変換できなかったイベントの一覧、
   cutoverの`migrated`のうち`carried_over`として残す付与の一覧を出す。
2. `--apply`: 新しい表`stored_events_rebuilt`に変換後のイベント列を書く(元の`id`順に並べ、idを1から振り直し、集約ごとに
   版を1から連番。`meta_data['aggregate-root-version']`も更新)。休暇以外のイベントは変換せずそのまま同じ順で写す。
3. `--verify`: `stored_events_rebuilt`から全Projectorをリビルドした結果を、作り直し前のReadModel(付与の残数・消化記録・
   申請の状態・勤怠の日次計算・月次スナップショット・出勤率)と比較し、差分を一覧にする。
4. `--swap`: 表を入れ替える(`stored_events`→`stored_events_before_rebuild_YYYYMMDDHHMMSS`、
   `stored_events_rebuilt`→`stored_events`)。続けて全Projectorをリビルドする。

## リハーサルで確認すること

- 旧イベントの種類ごとの件数と、変換できなかったイベントの有無
- cutoverの`migrated`の付与のうち、旧イベントで再現できないもの(`carried_over`)の件数と内容
- cutover後の`usage_cancelled`の差戻し/取消の判定結果(ワークフローの状態と突き合わせ)
- `--verify`の差分が、意図した差(差し戻された休暇の消化記録が取り消される等、本変更で直す不具合)だけであること
