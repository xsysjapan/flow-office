# flow-office MCP サーバー

docs/25-usecases-integrations-mcp.md「MCPサーバーの責務」の実装。ClaudeなどのAIアプリへ
勤怠操作用ツールを`/mcp`エンドポイント(MCPのJSON-RPC 2.0、Streamable HTTP)で提供する。

`backend/`とは完全に独立したLaravelアプリ(別の`composer.json`・別のDB)であり、
**勤怠計算ロジックはここに実装しない**。すべて`backend/`のLaravel APIをHTTP経由で
呼び出すクライアントであり、`backend/`のDBには一切アクセスしない。認証・認可・労働時間
計算・休日判定・締め判定・承認ルールはすべてbackend側の責務(docs/03-architecture.md 3.5/3.7)。

## アーキテクチャ

```
Claude (MCPクライアント)
  │ 1. DCR: POST /oauth/register            (RFC 7591、公開クライアント)
  │ 2. 認可: GET /oauth/authorize (+PKCE)     ブラウザで人間が同意
  │ 3. トークン交換: POST /oauth/token
  ▼
mcp/ (このアプリ。独自DB: oauth_*, mcp_users, mcp_user_backend_tokens)
  │ 4. MCPツール呼び出し: POST /mcp (Bearer: mcp/発行のOAuthアクセストークン)
  │    → トークンから mcp_user を解決 → 紐付け済みのbackend Sanctumトークンを取得
  ▼
backend/ (既存Laravel API。Sanctum Bearer)
  Authorization: Bearer <backendのSanctum個人連携トークン>
```

mcp/は2つの顔を持つ。

- Claudeに対しては**OAuth2認可サーバー + リソースサーバー**(`/oauth/*`, `/mcp`)。
  Dynamic Client Registration(RFC 7591)・認可コード+PKCE・アクセストークン(JWT)発行を
  自前で実装する(`league/oauth2-server`を使用)。
- `backend/`に対しては**単なるSanctum Bearerクライアント**(DBアクセスなし)。

## 作業報告書インポート・月次勤怠下書き(docs/26参照)のデータ保持

作業報告書から月次勤怠を作成する機能(docs/26-usecases-monthly-import.md)の下書き・
インポートセッションのデータは、**mcp/自身のDBにのみ保持し、backend/には一切書き込まない**。
MCP連携特有の一時的な業務(下書き作成・差異照合・AI推定値の出所管理)をbackend/の本来の
勤怠データベースに持ち込まないため(CLAUDE.mdの設計原則9)。

- `attendance_import_sessions` / `attendance_import_items` / `monthly_attendance_drafts` /
  `field_provenances` — いずれもmcp/自身のDB(旧`oauth_*`, `mcp_users`,
  `mcp_user_backend_tokens`と同じ場所)に持つテーブル。カラム定義はdocs/26参照
- ユーザーが「申請して」と明示的に指示した時点でのみ、`mcp/`がbackend/の既存API
  (日次編集の一括版・UC-A008月次提出)を呼び出し、backend/側の正データ
  (`attendance_days`/`attendance_months`)を作成する。それ以外は`mcp/`のDB内で完結し、
  backend/はこの機能専用のテーブル・エンドポイントを一切持たない

## セットアップ

```
cd mcp
composer install
cp .env.example .env && php artisan key:generate
php artisan mcp:oauth-keys      # OAuth2アクセストークン署名用のRSA鍵ペア(storage/oauth-*.key)を生成する
touch database/database.sqlite  # ローカル開発はsqlite
php artisan migrate
php artisan serve --port=8090
php artisan test
```

`.env`の`MCP_BACKEND_API_BASE_URL`を、動作させたい`backend/`のURLに合わせる
(既定値 `http://localhost:8000/api/`)。

## 前提: 個人API/MCP連携トークンの発行

mcp/自身はAzure AD等のユーザー認証手段を持たない。そのため、ユーザーは以下の手順で
「backendの個人連携トークンをmcp/へ紐付ける」初回セットアップを行う。

1. `backend/`のフロントエンド「アプリ・API連携」画面(または直接API)で、UC-I001の手順
   通りに個人連携トークンを発行する。

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

   レスポンスの`token`が、平文で一度だけ返る個人連携トークン。`profile:self:read`スコープは
   選択したスコープに関わらず常に自動付与される。

2. mcp/の`/oauth/authorize`に(ClaudeなどのMCPクライアント経由で)初めてアクセスすると、
   `/link`画面へ誘導される。そこへ手順1のトークンを貼り付け、発行時に選んだのと同じスコープに
   チェックを入れて登録する(mcp/側では選択内容をbackendに照会できないため自己申告)。
   これにより`mcp_users`/`mcp_user_backend_tokens`(mcp/自身のDB)に暗号化して保存される。
3. 以後、ブラウザセッションが有効な間は`/oauth/authorize`の同意画面がそのまま表示される。

## Claude / MCPクライアントへの登録

このサーバーはHTTP(Streamable HTTP)でDynamic Client Registrationに対応しているため、
対応するMCPクライアントであれば以下のURLを指定するだけで自己登録・OAuth認可が行われる。

```
http://localhost:8090/mcp
```

discoveryメタデータ(RFC 8414 / RFC 9728)は以下で取得できる。

- `GET /.well-known/oauth-authorization-server`
- `GET /.well-known/oauth-protected-resource`

## ツール一覧

docs/25-usecases-integrations-mcp.md「MCPツール一覧」に対応する。

- 読み取り系: `get_my_profile`, `get_my_attendance_month`, `get_my_attendance_day`,
  `get_my_attendance_events`, `get_my_work_schedule`, `get_my_calendar`,
  `get_my_leave_requests`, `get_my_monthly_summary`, `get_my_monthly_attendance_status`
- 打刻系: `clock_in`, `start_break`, `end_break`, `clock_out`
- 日次勤怠編集系: `create_attendance_day_draft`, `update_attendance_day_draft`,
  `bulk_update_attendance_days`, `delete_attendance_day_draft`
- 月次勤怠系: `create_monthly_attendance_draft`, `list_my_monthly_attendance_drafts`,
  `get_monthly_attendance_draft`, `validate_monthly_attendance`,
  `list_attendance_draft_fields`, `confirm_attendance_draft_field`,
  `submit_monthly_attendance`, `cancel_monthly_attendance_submission`
- インポート・照合系: `create_attendance_import_session`, `upload_attendance_import_data`,
  `preview_attendance_import`, `compare_import_with_existing_attendance`,
  `apply_import_to_monthly_draft`, `get_attendance_import_status`

各ツールが要求するスコープは、mcp/が発行するOAuthアクセストークンのスコープと、紐付けた
backendの個人連携トークンのスコープの両方を満たす必要がある(いずれか一方が欠けている場合は
`isError: true`のツール結果、または`/oauth/authorize`時点でのエラー画面として現れる)。

### 既知の制約

- `delete_attendance_day_draft` は月次下書き専用の削除APIがbackendに無いため、既存の
  日次勤怠削除(UC-A015、`DELETE /attendance/days/{id}`)を呼び出す。
- `cancel_monthly_attendance_submission` はbackend未実装のため、常にエラーを返す
  (docs/26のPhase6以降で対応予定)。
- ファイル(Excel/PDF/画像等)の解析はClaude側で行う。このサーバーに汎用パーサは実装しない
  (`upload_attendance_import_data`は解析済みの構造化データのみを受け取る)。

## テスト

```
php artisan test
```

`tests/Feature/Oauth/` がDynamic Client Registration・認可コード+PKCE+トークン発行の
ハッピーパスとスコープ不足時の拒否を、`tests/Feature/Mcp/` が`/mcp`のtools/list・tools/call
(成功・backend APIエラーの`isError`整形・OAuthスコープ不足時の拒否)を検証する
(いずれも`Http::fake()`でbackendをモックする)。

実際に動いている`backend/`に対する手動確認は、`backend/`と`mcp/`の両方を起動したうえで、
DCR → 認可(PKCE) → トークン交換 → `/mcp`の順にcurlで叩くことで行える(手順は
`docs/25-usecases-integrations-mcp.md`のUC-I001、及び本READMEの「前提」節を参照)。
