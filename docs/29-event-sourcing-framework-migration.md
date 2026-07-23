# 29. spatie/laravel-event-sourcing への移行

自前実装のCQRS+ES基盤(`App\Domain\EventSourcing\EventStore`/`CommandBus`/独自`Projector`契約)を
`spatie/laravel-event-sourcing`(v7)に置き換える。目的は「Projectionは必ずイベントから生成する」
(3.2節)を全ドメインで徹底すること。現状は`AttendanceDailyCalculationProjector` /
`WorkflowRequestProjector` / `BackOfficeTaskProjector` / `NotificationProjector` の4ドメインのみが
Projector経由で、他は CommandHandler が正データのEloquentモデルを直接読み書きしている
(backend/CLAUDE.md参照)。これをドメインごとに段階的に置き換える大工事であり、最終的に
イベントストアのデータ移行(バックフィル)まで行う。

## 現状分析(移行前の集約棚卸し)

`stored_events`は元々 `aggregate_type` + `aggregate_id` + `version` によるストリームキーと
楽観ロックを既に持っていた(spatieの`aggregate_uuid`+`aggregate_version`と概念は同じ)。
つまり「集約」という概念自体は既存コードにも存在しており、欠けていたのは
`AggregateRoot`という再利用可能なPHPクラスだけだった。

洗い出した現在の集約(`aggregate_type`文字列)一覧:

`attachment, device, device_admin_session, authentication_key, application_integration,
backoffice_task, workflow_request, notification, user, attendance_day, attendance_punch,
attendance_month, work_calendar, work_calendar_day, work_style, shift_pattern,
employee_shift_assignment, rotation_pattern, employee_rotation_assignment,
user_work_style_monthly_assignment, legal_holiday_designation, paid_leave_request,
paid_leave_grant, special_leave_request, special_leave_grant, export`

設計上の注意点:

- **PaidLeave/SpecialLeaveの承認系、DesignateLegalHoliday** は1つのCommandHandlerが複数の集約
  ストリーム(request + grant + attendance_day 等)に同一トランザクションで書き込む。spatieの
  `AggregateRoot::persistInTransaction(...$roots)`がこの用途に対応するヘルパーであり、
  現状のドメインロジックを再設計する必要はない。
- **ExportController** はCommand/CommandHandlerを経由せず直接`EventStore::append()`を呼んでいる
  唯一の例外。移行対象に含めるかは各ドメイン移行時に判断する(現時点では未着手)。
- **AttendanceImport**(作業報告書インポート)は`docs/17-events.md`にイベント体系が文書化されて
  いるが実装は存在しない(`AttendanceDifferenceDetector`サービスのみ)。spatie移行の対象は
  「既存の実装」であり、このドキュメント専用のイベント群は本移行のスコープ外。

## テーブル構成

`spatie/laravel-event-sourcing`は標準で`stored_events`という名前のテーブルを要求し、既存の
自前実装のテーブル名と衝突する。移行期間中は新旧を並存させる:

- **`legacy_stored_events`**(旧`stored_events`から改名): 自前`EventStore`が書き込む、未移行
  ドメイン用のテーブル。`App\Models\StoredEvent`(テーブル名のみ変更、クラス名は既存コードへの
  影響を避けるため変更していない)が引き続き参照する。
- **`stored_events`**(新規): spatieの標準スキーマ(`aggregate_uuid` / `aggregate_version` /
  `event_version` / `event_class` / `event_properties` / `meta_data` / `created_at`)。移行済み
  ドメインはこちらに書き込む。

このテーブル名の入れ替えは `2026_07_09_145900_create_legacy_stored_events_table.php`
(旧`create_stored_events_table.php`から改名・内容変更)と
`2026_07_23_044800_create_stored_events_table.php`(新規)の2マイグレーションで行った。

## `event_class`の短縮文字列ルール(既存原則の継承)

backend/CLAUDE.mdの原則「`stored_events.event_type`はPHPクラス名ではなく短い文字列」を
spatie移行後も維持するため、`config/event-sourcing.php`で以下を設定した:

- `enforce_event_class_map = true`: `event_class_map`に登録されていないイベントクラスは
  永続化時に例外(`EventClassMapMissing`)になる。イベントクラスの名前空間・クラス名を
  後から変更しても、`event-sourcing:replay`による再生に影響しない。
- `event_class_map`: `'<aggregate>.<past_tense_verb>' => FQCN::class` の対応表。ドメインを
  移行するたびにここへ追記する(`.claude/skills/add-domain-event`を今後更新しこの手順を明文化する)。
- `aggregate_event_order_column = 'aggregate_version'`: 集約単位の取得は`aggregate_version`で
  ソートする(スキーマコメント通り、大量イベントでのfilesortを避けるための推奨設定)。

## 集約IDのUUID化方針

spatieの`aggregate_uuid`はグローバルに一意な文字列(型は`uuid()`だがMySQL/SQLiteでは
フォーマット強制のないただの文字列カラム)である。`aggregate_type`のような区分を持たないため、
複数の集約が同じ整数IDを再利用すると衝突する。

- **既にコマンド側生成UUIDを主キーにしている集約**(`workflow_requests` / `backoffice_tasks`、
  今回`attachments`もこれに追加): そのままEloquentモデルの主キー(`HasUuids`)を
  `aggregate_uuid`として使う。行の新規作成イベントもUUIDが事前に確定しているため
  Projectorが担当でき、「Projectionは必ずイベントから生成する」を完全に満たせる。
- **外部キーで広く参照されるDB採番の集約**(代表例: `users.id`。認証・Sanctumトークン・
  ほぼ全テーブルから参照される): 主キー自体をUUID化するのは影響範囲が大きすぎるため、
  このドキュメントでは主キー変更を強制しない。該当ドメインの移行時に、
  (a) 既存の連番PKとは別にストリーム識別用の`aggregate_uuid`列を追加してリレーションのPKには
  触れない方式と、(b) 主キー自体をUUID化する方式のどちらを採るか、そのドメインの移行に着手する
  タイミングで個別に判断する。
  - `application_integrations`(`attendance_punches.integration_id`が参照)、
    `devices`(`attendance_punches.device_id` / `authentication_key_device_rules` /
    `device_admin_sessions`が参照)、`authentication_keys`(`attendance_punches.
    authentication_key_id` / `authentication_key_device_rules` / `device_admin_sessions`が参照)は、
    Attendanceドメイン(作業順6・最後)のスキーマに今このタイミングで手を入れたくないため
    (a)方式を採用する: 主キーは連番intのまま維持し、`aggregate_uuid`列(unique)を追加して
    ESの集約ストリーム識別に使う。CommandHandlerはUUIDを発番して
    `XxxAggregate::retrieve($uuid)`→`persist()`し、Projectorが`aggregate_uuid`列を
    キーに`updateOrCreate`する(行の新規作成含めて100% Projector経由)。既存の連番PKを
    使うルート/コントローラ/FKには一切変更が不要という利点がある
    (`ApplicationIntegration`で実装・検証済み)。
  - `device_admin_sessions` / `notifications`のように他テーブルから参照されない集約は、
    Attachment/WorkflowRequest/BackOfficeTaskと同様に(b)方式(主キー自体をUUID化)でよい。

## 作業順(合意済み)

1. **Attachment**(完了。詳細は下記「移行済み: Attachment」)
2. Integration / AuthenticationKey / Device / DeviceAdminSession / Notification
   (単純・他ドメインに依存しない) — **全て完了**
   - **Integration完了**(詳細は下記「移行済み: Integration」)。
   - **AuthenticationKey完了**(Integrationと同じ(a)方式。詳細は下記「移行済み: AuthenticationKey」)。
   - **Device完了**(同じ(a)方式。詳細は下記「移行済み: Device」)。
   - **DeviceAdminSession完了**(他テーブルから参照されないため(b)方式=UUID主キー。
     `DeviceAdminSessionOpener`で`AggregateRoot::persistInTransaction()`を初適用し、
     既存セッションの終了+新規セッションの開始を1トランザクションで記録する)。
   - **Notification完了**((b)方式。`notifications.id`は元々`SendNotificationJob::enqueue()`側で
     UUID発番済みだったため主キー変更は不要だった)。
3. BackOffice / Workflow(既に主キーUUID化・Projector化済みのため、spatieへの載せ替えは
   AggregateRoot導入のみで済む)
4. PaidLeave / SpecialLeave(複数集約書き込みが絡む。`persistInTransaction`の実地検証を兼ねる)
5. User(`users.id`のUUID化要否をここで最終判断する)
6. Attendance(最大・最も複雑なため最後。サブ集約が多数あるため複数回に分けて移行する想定)

各段階の完了条件は「対象ドメインの正データテーブルが100% Projector経由でのみ更新される
(CommandHandlerが直接`::create`/`->save()`しない)」こと。

## 移行済み: Attachment(パイロット実装)

最も単純な独立ドメインとして、フレームワーク導入の検証を兼ねて最初に完全移行した。

- `attachments.id`をDB採番からUUID主キー(`HasUuids`)に変更
  (`workflow_requests`/`backoffice_tasks`と同じ理由。集約ルート自体をProjector化するため)。
- `App\Domain\Attachment\Events\AttachmentUploaded` / `AttachmentDownloaded` を
  `Spatie\EventSourcing\StoredEvents\ShouldBeStored`のサブクラスに変更。
  `AttachmentUploaded`には行の再構築に必要な全フィールド(`storedPath`/`mimeType`含む)を
  持たせた(旧実装はHandlerが直接rowを作っていたため一部フィールドが欠けていた)。
- `App\Domain\Attachment\Aggregates\AttachmentAggregate`を新設
  (`uploadAttachment()` / `downloadAttachment()`)。
- `App\Domain\Attachment\Projectors\AttachmentProjector`を新設。`attachment.uploaded`で
  行を作成・更新する。`attachment.downloaded`は状態を変えない監査イベントなので
  ハンドラメソッドを持たない(`stored_events`に記録されること自体が監査ログ)。
  spatieの`auto_discover_projectors_and_reactors`によりconfig登録不要で自動検出される。
- `UploadAttachmentHandler` / `AttachmentController::download()`
  を`AttachmentAggregate::retrieve()->...->persist()`経由に書き換え、
  旧`EventStore`/`CommandBus`直呼びを除去。
- テスト(`tests/Feature/Attachment/AttachmentTest.php`)を
  `Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent`(新`stored_events`)への
  問い合わせに更新。
- フロントエンド`Attachment.id`型を`number`→`string`に追随
  (`frontend/src/api/types.ts`、関連するテスト・story)。

Attachmentドメインの旧`projections:rebuild`コマンドへの登録は行っていない(旧実装の
`App\Domain\EventSourcing\Contracts\Projector`とは別インターフェースのため)。再生成が必要な
場合は`php artisan event-sourcing:replay AttachmentProjector`を使う(事前に`attachments`
テーブルを手動でtruncateしてから実行する。spatieの`replay`コマンド自体はテーブルを
truncateしない)。

## 移行済み: Integration((a)方式のパイロット実装)

`application_integrations.id`は`attendance_punches.integration_id`から参照されるため主キーは
連番intのまま維持し、「集約IDのUUID化方針」の(a)方式を初適用した。

- `application_integrations`に`aggregate_uuid`(uuid, unique)列を追加。主キー(`id`)・
  ルート(`{integration}`は引き続きint)・コントローラは無変更。
- `App\Domain\Integration\Events\*`を`ShouldBeStored`のサブクラスに変更。行の再構築に
  必要な全フィールド(`ownerType`/`purpose`/`personalAccessTokenId`など)を持たせた。
  集約の識別子は独自にコンストラクタ引数へ持たせず、`ShouldBeStored`が標準で持つ
  `aggregateRootUuid()`(イベント記録時に自動セットされるメタデータ)をProjectorから
  参照する形にした(冗長なプロパティを避けるため)。
- `App\Domain\Integration\Aggregates\ApplicationIntegrationAggregate`を新設。
  `status`/`scopes`を内部状態として保持し、`reissueToken()`の「無効化済みは再発行不可」
  という業務ルールの判定はEloquentモデルの列ではなく、この集約が過去イベントを再生して
  復元した状態(`$aggregate->status()`)を見て行う(CQRSの原則により忠実な形)。
- `App\Domain\Integration\Projectors\IntegrationProjector`を新設。
  `application_integration.registered`で`updateOrCreate(['aggregate_uuid' => ...], [...])`
  により行の新規作成を含めて担当する。
- Sanctumトークンの発行(`$user->createToken()`)はイベント再生では再現できない外部作用のため、
  Handlerが集約に記録する前に実行し、その結果(トークンID)をイベントペイロードに含める
  (docs/29冒頭の設計判断通り)。
- Handlerは以後、`ApplicationIntegration`モデルを直接`create`/`save`せず、業務判断に必要な
  現在値の読み取り(`personalAccessToken`関連・`owner`関連の取得)にのみEloquentを使う。

## 移行済み: AuthenticationKey((a)方式)

`authentication_keys.id`は`device_admin_sessions.authentication_key_id`等から参照されるため
主キーは連番intのまま維持し、Integrationと同じ(a)方式を適用した。

- `authentication_keys`に`aggregate_uuid`(uuid, unique)列を追加。
- `App\Domain\AuthenticationKey\Events\*`を`ShouldBeStored`のサブクラスに変更。行の再構築に
  必要な全フィールド(`keyHash`/`validFrom`/`validUntil`/`metadata`/`registeredAt`/`disabledAt`)
  を持たせた(旧実装はHandlerが直接rowを作っていたため一部フィールドが欠けていた)。
- `App\Domain\AuthenticationKey\Aggregates\AuthenticationKeyAggregate`と
  `App\Domain\AuthenticationKey\Projectors\AuthenticationKeyProjector`を新設。
- `App\Models\Concerns\HasAggregateUuid`トレイトを新設し、`AuthenticationKey` /
  `ApplicationIntegration`の両モデルに適用した。(a)方式のモデルはCommandHandler経由の
  作成では集約側でUUIDを発番しProjector経由で行を作るが、ユニットテスト・ファクトリ等で
  モデルを直接`create()`する既存コード(`tests/Unit/Models/AuthenticationKeyTest.php`等)も
  引き続き動作させるため、`creating`イベントで`aggregate_uuid`が未指定なら自動生成する
  フォールバックを持たせている。(a)方式の集約を今後追加する際はこのトレイトを再利用する。

## 移行済み: Device((a)方式)

`devices.id`は`attendance_punches.device_id` / `authentication_key_device_rules.device_id` /
`device_admin_sessions.device_id`から参照されるため主キーは連番intのまま維持し、
Integration/AuthenticationKeyと同じ(a)方式を適用した。9イベント(登録・ペアリング・
ペアリングトークン発行・停止・失効・論理削除・役割変更・スコープ付与・設定変更)を
`ShouldBeStored`化し、`DeviceAggregate`/`DeviceProjector`を新設した。

- **`device.pairing_reissued`を`device.pairing_claim_issued`に統合**: 旧実装は「既にactive
  だった端末への再発行」の場合のみイベントを記録し、初回発行時は`activated_by_user_id`を
  直接rowへ書き込むだけでイベントを残していなかった(「必ずイベントを書く」原則に反する)。
  移行にあたり、初回・再発行のどちらでも必ず記録する`DevicePairingClaimIssued(issuedByUserId,
  wasReissued)`に統合し、Projectorが`wasReissued`のときだけstatusをpending_pairingへ戻す形にした。
- **業務ルール判定は集約の再生状態ではなくProjection(Eloquent)の現在値を読む**: 当初は
  `DeviceAggregate`に`status`/`ownerType`等の内部状態をイベント再生で保持させ、
  `ClaimDevicePairingHandler`等の判定に使う設計にしたが、既存の
  `tests/Feature/Device/DeviceRegistrationTest.php`等が`Device::factory()->create(['status' =>
  'disabled'])`のようにイベントを経由せず直接rowを作成しており、その場合は集約の再生状態が
  空(初期値)のままでProjectionの実際の値と乖離してしまい、削除可否等の判定を誤る
  ことが分かった。Projectorは同一リクエスト内で同期的に反映されるため、Projectionの現在値は
  常に最新かつ正しい。そのため`DeviceAggregate`はイベントの記録(recordThat/persist)専用の
  薄いクラスに留め、ステータス等の判定はHandlerが`devices`テーブルを直接読んで行う方式に
  変更した(Integration/AuthenticationKeyのように集約の再生状態を判定に使うか、Projectionを
  直接読むかは、ケースバイケースで低コストな方を選んでよい)。
- Sanctumトークンのability再計算(`GrantDeviceScope`/`UpdateDeviceRoles`)は、まず集約へ
  イベントを記録・永続化してProjector経由でdevice_roles/device_scopesを更新した後、
  更新済みのProjectionを読み直してabilitiesを再計算し、トークンへ反映する順序にした
  (Projectorが先に反映を終えているため、Handlerは常に最新のroles/scopesを見られる)。

## 移行済み: DeviceAdminSession((b)方式、複数集約トランザクションの初適用)

`device_admin_sessions.id`は他のどのテーブルからも参照されないため、(b)方式(UUID主キー)を
採用した。

- `device_admin_sessions.id`をUUID主キー(`HasUuids`)に変更。
- `DeviceAdminSessionAggregate` / `DeviceAdminSessionProjector`を新設。
- `DeviceAdminSessionOpener`(NFCタップ経由・ブートストラップ経由の両Handlerが共通で使う
  サービス)は「既存のアクティブセッションがあれば終了させ、新しいセッションを開始する」を
  1つの操作として扱う。これは異なる2つの集約インスタンス(終了させる既存セッションのUUIDと、
  新規セッションのUUID)に同一トランザクションでイベントを記録する必要があるケースで、
  `Spatie\EventSourcing\AggregateRoots\AggregateRoot::persistInTransaction(...$aggregateRoots)`
  を使う設計方針(本ドキュメント冒頭「現状分析」参照)を初めて実地に適用した。
  `retrieve()->end()`/`retrieve()->start()`で2つの集約に別々にイベントを記録してから、
  最後に`persistInTransaction()`へまとめて渡すことで、DBトランザクションを1つにまとめつつ
  Projectorへの反映もそれぞれ正しく行われることを確認した。

## 移行済み: Notification((b)方式)

`notifications.id`は元々`SendNotificationJob::enqueue()`側で`Str::uuid()`により発番されて
いたため、主キーの変更は不要だった(既に「集約ルートのUUID化」の前提を満たしていた)。
`NotificationAggregate` / `NotificationProjector`を新設し、`SendNotificationJob::enqueue()`/
`handle()`・`ConfirmNotificationHandler`を書き換えた。旧`App\Domain\EventSourcing\Contracts\
Projector`実装だった`NotificationProjector`は`config/domain.php`の`projectors`配列から削除し、
spatieの自動検出に委ねた。

## 最終的なデータ移行(全ドメイン移行後)

全ドメインの移行が完了したら、`legacy_stored_events`に残っている過去イベントを新しい
`stored_events`へバックフィルする。手順(未実施・今後の作業):

1. ドメインごとに `aggregate_type` → 集約ルートのUUID解決方法(既にUUID主キーならそのまま、
   ①方式の別列運用ならその列の値)を確定させる対応表を作る。
2. `event_type`(短い文字列)は既に`event_class_map`のキーと1対1になっているため、
   そのまま`event_class`列にコピーできる。
3. Artisanコマンド(例: `event-sourcing:backfill-legacy`)を新設し、
   `legacy_stored_events`を`aggregate_type`+`aggregate_id`+`version`順に読み、
   新`stored_events`へ`aggregate_uuid`/`aggregate_version`/`event_version`/`event_class`/
   `event_properties`/`meta_data`/`created_at`を対応付けて挿入する。
   既存の`occurred_at`は`meta_data.created_at`相当として保持する。
4. 移行後、両テーブルのイベント件数・集約バージョンの整合性を検証してから
   `legacy_stored_events`を読み取り専用アーカイブとして残す(削除はしない)。

このバックフィルは全ドメイン移行(上記作業順6まで)が終わってから着手する。
