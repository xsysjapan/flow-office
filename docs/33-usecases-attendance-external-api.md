# 33. 勤怠API連携ユースケース(フェーズ2)

CSV出力(docs/14-usecases-export.md)に加えて、勤怠の月次確定データ(`attendance_months`)を
freee人事労務へAPI経由で直接送信する機能。フェーズ1で用意した`ExternalPublisher`抽象
(`App\Domain\Export\Contracts\ExternalPublisher`)の実装として`ExternalApiPublisher`を
完成させる。

**勤怠のAPIプッシュ連携はfreeeのみ対応する。** 一次情報を調査した結果
(docs/notes/moneyforward-api-investigation.md)、マネーフォワードクラウド給与・クラウド勤怠には
外部システムが任意の勤怠データをプッシュ登録できる公開APIが存在しないことを確認した
(`payroll.moneyforward.com/api/v2/document`は参照専用で、開発者ポータルのAPIリファレンス一覧にも
「クラウド勤怠」「クラウド給与」向けの外部プッシュ型APIは案内されていない)。そのため
`MoneyForwardAttendanceApiPayloadBuilder`は実装せず、**マネーフォワード向けの勤怠出力は
引き続きCSV(`MoneyForwardAttendanceCsvFormat`、フェーズ1で対応済み)のみ**とする。CSV出力
自体には今回の修正による影響はない。

## 対象外

- 経費側のAPI連携(フェーズ3、docs/30-usecases-expense.md参照)
- マネーフォワードへの勤怠データAPIプッシュ(公開APIが存在しないため非対応。CSVのみ)
- freee実サーバーへの実通信確認(本フェーズはHttp::fake()でのテストに留める)
- 自動リトライ(失敗時は手動で再実行する)
- flow-office側にフレックスタイム制・裁量労働制に相当する概念が無いため、freee側の対応
  フィールド(`total_shortage_work_mins`等)・遅刻早退時間・勤務日数(`work_days`系)は未対応
  (送信しない。freee側で未送信項目は自動的に0になる)

## freee人事労務API仕様

freee/freee-api-schemaリポジトリの公式OpenAPIスキーマ(`hr/open-api-3/api-schema.json`)で
確認済み(docs/notes/moneyforward-api-investigation.md 4.freee人事労務API)。

- 更新: `PUT /api/v1/employees/{employee_id}/work_record_summaries/{year}/{month}`
  (「勤怠情報月次サマリの更新」。管理者権限が必要。データが無ければ新規作成、あれば上書き。
  値未設定の項目は自動的に0になる)
- `employee_id`はflow-office側のユーザーIDではなく、freee内部の従業員ID(整数)。
  `ExternalEmployeeMapping.external_employee_code`で保持する
- リクエストボディの必須項目は`company_id`(事業所ID)のみ。事業所IDは
  `ExternalIntegrationConnection.external_office_id`から解決する
- 値はすべて分(minutes)単位。`attendance_months.snapshot_json`も分単位のため単位変換は不要

詳細なフィールド対応は`FreeeAttendanceApiPayloadBuilder`のPHPDocを参照。

## 全体の流れ

1. 事前に管理者が連携先(freee/moneyforward)ごとの認可情報を`external_integration_connections`
   に登録する(本フェーズでは登録APIは対象外。シーダー/管理者が直接投入する運用を想定)。
2. 管理者がflow-office側の社員(`users.id`)と連携先の従業員番号を`external_employee_mappings`
   へ登録する(連携先ごとに1レコード)。
3. 管理者が`POST /exports/attendance/external-publish`で対象月・対象社員・連携先を指定して送信する。
4. 対象の`attendance_months`(承認済み・締め済みのみ。CSV出力と同じ`resolveAttendanceMonths()`を
   使う)ごとに、`AttendanceApiPayloadBuilder`実装がペイロードを組み立て、`ExternalApiPublisher`が
   `AuthStrategy`で付与した認可ヘッダーとともに送信する。
5. 送信に成功した社員・月について、`external_integration.published`イベントを
   `ExportAuditAggregate`経由で`stored_events`へ記録する(冪等性キー: 「attendance_months.id +
   連携先 + 出力種別 + 実行回数」)。
6. 従業員番号マッピングが無い社員・送信に失敗した社員は`failures`としてレスポンスへ含め、
   自動リトライせず、管理者が原因を解消した上で同じAPIを再実行する。

## 認可情報管理

### AuthStrategy

`App\Domain\Export\Contracts\AuthStrategy`を実装し、外部APIリクエストへ付与する認可ヘッダーを
返す。

- `OAuth2Strategy`(freee): アクセストークンが期限切れ・期限間近の場合、`refresh_token`で
  トークンエンドポイントへ再取得し、`external_integration_connections`へ暗号化保存し直す。
- `ApiKeyStrategy`: 保存済みAPIキーをそのままヘッダー(`X-Api-Key`)へ付与する。トークン
  リフレッシュは行わない。勤怠側では現在使用しないが、経費側のMoneyForward連携
  (docs/30-usecases-expense.md)で使用する。

実際のHTTP通信は`Illuminate\Support\Facades\Http`を使う(`HttpMicrosoftGraphClient`と同じ
codebaseの慣例)。テストでは`Http::fake()`で差し替える。本フェーズでは実サーバーへの疎通確認は
行わない。

### `external_integration_connections`(正データ)

連携先(freee/moneyforward)ごとに1レコード。`access_token` / `refresh_token` / `api_key` /
`client_id` / `client_secret`はLaravelの`encrypted`キャスト(`SystemSetting::m365_client_secret`
と同じ方式)で暗号化して保存し、平文では保持しない。詳細は docs/16-database-schema.md 参照。

### `external_employee_mappings`(正データ)

flow-office側の`user_id`と、連携先の従業員番号(`external_employee_code`)の対応表。連携先ごとに
1レコード(`unique(provider, user_id)`)。

## ペイロード組み立て

`App\Domain\Export\Services\AttendanceApi\AttendanceApiPayloadBuilder`インターフェースを、
`AttendanceCsvFormat`と同じ発想で連携先ごとに実装する。現在の実装は`FreeeAttendanceApiPayloadBuilder`
(freee人事労務の勤怠サマリーAPIを模した構造。`FreeeAttendanceCsvFormat`と同じ分単位の値)のみ。

`attendance_months.snapshot_json`の確定値をそのまま使い、日次実績・計算ロジック自体は
変更しない(CLAUDE.mdの設計原則3)。

## API

### `POST /exports/attendance/external-publish`

`attendance.export`権限(CSV出力と同じ)を持つ利用者のみ実行できる。

リクエストボディ:

- `year_month`(必須、配列。`YYYY-MM`形式)
- `user_id`(任意、配列。未指定時は対象月に該当する全社員)
- `provider`(必須。`freee`のみ。`moneyforward`を指定した場合はバリデーションエラー(422)。
  マネーフォワード向けの勤怠データは前述のとおりCSVで出力する)

レスポンス:

```json
{
  "provider": "freee",
  "successes": [{ "user_id": "...", "year_month": "2026-06", "external_employee_code": "4001" }],
  "failures": [{ "user_id": "...", "year_month": "2026-06", "reason": "employee_mapping_missing", "message": "..." }]
}
```

`reason`は`employee_mapping_missing`(従業員番号マッピング未登録)または`send_failed`
(外部APIへの送信失敗。認可エラー・ネットワークエラー等)。失敗した組み合わせは自動リトライせず、
原因を解消した上で同じAPIを再実行する(手動再実行)。

## イベント

`external_integration.published`(docs/17-events.md参照)。送信に成功した場合のみ記録する
(失敗はイベントを記録しない。レスポンスの`failures`で管理者へ通知する)。
