# 休暇まわりの文脈間結合の現状(2026-10-10、investigator調査・委譲元で主要箇所を検証済み)

パスは backend/app/Domain/ 配下。

## docsの方針
- `docs/03-architecture.md:118-124`: 複数集約にまたがる副作用はCommandHandlerが正データを直接読み書きする、と明記
  (`ApprovePaidLeaveRequestHandler`が承認1件で申請・付与・勤怠日の3集約を更新する例)。**現状の結合を正当化している記述(検証済み)**。
- `docs/29-event-sourcing-framework-migration.md:312-322`: Reactorは「イベントを見て新Commandを発行する副作用」専用。
- コレオグラフィー/オーケストレーションの方針記述は無い。
- `docs/09-usecases-paid-leave.md:216`は全休のwork_type反映を「Workflow側Reactor」が担うと記載(実装はRequestPaidLeaveHandler内)。

## Handlerからの文脈横断
| 箇所 | 呼び先 | 種別 |
|---|---|---|
| PaidLeave/RequestPaidLeaveHandler:126 | DesignatePaidLeaveUsage(残数) | Command |
| 同:147 | SubmitWorkflowRequest(Workflow) | Command(インライン、特別・代休はShared+Reactor・検証済み) |
| 同:101 | 特別休暇申請Projection | 直接読取(重複チェック) |
| 同:179,193 | AttendanceDay create/save | 直接書込 |
| 同:161 | AttendanceCalculator→AttendanceDayAggregate::calculate() | 勤怠集約操作 |
| PaidLeave/ApprovePaidLeaveRequestHandler:62,67,73,83 | AttendanceDay・PaidLeaveUsage読取、ConfirmPaidLeaveUsage、勤怠計算 | 読取/Command/集約操作 |
| PaidLeave/CancelPaidLeaveRequestHandler:78,96,102,115 | CancelPaidLeaveUsage、AttendanceDay save、勤怠計算、CancelWorkflowRequest | Command/直接書込/集約操作 |
| PaidLeave/ReturnPaidLeaveRequestHandler | 通知のみ(Usage取消なし) | - |
| Special/Comp Request | 勤怠日create/save(Special:156,170 / Comp:145,159)・勤怠計算、他休暇Projection読取(Special:184 / Comp:185,195) | 直接書込/集約操作/読取 |
| Special/Comp Approve | 申請集約+付与集約をpersistInTransaction(Special:79 / Comp:82)、付与Projection読取(Special:104 / Comp:108)、勤怠計算 | 集約操作/読取 |
| Special/Comp Cancel | reverseUsage、勤怠日save、勤怠計算 | 集約操作/直接書込 |
| Special/Comp Return | 申請集約のみ(Usage取消なし) | - |

## Workflow側Reactor(Spatie Reactor、同期・ShouldQueue無し・検証済み)
- Drafted→Request*Leave、Approved→Approve*LeaveRequest(有給は兄弟申請を再帰承認 PaidLeaveApprovalOn...:51-90)、Returned→Return*LeaveRequest、
  特別・代休のCancelled→CancelWorkflowRequest、Shared→SubmitWorkflowRequest。
- 勤怠側に休暇イベントを購読するReactorは無い(Attendance/Reactorsは週40時間配賦とバックオフィスタスクのみ・検証済み)。
  逆方向は既にReactor化(SyncCompensatoryLeaveGrantOnAttendanceDayCalculatedReactor)。

## 残数・使用管理の境界
- 有給: PaidLeaveAccountAggregate(userId単位)がgrant/usageを保持。PaidLeaveは集約を持たずHandlerのみ。
  **paid_leave_requestsは残数側のPaidLeaveUsageAllocationProjector(:127-202)が更新**し、WorkflowRequestReturnedも購読してRETURNEDにする
  → 申請状態の所有者が不明確。
- 特別・代休: 申請集約と付与集約が同一ドメイン内に別々にある。usage作成は申請集約(designateUsage)、消化・取消は付与集約(use/reverseUsage)。

## 実行モデル
- CommandBus::dispatchはhandlerごとにDB::transaction。Reactorは同期で、Reactor内のdispatchは入れ子トランザクションになり、例外は伝播する
  (catch_exceptions=false)。→ 1回の利用者操作の連鎖は全体として原子的。
- Reactorに処理済み管理は無く、冪等性はHandlerの状態ガード頼み。Projectorはstored_event_idのユニーク制約で冪等。
- replayはProjectorのみで、Reactorは再実行されない。

## 月次集計
- 月次の有給日数等は日次計算(`AttendanceDailyCalculationProjector.php:83`のpaid_leave_days)を集計しており、
  給与連携(MoneyForward CSV・freee API・Excel)はその月次スナップショットを使う(検証済み)。
  → 勤怠日の行(と日次計算)が無い日は月次の休暇日数に入らない。
