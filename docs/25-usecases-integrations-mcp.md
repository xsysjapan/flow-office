# 25. API・MCP連携ユースケース

ユーザー本人または組織が、自分のアカウントに紐づくAPI・MCPクライアントを登録できるようにする
(`application_integrations`、docs/16-database-schema.md)。ClaudeなどのAIアプリはMCPサーバーを
経由してこの連携を利用する。

## UC-I001: 個人API・MCP連携を登録する

1. ユーザーが「アプリ・API連携」画面を開く
2. 「新しい連携」を選択する
3. 連携種別(`client_type`: `mcp_client` / `api_client` / `ai_application`)を選ぶ
4. 連携名(`client_name`)・利用目的(`purpose`)を入力する
5. 要求する権限スコープ(`integration_scopes`)を選ぶ(下記「個人連携の権限」参照)
6. ユーザー本人が権限を人間が理解できる説明で確認する
   (例: 「この連携は、あなた自身の勤怠の閲覧・下書き作成・申請ができます。他の社員の勤怠には
   アクセスできません。」)
7. サーバーが`$user->createToken($clientName, $scopes)`でSanctumトークンを発行し、
   `application_integrations`に`owner_type=personal`の行を作成する(`personal_access_token_id`
   を記録)
8. 平文トークンを一度だけ返す(OAuth的なクライアントID/シークレットの発行、または単純な
   アクセストークンの発行のいずれでもよいが、本システムでは既存のSanctum基盤を再利用し、
   新しい認可サーバーを追加実装しない)
9. 疎通確認を行い、`application_integration.registered`イベントを記録して有効化する

関連イベント: `application_integration.registered`, `application_integration.scope_granted`
関連テーブル: `application_integrations`, `integration_scopes`

## UC-I002: 個人連携の権限

個人連携(`owner_type=personal`)で許可できるスコープ例:

- `attendance:self:read` / `attendance:self:clock` / `attendance:self:draft` /
  `attendance:self:update` / `attendance:self:validate` / `attendance:self:submit`
- `leave:self:read` / `leave:self:create`
- `schedule:self:read`
- `report:self:import`

個人連携では、原則として次を禁止する(既存のトークン基盤にability検証を追加することで
実現する。docs/23-usecases-devices.md UC-D002の「重要な考慮事項」と同じ対応が必要)。

- 他人の勤怠閲覧・登録・代理打刻
- 勤怠承認、月次締め
- 組織設定変更、端末管理、認証キーの管理者操作

管理職であっても、個人トークンへ自動的に部下の閲覧権限を付与しない。管理者権限・承認者権限は
別の組織連携(`owner_type=organization`)または明示的な委任として扱い、個人連携の権限を
拡張しない。

## UC-I003: 連携を停止・削除する

1. ユーザー(または管理者、組織連携の場合)が連携一覧から対象を選ぶ
2. 「アクセストークン再発行」または「連携停止」「連携削除」を選ぶ
3. 再発行の場合は既存トークンを失効させ、新しいトークンを発行し直す
4. 停止・削除の場合は`$integration->personalAccessToken->delete()`し、
   `application_integration.revoked`イベントを記録する

関連イベント: `application_integration.revoked`
関連テーブル: `application_integrations`

## アプリ・MCP連携管理画面(UI)

- 連携一覧、新規連携、権限確認(人間が理解できる説明で表示)
- 最終利用日時、アクセストークン再発行、連携停止・削除、監査履歴

## MCPサーバーの責務

MCPサーバー(本リポジトリの `mcp/`)は、ClaudeなどのAIアプリへ勤怠操作用ツールを提供する
**backend/とは別のLaravelアプリ(別のcomposer.json・別のDB)**であり、本リポジトリ
(backend/)の勤怠管理APIを、UC-I001で発行した個人連携のアクセストークンを使って呼び出す
だけの立場とする。backend/のDBには一切アクセスしない。**MCPサーバー内に勤怠計算ロジック
を重複実装しない**(docs/03-architecture.md 3.5/3.7)。

エンドポイントは `/mcp`(MCPのJSON-RPC 2.0、Streamable HTTP)。ClaudeなどのMCPクライアント
との認可はmcp/自身が実装するOAuth2認可サーバー(Dynamic Client Registration、RFC 7591
対応)で行い、backend/に新しい認可サーバーを追加することはしない(backend/は引き続き
Sanctumの個人アクセストークンのみを持つ)。人間の識別・backendトークンとの紐付けは、
mcp/が独自に持つ`mcp_users`/`mcp_user_backend_tokens`(mcp/のDB。backendのDBとは別)で
管理する。詳細は `mcp/README.md` を参照。

### MCPサーバーが担当する処理

- MCPクライアント(Claude等)との接続、Dynamic Client Registration、OAuth2認可(認可コード
  +PKCE)・アクセストークン発行/検証、スコープ確認
- MCPツール定義、入力値の基本バリデーション
- 勤怠管理API(本リポジトリのbackend API)の呼び出し(backendの個人連携Sanctumトークンを
  Bearerとして使う)
- APIレスポンスのAI向け整形、監査用クライアント情報の付加、エラーの説明可能な形式への変換

### MCPサーバーが担当しない処理(すべて勤怠管理API側で行う)

- 労働時間計算、深夜時間計算、休日判定、休暇残高計算
- 勤怠締め判定、承認ルール、勤怠整合性の最終決定

### MCP認可

MCPサーバー(mcp/)はClaudeそのものを信用せず、mcp/自身が発行したOAuth2アクセストークン
(JWT)を毎回検証し(有効性・有効期限・発行先クライアント・スコープ・失効状態)、そのうえで
紐付けられたbackendの個人連携Sanctumトークン(UC-I001で発行、`application_integrations.status`)
を使ってbackend APIを呼び出す。mcp/が発行するOAuthスコープは、backendの個人連携トークンに
付与されたスコープを超えることはできない(認可時にmcp/側で照合する)。

### MCPツール一覧(最低限)

**読み取り系**

`get_my_profile` / `get_my_attendance_month` / `get_my_attendance_day` /
`get_my_attendance_events` / `get_my_work_schedule` / `get_my_calendar` /
`get_my_leave_requests` / `get_my_monthly_summary` / `get_my_monthly_attendance_status`

**打刻系**(`attendance:self:clock`スコープが必要。既存のUC-A001〜A004・UC-A012の打刻経路の
1つとして扱い、専用の計算ロジックは持たない)

`clock_in` / `start_break` / `end_break` / `clock_out`

**日次勤怠編集系**(`attendance:self:draft`/`attendance:self:update`スコープが必要)

`create_attendance_day_draft` / `update_attendance_day_draft` /
`bulk_update_attendance_days` / `delete_attendance_day_draft`

**月次勤怠系**(`attendance:self:validate`/`attendance:self:submit`スコープが必要)

`create_monthly_attendance_draft` / `get_monthly_attendance_draft` /
`validate_monthly_attendance` / `submit_monthly_attendance` /
`cancel_monthly_attendance_submission`

下書き(`monthly_attendance_drafts`)は`mcp/`自身のDBに保持し、backend/には書き込まない。
`submit_monthly_attendance`が呼ばれ、下書き段階の検証(未確認のAI推定値が無いこと等)を
`mcp/`側で満たした場合のみ、backend/の既存API(日次編集の一括版・UC-A008月次提出)を呼び出し、
backend/の正データ(`attendance_days`/`attendance_months`)を作成する(docs/26参照)。

**インポート・照合系**(`report:self:import`スコープが必要。docs/26参照)

`create_attendance_import_session` / `upload_attendance_import_data` /
`preview_attendance_import` / `compare_import_with_existing_attendance` /
`apply_import_to_monthly_draft` / `get_attendance_import_status`

これらのツールが扱うインポートセッション・下書きデータ(`attendance_import_sessions` /
`attendance_import_items` / `field_provenances`)はすべて`mcp/`自身のDBにのみ存在し、
backend/には対応するテーブルを持たない。`preview_attendance_import`が行う労働時間・
深夜時間・休日判定等の検証は、backend/が提供するステートレスな検証エンドポイント
(`AttendanceCalculator`等の既存ロジックを再利用し、何も保存せず計算結果のみ返す)を
呼び出して行う。

MCP経由でファイルそのものを送れない構成の場合、Claudeが解析済みの構造化データを
`upload_attendance_import_data`へ渡す方式にする(汎用的なPDF・Excel解析ロジックは
勤怠管理API・MCPサーバーのいずれにも必須実装しない)。

## 操作主体・操作経路の監査

MCP経由の操作では、監査ログ(`stored_events`)に次を区別して記録する(docs/03-architecture.md
3.5、docs/17-events.md)。

- 対象ユーザー: 勤怠の当事者
- 操作主体: ユーザー本人(Claude自体は操作主体にならない)
- 実行クライアント: `application_integrations.client_name`(例: 「Claude」)
- 操作経路: `mcp`

例(仕様書16.1節の記載をそのまま踏襲): 対象ユーザー=永野ゆうと、操作主体=永野ゆうと、
認証ユーザー=永野ゆうと、実行クライアント=Claude、操作経路=MCP。
