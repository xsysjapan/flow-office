# 26. 作業報告書からの月次勤怠作成ユースケース

月末にユーザーが作成した作業報告書(顧客・案件向けの作業実績)をClaudeへ読み込ませ、
月次勤怠の下書きを一括作成する機能。本システムの中核ユースケースの1つとする
(docs/03-architecture.md 3.6/3.7、CLAUDE.mdの設計原則10/11)。

## 作業報告書と勤怠の違い

作業報告書を勤怠そのものとして扱わない。

- **作業報告書**: 顧客・案件への作業実績(顧客向け作業時間・案件・作業内容・請求対象時間・
  成果・進捗)。社内会議や移動時間が含まれていない可能性がある。
- **勤怠**: 実際の労働時間(始業・終業・休憩・社内業務・移動時間・会議・深夜時間・休日労働・
  休暇・欠勤)。

そのため作業報告書の値は月次勤怠の**候補**として扱い、既存の打刻(`attendance_punches`)・
勤務予定(`employee_shift_assignments`)・休暇情報(`paid_leave_requests`等)と必ず照合する。

## UC-R001: 作業報告書から月次勤怠下書きを作成する

1. ユーザーがClaudeへ月次作業報告書(Excel/CSV/PDF/Word/テキスト/画像等)を添付し、
   「この作業報告書をもとに、2026年7月分の勤怠を作成して」のように依頼する
2. Claudeが報告書を解析し、日ごとの勤務候補(開始・終了・休憩・勤務場所・案件・作業内容・
   信頼度・元資料参照)を構造化データとして抽出する。**ファイル解析自体はClaude側で行い、
   勤怠管理API・MCPサーバーに汎用的なPDF・Excel解析ロジックを実装しない**
3. Claudeが個人MCP連携(docs/25-usecases-integrations-mcp.md、`report:self:import`スコープ)
   経由で`create_attendance_import_session`を呼び出し、`attendance_import_sessions`を作成する
4. Claudeが`upload_attendance_import_data`で構造化データ(下記フォーマット例)を送信する
5. Claudeが同じくMCP経由で対象月の既存勤怠・打刻イベント・勤務予定・勤務カレンダー・
   所定休日/法定休日・休暇申請・遅刻早退申請・休日出勤申請・月次勤怠の状態・勤務形態・
   所定労働時間・締め状態を取得する(`get_my_attendance_month`等の読み取り系ツール)
6. 勤怠管理API側(`preview_attendance_import`)が、報告書候補と既存データを比較し、
   `attendance_import_items`ごとに差異(下記「差異検出」)を計算する。**この検証は勤怠管理API
   側の既存`AttendanceCalculator`等を再利用し、MCPサーバー・Claude側に重複実装しない**
7. Claudeが検証結果を見て、問題のない日はまとめて下書き候補とし、不明な日だけユーザーへ
   確認する(下記「不明点の確認」)
8. ユーザーが確認・回答した後、Claudeが`create_monthly_attendance_draft` /
   `bulk_update_attendance_days` / `validate_monthly_attendance`をMCP経由で呼び出し、
   `monthly_attendance_drafts`へ反映する。**この時点では正式申請しない**
9. `attendance_import_session.applied` / `monthly_attendance_draft.created` /
   `monthly_attendance_draft.updated`イベントを記録する

関連イベント: `attendance_import_session.created`, `.previewed`, `.applied`,
`monthly_attendance_draft.created`, `.updated`
関連テーブル: `attendance_import_sessions`, `attendance_import_items`,
`monthly_attendance_drafts`, `field_provenances`

### 構造化データの例

```json
{
  "targetMonth": "2026-07",
  "source": {
    "type": "WORK_REPORT",
    "fileName": "2026-07-work-report.xlsx",
    "fileHash": "sha256:...",
    "parsedBy": "Claude"
  },
  "days": [
    {
      "date": "2026-07-01",
      "startTime": "09:00",
      "endTime": "18:00",
      "breaks": [{ "startTime": "12:00", "endTime": "13:00" }],
      "workLocation": "REMOTE",
      "projectName": "顧客A",
      "workDescription": "API設計",
      "confidence": "HIGH",
      "sourceReferences": [{ "sheet": "作業実績", "row": 15 }]
    }
  ]
}
```

`workLocation`は`docs/07-usecases-attendance.md`の勤務形態区分
(`attendance_days.work_location_type`)にマッピングする。

### 差異検出

`preview_attendance_import`(勤怠管理API側)が検出する項目:

- 報告書に勤務があるが勤怠が存在しない/勤怠に勤務があるが報告書に記載がない
- 出勤・退勤・休憩時刻の差異、打刻漏れ、休憩不足
- 休日勤務、有給取得日・欠勤日との矛盾、日跨ぎ勤務、重複勤務区間
- 開始時刻と終了時刻の逆転、対象月外の勤務、月次締め済み
- 必須情報不足、所定勤務との差異、申請が必要な勤務

### 不明点の確認

問題のない日は一括で下書き候補とする。不明な日だけClaudeからユーザーへ確認する。

例:
```
18日分はそのまま登録できます。
次の3日について確認が必要です。
- 7月8日: 終了時刻がありません
- 7月14日: 有給申請がありますが作業記録もあります
- 7月22日: 打刻は9:18、作業報告書は9:00です
```

## UC-R002: 月次勤怠下書きの最終確認と申請

1. Claudeまたは勤怠管理アプリの画面(月次勤怠インポート確認画面)で、勤務日数・総労働時間・
   所定内/所定外労働時間・法定外残業・深夜労働・法定休日労働・所定休日労働・休暇日数・
   欠勤日数・未解決エラー件数・警告件数・AI推定値件数を表示する
2. ユーザーが内容を確認する
3. ユーザーが明示的に「内容に問題ないので申請して」のように指示した場合のみ、Claudeが
   `validate_monthly_attendance`→`submit_monthly_attendance`をMCP経由で呼び出す。
   **ユーザーの明示的な指示なしに月次申請しない**
4. 未解決のエラーがある場合、または対象月に`field_provenances.source_type=ai_inferred`かつ
   `confirmed_at`未設定の重要項目(出勤時刻・退勤時刻・休憩時間・勤務日・休日勤務・休暇との
   競合)が残っている場合は申請を拒否する(`AI_INFERRED_VALUE_UNCONFIRMED`エラー)
5. 警告のみの場合は組織ルール(`system_settings`等)に従い申請可否を決める
6. 申請が受理されると、下書き(`monthly_attendance_drafts`)の内容を既存の月次勤怠提出フロー
   (`docs/07-usecases-attendance.md` UC-A008)へ引き渡し、`attendance_months`を作成・提出する
7. `monthly_attendance_draft.submitted`イベントを記録する

関連イベント: `monthly_attendance_draft.validated`, `monthly_attendance_draft.submitted`
関連テーブル: `monthly_attendance_drafts`, `field_provenances`, `attendance_months`

## AI生成値の出所管理

各入力項目について、値の生成元を`field_provenances`(docs/16-database-schema.md)へ保持する。

- `source_type`: `source_document` / `existing_clock_event` / `existing_attendance` /
  `work_schedule` / `employment_rule` / `ai_inferred` / `user_confirmed` /
  `user_manual_input` / `admin_correction`
- 値・出所・信頼度・元資料参照・AI推定理由・ユーザー確認日時・確認者・変更前後の値を保存する

`ai_inferred`のまま残っている重要項目(出勤時刻・退勤時刻・休憩時間・勤務日・休日勤務・
休暇との競合)は、ユーザー確認(`field_provenance.confirmed`イベント、`user_confirmed`への
遷移)なしに月次申請できない(UC-R002手順4)。

## API設計方針(一括更新・楽観ロック・冪等性)

### 一括更新API

月次一括作成では日ごとにAPIを呼ぶ設計を避け、一括更新APIを用意する
(`bulk_update_attendance_days`が内部的に呼ぶAPI)。

```json
{
  "targetMonth": "2026-07",
  "expectedVersion": 12,
  "days": [
    {
      "date": "2026-07-01",
      "startTime": "09:00",
      "endTime": "18:00",
      "breaks": [{ "startTime": "12:00", "endTime": "13:00" }],
      "source": "SOURCE_DOCUMENT"
    }
  ]
}
```

レスポンスは日別の成功・警告・失敗を返す(`PARTIALLY_ACCEPTED`等)。

```json
{
  "status": "PARTIALLY_ACCEPTED",
  "results": [
    { "date": "2026-07-01", "status": "ACCEPTED", "warnings": [] },
    {
      "date": "2026-07-14",
      "status": "REJECTED",
      "errors": [{ "code": "LEAVE_CONFLICT", "message": "有給申請と勤務時間が重複しています。" }]
    }
  ]
}
```

### 楽観ロック

`monthly_attendance_drafts.version`を使い、Web画面・Claude(MCP経由)の同時編集で後勝ち
上書きをしない。リクエストの`expectedVersion`が現在の`version`と一致しない場合、
HTTP 409相当(`ATTENDANCE_VERSION_CONFLICT`)を返す。

### 冪等性

一括更新・月次申請には`Idempotency-Key`ヘッダーを使用する(`attendance_punches.idempotency_key`
と同じ考え方)。同じMCPツールが再実行されても二重登録しない。

## テスト観点(抜粋)

- 作業報告書由来の構造化データから下書きを作成できる
- 既存勤怠との差異・休暇との競合・休日勤務を検出できる
- AI推定値を識別でき、未確認のAI推定値がある場合に申請を拒否できる
- 問題のない日だけ一括登録でき、日別エラーを返せる
- 楽観ロック競合を検出できる、締め済み月には登録できない
- 下書き作成と月次申請が分離されている、明示的なユーザー指示なしに月次申請されない

## 受け入れシナリオ

1. ユーザーがClaudeへ月次作業報告書を添付する
2. Claudeが日別勤務情報を抽出し、MCP経由で既存勤怠・勤務カレンダーを取得する
3. 差異を検出し、不明な日だけユーザーへ確認する
4. MCP経由で月次勤怠下書きを一括作成する。日別の警告・エラーを表示する
5. ユーザーが内容を確認し、明示的な指示で月次申請する
6. 元資料・AI生成・ユーザー確認の履歴(`field_provenances`)が残る
