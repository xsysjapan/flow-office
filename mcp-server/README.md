# flow-office MCP サーバー

docs/25-usecases-integrations-mcp.md「MCPサーバーの責務」の実装。ClaudeなどのAIアプリへ
勤怠操作用ツールを提供する。**勤怠計算ロジックはここに実装しない**。すべて`backend/`の
Laravel APIを呼び出すだけのクライアントであり、認証・認可・労働時間計算・休日判定・
締め判定・承認ルールはすべてbackend側の責務(docs/03-architecture.md 3.5/3.7)。

## セットアップ

```
cd mcp-server
npm install
npm run build
```

## 前提: 個人API/MCP連携トークンの発行

このMCPサーバーは、ユーザー自身が発行した個人API/MCP連携トークン
(docs/25-usecases-integrations-mcp.md UC-I001)を使ってbackend APIを呼び出す。
Webアプリの「アプリ・API連携」画面(`POST /users/me/integrations`)、または直接APIで
以下のようにトークンを発行する。

```
POST /api/users/me/integrations
{
  "client_type": "mcp_client",
  "client_name": "Claude連携",
  "scopes": ["attendance:self:read", "attendance:self:clock", "attendance:self:draft",
             "attendance:self:update", "attendance:self:validate", "attendance:self:submit",
             "report:self:import"]
}
```

レスポンスの`token`が、以降このMCPサーバーに渡す`FLOW_OFFICE_TOKEN`になる。
`profile:self:read`スコープは選択したスコープに関わらず常に自動付与される
(`get_my_profile`等が対象ユーザーIDを解決するために必要)。

## 環境変数

| 変数名 | 説明 |
|---|---|
| `FLOW_OFFICE_API_BASE_URL` | backendのAPIベースURL(例: `http://localhost:8000/api/`) |
| `FLOW_OFFICE_TOKEN` | 個人API/MCP連携で発行したSanctumトークン(平文) |

## Claude Desktop / Claude Code への登録例

```json
{
  "mcpServers": {
    "flow-office": {
      "command": "node",
      "args": ["/path/to/flow-office/mcp-server/dist/index.js"],
      "env": {
        "FLOW_OFFICE_API_BASE_URL": "http://localhost:8000/api/",
        "FLOW_OFFICE_TOKEN": "ここに発行したトークン"
      }
    }
  }
}
```

## ツール一覧

docs/25-usecases-integrations-mcp.md「MCPツール一覧」に対応する。

- 読み取り系: `get_my_profile`, `get_my_attendance_month`, `get_my_attendance_day`,
  `get_my_attendance_events`, `get_my_work_schedule`, `get_my_calendar`,
  `get_my_leave_requests`, `get_my_monthly_summary`, `get_my_monthly_attendance_status`
- 打刻系: `clock_in`, `start_break`, `end_break`, `clock_out`
- 日次勤怠編集系: `create_attendance_day_draft`, `update_attendance_day_draft`,
  `bulk_update_attendance_days`, `delete_attendance_day_draft`
- 月次勤怠系: `create_monthly_attendance_draft`, `get_monthly_attendance_draft`,
  `validate_monthly_attendance`, `confirm_attendance_draft_field`,
  `submit_monthly_attendance`, `cancel_monthly_attendance_submission`
- インポート・照合系: `create_attendance_import_session`, `upload_attendance_import_data`,
  `preview_attendance_import`, `compare_import_with_existing_attendance`,
  `apply_import_to_monthly_draft`, `get_attendance_import_status`

### 既知の制約

- `delete_attendance_day_draft` は月次下書き専用の削除APIがbackendに無いため、既存の
  日次勤怠削除(UC-A015、`DELETE /attendance/days/{id}`)を呼び出す。
- `cancel_monthly_attendance_submission` はbackend未実装のため、常にエラーを返す
  (docs/26のPhase6以降で対応予定)。
- ファイル(Excel/PDF/画像等)の解析はClaude側で行う。このサーバーに汎用パーサは実装しない
  (`upload_attendance_import_data`は解析済みの構造化データのみを受け取る)。

## テスト

```
npm test          # vitestによるユニットテスト(fetchをモック)
node smoke-test.mjs   # 実際に起動中のbackendに対する手動スモークテスト。
                       # FLOW_OFFICE_TOKEN環境変数に実トークンを設定して実行する。
```
