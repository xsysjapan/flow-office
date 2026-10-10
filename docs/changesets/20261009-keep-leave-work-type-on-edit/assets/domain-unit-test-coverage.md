# 業務ルールの単体テストの現状(2026-10-10、investigator調査・委譲元で一部検証済み)

- テスト: Unit 17ファイル(約148メソッド)、Feature 138ファイル(約954メソッド)。Unitの17ファイル中14ファイルが`Tests\TestCase`
  (Laravel起動)を継承し、10ファイルが`RefreshDatabase`を使う(委譲元で検証: 純粋な`PHPUnit\Framework\TestCase`は3ファイル)。
- 置き場所: Aggregate(48)はEloquent非依存。Services(48)の約33件、Handlers(207)の約186件がEloquent直接依存で業務ルールが混在。
- 純粋+Unitあり: `PaidLeaveAccountAggregate`(40件)、`PaidLeaveScheduleAggregate`(27件)、`AllocationPlanner`。
- 純粋だがUnitなし: `AttendanceCalculator`(入力がEloquentモデル、Featureのみ)。

## 代休(CompensatoryLeave): Unitテスト0件、Feature4ファイル35件
| 業務ルール | 置き場所 | 問題点 |
|---|---|---|
| 付与日数換算(daily/half_day/hourly・半日しきい値) | `Services/CompensatoryLeaveGrantCalculator.php:19-27` | 純粋だがUnitなし、引数がモデル |
| 有効期限 | `ConfirmCompensatoryLeaveGrantsForMonthHandler.php:26-28` | `SystemSetting::current()`直読み・Handler内 |
| 勤務予定日・休暇種別制限・時間単位 | `RequestCompensatoryLeaveHandler.php:55-66,88-107,215-234` | privateでHandler経由のみ |
| 重複申請チェック | `RequestCompensatoryLeaveHandler.php:182-209` | DB依存 |
| 承認時の充当(期限が近い順→無期限) | `ApproveCompensatoryLeaveRequestHandler.php:98-130` | SQL内の順序・privateループ |
| 付与取消(未使用のみ) | `CancelCompensatoryLeaveGrantHandler.php:29-50`ほか | Handler内 |
| 付与確定(月次提出) | `ConfirmCompensatoryLeaveGrantsForMonthHandler.php:31-35` | 文字列LIKE判定 |
| 勤怠からの同期(休日出勤判定) | `SyncCompensatoryLeaveGrantHandler.php:34-75` | Handler内 |
| 残数・消化集計 | `CompensatoryLeaveGrantProjector.php:210-244` | Projector内、Aggregateに不変条件なし |

## 原因の傾向
1. 業務ルールがHandler内でEloquent取得と混在。2. 純粋なルールがprivateに閉じている。3. Services/Supportがモデルや
`SystemSetting::current()`を直接読む。4. Projectorが残数計算を持ちAggregateに不変条件が無い。5. Unitテストが全て
アプリ起動で純粋テストと区別されていない。既存ルール(`backend/CLAUDE.md:78-82`)はテストの配置のみで単体化の基準が無かった。
