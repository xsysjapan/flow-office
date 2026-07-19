# 27. 本番(XSERVER)リリース手順

`backend/` `frontend/` `mcp/` の3アプリを、単一ドメインの `/flow-office` 配下にまとめて公開する
場合の手順。Docker上にXSERVER相当の環境(Apache + PHP + MySQL + SSH、常駐ワーカーなし)を構築し、
実際にSSH経由でこの手順を通してリハーサル済み(2026-07-19)。

## 1. 配置構成

同一ドメインの `/flow-office` 配下に3アプリをパス分けして配置する。

| パス | アプリ | 備考 |
|---|---|---|
| `/flow-office/` | `frontend/` | SPA。ドキュメントルート |
| `/flow-office/api` | `backend/` | Laravel API。マウントパス自体が`api`セグメントを兼ねる |
| `/flow-office/mcp` | `mcp/` | MCPサーバー。JSON-RPCエンドポイントはマウントパス直下(`/flow-office/mcp`) |

frontend・backend・mcpは同一オリジン(同一ドメイン)になるため、CORS設定は基本的に不要。

## 2. Apache設定

XSERVERの`public_html/flow-office/`配下にドキュメントルートを置き、`backend/public`・
`mcp/public`をAliasでマウントする(いずれも`public/`配下だけをWebから見せ、それ以外は
Web非公開のディレクトリに置く)。

```apache
# Aliasは宣言順にマッチする(最長一致ではない)。
# 限定的なパス(api, mcp)を、frontend用の一般的な/flow-officeより先に書くこと。
# 後に書くと、frontendのAliasが先にマッチしてapi/mcpへのリクエストを奪ってしまう。

Alias /flow-office/api /path/to/backend/public
<Directory /path/to/backend/public>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

Alias /flow-office/mcp /path/to/mcp/public
<Directory /path/to/mcp/public>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

# frontend(SPA)。上記2つより後に書く。
Alias /flow-office /path/to/frontend/dist
<Directory /path/to/frontend/dist>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
    FallbackResource /flow-office/index.html
</Directory>
```

XSERVERの共有ホスティングでは`.htaccess`(`AllowOverride All`相当)経由でのApache設定変更が
基本のため、上記のAlias自体はサーバーパネル側のドキュメントルート設定機能、または契約プランに
応じた設定方法に読み替えること。

### ディレクトリ権限

デプロイ用ユーザーと実行時のWebサーバーユーザーが異なる場合、`storage/`・
`bootstrap/cache/`はどちらからも書き込めるようグループ共有にする。ただし
mcpの`storage/oauth-*.key`は**このディレクトリ単位の権限とは別に600で厳格に保つ**こと
(league/oauth2-serverが600/660以外だと明示的にエラーにする。775等に緩めると
本番でMCPエンドポイントが500になる)。

## 3. backend/ のデプロイ手順

```
cd backend
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env   # 値は下記「.env設定」参照
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
php artisan l5-swagger:generate
```

### .env設定(抜粋、本番差分のみ)

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com/flow-office/api
FRONTEND_URL=https://example.com/flow-office

# マウントパス自体が'api'セグメントを兼ねるため空にする(既定値'api'はローカル開発用)
APP_API_PREFIX=

DB_CONNECTION=mysql
DB_HOST=...
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

L5_SWAGGER_GENERATE_ALWAYS=false
MICROSOFT_MOCK_ENABLED=false   # 本番では設定しない(Entra ID資格情報はsystem_settingsで管理)
```

`config:cache`/`route:cache`/`view:cache`は、**.envを含む全設定を確定させた後に**実行すること。
.env変更後に再実行し忘れると古いキャッシュのまま動き、原因が分かりにくい不具合になる
(疑わしい挙動が出たら`config:clear && route:clear && view:clear`で一旦切り分ける)。

## 4. frontend/ のデプロイ手順

```
cd frontend
npm ci
VITE_BASE_PATH=/flow-office/ VITE_API_BASE_URL=https://example.com/flow-office/api npm run build
# dist/ の中身を /flow-office/ のドキュメントルートへ配置
```

`VITE_API_BASE_URL`は相対パスではなく**フルURL(スキーム+ホスト込み)を指定すること**
(`src/api/client.ts`の`new URL(path, base)`がbaseに絶対URLを要求するため)。

## 5. mcp/ のデプロイ手順

```
cd mcp
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build   # resources/のVite資産(/link, /oauth/authorize画面用)をビルド
cp .env.example .env   # 値は下記参照
php artisan key:generate
php artisan mcp:oauth-keys   # OAuth2署名鍵を「本番環境で」新規生成すること(devの鍵を使い回さない)
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
chmod 600 storage/oauth-private.key storage/oauth-public.key
chown <webサーバー実行ユーザー> storage/oauth-private.key storage/oauth-public.key
```

### .env設定(抜粋)

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com/flow-office/mcp

DB_CONNECTION=mysql   # backend/とは別のDB(スキーマ・接続情報を分ける)
DB_DATABASE=...

MCP_BACKEND_API_BASE_URL=https://example.com/flow-office/api/
```

mcp/はbackend/とは別データベースを使う(独立したLaravelアプリ)。DB作成・ユーザー作成を
別途行うこと。

## 6. cron設定

3アプリとも常駐ワーカーを前提にしない。各アプリのcrontabに**1行だけ**登録する
(具体的なジョブスケジュールは各アプリの`routes/console.php`側で定義済み)。

```cron
* * * * * cd /path/to/backend && /usr/bin/php8.x artisan schedule:run >> /dev/null 2>&1
* * * * * cd /path/to/mcp     && /usr/bin/php8.x artisan schedule:run >> /dev/null 2>&1
```

cronのPATHには`php`が含まれないことが多い。**必ずphpバイナリをフルパスで指定すること**
(XSERVERで実際に使われているPHPバージョンのパスを確認する)。frontendはSPAの静的配信のみで
cron不要。

## 7. デプロイ後の疎通確認

```
curl -I https://example.com/flow-office/                                  # 200 (frontend)
curl    https://example.com/flow-office/api/onboarding/status             # 200 JSON (backend)
curl -I https://example.com/flow-office/api/api-docs                      # 200 (Swagger UI)
curl -I https://example.com/flow-office/mcp/link                          # 200 (mcp)
curl    https://example.com/flow-office/mcp/.well-known/oauth-authorization-server  # 200 JSON
curl -I -X POST https://example.com/flow-office/mcp                       # 401 (トークン無し。404/405/500ではないこと)
```

最後の`POST /flow-office/mcp`(マウントパス直下)は、JSON-RPCエンドポイントが
アプリのルート(`/`)に登録されているため成立する。ここが**404か405で返ってきた場合は
route:cacheとURLサブパス配置の組み合わせによる既知の問題(下記「8. リハーサルで発見した
注意点」)が再発している可能性が高い**ので、`route:clear`で切り分けること。

cron設置後、`schedule:run`が実際に1分毎に発火しているか、DBキュー(`jobs`テーブル)に
積んだジョブが処理されるかを一度確認する(ログや`jobs`テーブルの行数で確認できる)。

## 8. リハーサルで発見した注意点

今後別のドメイン構成・別のPHPバージョンで再デプロイする際にも再発しうるため記録する。

- **`bootstrap/app.php`内の`env()`は`.env`を読めない**: Laravel標準の`.env`読み込み
  ブートストラッパーは`bootstrap/app.php`実行後に動くため、`withRouting()`の`apiPrefix`引数
  などここで`env()`を直接呼んでも常に既定値にフォールバックする(`config:cache`の有無に
  関わらず)。`backend/bootstrap/app.php`は先頭で`Dotenv::createImmutable(...)->safeLoad()`を
  明示的に呼んで回避している。同種の「起動の最速部分でしか使えない設定値」を追加する際は
  この先読みが必要になることに注意。
- **`route:cache`とURLサブパス配置の組み合わせで、ルート`'/'`のGETが誤って405になる**:
  Laravel/Symfonyの既知の問題として、アプリがサブディレクトリにマウントされ
  (basePathが空でない)、かつルートキャッシュが有効な場合、文字通りのルート`'/'`への
  GETリクエストが「GETは許可されていない」405として扱われることがある(クロージャ・
  `Route::redirect()`・通常のコントローラアクションいずれでも再現)。ルートキャッシュを
  使う前提でサブパス配置するアプリでは、**GETで文字通りの`'/'`にルートを登録しない**
  設計にすること(案内が必要な場合はWebサーバー側のリダイレクトで対応する)。
  mcp/はJSON-RPCエンドポイント(`POST /`、`mcp/routes/api.php`)だけ例外的に`'/'`へ
  登録している(バグの再現条件がGETのみと判断したため)。本番デプロイのたびに上記
  「7. デプロイ後の疎通確認」の`POST /flow-office/mcp`が401で返ることを必ず確認し、
  もし404/405が再発したらこの前提が誤りだったということなので、`mcp/routes/api.php`の
  ルートを`/mcp`など非ルートパスへ戻し、`scripts/tunnel/Caddyfile`・
  `MetadataController`のresource組み立てもあわせて戻すこと。
- **Apacheの`Alias`は宣言順マッチ**: 最長一致ではない。限定的なパスを先に書かないと、
  一般的なパスのAliasに奪われる。
- **MySQLの識別子長制限(64文字)**: Laravelの自動命名する複合`unique`/`index`は、
  テーブル名・カラム名が長いと64文字を超えてマイグレーションが失敗する。SQLite(ローカル
  開発・CI)は無制限のため気づけない。長くなりそうな複合indexには明示的に短い名前を
  指定すること。
- **マイグレーションの外部キー参照順序**: 参照先テーブルを作るマイグレーションより後に
  参照元を実行してしまうと、SQLiteでは無警告で通るがMySQLでは外部キーエラーになる。
- **composer.json(`^8.3`)とcomposer.lockの不一致**: 現状`composer.lock`はsymfony系が
  PHP 8.4.1以上を要求しており、PHP 8.3環境では`composer install`自体が失敗する
  (CI・devcontainerは既にPHP 8.4を使用)。本番のPHPバージョンをどちらに合わせるか要判断
  (`composer.json`を`^8.4`に上げるか、依存を8.3対応に戻すか)。backend/mcp両方に該当。
- **OAuth鍵ファイルの権限**: `storage/`配下を一括でグループ書き込み可能にすると、
  `mcp:oauth-keys`が生成した鍵ファイルも一緒に緩んでしまい、league/oauth2-serverの
  権限チェックで500エラーになる。鍵ファイルは常に600を維持すること。

## 9. 未対応の既知課題

- mcp/にCIワークフローが存在しない(`.github/workflows/`にbackend用・frontend用のみ)。
  `phpunit.xml`の設定不備(存在しない`tests/Unit`参照)によりFeatureテストが一度も
  実行されていなかった問題は2026-07-19に修正済みだが、CI組み込みは別途対応が必要。
