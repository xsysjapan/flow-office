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
php artisan access-control:sync-catalog   # AccessControlCatalogに定義したFeature・Permissionを反映
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
php artisan l5-swagger:generate
```

本番は`db:seed`を実行しないため、`AccessControlCatalog`
(`app/Domain/AccessControl/AccessControlCatalog.php`)にFeature・Permissionを追加しただけでは
本番DBへは反映されない。`access-control:sync-catalog`は追加専用(`updateOrInsert`のみ、既存の
Role・Feature割当は変更しない)で毎デプロイ実行しても安全なため、`activate-release.sh`から
`migrate --force`の直後に自動実行し、コードに追加するだけで管理画面(アクセス管理)の
一覧に反映されるようにしている。

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

### 3.1 休暇まわりの連携変更(変更セット `20261009-keep-leave-work-type-on-edit`)のリリース手順

休暇の申請・残数・勤怠を文脈間のイベント連携に切り替えたリリース。既存データは移行コマンドではなく、
`stored_events`の作り直しで新しいイベント構成に変換して引き継ぐ(変更セット論点18。変換規則と実行手順は
`docs/changesets/20261009-keep-leave-work-type-on-edit/assets/event-rebuild-mapping.md` 7章)。移行イベント・移行コマンド・
補正イベントは作らない。作り直しは本番での実行の前に、変更セットのリハーサル手順書(`docs/changesets/20261009-keep-leave-work-type-on-edit/assets/rehearsal-checklist.md`)の手順で複製DBに対して行い、
試し実行の結果を確認する。以下の順序で行う。

1. 事前条件の確認(試し実行で自動確認): 作り直しの表`stored_events_rebuilt`が存在しないこと、補正ログ`stored_event_corrections`が空であること、
   本番に新しい種類の休暇申請イベント(`paid_leave_request.*`)が無いこと。違反があれば`--apply`は拒否される。
2. 休暇の申請・承認・付与取消の操作を止める(メンテナンス窓)。書き込みの停止(メンテナンスモード、スケジューラ・キューワーカーの停止、
   打刻端末・API・MCPの受付停止)は作り直しの前に行う。
3. 作り直しの前に、DB全体のバックアップと、全ReadModelのテーブルのバックアップを取る(ロールバックに使う)。
4. 試し実行: `php artisan leave:rebuild-event-store`。件数・申請の最終状態・cutoverの突き合わせ結果を確認する。
   扱えない並びが1件でもあれば失敗する(推測で埋めない。変換規則を決めてから再実行する)。
5. 作り直しを書く: `php artisan leave:rebuild-event-store --apply`(`stored_events_rebuilt`に書き、自己整合性を検査する)。
   検査だけを再度行う場合は`--verify`。
6. 複製DBで検証する。本番DBを複製し、複製に対して`--apply`・`--swap`を行い、全ReadModelを空にして全Projectorを
   `event-sourcing:replay`した結果を、本番の作り直し前のReadModelと比較する。差分が意図した差(差し戻された休暇の消化記録の取消、
   休暇値の削除等)だけであることを確認する。
7. 本番で入れ替える: `php artisan leave:rebuild-event-store --swap`。元の表は`stored_events_before_rebuild_YYYYMMDDHHMMSS`として残る。
8. 旧系統を削除した新しいコードを配備し、`php artisan migrate --force`を実行する(マイグレーション`2026_10_10_000001`〜`000009`・
   `2026_10_11_000001`)。新しいコードは旧イベントを読めないため、7の後は新しいコードを配備するまで休暇を操作させない。
9. ReadModelを作り直す: 管理画面「データの作り直し」(`/admin/read-model-rebuild`。権限`read_model.rebuild`)から、まず「試し実行」で
   テーブルごとの件数・内容の差(利用者・権限のテーブルの変化の警告を含む)を確かめ、問題が無ければ「作り直す」を実行する
   (1トランザクションで全ReadModelを空にして全Projectorを再生する。実行中は全APIが503のメンテナンス中になり、失敗・中止ならロールバックされる。
   スケジューラー(`schedule:run`のcron)が動いていることが前提。日次バッチの時刻(1:00〜3:00台)を避ける)。
   SSHで行う場合の手作業の手順: `php artisan leave:rebuild-event-store --list-readmodels`で出るテーブルを空にしてから、
   `php artisan event-sourcing:replay`で全Projectorを再生成する(Projectorは既存の状態を消さない前提のため、必ず空にしてから実行する)。
10. 日次計算を再計算する: `php artisan attendance:recalculate-days --from=... --to=...`(既定は試し実行。一覧で値が変わる日を確認し、
    `--apply`で記録する)。締め・提出済みの月の日も対象に含め、一覧に印を付ける。月次スナップショットは変えない。
    値が変わる日と締め・提出済みの月のスナップショットとの差は、リハーサルで確認した方針に従う。
11. 書き込みを再開する(メンテナンス窓の終了)。再開後に、休暇まわりの画面で差戻し・再申請・取消・休暇日の勤怠表示を確認する
    (7章の疎通確認に加える)。

- ロールバック: 7までに問題があれば、`RENAME TABLE stored_events TO stored_events_rebuilt_失敗時刻, stored_events_before_rebuild_YYYYMMDDHHMMSS TO stored_events`
  で表を元に戻し、ReadModelを3の方法で取ったバックアップから戻し、旧コードを再配備する。新しいコードは旧イベントを読めないため、
  表とコードは同時に戻す。8以降の問題は、DB全体のバックアップからの復元を検討する(ユーザーの判断)。

### 3.2 有給口座・付与予定のAggregateId分離(変更セット `20261021-paid-leave-aggregate-stream-id`)のリリース手順

有給口座・付与予定のイベントを、社員IDとは別のストリームIDに分けるリリース。新しいコードは、付け替えの前の履歴を正しく読めない
(空のストリームを読んだ状態で記録される)ため、付け替えが終わる前に1件でも書かれてはならない。本番の過去のイベントは、
`leave:rekey-paid-leave-streams`で付け替える(有給口座は新しいIDへ、付与予定の行は取り除く。変換規則・事前条件・検証は
`docs/32-stored-event-history-normalization.md`)。本番での実行の前に、複製DBでリハーサルする。

止めるもの(付け替えの前から、再開まで):

- `php artisan down`(メンテナンスモード)
- cronの`schedule:run`(`paid-leave:roll-schedules`・警告・社員同期など、有給口座・付与予定に書くスケジューラ)
- キュー(空になるまで処理してから止める。`userId`の無いイベントを持つ古いジョブ(`ReapplyPaidLeaveSchedulePolicyJob`等)を残さない)
- 打刻端末・API・MCPの受付

実行の順:

1. 本番の複製DBでリハーサルする。手順は`docs/changesets/20261024-paid-leave-release-rehearsal/assets/rehearsal-checklist.md`に従う(基準Aと合否の条件を含む)。
2. 本番のDB全体と、全ReadModelのテーブルのバックアップを取る(戻し方に使う。基準Aにも使う)。
2a. 基準A(旧コードで、付け替えの前に取る)。新しいコードは付け替えの前のイベントを読めない(例: `paid_leave_account.usage_designated`の旧payloadには`userId`が無い)ので、旧コードで取る。
   - 手順2のバックアップを、同じサーバーの別のDB(例: `flow_office_before`)に戻す。
   - 今動いている旧コードの`backend/`で、`DB_DATABASE=<別のDB>`を付けて実行する(Laravelは`.env`より環境変数を優先する)。実行の前に、`php artisan db:show`で接続先を確かめる。
   - 一覧(`php artisan leave:rebuild-event-store --list-readmodels`)のテーブルを空にし、`php artisan event-sourcing:replay --force`で再生する。
   - `php artisan leave:rebuild-event-store --readmodel-fingerprint`を取る(基準A)。
   - 別のDBを作れないとき(XSERVERのDBの作成の権限・容量は要確認)は、本番のDBで旧コードで再生して取る。その場合は、本番で実行する前にユーザーの許可を取る。
3. 新しいコードを配備する(`php artisan migrate --force`があれば実行する)。止めるものは、ここまでに止めておく。
4. 試し実行: `php artisan leave:rekey-paid-leave-streams`。付け替える行数・取り除く付与予定の行数(種類別)・旧ID→新IDの対応の件数を確かめる。
   事前条件に違反がある(付与予定の付与済み・判定の上書き・個別修正、`source='scheduled_batch'`の付与)ときは止まる。件数をユーザーに報告して判断を仰ぐ。
5. `php artisan leave:rekey-paid-leave-streams --apply`(`stored_events_rebuilt`に書き、検査する)。検査だけを再度行う場合は`--verify`。
6. `php artisan leave:rekey-paid-leave-streams --swap`(検査が通っている場合のみ。元の表は`stored_events_before_rekey_YYYYMMDDHHMMSS`として残る)。
7. ReadModelを作り直す: 管理画面「データの作り直し」から実行する(試し実行→本実行。手順9の説明を参照)。SSHで行う場合は、
   `php artisan leave:rebuild-event-store --list-readmodels`で出るテーブルを空にしてから、`php artisan event-sourcing:replay`で
   全Projectorを再生成する(必ず空にしてから)。
8. 指紋を比べる(原則2の確認。リハーサルと本番で同じことをする)。
   - 7の再生の後に、`php artisan leave:rebuild-event-store --readmodel-fingerprint`を取る(B)。
   - AとBを比べる。合否の条件は次のとおり(リハーサル手順書の「7」と同じ)。
     - 付与予定の2つの表(`paid_leave_schedule_entries`・`paid_leave_schedule_assessments`)は、Bでは0件(Aの件数は記録する)。
     - `paid_leave_fiscal_year_settings`は、Aには無い表(このリリースで新しくできる)。Bの行が、既定の会社カレンダー(`company_calendars`の`is_default=1`の行)と、カレンダーのID・年度開始の月・日で一致すること。一致しなければ止める。
     - それ以外の表は、件数・ハッシュがAと一致すること。
     - 比較では、`created_at`・`updated_at`と、`stored_events.id`を指す列(`stored_event_id`・`last_changed_event_id`)を比べない(`ReadModelFingerprinter::EXCLUDED_COLUMNS`)。
   - 差があれば`--readmodel-fingerprint-rows=<テーブル>`・`--readmodel-diff=<基準AのDB>`で行を特定して止める。
   - 本番のReadModelと基準Aの差は、別件として報告する。
9. 受付を再開する(`php artisan up`、`schedule:run`・キュー・打刻端末・API・MCP)。再開後に、有給の付与・消化・履歴の画面と、
   次の`paid-leave:roll-schedules`(自動付与が有効な社員がいれば、新しいIDで付与予定が作り直される)を確認する。

- 戻し方: 表をRENAMEで戻し(`RENAME TABLE stored_events TO stored_events_rekeyed_失敗時刻, stored_events_before_rekey_YYYYMMDDHHMMSS TO stored_events`)、
  ReadModelを2のバックアップから戻す(または旧コードで再生する)、旧コードを再配備する。付け替えでは残る行のidが変わらないので、戻すのは簡単。
  表とコードは同時に戻す(新しいコードは付け替え前の履歴を、旧コードは付け替え後の履歴を、正しく読めない)。
- 本番で実行する前に、試し実行(手順4)の結果をユーザーに示して許可を取る(`data-correction`スキル)。許可の日時と内容は、変更セットの
  「レビュー履歴」に残す。

### 3.3 有給の年度はじめ一斉付与・付与日数の表の区分(変更セット `20261021-paid-leave-fiscal-mass-grant-and-auto-assessment` 段階1)のリリース手順

付与方式の初期値を「年度はじめに一斉付与」にし、付与日数の表を区分(通常/比例の週4〜1日)ごとに持たせ、一斉付与日を既定の
会社カレンダーの年度開始日に連動させるリリース。migrationはマスタの変更だけを行い、付与予定の作り直し(Job)は発行しない。
デプロイの後に、運用コマンドで作り直す。

1. `php artisan migrate --force`を実行する。次を行う。
   - `paid_leave_grant_rules.grant_cycle_type`の既定値を`mass_grant_month`にし、`mass_grant_month`列を削除する。
     既定のカレンダーの年度開始月と違う`mass_grant_month`を持つルールがあれば、ルール名と月を出力(コンソールと`storage/logs`)に出す。
     付与日が年度開始日に変わるので、出力の内容を確認する。
   - `paid_leave_grant_rule_steps.grant_category`を追加し、uniqueを(rule_id, grant_category, continuous_service_months)へ付け替える。
   - 勤務形態が比例に分類されるルールの表を`proportional_{N}`へ移し、表が空の全社ルールに法定の写しを入れ、初期の全社ルールの名前を
     「全社共通(年度はじめ一斉付与)」にする。
   - `paid_leave_fiscal_year_settings`を作り、今の既定の会社カレンダーから初期値を入れる。
1a. 影響を数える(個人を特定できる値は出さない)。手順1の直後、手順2の前に、SQLで件数だけを数える。
   - migrationの出力のルール名と月を控える(ルールの名前は個人情報ではない)。
   - 在籍中(`employment_status='active'`)・自動付与が有効(`paid_leave_auto_grant_enabled=1`)・入社日がある社員の数。
   - 付与予定の、状態ごとの件数。
   - 一斉付与の月が年度開始の月と違うルールに当たる社員の数は、SQLでは数えない(ルールの選び方が複雑なため)。付与予定画面の絞り込みと、手順2の出力の件数で確かめる。
   - 手順2の出力の件数(作り直した社員の数・付与予定日・日数が変わった行の数)と合わせて、ユーザーに報告する。社員への影響は、付与予定の作り直しを通じてしか出ない(手動の付与には影響しない)。
   - 詳しいSQLは、リハーサル手順書(`docs/changesets/20261024-paid-leave-release-rehearsal/assets/rehearsal-checklist.md`)の「4」「8」を参照する。
2. `php artisan paid-leave:schedule:reapply-policy`を実行する(運用コマンド画面からも実行できる)。全アクティブ社員の未確定の
   付与予定を、新しいルール・年度開始日で作り直す(個別修正の行も作り直す。付与済み・取消済みは変えない)。
   出力の「作り直した社員の数」と「付与予定日・日数が変わった行の数」を確かめる。付与済みの社員は、最後の付与済みの行を起点に
   次回が並ぶ。
3. 付与予定画面で、一斉付与日・日数(通常は法定の通常表、比例の社員は週日数に応じた比例表)を確認する。
   既存の比例のルールが要確認に後退していないこと。
- 戻し方: 付与予定は、ルール・年度開始日を戻して`paid-leave:schedule:reapply-policy`をもう一度実行すれば作り直せる。
  migrationの`down`は、列の既定値・区分・uniqueを戻すが、削除した`mass_grant_month`の値は戻らない(出力した内容を控えておく)。

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
# route:cacheは実行しない(下記「8. リハーサルで発見した注意点」参照)
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
curl -I -X POST https://example.com/flow-office/mcp/                      # 401 (トークン無し。404/405/500ではないこと)
```

最後の`POST /flow-office/mcp/`(末尾スラッシュあり。理由は下記「8. リハーサルで
発見した注意点」参照)は、JSON-RPCエンドポイントがアプリのルート(`/`)に登録されて
いるため成立する。mcp/では`route:cache`を使わない運用にしているため、ここが
**404か405で返ってきた場合はmcp/の`bootstrap/cache/routes-v7.php`が古いデプロイの
`route:cache`実行によって残ってしまっている可能性が高い**ので、`route:clear`で
切り分けること。

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
- **`route:cache`とURLサブパス配置の組み合わせで、ルート`'/'`が誤って405になる**:
  Laravel/Symfonyの既知の問題として、アプリがサブディレクトリにマウントされ
  (basePathが空でない)、かつルートキャッシュが有効な場合、文字通りのルート`'/'`への
  リクエストが「このメソッドは許可されていない」405として扱われることがある(クロージャ・
  `Route::redirect()`・通常のコントローラアクションいずれでも再現し、GET/POSTいずれの
  登録でも再現する)。当初「GETでのみ再現する」と判断してmcp/のJSON-RPCエンドポイント
  (`POST /`、`mcp/routes/api.php`)を例外的に`'/'`へ登録したが、2026-07-20の本番デプロイ
  で`POST /flow-office/mcp`が405を返しパイプラインが失敗する事故が発生し、POSTでも
  再現することが判明した。
  mcp/のJSON-RPCエンドポイントはマウントパス直下(`/flow-office/mcp`)がそのままエンド
  ポイントになる設計を維持するため、ルート自体は`'/'`から動かさず、代わりに**mcp/では
  `route:cache`を実行しない**ことでこの問題を回避している(`deploy/scripts/activate-release.sh`
  参照。`config:cache`/`view:cache`は問題ないため引き続き実行する)。ルートキャッシュを
  使う前提でサブパス配置する他アプリ(backend/等)で新たに文字通りの`'/'`にルートを
  追加する場合も、同じ理由で**`route:cache`を使わない**か、**`'/'`にルートを登録しない**
  設計にすること(案内が必要な場合はWebサーバー側のリダイレクトで対応する)。
- **Apacheの`Alias`は宣言順マッチ**: 最長一致ではない。限定的なパスを先に書かないと、
  一般的なパスのAliasに奪われる。
- **シンボリックリンクで実ディレクトリとして見える`/api`・`/mcp`直下への末尾スラッシュ
  なしアクセスは、Apache本体(`mod_dir`)にディレクトリとして処理され、期待通りに
  動かないことがある**: `frontend/dist/api`・`frontend/dist/mcp`はそれぞれ
  `backend/public`・`mcp/public`へのシンボリックリンクであり、Apacheからは実
  ディレクトリに見える。末尾スラッシュなしで`/flow-office/mcp`のようにこのディレクトリ
  直下へアクセスすると、`mod_dir`が`frontend/dist/.htaccess`のRewriteRuleより先に
  ディレクトリとして処理してしまい、301リダイレクト(既定の`DirectorySlash On`時)や
  素の403(GET)/404(POST)になることがある。これはメソッドに関わらず起きるため、
  `POST /flow-office/mcp`のようなボディ付きリクエストが期待通りの401(mcp/側の
  レスポンス)を返さず、ヘルスチェックが失敗する事故が発生した。

  `.htaccess`側だけでの対処(`DirectorySlash Off`、RewriteRuleのsubstitutionを
  相対パス/絶対パスに変える、シンボリックリンク名をURLパスと違える等)を複数試したが、
  この本番環境では`mod_dir`が`mod_rewrite`より先に評価されるため確実には防げず、
  シンボリックリンク名を変える方式はLaravelの`SCRIPT_NAME`/`REQUEST_URI`の前提
  (物理パスとURLパスの接頭辞が一致すること)を壊してしまい、`api/onboarding/status`
  等の既存エンドポイントまで壊れる regressionを起こした。

  最終的に、**末尾スラッシュありの`/api/`・`/mcp/`は問題なく動く**ことを確認した上で、
  末尾スラッシュなしの直下アクセスは無理に修正せず、そちらを正としてヘルスチェック・
  MCPクライアント設定を統一する方針にした(`.github/workflows/deploy.yml`の
  ヘルスチェックは`POST $BASE_URL/mcp/`を叩く)。mcp/のOAuthディスカバリー
  (`/mcp/.well-known/oauth-protected-resource`)が広告するリソースURLも、この制約に
  合わせて末尾スラッシュ付きにすることを今後検討すること。
- **MySQLの識別子長制限(64文字)**: Laravelの自動命名する複合`unique`/`index`・外部キー
  (`foreign`)制約名は、テーブル名・カラム名が長いと64文字を超えてマイグレーションが
  失敗する。SQLite(ローカル開発・従来のCI)は無制限のため気づけない。長くなりそうな
  複合index・外部キーには明示的に短い名前を指定すること
  (`$table->foreignUuid('col')->constrained('table', 'id', '短い制約名')`、
  `$table->index([...], '短いindex名')`)。
  2026-08にこの理由で本番デプロイが失敗する事故が2度目に起きたため、
  `.github/workflows/tests.yml`の`migrate-mysql`ジョブでPR・デプロイ前に実際のMySQLへ
  `migrate`→`migrate:rollback`→`migrate`を通して検証するようにした(sqliteだけの
  `tests`ジョブでは検知できないため、必ずMySQLで確認する)。このジョブが失敗する場合、
  本番デプロイでも同じ理由で失敗する。
- **マイグレーションの外部キー参照順序**: 参照先テーブルを作るマイグレーションより後に
  参照元を実行してしまうと、SQLiteでは無警告で通るがMySQLでは外部キーエラーになる。
- **composer.json(`^8.3`)とcomposer.lockの不一致**: 現状`composer.lock`はsymfony系が
  PHP 8.4.1以上を要求しており、PHP 8.3環境では`composer install`自体が失敗する
  (CI・devcontainerは既にPHP 8.4を使用)。本番のPHPバージョンをどちらに合わせるか要判断
  (`composer.json`を`^8.4`に上げるか、依存を8.3対応に戻すか)。backend/mcp両方に該当。
- **OAuth鍵ファイルの権限**: `storage/`配下を一括でグループ書き込み可能にすると、
  `mcp:oauth-keys`が生成した鍵ファイルも一緒に緩んでしまい、league/oauth2-serverの
  権限チェックで500エラーになる。鍵ファイルは常に600を維持すること。

- **migrate失敗が`current`の切替をブロックし、以後のデプロイも同じ理由で失敗し続ける**:
  MySQLはDDL(`CREATE TABLE`/`ALTER TABLE`等)をトランザクション内でもロールバックせず
  即コミットする。そのため「テーブル作成自体はDB上で成功したが、直後の`migrations`
  テーブルへの記録処理でmigrateコマンド自体が中断した(SSHセッション切断等)」状態が
  起こり得る。`activate-release.sh`は`migrate --force`が失敗するとその場で停止し
  `current`の切替まで到達しないため、本番は古いリリースのままになる。この状態になると、
  次回以降のデプロイでも**同一のマイグレーションが「table already exists」等で
  同じ理由により失敗し続け**、誰かが手動で気づいて復旧しない限り`current`が永遠に
  進まない(2026-08-01に本番で実際に発生。約3日分のマージがデプロイされないまま
  気づかれなかった)。

  `activate-release.sh`はmigrate失敗時に`migrate:status`の全出力を診断情報として
  ログに残すようにしてある(`run_migrate`関数)。復旧手順:
  1. GitHub Actionsのデプロイログで、失敗したmigrate:statusの出力を確認し、
     どのマイグレーションが`Pending`のまま止まっているか特定する
     (手元で確認する場合は対象アプリのディレクトリで`php artisan migrate:status`)
  2. 該当テーブル/カラムが実際のDBに存在するか、マイグレーション定義と一致するかを
     `SHOW CREATE TABLE <table>;`等で確認する
  3. 一致していれば(=単なる記録漏れ)、`migrations`テーブルに手動で行を追加する:
     ```sql
     SELECT MAX(batch) FROM migrations;  -- 現在の最大batch番号を確認
     INSERT INTO migrations (migration, batch)
     VALUES ('<マイグレーションファイル名(.php抜き)>', <上記+1>);
     ```
  4. 一致していなければ(=作成が構造の途中で中断した可能性)、不足しているカラム/
     制約を`ALTER TABLE`で個別に補うか、テーブルを`DROP`してから再デプロイで
     作り直す(どちらが安全かはデータの有無で判断する)
  5. GitHub Actionsのデプロイを再実行する(`workflow_dispatch`または空コミットpush)

## 9. 未対応の既知課題

- mcp/にCIワークフローが存在しない(`.github/workflows/`にbackend用・frontend用のみ)。
  `phpunit.xml`の設定不備(存在しない`tests/Unit`参照)によりFeatureテストが一度も
  実行されていなかった問題は2026-07-19に修正済みだが、CI組み込みは別途対応が必要。
