# 一括入力(週次・月次)で休みの予定を表示し、確定理由を任意にする

ステータス: 実装中

## 変更要望(原文)
> 週次、月次の一括入力画面で休みの予定がわかるようにできますか？
> また、一括入力時の必須入力を任意に変更してください。

確認した回答:
- 休みの日の扱い: 「表示のみ」(チェック状態は従来どおり曜日で決まる。休日も入力できる)
- 任意にする項目: 「確定理由」のみ(タイムゾーンオフセット・時刻は変更しない)

## 背景・目的
一括入力モーダルは日別の行に休日・休暇が出ないため、祝日や有給の日に気づかず勤怠を作ってしまう。
また確定理由が必須で、定型の一括入力のたびに理由を書く手間がある。

## 現状(As-Is)
- 週次モーダル: `frontend/src/components/WeeklyAttendanceBulkEntryModal/WeeklyAttendanceBulkEntryModal.tsx`
  (表示中の週=7日に固定。`WeekdayScheduleFields` の月〜日の7行で入力)。呼び出し元 `pages/attendance/WeekAttendancePage.tsx:223`
- 月次モーダル: `frontend/src/components/MonthlyAttendanceBulkEntryModal/MonthlyAttendanceBulkEntryModal.tsx`
  (「日にちごとに設定」タブで対象月の全日を1行ずつ表示)。呼び出し元 `pages/attendance/AttendanceMonthDetailPage.tsx:310`
- モーダルは休日・休暇情報を受け取っていない(props は期間・年月のみ)。
- 画面側は既に休日・休暇を持っている: 週次は `useShiftAssignments`(`/employee-calendar-entries`)と `day.leaves`、
  月次は `data.schedule` と `days[].leaves`。表示用の関数 `attendanceScheduleHolidayLabel` / `attendanceDayLeaveLabels`
  (`frontend/src/utils/statusLabels.ts`)と祝日名 `public_holiday_name` がある。
- 確定理由: フロントで必須(週次 `:84` ガード・`:150` required・`:169` disabled、月次も同様)。
  バックエンド `AttendanceController::generateAttendancePattern` の `'reason' => ['required', 'string']`(:535)、
  OpenAPIの `required` 配列(:523)、`GeneratePatternAttendanceDays::$reason`(string)。Handler が日次の
  `CreateAttendanceDay` / `EditAttendanceDay`(reason は string 必須)へ渡す。
- docs/07 UC-A006 には一括入力の記述がない。

## 設計前提の検証
- 正データと派生データ: 表示に使うのは勤務予定(`employee_calendar_entries` 由来の schedule)と休暇ビュー
  (`leaves`)で、いずれも既存の読み取りAPIの値をそのまま表示するだけ。書き込み経路は変えない。
- 定義と実態: 確定理由は日次編集イベントに「理由」として記録される監査情報。日次の Command は理由必須のまま残し、
  一括入力で未入力のときは固定文言を記録することで、イベント上は常に理由が残る(監査情報の欠落を作らない)。
- 原因の分類: 機能追加・仕様変更(不具合ではない)。
- 横展開: 一括入力は週次・月次の2つのみ。両方に同じ変更を入れる。日次の個別編集(理由必須)は今回の依頼対象外。
  MCPサーバー(`mcp/`)は勤怠APIを呼ぶ側で、reason を送り続けても互換が保たれる。
- 一覧のページング: 一覧の新設・変更なし。

## 仕様検討

### 論点1: 休みの予定をどこに表示するか
- 選択肢:
  - A. モーダルの日別の行(週次は曜日行、月次は日付行)に休日・休暇のバッジを出す
  - B. モーダル上部に「この期間の休み」一覧を出す
- 決定: A
- 理由: 入力する行のすぐ横で分かる方が誤入力を防げる。週次モーダルは表示中の週(7日)に固定のため、曜日行=日付が1対1に決まる。
- 「まとめて設定」タブは曜日の選択だけで日付行がないため表示しない(確定は必ず詳細タブを経由するので、そこで確認できる)。

### 論点2: 表示する内容とデータの取り方
- 選択肢:
  - A. 呼び出し元の画面が既に持つ schedule・leaves からバッジを作り、モーダルへ props で渡す
  - B. モーダル内で API を呼ぶ / プレビューAPIを拡張する
- 決定: A
- 理由: 新しいAPIが要らず、画面の日別一覧と同じ判定(同じ関数)で表示が揃う。
- 表示内容(1日に複数あれば全部並べる):
  - 法定休日(danger)/所定休日(warning) … `attendanceScheduleHolidayLabel(schedule)`
  - 祝日名(例「文化の日」)… `schedule.public_holiday_name`(neutral)
  - 休暇(例「有給休暇(全休)」「代休(午前半休)」、申請中・承認済)… `attendanceDayLeaveLabels(day.leaves)`(info)

### 論点3: 休みの日の入力可否・初期チェック
- 決定: 表示のみ。チェック状態・入力可否は従来どおり(ユーザー回答)。
- 理由: 休日出勤の入力も一括で行えるようにしておくため。

### 論点5: モバイルでの崩れ防止(レビュー時の追加要望「モバイルで画面が崩れないように配慮」)
- 選択肢:
  - A. バッジを曜日/日付ラベルと同じ横並びに入れる(固定幅 `w-24` のラベル内)
  - B. バッジは行の中で折り返し可能な独立したまとまり(`flex flex-wrap gap-1`)として置き、狭い画面では日付ラベルの下の段に回り込ませる
- 決定: B
- 理由: 固定幅のラベルにバッジを詰めると、祝日名・休暇ラベル(例「有給休暇(午前半休)」)が複数並んだときにはみ出して横スクロールが出る。
- 具体: 行の先頭の「チェック+曜日/日付ラベル+出退勤時刻」のまとまりはそのまま。バッジ群はその直後に `flex flex-wrap gap-1` の要素として置き、
  モバイル(md未満)では行が縦積み(既存の `flex-col`)なので、ラベル行の下に `pl-4` で表示される。md以上では既存の `md:flex-wrap` の中で横に並ぶ。
  バッジの文言は省略せず、折り返しで収める。360px幅で横スクロールが発生しないこと。
- 理由欄のプレースホルダーは長いため、文言を短く「未入力時は『一括入力』と記録」にする。

### 論点4: 確定理由を任意にしたときに記録する理由
- 選択肢:
  - A. 未入力なら固定文言「一括入力」を日次イベントの理由に記録する
  - B. 日次 Command の理由も任意にし、空で記録する
- 決定: A。固定文言は Handler(`GeneratePatternAttendanceDaysHandler`)で補う(API・MCP等どの入口でも同じ。設計原則9)
- 理由: 日次編集の監査情報(理由)を欠落させない。日次の個別編集の必須ルールに影響しない。

## 仕様確定事項(まとめ)
### フロントエンド
1. `statusLabels.ts` に、1日分の休みバッジを返す関数を追加する:
   `attendanceDayOffBadges(schedule, leaves): StatusMeta[]` — 順に 法定休日/所定休日、祝日名(neutral)、休暇ラベル(info)。何もなければ空配列。
2. `WeekdayScheduleFields` に任意 prop `rowBadges?: Record<number /*ISO曜日*/, StatusMeta[]>` を追加し、曜日ラベルの右に `Badge` で表示する。
3. `WeeklyAttendanceBulkEntryModal` に任意 prop `dayOffBadgesByDate?: Record<string /*YYYY-MM-DD*/, StatusMeta[]>` を追加。
   `defaultFrom`〜`defaultTo` の各日を ISO 曜日に変換して `rowBadges` に詰め、「曜日ごとに設定」タブで表示する。
4. `MonthlyAttendanceBulkEntryModal` に同じ prop `dayOffBadgesByDate` を追加し、「日にちごとに設定」タブの各日付行の日付の右に表示する。
4-2. バッジの配置は論点5のとおり(折り返し可能な独立したまとまり。360px幅で横スクロールなし)。
5. `WeekAttendancePage` / `AttendanceMonthDetailPage` で、既存の schedule・days から `dayOffBadgesByDate` を作って渡す(新規APIなし)。
6. 確定理由: 両モーダルで `required` 表示を外し、ラベルを「確定理由(任意)」、プレースホルダー「未入力時は『一括入力』と記録」。
   確定ボタンの disabled 条件・handleGenerate のガードから reason を外す。送信時は空白のみなら `reason` を送らない。
7. `api/attendance.ts` の `GenerateAttendancePatternInput.reason` を任意(`reason?: string`)にする。

### バックエンド
1. `AttendanceController::generateAttendancePattern` の検証を `'reason' => ['nullable', 'string']`とし、OpenAPIの required から `reason` を外す。
2. `GeneratePatternAttendanceDays::$reason` を `?string` にする。
3. `GeneratePatternAttendanceDaysHandler` で、reason が null または空白のみなら「一括入力」に置き換えて日次 Command に渡す(定数として Handler に置く)。

### テスト
- フロント: 両モーダルのテストに (a) 休みバッジ(法定休日・祝日名・有給)が該当行に表示される、(b) 理由未入力で確定ボタンが押せ、送信内容に reason が含まれない、を追加。既存の「理由がないと確定できない」系の期待値は削除・置換。
  `statusLabels` の新関数の単体テスト(空・休日のみ・祝日名・休暇複数)。stories に休みありの例を追加。
- バックエンド: `GeneratePatternAttendanceDaysTest` に (a) reason 省略で 200、作成された日次イベントの理由が「一括入力」、(b) reason 指定時はその値が記録される(既存)、を追加。

### ドキュメント
- docs/07 UC-A006 に一括入力(週次・月次)の補足を追記: 日別の行に休日・祝日・休暇を表示する/確定理由は任意で、未入力時は「一括入力」を記録する。

## 対象外
- 休日・休暇の日を自動で対象外にする、入力不可にする(論点3)。
- タイムゾーンオフセット・時刻の任意化。
- 日次の個別編集・週次画面の通常編集の理由必須ルール。
- プレビューAPIの拡張。

## ドキュメントへの影響
- docs/07-usecases-attendance.md UC-A006 に追記。docs/16・docs/17 の変更なし(イベント構造は不変)。

## 受け入れ条件
- 週次・月次の一括入力モーダルの詳細タブで、休日(法定/所定)・祝日名・申請中/承認済の休暇が該当する行に表示される。
- 幅360pxでも両モーダルの詳細タブで横スクロールが出ず、バッジが折り返して表示される。
- 確定理由が空でも確定でき、日次勤怠のイベントには理由「一括入力」が記録される。理由を入力した場合はその文言が記録される。
- 上記のフロント(Vitest)・バックエンド(PHPUnit)テストが追加されPASSし、既存テスト・lint・型チェックもPASSする。

## 実装結果
(実装後に追記)
