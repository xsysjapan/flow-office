# 2. 技術前提

- **Backend**: Laravel
- **DB**: MySQL
- **Hosting**: XSERVER
- **Queue**: database queue
- **Batch**: cron から `php artisan schedule:run`
- **Auth**: Microsoft Entra ID SSO
- **User Integration**: Microsoft Graph によるユーザー参照または同期
- **Notification**: Teams Webhook または Graph API
- **Architecture**: CQRS + Event Sourcing 風設計
- **API Docs**: darkaonline/l5-swagger (zircote/swagger-php) による PHP属性ベースの
  コードファーストOpenAPI定義。ローカル開発では Swagger UI (`/api/documentation`) を提供

XSERVER では常駐プロセスを前提にしない。キューは DB に積み、cron で
`queue:work --stop-when-empty` を起動する。

## API仕様書 (OpenAPI / Swagger UI)

コントローラーのメソッドに `OpenApi\Attributes`(PHP属性)でエンドポイント定義を書き、
`l5-swagger:generate` でそこから OpenAPI 3.0 定義(JSON/YAML)を生成する。手書きのYAMLを
別管理せず、実装(コントローラー)とAPI仕様を同じ場所に保つコードファースト方式。

```
cd backend
php artisan l5-swagger:generate   # storage/api-docs/api-docs.json を生成
php artisan serve
# http://localhost:8000/api/documentation  … Swagger UI
# http://localhost:8000/docs               … 生成済みOpenAPI JSON
```

`.env` で `L5_SWAGGER_GENERATE_ALWAYS=true`(`.env.example` にデフォルトで設定済み)にしておくと、
ローカル開発中はリクエストの都度ドキュメントが再生成されるため、手動での`generate`実行は不要。
本番(XSERVER)ではこの値を`false`のままにし、デプロイ時に`l5-swagger:generate`を一度実行する。

エンドポイント定義の書き方は `app/Http/Controllers/Api/UserController.php` の例を参照。
共通の`Info`/`Server`定義は `app/Http/Controllers/Controller.php` に置いてある。
新しいコントローラーを追加する際は、そのコントローラーのpublicメソッドにも同様に
`#[OA\Get]` 等の属性を追加すること。
