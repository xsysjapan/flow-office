# 所定労働時間を休暇で減らさず、勤怠集計の表示を「所定内労働時間」「残業時間」に揃える

ステータス: レビュー中

## 変更要望(原文)
> 月次集計で、今月の集計の所定労働時間について仕様を教えてください。有給休暇(有給休暇・特別休暇・代休)がある場合には、所定労働時間は減るべきと思うのですが、所定労働時間の計算がおかしい気がします。

(法律上の所定労働時間の意味を説明した後)

> 所定労働時間は減らさない方向に変更して下さい。そのうえで、画面表示上は所定労働時間ではなく、所定内労働時間に変更して下さい。
>
> 所定内労働時間は所定内法定内労働時間+所定内法定外労働時間、残業時間は所定外法定内労働時間+所定外法定外労働時間でお願いします。

## 背景・目的
法律上の所定労働時間は、就業規則・労働契約で定めた労働義務の時間であり、休暇を取っても減らない。
現行の日次計算は半休が1つだけの日にこれを半分(P/2)にしており、全休(P のまま)と扱いが矛盾している。
また勤怠集計の簡易表示が「所定労働時間」(義務の時間)と「残業時間」(旧3区分)を並べているため、
働いた時間の内訳として読めない。所定労働時間は常に P とし、画面には実労働の内訳(5区分)から
「所定内労働時間」「残業時間」を出す。

## 現状(As-Is)
- 日次: `backend/app/Domain/Attendance/Support/LeaveCalculationInput.php:79` が
  `effectivePrescribedMinutes` を「全休→P / 半休1つ→P/2 / それ以外→P」で決める。
  `AttendanceCalculator.php:136` がこれを `$prescribedWorkMinutes` として使い、次の3つに使っている。
  1. 保存値 `prescribed_work_minutes`(`:258`。法定休日・所定休日は0)
  2. 法定内残業の判定基準 `$overtimeBaselineMinutes`(`:177`。1か月単位変形は planned を使う)
  3. 所定内/所定外の振分け基準 `$prescribedBaselineMinutes`(`:243`。planned が0のときのみ)
- 月次: `MonthlyOvertimeCalculator::calculateCategoryTotals()` が日次の `prescribed_work_minutes` を合計する。
  `absence_days` は「欠勤時間 ≥ その日の prescribed_work_minutes」で数える(`:111`)。
- 画面: `frontend/src/components/AttendanceCalculationSummary/AttendanceCalculationSummary.tsx` の簡易表示が
  - 「所定労働時間」= `prescribed_work_minutes`(`:203`)
  - 「残業時間」= `statutory_within_overtime_minutes + statutory_excess_overtime_minutes`(`:106`)

  を表示する。このコンポーネントは月次確認・日次・週次・勤怠参照の各画面(`AttendanceMonthDetailPage`/
  `AttendanceDayPage`/`WeekAttendancePage`/`AttendanceReferencePage`)で使われる。詳しく表示側は既に5区分を出している。
- ドキュメント: `docs/07-usecases-attendance.md` の「同じ日の休暇の衝突」(653行目付近)と
  `AttendanceCalculator` のクラスコメントが「半休1つの日は所定を半分」と定めている。
- テスト: `tests/Unit/Attendance/LeaveCalculationInputTest.php`、
  `tests/Feature/Attendance/HalfDayLeavePrescribedMinutesTest.php`(P/2 を期待している)。

## 設計前提の検証
- 正データと派生データ: `prescribed_work_minutes` と5区分はいずれも `attendance_day.calculated` イベントの
  payload を Projector が写した派生データ(`attendance_daily_calculations`)。計算ロジックを変えると以後の
  計算イベントから値が変わり、既存日は再計算コマンドで新しい計算イベントを追記して直す(設計原則1・2に沿う)。
- 定義と実態: `prescribed_work_minutes` は名前上「所定労働時間」だが、実態は「保存値」「残業判定の基準」
  「所定内/外の振分け基準」の3役を兼ねており、半休の調整は後の2つのためのもの。これが今回の不整合の原因
  (設計の誤り: 1列に複数の意味)。今回は保存値と判定基準を分ける。
- 原因の分類: 設計の誤り(上記)。対症療法(半休でも P にするだけ)は残業判定を変えてしまうため採らず、
  基準値を別に持つ。
- 横展開: `AttendanceCalculationSummary` を使う全画面(月次・日次・週次・参照)に同じ表示変更が及ぶ。
  同じ集計を出す他の箇所(CSV・Excel・給与API連携)は「対象外」に記載。休暇の画面・APIは変更しない。
- 一覧のページング: 一覧の新設・変更なし。

## 仕様検討

### 論点1: 日次の所定労働時間(prescribed_work_minutes)の値
- 選択肢:
  - A. 休暇の有無にかかわらず、働き方の日所定時間 P とする(法定休日・所定休日は従来どおり0)
  - B. 休暇の分を差し引く(全休0・半休P/2・時間休P−時間)
- 決定: A
- 理由: ユーザー指示。法律上の所定労働時間(労働義務の時間)は休暇で減らない。
- 未確定・要確認事項: なし

### 論点2: 半休が1つだけの日の残業判定・所定内/外の振分けの基準
- 選択肢:
  - A. 基準は従来どおり P/2 を使う。保存する所定労働時間とは別の値として計算内で持つ
  - B. 基準も P にする(半休の日は P を超えるまで所定外にならない)
- 決定: A
- 理由: 今回の依頼は所定労働時間の値と表示の見直しで、残業時間(給与)の計算は変えない。
  半休の日に残り半日を超えて働いた時間を所定外とする現行の判定を維持する。
- 未確定・要確認事項: なし(Bに変える場合は、給与に影響するため社労士確認のうえ別の変更セットとする)

### 論点3: 簡易表示の項目と値
- 選択肢:
  - A. 「所定労働時間」を「所定内労働時間」に名前だけ変える(値は prescribed_work_minutes)
  - B. 5区分から算出する
- 決定: B
  - 所定内労働時間 = 所定内法定内労働時間 + 所定内法定外労働時間
  - 残業時間 = 所定外法定内労働時間 + 所定外法定外労働時間
- 理由: ユーザー指示。実労働時間 = 所定内労働時間 + 残業時間 + 法定休日労働時間 となり、表示が内訳として読める。
- 補足(挙動の変化):
  - 所定内法定外(所定が8時間を超える日の8時間超部分)は、これまで「残業時間」に含まれていたが「所定内労働時間」に移る。
  - 所定休日の労働は所定外として5区分に入るため「残業時間」に含まれるようになる(従来の旧3区分の残業時間には含まれなかった)。
  - 週40時間超を所定内から振り分けた分(ユーザーが対象日を選ぶ分)は「所定内労働時間」側に残る(振分けは所定内・所定外それぞれの中で法定内→法定外へ移すだけで、両者の合計は変わらない)。
  - 5区分が無い古い計算データは、詳しく表示と同じフォールバック(`prescribedWithin` 等の既存変数)を使う。
- 未確定・要確認事項: なし

### 論点4: 終日欠勤日数(absence_days)の判定基準
- 選択肢:
  - A. 保存値(P)で判定する(論点1の結果に従う)
  - B. 論点2の基準値(半休なら P/2)で判定する
- 決定: A
- 理由: ユーザー確認済み。終日欠勤は「その日の労働義務の全てを欠勤した日」であり、半休+残り半日欠勤の日は
  1日欠勤ではない。欠勤時間(分)は従来どおり出るため時間単位の控除はできる。判定ロジック(`MonthlyOvertimeCalculator`・
  フロントの日次/週次の同じ判定)は変更せず、保存値の変化に従わせる。
- 影響: freee API の `num_absences_for_deduction`・マネーフォワードCSV・Excelの欠勤日数が、今後提出する月から変わる
  (半休+残り半日欠勤の日が0日になる)。
- 未確定・要確認事項: なし

### 論点5: フレックスタイム制・管理監督者の5区分と週40時間
- 背景: 5区分の振分け(`AttendanceCalculator.php:241-249`)は `$isFlex`/`$isManagerSupervisor` を見ておらず、
  その日の所定を超えた分を所定外に入れている。従来の残業(旧3区分)は両制度とも0のため、新しい「残業時間」に
  値が出てしまう。また `WeeklyOvertimeCalculator` も両制度を除外しておらず、所定外を0にすると週40時間超を
  自動配賦できず、未振分けで月次提出が止まる。
- 選択肢:
  - A. 両制度は法定休日以外の日の労働を全て所定内(所定内法定内)にし、所定外・法定外は0にする。週40時間の判定からも除外する
  - B. 5区分の値をそのまま表示する
  - C. 今回は対象外にする
- 決定: A
- 理由: ユーザー確認済み。フレックスは清算期間で過不足を判定し(清算期間ダッシュボード)、管理監督者は労働時間の規定の
  適用除外であり、どちらも日・週ごとの残業を持たない。従来の残業が0である扱いと揃える。
- 詳細:
  - 法定休日の扱い・深夜(所定内の深夜内数になる)・所定休日労働時間(`prescribed_holiday_work_minutes`)は変えない。
  - `WeeklyOvertimeCalculator::calculateWeek()` は、その日の働き方が flex / manager_supervisor の日を週の合計から除く。
- 未確定・要確認事項: なし

### 論点6: 既存データの扱い
- 選択肢:
  - A. 再計算しない(以後、日次が再計算された日から新ルール)
  - B. `attendance:recalculate-days` で全て再計算する(週40時間の手動振分けも外れる)
  - C. 週40時間を手動で振り分けた日だけ除き、それ以外は再計算する
- 決定: C
- 理由: ユーザー確認済み。再計算すると、その日の週40時間の振分けは `AttendanceWeeklyOvertimeAllocationProjector` が削除し、
  `AutoAllocateWeeklyOvertimeReactor` が自動で振り分け直す。ただし自動配賦は所定外からしか移さないため、
  所定内から移した手動の振分けは戻らず、未振分けで月次提出が止まる。
- 詳細:
  - 「手動で振り分けた日」= その日の `attendance_weekly_overtime_allocations` の `prescribed_minutes` または
    `late_night_prescribed_minutes` が0より大きい日(自動配賦は所定内を使わないため、これで区別できる)。
    所定外だけを手動で振り分けた日は自動配賦と区別できないため、再計算の対象とする(自動で振り分け直される)。
  - `attendance:recalculate-days` に `--skip-manual-weekly-allocations` を追加し、該当日を
    「[除外] 週40時間の手動振分けがあるため対象外」として一覧に出す。既定の挙動は変えない。
  - 締め・提出済みの日の扱いは既存コマンドのとおり(日次計算のみ更新し、月次 snapshot は変えない)。
  - 本番での実行は運用で行う。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
1. `LeaveCalculationInput`
   - `effectivePrescribedMinutes` を廃止し、次の2つを持つ。
     - `prescribedMinutes`: 常に引数の P
     - `workBaselineMinutes`: 半休が1つだけなら `intdiv(P, 2)`、それ以外(全休・午前+午後半休・時間休・休暇なし)は P
   - 単体テストで全パターンを確認する。
2. `AttendanceCalculator`
   - 保存する `prescribed_work_minutes` には P(`prescribedMinutes`)を使う。法定休日・所定休日は従来どおり0。
   - `$overtimeBaselineMinutes`(非変形時)と `$prescribedBaselineMinutes`(planned が0のとき)には `workBaselineMinutes` を使う。
   - クラスコメントの半休の記述を新しい定義に直す。
   - 半休の日の5区分・残業・深夜の計算結果は変更前と同じになる。
   - 働き方が flex / manager_supervisor で法定休日でない日は、`prescribedBaselineMinutes` に関係なく
     実労働の全量を所定内(`prescribed_statutory_within_work_minutes`)にし、他の4つの5区分と深夜の所定外・法定外内数は0にする。
2-2. `WeeklyOvertimeCalculator::calculateWeek()`: flex / manager_supervisor の日を週の集計から除く。
2-3. `RecalculateAttendanceDaysCommand`: `--skip-manual-weekly-allocations` を追加(論点6)。
3. `MonthlyOvertimeCalculator`: 変更しない。
   - 月の `prescribed_work_minutes` 合計は、半休の日も P で合算される。
   - 半休+残り半日欠勤の日は、終日欠勤に数えなくなる。
4. `AttendanceCalculationSummary` の簡易表示
   - 「所定労働時間」→ ラベル「所定内労働時間」、値 `prescribedWithin + prescribedExcess`
   - 「残業時間」→ 値 `nonPrescribedWithin + nonPrescribedExcess`
   - 詳しく表示・他の項目は変えない。story の説明文とテストを更新する。
5. 日次の手動補正フォーム(`AttendanceDayPage`「所定労働時間(分)」)は所定労働時間そのものを補正する項目のため、名前を変えない。
6. テスト
   - `HalfDayLeavePrescribedMinutesTest` を新ルールに更新する。
     - 半休の日: `prescribed_work_minutes` = 480
     - 残業・5区分: 変更前と同じ値(午前半休+4h勤務+1h延長で所定外1hなど)
     - 月次合計: 480 + 480
     - 半休+残り半日欠勤: 終日欠勤に数えない
   - フレックス・管理監督者: 所定を超える日の5区分が全て所定内になり、週40時間超の週でも未振分けが出ない(月次提出できる)。
   - 再計算コマンド: 手動振分け(所定内から)の日が除外され、自動配賦の日は再計算後に自動で振り分け直される。
   - フロントのテスト: 所定内労働時間・残業時間の値を、所定内法定外・所定休日労働を含むデータで確認する。

## 受け入れ条件
- 半休が1つだけの日の日次計算で `prescribed_work_minutes` が P になり、残業・5区分・深夜の値は変更前と同じ。
- 全休・時間休・休暇なしの日の計算結果が変わらない(フレックス・管理監督者を除く)。
- フレックス・管理監督者の日の「残業時間」が0になり、週40時間超の週があっても月次提出が止まらない。
- `attendance:recalculate-days --skip-manual-weekly-allocations` が所定内からの手動振分けがある日を除外する。
- 勤怠集計の簡易表示に「所定労働時間」が無く、「所定内労働時間」(=所定内法定内+所定内法定外)と
  「残業時間」(=所定外法定内+所定外法定外)が表示される。
- `LeaveCalculationInputTest`・`HalfDayLeavePrescribedMinutesTest`・backend 全テスト・`AttendanceCalculationSummary` のテスト・frontend の型チェックと全テストが PASS する。

## 対象外
- 給与連携・CSV・Excelの出力値と項目名(freee API の `total_normal_work_mins` が `prescribed_work_minutes` を
  使っている点など、出力側の意味の見直しは別の変更セットで扱う)。
  なお今後提出する月の snapshot では、半休の日の所定労働時間が P で合算される。
- 半休の日の残業判定基準そのものの変更(論点2のB)。
- 1か月単位変形・シフト時刻ありの日の planned を使う判定(変更なし)。
- 本番データの再計算の実行(手順は論点6のとおり。実行は運用で行う)。
- 日次の手動補正(`AttendanceDailyCalculationProjector` が補正した所定労働時間を所定内法定内に入れる互換変換)の見直し。
  補正した日は所定内労働時間が義務の時間で表示される既存の挙動のまま。
- 裁量労働制のみなし時間と5区分の関係。
- 簡易表示の旧データ向けフォールバック(`prescribedWithin` が5区分の無いとき prescribed_work_minutes を使う)。Projectorが5区分を補うため実害なし。

## ドキュメントへの影響
- `docs/07-usecases-attendance.md`: 「同じ日の休暇の衝突」の半休の記述を、所定労働時間は P のまま・残業判定の基準のみ P/2 と直す。
  UC-A007 の集計の説明に、簡易表示の所定内労働時間・残業時間の定義を追記する。
  「フレックスタイム制」「裁量労働制・管理監督者」の節に、5区分は全て所定内・週40時間の判定対象外であることを追記する。
- `docs/16-database-schema.md`: `prescribed_work_minutes` に「その日の所定労働時間(労働義務の時間)。休暇で減らさない。法定休日・所定休日は0」を追記する。

## モック・アセット
なし

## 実装対象
- `backend/app/Domain/Attendance/Support/LeaveCalculationInput.php`
- `backend/app/Domain/Attendance/Services/AttendanceCalculator.php`
- `backend/app/Domain/Attendance/Services/WeeklyOvertimeCalculator.php`
- `backend/app/Console/Commands/RecalculateAttendanceDaysCommand.php`
- 上記のテスト(既存のフレックス・管理監督者・週40時間・再計算コマンドのテストの期待値の更新を含む)
- `backend/tests/Unit/Attendance/LeaveCalculationInputTest.php`
- `backend/tests/Feature/Attendance/HalfDayLeavePrescribedMinutesTest.php`
- `frontend/src/components/AttendanceCalculationSummary/`(本体・stories・test)
- `docs/07-usecases-attendance.md`、`docs/16-database-schema.md`

## 検証方法
- `cd backend && php artisan test`
- `cd frontend && npx tsc --noEmit && npx vitest run`

## レビュー履歴
- 初版(2026-11-14)
- 独立設計レビュー(2026-11-14、general-purpose): 次の指摘に対応した。
  - 【高】再計算で週40時間の手動振分けが外れる → 論点6(ユーザー確認のうえC)
  - 【中】終日欠勤日数の変化が給与連携に出る → 論点4に影響を明記(ユーザー確認のうえ新ルールに従う)
  - 【中】フレックス・管理監督者で残業時間が出る → 論点5を追加(ユーザー確認のうえA)。週40時間の判定の除外も追加
  - 【中】手動補正した日の所定内労働時間 → 既存挙動として対象外に明記
  - 【低】週40時間を所定内から振り分けた分の表示 → 論点3の補足に追記
  - 【低】旧データのフォールバック → 対象外に明記
  - 問題なしとされた点: 半休の日の5区分・残業・深夜が変わらないこと、他の読み手(LeaveDayConflictPolicy・FlexSettlementSummaryCalculator・Excelの所定日数)への影響なし

## 実装結果
未着手
