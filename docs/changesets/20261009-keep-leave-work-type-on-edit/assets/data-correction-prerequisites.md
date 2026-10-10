# 本番データ補正の前提調査(2026-10-10、investigator調査。SQLは未実行)

## イベントの無い勤怠日の行(休暇Handlerが直接create)
- 直接create: `RequestSpecialLeaveHandler.php:156-166`、`RequestPaidLeaveHandler.php:179-190`、`RequestCompensatoryLeaveHandler.php:145-159`
  (source=manual, status=not_started)。直後に日次計算をpersistするため、行の最初のイベントは`attendance_day.calculated`(版1想定)。
- Projectorの行前提: calculated=行無しはreturn(`AttendanceDailyCalculationProjector.php:28-30`)、edited=findOrFail(`AttendanceDayProjector.php:50`)、
  created=updateOrCreate(:27)、synced_from_punches=updateOrCreate(:123)、live_status_synced=無ければcreate(:76-97)、deleted=delete(:71)。
  → createdの無い行は再生で作られず、後続のeditedが例外になる。
- source=manualの休暇行は打刻が反映されない(`AttendanceDayPunchSyncer.php:86-89`、web打刻は`WebPunchDispatcher.php:32-37`で拒否)。
  editedはsourceを更新せず、取消Handlerもsourceを戻さない。→ 休暇を取り消した日も打刻できないまま残る(既存不具合)。
- 削除ガード`DeleteAttendanceDayHandler.php:41-47`はusageのexists()で拒否(取消済み有給も拾う)。
- stored_eventsは unique(aggregate_uuid, aggregate_version)。

## 休暇の勤怠日IDを任意にする影響
- `paid_leave_usages.attendance_day_id`は既にnullable。`special_leave_usages`・`compensatory_leave_usages`はNOT NULL+FK。
- 3テーブルとも`user_id`列と index(user_id, used_on) あり。(user_id, used_on) 検索の前例: `AttendanceDifferenceDetector.php:80-81`。
- attendance_day_idの利用: relation(`Models/AttendanceDay.php:77-88`、代休relation無し)、`AttendanceCalculator.php:130-147`、eager load 15箇所、
  `AttendanceDayResource.php:59-68`、削除ガード、各usage Projector(Special:32,67 / Paid:139 / Comp:139,159)、
  特別・代休・有給のUsed/Designated/Reversedイベントは attendance_day_id が非null string(有給Designatedのみ?string)、
  取消Handler(`CancelSpecialLeaveRequestHandler.php:78`、`CancelCompensatoryLeaveRequestHandler.php:82`)がイベントへ渡している。

## statusを「出勤または休暇」として読む箇所(全休=clocked_out前提)
- `ClockInHandler.php:40`(not_started以外は拒否)、`AttendanceDayPunchSyncer.php:92-94`(clocked_outは打刻無視)、
  `DevicePunchController.php:92,96,109`、`AttendanceRateAssessor.php:70-73`(clocked_outかpaid_leave_%、特別・代休は含まない)、
  `GrantScheduledSpecialLeaveHandler.php:176-181`(clocked_outかpaid/special、代休は含まない。両者で不一致)、
  frontend `TodayAttendancePanel.tsx:93,106,259,279`、`AttendanceDayRow.tsx:55`、`statusLabels.ts:159-196,222`、`attendanceDayWarnings.ts:26`。mcpは参照なし。

## 補正対象把握SQL(MySQL、SELECTのみ)
```sql
-- (a1) createdイベントの無い勤怠日と、そのうち他の勤怠イベントがある行
SELECT COUNT(*) no_created,
 SUM(EXISTS(SELECT 1 FROM stored_events e WHERE e.aggregate_uuid=d.id AND e.event_class LIKE 'attendance_day.%')) with_other_events
FROM attendance_days d
WHERE NOT EXISTS(SELECT 1 FROM stored_events c WHERE c.aggregate_uuid=d.id AND c.event_class='attendance_day.created');
-- (a2) 上記行のイベント種別内訳
SELECT e.event_class, COUNT(*) events, COUNT(DISTINCT e.aggregate_uuid) days
FROM stored_events e JOIN attendance_days d ON d.id=e.aggregate_uuid
WHERE e.event_class LIKE 'attendance_day.%'
  AND NOT EXISTS(SELECT 1 FROM stored_events c WHERE c.aggregate_uuid=d.id AND c.event_class='attendance_day.created')
GROUP BY e.event_class;
-- (b) 休暇値のwork_type
SELECT work_type, COUNT(*) FROM attendance_days WHERE work_type REGEXP '^(paid|special|compensatory)_leave_' GROUP BY work_type;
-- (c) 実績も打刻も無いclocked_out
SELECT d.work_type, COUNT(*) FROM attendance_days d
WHERE d.status='clocked_out' AND d.actual_start_at IS NULL AND d.actual_end_at IS NULL
  AND NOT EXISTS(SELECT 1 FROM attendance_punches p WHERE p.user_id=d.user_id AND p.work_date=d.work_date)
GROUP BY d.work_type;
-- (d) 編集イベントに休暇値が記録されたもの
SELECT event_class, COUNT(*) FROM stored_events
WHERE event_class IN ('attendance_day.created','attendance_day.edited')
  AND JSON_UNQUOTE(JSON_EXTRACT(event_properties,'$.workType')) REGEXP '^(paid|special|compensatory)_leave_'
GROUP BY event_class;
```

## イベント書き換えの前例
- `NormalizeAttendanceCalculationEventsCommand.php`: dry-run既定、--applyでバックアップテーブルへ複写後、event_propertiesをUPDATE(削除・版変更なし)。
- `StoredEventHistoryNormalizer`(docs/32): 削除(:169-172)、`renumberStream`(:387-402、版を退避→1..nに振り直し、meta_dataの版も更新)、
  `mergeMembershipStream`(:322-385、既存と新規を時系列マージして振り直し)。補正後にreplayと「版が1から連続」の検証を求める。
  注意: 同ファイル:437-451はReadModelを直接UPDATEしており、設計原則2と矛盾する前例。

## 補正方法の選択肢(調査時点の評価)
- (A) payload直接修正: 前例あり。休暇値・status・計算結果が連動するため、書き換え単独では再現できず再計算が必要。
- (B) 誤記録イベントの削除: 版の振り直しが必要(前例あり)。
- (C) 補正イベント追記: 通常経路で可能。ただしcreatedの無い行はeditedのfindOrFailで再生が落ちるためProjector改修が要る。
  usage参照(FK・削除ガード)の解消、source=manualの解除も必要。
- (D) 欠落した`attendance_day.created`の挿入(版1、後続を振り直し): `renumberStream`方式で技術的に可能。欠落した事実の補完。
