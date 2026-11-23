# rebuild-drift-fixes(ReadModelの作り直しで内容がずれる不具合の修正)

ステータス: レビュー中

## 変更要望(原文)

変更セット`20261123-boolean-column-naming`のリハーサルで、旧コードのままでも`read-models:rebuild --dry-run`が2表で差分を出した。
このずれがあると、改名の書き換えコマンド(`events:rename-boolean-properties --execute`)は検証に失敗して戻る。リリースの前提として直す。
設計レビューで、同じ種類のずれをさらに見つけたため、併せて扱う。

## 原因

### 不具合1: 削除した行の子行が、作り直すと残る

- `ReadModelRebuilder`(`app/Domain/EventSourcing/Rebuild/ReadModelRebuilder.php:52-55`)は、外部キーの検査を切ってから全表を空にし、全Projectorを再生する。
  - 検査を切っている間は`cascadeOnDelete`が働かない(MySQLは`FOREIGN_KEY_CHECKS=0`、SQLiteはトランザクションの前にPRAGMAで切る)。
- 次の削除ハンドラは親行だけを消し、子行は`cascadeOnDelete`に任せている。そのため作り直すと子行が孤児として残り、ライブと一致しない。

| 削除イベント(ハンドラ) | 親 | cascadeに頼っている子(子の行を書くProjector) |
|---|---|---|
| `attendance_day.deleted`(`AttendanceDayProjector:75`) | attendance_days | attendance_breaks・attendance_leave_segments(AttendanceDayProjector)、attendance_daily_calculations(AttendanceDailyCalculationProjector)、attendance_weekly_overtime_allocations(AttendanceWeeklyOvertimeAllocationProjector) |
| 備品の削除(`AssetProjector:72`) | assets | asset_default_location_changes・asset_loans・asset_placements |
| 会社カレンダーの削除(`CompanyCalendarProjector:73`) | company_calendars | company_calendar_years → company_calendar_days → company_calendar_day_sources の3段 |
| 会社カレンダー年度の削除(`CompanyCalendarYearProjector:115-118`) | company_calendar_years | company_calendar_days → company_calendar_day_sources |
| 祝日の出典の削除(`HolidayCalendarSourceProjector:211`) | holiday_calendar_sources | holiday_calendar_events。`company_calendars.holiday_calendar_source_id`は`nullOnDelete`だが、`DeleteHolidayCalendarSourceHandler.php:26`の使用中チェックがあるため発動しない |
| 経費の下書き削除(`ExpenseClaimProjector:195`) | expense_claims | expense_items |

- リハーサルで実際に差分になったのは、削除した勤怠日の`attendance_daily_calculations`の1件だけ。残りはリハーサルのデータに無かっただけ。

### 不具合2: カレンダー一括操作の対象行のIDが、作り直すたびに変わる

- `CalendarBulkOperationProjector`(:36-47)は、対象行のIDを`Str::uuid()`で毎回作る。
  - そのため作り直しの結果がライブと一致することはない。カレンダー一括操作を一度でも使った環境では、改名の`--execute`が必ず失敗する。
- 取消イベント`calendar_bulk_operation.reverted`の`revertedTargetIds`・`excludedTargetIds`(CalendarBulkOperationReverted.php:14-19)は、このIDを記録している。
  - 作り直した後は、存在しないIDを指す。取消の処理そのものはIDに依存しないので動くが、監査情報が食い違う。
- 対象行のIDを参照しているのは、取消イベントとAPI(`CalendarBulkOperationTargetResource.php:16`。frontendの型では任意)だけ。他のイベント・表・MCP・画面からの参照は無い。

### 不具合3: 自動採番IDを参照する列が、作り直すと比較で差分になる

- `company_calendar_day_sources.company_calendar_day_id`は、`company_calendar_days.id`(自動採番)を参照している(`HolidayCalendarSourceProjector.php:126`)。
  - `DELETE`では採番がリセットされないので、作り直すとidが変わる。参照先の行そのものは正しく作り直される。
- `ReadModelFingerprinter`(:81-95)は自動採番の主キーだけを比較から除き、それを参照する列は比較に含める。そのため祝日同期の出典が1件でもある環境では、必ず差分になる。
- イベント側の参照(祝日同期イベントの`company_calendar_day_id`)は、既存の`HolidayCalendarDayRefRebuilder`で年度id+日付に補われ、Projectorも年度+日付で日を引く(:193-199)。イベントの修正は不要。

### 不具合4: 取消済みの一括操作をもう一度取り消せる

- `CalendarBulkOperationAggregate`は状態を持たず、取消のたびに無条件でイベントを記録する。`RevertCalendarBulkOperationHandler.php:30`にも確認が無い。
- 二重に取り消すと、復元処理がもう一度走る。

## 仕様検討

### 論点1: 子行の削除をどこで保証するか(不具合1)
- 選択肢:
  - A: 削除イベントで、子の行を明示的に消す。
  - B: `ReadModelRebuilder`で、再生の間も外部キーの検査を有効にする。
  - C: 作り直しの後に孤児行を掃除する。
- 決定: A。
  - 子の表を書くProjectorが、親の削除イベントを購読して自分の表の行を消す(Projectorは自分の表だけを書く)。
  - 同じProjectorが親と子の両方を書く場合は、そのハンドラで子→親の順に消す。
  - 3段の連鎖は、親の削除イベント1つから全段を消す。例えば会社カレンダーの削除では、年度・日・出典を書くProjectorがそれぞれ同じ削除イベントを購読し、該当する行を消す。カレンダーの年度の削除も同様。
  - 同じイベントを複数のProjectorが処理する順序は問わない。ライブで親が先に消えればcascadeで子が消えて子のdeleteは0件になり、子が先なら子が消える。どちらの順でも結果は同じ。
- 理由:
  - B は、同じイベントを複数のProjectorが処理する順序次第で外部キー違反になりうる。また`event-sourcing:replay`など別の経路では効かない。
  - C は症状を隠すだけ。
  - A は、ライブでも作り直しでも同じ結果になる。
- 過去イベントの修正: 不要(派生データの作り方だけの問題)。
- `cascadeOnDelete`の定義は残す(二重に消しても害はない)。
- 未確定・要確認事項: なし

### 論点2: 一括操作の対象行のIDをどう安定させるか(不具合2)
- 選択肢:
  - A: 適用イベントの`targets`にIDを入れる(Handlerで生成)。過去のイベントにはライブのIDを補う。
  - B: Projectorで、イベントの内容から決まるIDを作る。
- 決定: B。
  - IDは`Uuid::uuid5(CalendarBulkOperationProjector::TARGET_ID_NAMESPACE, "{操作ID}:{位置}")`とする。
  - 位置は、適用イベントの`targets`配列内の0始まりの添字。
  - 名前空間は新しい定数にし、値は実装時に1度だけ生成して固定する。中間版とHEADで同じ値を使う。
- 理由:
  - B は、過去の適用イベントを変えずに、いつ再生しても同じIDになる。
  - 利用者+日付をキーにしない理由: 明細指定の操作では、同じ利用者・日付の重複が検証で弾かれていない(`CalendarBulkOperationPlanner.php:221-229`)。配列内の位置なら必ず一意になる。
- 未確定・要確認事項: なし

### 論点3: 過去の取消イベントの補正(不具合2の履歴)
- 決定: 過去の`calendar_bulk_operation.reverted`の`revertedTargetIds`・`excludedTargetIds`を、新しいIDに書き換える(`data-correction`)。
  - 例外の理由: 取消の時点でどの対象を取り消したかという事実は、ライブの対象行(旧IDと内容の対応)からしか得られない。
    - 論点2で案Aを退けたのは、適用イベントの将来の再生のためにライブの値を持ち込むことを避けるため。
    - この補正は、過去の取消の事実を新しいIDの体系で書き直すためのもので、事実の出所がライブの行しかない例外にあたる。
- 対応表(旧ID→新ID)の作り方:
  - ライブの`calendar_bulk_operation_targets`の各行を、同じ操作の適用イベントの`targets`の要素と突き合わせる。
  - 突き合わせるキーは、利用者・日付・`employee_calendar_entry_id`・結果・`error_code`・`previous_snapshot`。
  - キーが同じ要素が複数あれば、内容が同一なので任意の順で1対1に対応させる(どちらでも事実は同じ)。
  - 取消イベントに対応が付かないIDが1つでもあれば、`--execute`を止めて一覧を出す。
- 二重取消の既存データ: 1つの操作に取消イベントが複数あっても、それぞれ同じ対応表で書き換える。イベントは消さない(過去の事実として残す)。
- コマンド: `events:fix-calendar-bulk-operation-target-ids`(`data-correction`の安全手順に従う)。
  - 試し実行が既定。書き換える件数、対応表、対応が付かないIDを表示する。
  - `--execute`は次の順に行う。
    1. バックアップ表を作る。
    2. 書き換える。
    3. 補正ログ(修正したイベントのid・修正前のpayload)を出す。
    4. `ReadModelRebuilder`で作り直す(作り直しはこの1回だけ。手順の後段で別に`read-models:rebuild`は実行しない)。
    5. 検証する。失敗したらトランザクションを戻す。
  - 冪等: 書き換え後のIDがすでに新しい体系(UUIDv5の再計算と一致)のイベントは何もしない。2回目の試し実行は「対象なし」になる。
- 合格条件(すべて満たすこと):
  1. 作り直した後の対象行と、書き換え前のライブの対象行が、id以外の全列で一致する。
  2. 対応表どおりに、旧IDの行と新IDの行が対応する。
  3. 取消イベントのIDが、すべて作り直した対象行に存在する。
- 作り直しの拒否: 未補正の取消イベント(IDがUUIDv5の再計算と一致しないもの)がある間は、作り直しを拒否する。
  - 拒否するのは`read-models:rebuild`・`projections:rebuild`・管理画面の作り直し。改名の`LegacyBooleanPropertyDetector`と同じ方式で検出器を作る。
  - 理由: 補正の前に作り直しが走ると、旧IDの行が消えて対応表を作れなくなる。
- 本番の実行には、試し実行の結果を示したうえでユーザーの明示的な許可を得て、許可を記録に残す。
- 未確定・要確認事項: なし

### 論点4: 自動採番IDを参照する列の比較(不具合3)
- 選択肢:
  - A: `ReadModelFingerprinter`で、自動採番の主キーを参照する外部キー列を、参照先の行の内容(主キーを除いた正規化した値)に置き換えて比較する。
  - B: 作り直しの前に採番をリセットする。
  - C: 参照を自然キー(年度id+日付)に変える(スキーマ変更)。
- 決定: A。外部キーの定義(`getForeignKeys`)から自動的に判断し、表ごとの個別の指定は持たない。
- 理由:
  - B はライブの採番に欠番があると、作り直した結果のidが一致しない。
  - C はスキーマとProjectorの変更が大きく、イベント側はすでに年度+日付で引けているので、得るものが少ない。
  - 参照先の行は正しく作り直されている(参照の意味は保たれている)ので、比較の方法を直すのが正しい。
- 未確定・要確認事項: なし

### 論点5: 取消済みの一括操作の再取消(不具合4)
- 決定: `CalendarBulkOperationAggregate`に状態(取消済みか)を持たせ、取消済みの操作の取消は集約で拒否する。APIは業務エラー(422)で返す。
- 理由: Handlerで読み取りモデルを見て拒否すると、同時に取り消されたときに防げない。
- 業務ルールのテストを追加する(`domain-test`)。
- 未確定・要確認事項: なし

### 論点6: 作り直しを決定的にするための規則
- 決定: `add-projection`スキルに次の規則を加える。
  - 外部キーの`cascadeOnDelete`・`nullOnDelete`に頼らず、自分の表の行を削除イベントで明示的に消す(論点1)。
  - Projectorで乱数のID(`Str::uuid()`等)を作らない。イベントの内容から決まるID(UUIDv5)にする。
  - 自動採番のIDをイベントに記録しない。他の表から参照する場合は比較の対象外の前提を理解する(論点4)。
  - 再生の時点の設定値(`SystemSetting::current()`等)を使わない。必要な値はイベントに持たせる。
- 未確定・要確認事項: なし

### 論点7: 命名統一(20261123-boolean-column-naming)とのリリース順
- 決定: 本変更セットは、改名前のコード(`cd1d40ce`)に本変更セットのコードを載せた中間版として、命名統一より先にリリースする。中間版は必須とする。
  - 理由:
    - HEADで補正コマンドを実行しても、旧項目名のイベントが残っている間は作り直しが拒否される。
    - 一方、改名を先に実行すると、既存のずれで検証が必ず失敗する。
- 中間版の手順。いずれもメンテナンス中(`down`・cron・キューの停止)に行う。
  1. 中間版を配備する。
  2. `events:fix-calendar-bulk-operation-target-ids`の試し実行 → `--execute`(作り直し込み)。
  3. `read-models:rebuild --dry-run`で、差分が0になることを確かめる。
  4. `up`。
- 中間版の作り方:
  - 本ブランチで、コードのコミットとドキュメントのコミットを分ける。
  - コードのコミットは、真偽値の改名に触れない(テストのpayloadも改名前後で共通の項目だけを使う。改名された項目が要る場合は中間版用に分ける)。
  - 受け入れ条件: `cd1d40ce`にコードのコミットを載せた作業ツリーで、`php artisan test`が全件通ること。
  - 中間版のブランチをリモートに作るかは、リリース時にユーザーの許可を得て決める(本変更セットでは、作業ツリーでの確認までを行う)。
- docs/27 §3.7(命名統一の手順書)を次のように直す。
  - 「旧コード」(手順1の基準値の取得、戻し方の(c)など)は、`cd1d40ce`ではなく中間版を指す。
  - 手順1で既存のずれが0でなければ、ずれの表を確認し、原因を特定するまで進めない。
- 未確定・要確認事項: なし

## 受け入れ条件
- 削除を含むシナリオ(勤怠日の削除・会社カレンダーと年度の削除・祝日の出典の同期と削除・経費の下書き削除・備品の削除・一括操作の適用と取消)の後に、`ReadModelRebuilder`の試し実行で`changed_tables == 0`になる。
  - Featureテストで確かめる。
  - テストは既存の`ReadModelRebuilderTest`と同じく`DatabaseMigrations`を使い、作り直しの間に外部キーが切れていることを確かめる。`RefreshDatabase`ではSQLiteのPRAGMAがトランザクション内で効かず、不具合1を再現できない。
- 一括操作の対象行のIDが、作り直しの前後で同じになる。
- 補正コマンドについて、次をテストする。
  - 試し実行・実行・冪等性。
  - 対応が付かない場合の停止。
  - 重複する対象の対応付け。
  - 二重取消のデータ。
  - バックアップと補正ログ。
  - 未補正の間の作り直しの拒否。
- 取消済みの操作の再取消は422になる。
- 中間版の作業ツリーで、`php artisan test`が全件通る。

## 対象外
- `UserProjector`(:32,54,77,129)・`UserManagementProjector`(:53)が、再生の時点の`default_timezone`・`m365_tenant_id`を使う問題。
  - 設定を変えた後に作り直すと`users`・`external_identities`がずれるが、イベントの構成の変更(値をイベントに持たせ、過去のイベントを補う)が必要になる。別の変更セットで扱う。
  - リハーサルでは差分になっていない。本番の手順3で差分が出た場合は、そこで止めて扱う。
- 外部キーの定義そのものの変更。

## ドキュメントへの影響
- `.claude/skills/add-projection`: 論点6の規則。
- `.claude/skills/data-correction`: 例として本補正を追記する(任意)。
- `docs/27`: 中間版の手順を追加し、§3.7の「旧コード」を中間版に直す。
- `docs/17-events.md`: `calendar_bulk_operation.reverted`のIDが決定的なIDであることを追記する。
- `docs/08`(UC-C013): 再取消の拒否を追記する。

## モック・アセット
なし

## 実装対象
1. 論点1: 削除イベントで子の行を消す(6つの削除イベント、各Projector)。
2. 論点2・3: 決定的なID、補正コマンド、検出器と作り直しの拒否、テスト。
3. 論点4: `ReadModelFingerprinter`の外部キーの比較、テスト。
4. 論点5: 集約の状態と再取消の拒否、テスト。
5. 作り直しが一致することのシナリオテスト。
6. ドキュメント・スキルの更新(別コミット)。
7. 中間版の作業ツリーでの全件テスト。

## 検証方法
- `php artisan test`の全件(HEADと中間版の両方)。
- 旧e2eで作ったDB(リハーサルDB A)で、次を確かめる。
  - 中間版で補正の試し実行・`--execute`を行い、作り直しの差分が0になること。
  - そのうえで、HEADで命名統一の`--execute`が通ること。

## レビュー履歴
- 2026-11-23 設計レビュー(general-purpose、独立):
  - 高: 削除の一覧の漏れ(年度の削除・週次の割当・3段の連鎖) → 反映。
  - 高: 自動採番IDの参照による比較のずれ → 論点4。
  - 高: 補正の前の作り直しで対応が失われる → 検出器で拒否。
  - 高: 中間版が必須 → 論点7。
  - 中: テストはDatabaseMigrationsで → 受け入れ条件。
  - 中: 再生時点の設定値 → 対象外(理由付き)と規則。
  - 中: 補正の方針の矛盾・重複の対応付け・補正ログ・冪等・作り直しの二重・二重取消・合格条件 → 論点3。
  - 中: cherry-pickの見通し → 全件テストを条件に、コミットを分ける。
  - 低: UUIDv5の仕様・再取消の置き場所・子を消すProjector → 論点2・5・1。

## 実装結果
未着手
