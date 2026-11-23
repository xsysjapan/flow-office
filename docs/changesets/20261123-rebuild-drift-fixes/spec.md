# rebuild-drift-fixes(ReadModelの作り直しで内容がずれる不具合2件の修正)

ステータス: レビュー中

## 変更要望(原文)

変更セット`20261123-boolean-column-naming`のリハーサルで、旧コードのままでも`read-models:rebuild --dry-run`が2表で差分を出した。
このずれがあると、改名の書き換えコマンド(`events:rename-boolean-properties --execute`)は検証に失敗して戻る。リリースの前提として直す。

## 原因

### 不具合1: 削除した行の子行が、作り直すと残る(`attendance_daily_calculations`ほか)

- `ReadModelRebuilder`(`app/Domain/EventSourcing/Rebuild/ReadModelRebuilder.php:52`)は、外部キーの検査を切ってから全表を空にし、全Projectorを再生する。
  - 検査を切っている間は、`cascadeOnDelete`が働かない(SQLite・MySQLとも)。
- 次のProjectorは、親行を消すだけで子行は`cascadeOnDelete`で消える前提にしている。
  - そのため作り直すと子行が孤児として残り、ライブと一致しない。
  - リハーサルで確認できたのは1件だけ: 削除した勤怠日の`attendance_daily_calculations`。

| Projector | 親 | cascadeに頼っている子 |
|---|---|---|
| `AttendanceDayProjector::onAttendanceDayDeleted`(:71) | attendance_days | attendance_breaks・attendance_leave_segments・attendance_daily_calculations |
| `AssetProjector`(:70) | assets | 要確認(実装時に列挙する) |
| `CompanyCalendarProjector`(:68) | company_calendars | company_calendar_years → company_calendar_days |
| `HolidayCalendarSourceProjector`(:206) | 出典 | 要確認 |
| `ExpenseClaimProjector`(:189) | expense_claims | expense_items |

- `CompanyCalendarYearProjector`(:124)だけは、すでに子を明示的に消している。これが前例になる。

### 不具合2: カレンダー一括操作の対象行のIDが、作り直すたびに変わる

- `CalendarBulkOperationProjector`(:36-47)は、対象行のIDを`Str::uuid()`で毎回作る。
  - そのため作り直しの結果がライブと一致することはない。カレンダー一括操作を一度でも使った環境では、改名の`--execute`が必ず失敗する。
- 取消イベント`calendar_bulk_operation.reverted`の`revertedTargetIds`・`excludedTargetIds`は、このIDを記録している。
  - 作り直した後は、存在しないIDを指す。取消の処理そのものはIDに依存しないので動くが、監査情報が食い違う。
- 画面はIDを使っていない(`CalendarBulkOperationsPage.tsx:327`は利用者+日付をkeyにしている)。APIは`id`を返している。

### 調査中に見つかった別の不具合

- 不具合3: 取消済みの一括操作をもう一度取り消せる。
  - `RevertCalendarBulkOperationHandler.php:30`に状態の確認が無い。
  - 二重に取り消すと、復元処理がもう一度走る。

## 仕様検討

### 論点1: 子行の削除をどこで保証するか
- 選択肢:
  - A: 各Projectorで、親を消す前に子を明示的に消す。
  - B: `ReadModelRebuilder`で、再生の間も外部キーの検査を有効にする。
  - C: 作り直しの後に孤児行を掃除する。
- 決定: A。
- 理由:
  - B は、同じイベントを複数のProjectorが処理する順序次第で外部キー違反になりうる。また`event-sourcing:replay`など別の経路では効かない。
  - C は症状を隠すだけ。
  - A は、ライブでも作り直しでも同じ結果になる。すでに前例(`CompanyCalendarYearProjector`)もある。
- 規則にする: 「Projectorは外部キーの`cascadeOnDelete`に頼らず、子のReadModelを明示的に消す」を、`add-projection`スキルに書く。
- 過去イベントの修正: 不要(派生データの作り方だけの問題)。
- 未確定・要確認事項: なし

### 論点2: 一括操作の対象行のIDをどう安定させるか
- 選択肢:
  - A: 適用イベントの`targets`にIDを入れる(Handlerで生成)。過去のイベントにはライブのIDを補う。
  - B: Projectorで、イベントの内容から決まるIDを作る。
- 決定: B。IDは`UUIDv5(名前空間, "{操作ID}:{targets内の位置}")`とする。
- 理由:
  - B は過去の適用イベントを変えずに、いつ再生しても同じIDになる。
  - A は過去のイベントにライブ(派生データ)の値を書き戻すことになり、「ReadModelはイベントから再生成する」の向きが逆になる。
  - 利用者+日付をキーにしない理由: 明細指定の操作では、同じ利用者・日付の重複が検証で弾かれていない(`CalendarBulkOperationPlanner.php:221-229`)。配列内の位置なら必ず一意になる。
- 既存の取消イベントの補正(`data-correction`): 過去の`calendar_bulk_operation.reverted`の`revertedTargetIds`・`excludedTargetIds`を、新しいIDに書き換える。
  - 対応表(旧ID→新ID)は、ライブの`calendar_bulk_operation_targets`の行と、適用イベントの`targets`の位置との突き合わせで作る。
  - 突き合わせは、操作ID・利用者・日付・`employee_calendar_entry_id`・結果で行う。それでも重複する場合は、内容が同じなので作成順に対応させる。
  - 対応が付かないIDがあれば、`--execute`を止めて一覧を出す。
- 補正コマンド: `events:fix-calendar-bulk-operation-target-ids`。
  - 試し実行が既定。`--execute`で書き換えと作り直しと検証を行う。
  - バックアップ表を作る。合格条件は、書き換え後の作り直しの結果が、不具合1・2を直した新しいコードの作り直しの結果と一致すること。
  - 作り直しの結果は、新しいIDの分だけライブと変わる。ライブとの比較ではなく、「この表以外の表は変わらない」ことと、「取消イベントのIDがすべて新しい対象行に存在する」ことを合格条件にする。
- 未確定・要確認事項: なし

### 論点3: 取消済みの一括操作の再取消(不具合3)
- 決定: 状態が`reverted`の操作の取消は、業務エラー(422)で拒否する。業務ルールのテストを追加する。
- 未確定・要確認事項: なし

### 論点4: 命名統一(20261123-boolean-column-naming)とのリリース順
- 決定: 本変更セットを先に、別のメンテナンス窓(または同じ窓の前段)でリリースする。手順は次のとおり。
  1. 本変更セットのコードを配備する(改名前の命名のまま)。
  2. `events:fix-calendar-bulk-operation-target-ids --execute`を実行する。
  3. `read-models:rebuild`で、子行の孤児とIDのずれを解消する。
  4. `read-models:rebuild --dry-run`で、差分が0になることを確かめる。
  5. そのうえで命名統一のリリース手順(docs/27 §3.7)に進む。
- 理由: 命名統一の書き換えは「作り直しの結果がライブと一致する」ことを合格条件にしている。先にずれを0にしておく必要がある。
- 実装上の扱い: ブランチ上では命名統一の後のコミットになる。そのため本変更セットの修正は、新旧どちらの命名でも動くように特定の真偽値の項目に触れない(触れるのはID・削除処理・取消の状態確認だけ)。
  - リリース用には、改名前のコミット(`cd1d40ce`)に本変更セットのコミットを載せた中間版を作れるようにする。cherry-pickで当たることを確かめる。
- docs/27 §3.7 の手順1(既存のずれが0であること)に、「0でなければ本変更セットの手順を先に行う」を書き足す。
- 未確定・要確認事項: なし

## 受け入れ条件
- 削除を含むシナリオ(勤怠日の削除・カレンダー削除・経費の下書き削除・備品削除・出典削除・一括操作の適用と取消)の後に、`ReadModelRebuilder`の試し実行で`changed_tables == 0`になる。Featureテストで確かめる。
- 一括操作の対象行のIDが、作り直しの前後で同じになる。
- 補正コマンドの試し実行・実行・対応が付かない場合の停止・バックアップをテストする。
- 取消済みの操作の再取消は422になる。

## 対象外
- 管理番号採番・勤怠計算などのロジックの変更。
- 外部キーの定義そのものの変更(`cascadeOnDelete`は残す。ライブで二重に消しても害はない)。

## ドキュメントへの影響
- `.claude/skills/add-projection`: cascadeに頼らない規則を追加する。
- `docs/27`: §3.7の手順1に追記し、本補正の手順を追加する。
- `docs/17-events.md`: `calendar_bulk_operation.reverted`のIDの意味(決定的なID)を追記する。
- `docs/08`(UC-C013): 再取消の拒否を追記する。

## モック・アセット
なし

## 実装対象
1. 論点1: 5つのProjectorで子を明示的に削除する。`add-projection`スキルを更新する。
2. 論点2: Projectorの決定的なID、補正コマンドとテスト。
3. 論点3: 再取消の拒否とテスト。
4. 作り直しが一致することのシナリオテスト。
5. ドキュメントの更新。

## 検証方法
- `php artisan test`の全件。
- 旧e2eで作ったDB(リハーサルDB A)で、補正の試し実行・`--execute`・作り直しを行う。
  - 作り直しの差分が0になることを確かめる。
  - そのうえで命名統一の`--execute`が通ることを確かめる。

## レビュー履歴

## 実装結果
未着手
