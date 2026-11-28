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

**本番で実施済み(コマンドは削除済み)**(変更セット`remove-finished-migration-commands`、ユーザーの回答、2026-11-24)。休暇の申請・残数・勤怠を
文脈間のイベント連携に切り替えたリリースで、既存データは`stored_events`の作り直し(`leave:rebuild-event-store`)で新しいイベント構成に変換して引き継いだ。
作り直しのコマンドと、移行前のバックアップから作り直す手順は廃止した(変換規則は`docs/changesets/20261009-keep-leave-work-type-on-edit/assets/event-rebuild-mapping.md`に残る)。
再実行は不要で、手順は残さない。

### 3.2 有給口座・付与予定のAggregateId分離(変更セット `20261021-paid-leave-aggregate-stream-id`)のリリース手順

**本番で実施済み(コマンドは削除済み)**(変更セット`remove-finished-migration-commands`、ユーザーの回答、2026-11-24)。有給口座・付与予定のイベントを、
社員IDとは別のストリームIDに分けるリリースで、過去のイベントは`leave:rekey-paid-leave-streams`で付け替えた。コマンドと手順は廃止した
(変換規則・検証の記録は変更セット`20261021-paid-leave-aggregate-stream-id`に残る)。

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

### 3.4 運用コマンドの実行経路の切替(変更セット `20261025-read-model-rebuild-admin-command`)のデプロイ手順

運用コマンドの実行を、DBキュー(`RunAdminCommandJob`。`queue:work`の60秒で強制終了される)から、毎分起動する実行役
`admin-commands:run-pending`(`routes/console.php`)へ移した。`App\Jobs\RunAdminCommandJob`は削除されている。

1. 新しいコードを配備する(`php artisan migrate --force`。この変更でmigrationは増えない)。
2. `jobs`テーブルに残った`RunAdminCommandJob`の行を削除する(クラスが無いため、残ると`queue:work`で失敗し続ける)。
   `DELETE FROM jobs WHERE payload LIKE '%RunAdminCommandJob%';`(先に`SELECT COUNT(*)`で件数を確かめる)。
3. `admin_command_runs`に`queued`のまま残っている記録は、新しい実行役が次の起動で拾って実行する(`running`のまま残っているものは、
   実行役の起動時に「応答がないため失敗とみなしました」で失敗になる)。

### 3.5 所定労働時間の見直し(変更セット `20261114-prescribed-minutes-no-leave-reduction`)のリリース手順

日次の所定労働時間を休暇(半休)で減らさないようにし、フレックス・管理監督者の5区分を全て所定内にした。migrationは増えない。
デプロイ後、既存の日次計算を新しい計算にそろえる(運用コマンドは管理画面「運用コマンド」から実行できる)。

1. 新しいコードを配備する(`php artisan migrate --force`。この変更でmigrationは増えない)。
2. 「日次勤怠の一括再計算」(`attendance:recalculate-days`)を、対象期間・「週40時間の手動振分け(所定内からの振分け)がある日を対象外にする」に
   チェックを付け、「実際に記録する」は付けずに実行し、変わる日・除外される日の一覧を確かめる。
3. 問題なければ「実際に記録する」も付けて再実行する。週40時間の自動の振分けは再計算後に自動でやり直される。
4. 提出・承認・締め済みの月の汎用CSV/TSV・Excelに新しい値(勤務予定ベースの所定労働時間・所定労働日数)を出す場合のみ、
   「月次勤怠スナップショット再計算」(`attendance:recalculate-month-snapshots`)を実行する(freee連携は実行しなくても新しい値になる)。

### 3.6 バックオフィスタスクの割当イベントの分離(変更セット `20261122-backoffice-task-reassign`・`20261122-backoffice-assignment-migration-admin`)のリリース手順

**本番で実施済み(コマンドは削除済み)**(変更セット`remove-finished-migration-commands`、ユーザーの回答、2026-11-24)。`backoffice_task.assigned`を
「担当者の設定のみ」に改め、割当と同時の確認中への変化を`backoffice_task.status_changed`として分けて記録し直す`stored_events`の作り直しは、
運用コマンド「バックオフィス割当イベントの移行」(`backoffice:migrate-assignment-events`)で行った。コマンドと旧形式の検出器(作り直しの拒否)は削除した。
手順の記録は変更セット`20261122-backoffice-task-reassign`・`20261122-backoffice-assignment-migration-admin`に残る。

### 3.7 ReadModelの作り直しのずれの修正・中間版(変更セット `20261123-rebuild-drift-fixes`)のリリース手順

ReadModelの作り直しが、ライブの内容と一致しない不具合(削除した行の子行の残り・カレンダー一括操作の対象行IDが作り直しのたびに変わる・自動採番IDを参照する列の比較)を直すリリース。
命名統一(3.8)より**先に**、改名前のコード(`cd1d40ce`)に本変更セットのコードを載せた「中間版」として配備する(必須)。
理由: 3.8の改名はずれがあると検証に失敗して戻る。一方、改名後のコードで補正コマンドを実行しても、旧項目名のイベントが残っている間は作り直しが拒否される。
過去の`calendar_bulk_operation.reverted`のID(`revertedTargetIds`・`excludedTargetIds`)と、利用者の作成系イベントのタイムゾーン・テナントID
(再生の時点の設定値を使わないためのキー。変更セット`20261124-user-projection-settings-at-replay`)は、運用コマンド`events:fix-rebuild-determinism`で
**まとめて1回**書き換える(`data-correction`)。2つの補正は別々に順に実行すると互いを止めるため、単独の`events:fix-calendar-bulk-operation-target-ids`・
`events:fix-user-settings-at-replay`は、相手の補正も必要な環境ではまとめのコマンドを案内して止まる。

実行は**管理画面の運用コマンドから**行う(管理者でログインする。補正の前でも、ログインと管理画面は動く)。実行中は`ReadModelRebuildRunService`のメンテナンスで
他の操作が止まるので、同時の書き込みは起きない。**`php artisan down`とSSHでの実行は不要**(配備そのものの短い停止は除く)。

実行の順:

1. 中間版を配備する(`php artisan migrate --force`)。
2. 管理画面の運用コマンドで「再生の決定性の補正」(`events:fix-rebuild-determinism`)を試し実行する(`--execute`を付けない。何も変更しない)。
   2つの補正の結果(書き換える件数・一括操作の対応表(旧ID→新ID)・タイムゾーン・テナントIDの補完とテナントIDの上書きの一覧・補えない値)を確かめる。
   対応が付かないID・補えない値があれば止めて人が確認する。
3. 結果をユーザーに示し、本実行の許可を得る。
4. 管理画面で「実行」する(`--execute`)。バックアップ表の作成 → 書き換え → 補正ログ → ReadModelの作り直し(1回)→ 検証を1つのトランザクションで行い、
   検証に失敗すれば戻る。この手順は作り直しを含むので、別に`read-models:rebuild`は実行しない。
5. 管理画面で「データの作り直し(ReadModel)」(`read-models:rebuild`)を試し実行し、変更のあるテーブルが0件(差分0)であることを確かめる。0件でなければ、ずれの表から原因を調べる。

- 本番の実行には、試し実行の結果を示したうえでユーザーの明示的な許可が要る。許可の日時と内容は、変更セット`20261123-rebuild-drift-fixes`・`20261124-user-projection-settings-at-replay`の「レビュー履歴」に記録する。
- 未補正のイベント(一括操作の取消イベントのIDがUUIDv5の再計算と一致しないもの、利用者の作成系イベントでタイムゾーン・テナントIDのキーが無いもの)が残っている間は、
  `read-models:rebuild`・`projections:rebuild`・管理画面の作り直しは拒否される(補正の前に作り直すと旧IDの行が消える、または未補正のイベントで例外になるため)。
  拒否の文言は、両方の補正が必要なら`events:fix-rebuild-determinism`、利用者の設定値だけなら`events:fix-user-settings-at-replay`、一括操作だけなら`events:fix-calendar-bulk-operation-target-ids`を案内する。
- 冪等: 補正済みなら2回目の試し実行は「対象なし」になる。
- **開発・ステージング・e2eのDBも、新しいコードにしたら`events:fix-rebuild-determinism`を実行する**(または`migrate:fresh --seed`で作り直す)。
  補正の前でも、集約の取り出し(ログイン等)は動く。止まるのは作り直しだけ。

### 3.8 真偽値の命名統一(変更セット `20261123-boolean-column-naming`)のリリース手順

列・イベント項目の真偽値の名前を命名ルール(`api-design`スキルの「命名」)に合わせ、`paid_leave_usages.confirmed` を削除するリリース。
列の改名(migration)と、`stored_events` の旧項目名の書き換え(運用コマンド`events:rename-boolean-properties`)を1回のメンテナンス窓で行う。
移行イベントは作らず、`stored_events`の行の`id`・集約ID・順序は変えない。書き換えの後に全ReadModelを作り直して検証する。
本番の実行の前に、リハーサルと試し実行の結果をユーザーに示して許可を得る(`data-correction`)。

止めるもの: 配備の後は、旧項目名のイベントが残る間、システムが「補正待ち」のメンテナンスに自動で入る
(変更セット`20261124-correction-pending-maintenance`。`php artisan down`・SSH・cron/キューの手動停止は使わない)。

- API: ログイン・認証(`auth/*`)、`auth/me`・`access/me`・`maintenance-status`・`onboarding/status`、運用コマンドの一覧・実行記録のGETと実行の依頼のPOSTだけが通り、
  他は503(`code=correction_pending`)になる。端末の打刻(`POST /device-punches`)も503になる(docs/23「補正待ちの間の端末」)。
- cron: `admin-commands:run-pending`(運用コマンドの実行役)だけが動き、他の定期処理は飛ばす(飛ばされた日次バッチは後から追い付かない。日次バッチの時刻(1:00〜2:20)を避けて配備・実行する)。
- 補正待ちの判定は、検出器(`LegacyBooleanPropertyDetector`・`LegacyUserSettingsDetector`・`LegacyCalendarBulkOperationTargetIdDetector`)のどれかが陽性か。
  結果は60秒キャッシュし、補正のコマンドが終わる(成功・失敗とも)とキャッシュを消す。補正の実行中は、従来の作り直しのメンテナンス(全API 503、GETの一部のみ)になる。
- MCPサーバーの受付は、`mcp/`が`backend/`のAPIを使う操作が503になるだけで足りる(別に止めない)。

事前条件: 旧項目名のイベントを読む過去の移行(休暇の作り直し等。3.1・3.2・3.6)は本番で実施済みで、コマンドは削除済みのため、事前の実行は要らない(開発・ステージングの古いDBは`migrate:fresh --seed`で作り直す)。

実行の順:

0. リハーサル(本番の複製DBで、この手順をすべて行う): 所要時間・`max_binlog_cache_size`・ピークメモリを測る。試し実行で「補えない行」が出ないことを確かめる。
1. 中間版で基準値を取る(配備の前。新しいコードは旧項目名のイベントを読めないため、中間版で行う)。
   - 本番のDB全体と全ReadModelのテーブルのバックアップを取る(戻し方に使う)。
   - `php artisan read-models:rebuild --dry-run`で、変更のあるテーブルが0件であることを確かめる(既存のずれが0であること)。
     0件でなければ、ずれの表(テーブルごとの差分)を確認し、原因を特定するまで手順2以降に進まない(ずれたまま改名の`--execute`を実行すると検証に失敗して戻る)。
   - 下の「基準値のSQL」(旧列名)を実行し、結果を控える(基準値)。
2. 配備の前に、端末アプリの利用者(共有端末の利用者)へ、「配備から補正の完了まで(数分〜数十分の想定)は打刻できない。完了後に勤怠の編集で入力する」と案内する
   (端末アプリがこの間の503を、オフラインの打刻としてためるかは未確認。docs/23「補正待ちの間の端末」)。
3. frontendとbackendを同じ窓で配備する(管理画面の旧画面が旧名で送ると、新しいbackendは422を返す。論点10)。
   `php artisan migrate --force`を実行する。migrationは列の改名と`paid_leave_usages.confirmed`の削除を行う。削除の前に
   `is_confirmed <> confirmed`の件数をログ(`storage/logs`)に出すので控える(0件の想定。0件でなくても、ReadModelの作り直しで新しいProjectorの値に揃う)。
   配備が済むと、旧項目名のイベントが残っているため、補正待ちのメンテナンスに自動で入る。
4. 試し実行(管理画面): 管理者がログインし、運用コマンド画面で『真偽値のイベント項目名の書き換え』(`events:rename-boolean-properties`)を、`execute`を付けずに実行する(何も変更しない)。
   event_classごとの対象件数・補う件数(旧キーが無く既定値を補う行)・旧→新の例を確かめる。「補えない行」の表示があれば止めて人が確認する。
   件数と例をユーザーに示し、本実行の許可を得る。
5. 本実行(管理画面): 同じコマンドを`execute`を付けて実行する。
   待ち時間(`READ_MODEL_REBUILD_CLAIM_WAIT_SECONDS`、既定60秒)の後、トランザクションの前にバックアップ表
   (`stored_events_backup_YYYYMMDDHHMMSS`。出力に出る表名を控える)を作り、1つのトランザクションで「書き換え → ReadModelの作り直し → 検証」を行う。
   検証(変更のあったテーブルが0件)に失敗すれば全て戻る(バックアップ表は残る)。
   実行中は全利用者がメンテナンス中になる。
6. 検出器が0になると、補正待ちのメンテナンスが自動で解ける(補正のコマンドが終わるとキャッシュを消す。残っていれば最大60秒)。cron・キューも自動で再開する。
7. 検証(次の3つが揃うこと)。
   - 旧項目名のイベントが残っていない: 管理画面の運用コマンドで『ReadModelの作り直し』(`read-models:rebuild`)を試し実行(dry-run)し、「旧項目名の真偽値のイベント」で拒否されず、変更のあるテーブルが0件(差分0)であること。
   - 基準値と一致: 「基準値のSQL」を新しいコードで、列名を下の対応表で読み替えて実行し、手順1の基準値と件数が一致する。
     `paid_leave_usages`は旧`is_confirmed`が新`is_confirmed`、旧`cancelled`が新`is_cancelled`と一致すること。
   - 画面: 有給の指定・承認・取消、年5日取得義務の警告、勤怠Excelの有給残日数を1件ずつ確かめる(従来と同じ値になる)。
     端末アプリが使う打刻の応答に`is_offline`と`offline`の両方が返ることを、1件のテスト打刻で確かめる。

- 戻し方(検証が失敗して本実行が戻った場合は、stored_events・ReadModelは変わっていない。原因を直して手順4からやり直すか、下の手順で戻す):
  配備の後に戻す場合は、受付を止めたまま次の順に行う。新しいコードは旧項目名の履歴を読めず、中間版は新項目名の履歴を読めないため、表とコードは同時に戻す。
  1. `stored_events`をバックアップ表から戻す: `RENAME TABLE stored_events TO stored_events_after_rename_YYYYMMDDHHMMSS, stored_events_backup_YYYYMMDDHHMMSS TO stored_events;`
     戻す前に`stored_events`の件数がバックアップ表と同じであることを確かめる(メンテナンス中のため同じはず。違えばバックアップ以降に書かれた行を失うので止める)。
  2. `php artisan migrate:rollback --step=1`(列を旧名に戻す。downで`paid_leave_usages.confirmed`を作り直し、`UPDATE paid_leave_usages SET confirmed = is_confirmed`で値を入れる)。
  3. 中間版を frontend と backend の両方で配備する。
  4. 中間版で`php artisan read-models:rebuild`を実行し、手順1の基準値(旧列名)と一致することを確かめる。
  5. 受付を再開する(`up`、cron・キュー・受付)。
  この戻し方はバックアップ以降に書かれたイベントを失うため、必ずメンテナンス中に行う。
- 本番で実行する前に、リハーサルの結果と試し実行(手順4)の件数・例をユーザーに示して許可を取る(`data-correction`スキル)。
  許可の日時と内容は、変更セット`20261123-boolean-column-naming`の「レビュー履歴」に残す。

列名の対応(基準値のSQLで使う。旧列名 → 新列名):

| 表 | 旧列名 | 新列名 |
|---|---|---|
| `paid_leave_usages` | `is_confirmed` | `is_confirmed`(変えない。`confirmed`は削除) |
| `paid_leave_usages` | `cancelled` | `is_cancelled` |
| `devices` | `allow_offline` | `allows_offline` |
| `devices` | `require_location` | `requires_location` |
| `devices` | `auto_detect_punch_type` | `punch_type_auto_detection_enabled` |
| `attendance_punches` | `offline` | `is_offline` |
| `company_calendars` | `allow_daily_holiday_override` | `allows_daily_holiday_override` |
| `work_styles` | `system_generated` | `is_system_generated` |
| `attendance_daily_calculations` | `core_time_violation` | `has_core_time_violation` |
| `group_types` | `primary_membership_required` | `requires_primary_membership` |
| `role_assignments` | `include_descendants` | `includes_descendants` |
| `leave_attendance_rate_days` | `attended` | `has_attended` |
| `special_leave_attendance_rate_days` | `attended` | `has_attended` |
| `leave_attendance_rate_attendance_days` | `clocked_out` | `has_clocked_out` |
| `special_leave_attendance_rate_attendance_days` | `clocked_out` | `has_clocked_out` |
| `authentication_key_device_rules` | `allow` | `allows_use` |
| `external_integration_connections` | `enabled` | `publishing_enabled` |
| `asset_number_rules` | `enabled` | `is_active` |
| `system_settings` | `prohibit_self_privileged_role_assignment` | `prohibits_self_privileged_role_assignment` |

基準値のSQL(旧列名。手順1で中間版の配備前のDBに対して実行する。結果は`列 / 値 / 件数`の行の一覧になる):

```sql
SELECT 'paid_leave_usages.is_confirmed' AS c, is_confirmed AS v, COUNT(*) AS n FROM paid_leave_usages GROUP BY is_confirmed
UNION ALL SELECT 'paid_leave_usages.cancelled', cancelled, COUNT(*) FROM paid_leave_usages GROUP BY cancelled
UNION ALL SELECT 'devices.allow_offline', allow_offline, COUNT(*) FROM devices GROUP BY allow_offline
UNION ALL SELECT 'devices.require_location', require_location, COUNT(*) FROM devices GROUP BY require_location
UNION ALL SELECT 'devices.auto_detect_punch_type', auto_detect_punch_type, COUNT(*) FROM devices GROUP BY auto_detect_punch_type
UNION ALL SELECT 'attendance_punches.offline', offline, COUNT(*) FROM attendance_punches GROUP BY offline
UNION ALL SELECT 'company_calendars.allow_daily_holiday_override', allow_daily_holiday_override, COUNT(*) FROM company_calendars GROUP BY allow_daily_holiday_override
UNION ALL SELECT 'work_styles.system_generated', system_generated, COUNT(*) FROM work_styles GROUP BY system_generated
UNION ALL SELECT 'attendance_daily_calculations.core_time_violation', core_time_violation, COUNT(*) FROM attendance_daily_calculations GROUP BY core_time_violation
UNION ALL SELECT 'group_types.primary_membership_required', primary_membership_required, COUNT(*) FROM group_types GROUP BY primary_membership_required
UNION ALL SELECT 'role_assignments.include_descendants', include_descendants, COUNT(*) FROM role_assignments GROUP BY include_descendants
UNION ALL SELECT 'leave_attendance_rate_days.attended', attended, COUNT(*) FROM leave_attendance_rate_days GROUP BY attended
UNION ALL SELECT 'special_leave_attendance_rate_days.attended', attended, COUNT(*) FROM special_leave_attendance_rate_days GROUP BY attended
UNION ALL SELECT 'leave_attendance_rate_attendance_days.clocked_out', clocked_out, COUNT(*) FROM leave_attendance_rate_attendance_days GROUP BY clocked_out
UNION ALL SELECT 'special_leave_attendance_rate_attendance_days.clocked_out', clocked_out, COUNT(*) FROM special_leave_attendance_rate_attendance_days GROUP BY clocked_out
UNION ALL SELECT 'authentication_key_device_rules.allow', allow, COUNT(*) FROM authentication_key_device_rules GROUP BY allow
UNION ALL SELECT 'external_integration_connections.enabled', enabled, COUNT(*) FROM external_integration_connections GROUP BY enabled
UNION ALL SELECT 'asset_number_rules.enabled', enabled, COUNT(*) FROM asset_number_rules GROUP BY enabled
UNION ALL SELECT 'system_settings.prohibit_self_privileged_role_assignment', prohibit_self_privileged_role_assignment, COUNT(*) FROM system_settings GROUP BY prohibit_self_privileged_role_assignment;

SELECT COUNT(*) AS paid_leave_usages_is_confirmed_mismatch FROM paid_leave_usages WHERE is_confirmed <> confirmed;
```

検証(手順7)では、同じSQLを新しいコード・新しい列名で実行する(左の列を対応表の新列名に置き換える。`paid_leave_usages`の`confirmed`の行は無い)。

### 3.9 月次勤怠の承認・差戻しをワークフローだけにする(変更セット `20261128-remaining-entrypoints` 論点8)のリリース手順

承認・差戻しの入口(`POST /attendance-months/{id}/approve`・`/return`)から、ワークフローの申請を持たない月を直接承認する分岐を外す。
ワークフローの申請が無い月は422「この月の申請が見つかりません。」になる(データ移行はしない)。リリースの前に、本番で
「提出済みでワークフローの申請が無い月」が0件であることを確かめる。

```sql
SELECT COUNT(*) AS submitted_without_workflow
FROM attendance_months m
WHERE m.status = 'submitted'
  AND NOT EXISTS (
    SELECT 1 FROM workflow_requests w
    WHERE w.subject_type = 'attendance_month' AND w.subject_id = m.id AND w.status = 'submitted'
  );
```

0でなければ、先にその月を(旧コードのまま)承認してもらってからリリースする。

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
