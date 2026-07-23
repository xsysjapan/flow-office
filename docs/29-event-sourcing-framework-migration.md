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

**方針: ESで管理する集約は、他テーブルからの参照有無によらず主キー自体をUUID化する
(`HasUuids`)。** 行の新規作成イベントもUUIDが事前に確定しているためProjectorが担当でき、
「Projectionは必ずイベントから生成する」を完全に満たせる。DB採番の連番PKとESの集約ストリーム
識別子を別列で持つ方式(以下「別列方式」)は採用しない。

### 別列方式を試して撤回した経緯

Integration/AuthenticationKey/Deviceの3ドメインは、`attendance_punches.integration_id` /
`device_id` / `authentication_key_id`など他テーブルから連番PKを直接参照されていたため、
当初は主キーを連番intのまま維持し、別に`aggregate_uuid`列(unique)を追加してESの集約
ストリーム識別だけに使う方式(以下「別列方式」)を採用していた。Attendanceドメイン
(作業順6・最後)のスキーマに時期尚早に手を入れたくない、という判断からの選択だった。

その後、別列方式は採用しない方針にした。理由:

- 同じ「集約の識別子」が主キー(`id`)と別列(`aggregate_uuid`)の2つ存在し、
  「どちらを外部から参照すべきか」「Handlerはどちらでretrieveすべきか」を毎回判断させる
  複雑さを生む。実際、Handler内で`$model->id`(ルート/コントローラ向け)と
  `$model->aggregate_uuid`(集約retrieve向け)を混在して扱うコードになり、可読性を下げていた。
- 主キーをUUID化してしまえば列が1本で済み、Attachment/WorkflowRequest/BackOfficeTaskと
  完全に同じパターンに揃えられる。ドメイン間でパターンが割れていること自体が認知コストだった。
- Attendanceのスキーマ変更が「後で必要になる」という前提は、実際にはFK列の型変更
  (`unsignedBigInteger`→`uuid`/`foreignUuid`)だけで済み、Attendanceドメインの
  CQRS/ESロジック自体には触れない。つまり「Attendanceを最後に回す」という作業順自体は
  維持できたまま、今このタイミングで型変更だけ済ませておける。恐れていたほどのコストではなかった。

このため、次の変更を行った:

- `application_integrations` / `authentication_keys` / `devices`の主キーを`id`(UUID, `HasUuids`)
  に変更し、`aggregate_uuid`列は削除した(`App\Models\Concerns\HasAggregateUuid`トレイトも削除)。
- これらを参照している列をUUID対応の型に変更した:
  `attendance_punches.device_id` / `.authentication_key_id` / `.integration_id`、
  `device_roles.device_id`、`device_scopes.device_id`、
  `authentication_key_device_rules.device_id` / `.authentication_key_id`、
  `integration_scopes.integration_id`、`device_admin_sessions.device_id` / `.authentication_key_id`。
  (`$table->foreignId(...)` → `$table->foreignUuid(...)`、素の列は`uuid()`)。
- `Device`は`Laravel\Sanctum\HasApiTokens`を使い`personal_access_tokens`を`User`(int主キー)と
  共有しているため、`personal_access_tokens.tokenable_id`を`$table->morphs('tokenable')`から
  `$table->uuidMorphs('tokenable')`に変更した(単なる文字列カラムなので、User側のint値も
  Device側のUUID値も同じ列に問題なく収まる)。
- 各ドメインのCommand(`deviceId`/`authenticationKeyId`/`integrationId`等)・関連する
  `AttendancePunchRecorded`/`RecordAttendancePunch`(Attendanceドメイン)・
  `AuthenticationKeyResolver`・`DeviceAdminSession`関連の型を`int`→`string`に変更した。
  Attendance側で変わったのはこれらのプロパティの型だけで、CQRS/ESの実装そのもの
  (Command→Handler→Event→Projectorの流れ)には一切手を入れていない。
- Projector/Handlerは`updateOrCreate(['aggregate_uuid' => ...], ...)`ではなく
  `updateOrCreate(['id' => $event->aggregateRootUuid()], ...)`に、
  `where('aggregate_uuid', ...)`は`whereKey(...)`/`findOrFail(...)`にそれぞれ置き換えた。

### 例外(今回は対象外): User

`users.id`は認証(セッション/Sanctumの`tokenable`)・ほぼ全テーブルのFK・監査ログなど
極めて広い範囲から連番intとして参照されており、UUID化の影響範囲は
Integration/AuthenticationKey/Deviceの比ではない。作業順5(User)に着手するタイミングで
改めてUUID化するかどうかを判断する。判断材料:

- 賛成: 主キーをUUID化すればUserドメインもAttachment等と全く同じパターンに揃えられ、
  「例外」が1つも残らない。
- 慎重になる理由: Sanctumの認証解決・全ドメインのFK型・シードデータ・フロントエンドの
  ユーザーID表示(URLやCSV等、社内で目にする機会が多い)まで影響が及ぶため、切り替えの
  影響範囲を洗い出す下調べに一定の工数がかかる。
- 現時点の暫定方針: Userの主キーは連番intのまま据え置き、作業順5で着手する際に改めて
  UUID化の是非を判断する(このドキュメントで先取りして決めない)。

## 作業順(合意済み)

1. **Attachment**(完了。詳細は下記「移行済み: Attachment」)
2. Integration / AuthenticationKey / Device / DeviceAdminSession / Notification
   (単純・他ドメインに依存しない) — **全て完了**
   - **Integration完了**(詳細は下記「移行済み: Integration」)。
   - **AuthenticationKey完了**(詳細は下記「移行済み: AuthenticationKey」)。
   - **Device完了**(詳細は下記「移行済み: Device」)。
   - **DeviceAdminSession完了**。`DeviceAdminSessionOpener`で
     `AggregateRoot::persistInTransaction()`を初適用し、既存セッションの終了+新規セッションの
     開始を1トランザクションで記録する。
   - **Notification完了**。`notifications.id`は元々`SendNotificationJob::enqueue()`側で
     UUID発番済みだったため主キー変更は不要だった。
   - Integration/AuthenticationKey/Deviceは当初「別列方式」で実装したが、
     「集約IDのUUID化方針」記載の通り撤回し、主キー自体のUUID化に統一した。
3. BackOffice / Workflow(既に主キーUUID化・Projector化済みのため、spatieへの載せ替えは
   AggregateRoot導入のみで済む) — **完了**(詳細は下記「移行済み: Workflow / BackOffice」)
4. PaidLeave / SpecialLeave(複数集約書き込みが絡む。`persistInTransaction`の実地検証を兼ねる)
5. User(`users.id`のUUID化要否をここで最終判断する)
6. Attendance(最大・最も複雑なため最後。サブ集約が多数あるため複数回に分けて移行する想定)
   - **【課題】`AttendanceDailyCalculationProjector`を同時にspatie形式へ変換すること**:
     旧イベントバス(`App\Domain\EventSourcing\StoredEventRecorded` →
     `app/Listeners/ProjectStoredEvent.php` → `config('domain.projectors')`)への
     残存購読者は現時点でこの1つだけ。Attendanceイベントがspatie形式(`ShouldBeStored`)へ
     切り替わった瞬間に旧`EventStore::append()`が発火しなくなるため、この
     Projectorをそのままにしておくと`CreateBackOfficeTaskOnApproval`の時と同じ理由で
     `attendance_daily_calculations`の更新が静かに止まる。Attendance移行タスクの一部として
     `Spatie\EventSourcing\EventHandlers\Projectors\Projector`のサブクラスへ書き換え、
     `config('domain.projectors')`からは除去すること。「旧イベントバス自体を最終的に削除する」
     という計画は、この移行が完了して初めて安全に実行できる話であり、削除予定があること自体は
     この課題を解消しない。

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

## 移行済み: Integration

`application_integrations.id`をUUID主キー(`HasUuids`)に変更した
(`attendance_punches.integration_id`側もuuid型に変更。詳細は「集約IDのUUID化方針」参照)。

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
  `application_integration.registered`で`updateOrCreate(['id' => $event->aggregateRootUuid()],
  [...])`により行の新規作成を含めて担当する。
- Sanctumトークンの発行(`$user->createToken()`)はイベント再生では再現できない外部作用のため、
  Handlerが集約に記録する前に実行し、その結果(トークンID)をイベントペイロードに含める
  (docs/29冒頭の設計判断通り)。
- Handlerは以後、`ApplicationIntegration`モデルを直接`create`/`save`せず、業務判断に必要な
  現在値の読み取り(`personalAccessToken`関連・`owner`関連の取得)にのみEloquentを使う。

## 移行済み: AuthenticationKey

`authentication_keys.id`をUUID主キー(`HasUuids`)に変更した(参照側の列も
`foreignUuid`に変更。詳細は「集約IDのUUID化方針」参照)。

- `App\Domain\AuthenticationKey\Events\*`を`ShouldBeStored`のサブクラスに変更。行の再構築に
  必要な全フィールド(`keyHash`/`validFrom`/`validUntil`/`metadata`/`registeredAt`/`disabledAt`)
  を持たせた(旧実装はHandlerが直接rowを作っていたため一部フィールドが欠けていた)。
- `App\Domain\AuthenticationKey\Aggregates\AuthenticationKeyAggregate`と
  `App\Domain\AuthenticationKey\Projectors\AuthenticationKeyProjector`を新設。

## 移行済み: Device

`devices.id`をUUID主キー(`HasUuids`)に変更した(`attendance_punches.device_id` /
`authentication_key_device_rules.device_id` / `device_admin_sessions.device_id`等の参照側も
`foreignUuid`に変更。詳細は「集約IDのUUID化方針」参照)。9イベント(登録・ペアリング・
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

## 移行済み: DeviceAdminSession(複数集約トランザクションの初適用)

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

## 移行済み: Notification

`notifications.id`は元々`SendNotificationJob::enqueue()`側で`Str::uuid()`により発番されて
いたため、主キーの変更は不要だった(既に「集約ルートのUUID化」の前提を満たしていた)。
`NotificationAggregate` / `NotificationProjector`を新設し、`SendNotificationJob::enqueue()`/
`handle()`・`ConfirmNotificationHandler`を書き換えた。旧`App\Domain\EventSourcing\Contracts\
Projector`実装だった`NotificationProjector`は`config/domain.php`の`projectors`配列から削除し、
spatieの自動検出に委ねた。

## 移行済み: Workflow / BackOffice

両ドメインは既に主キーがコマンド側生成のUUID(`workflow_requests.id` / `backoffice_tasks.id`)
かつ旧`Projector`実装で完全にProjector化済みだったため、スキーマ変更は不要で、
イベント/Aggregate/Projectorをspatie形式へ載せ替えるだけで済んだ。

- 各イベントを`ShouldBeStored`のサブクラスに変更。集約の識別子は`aggregateRootUuid()`を
  使い、コンストラクタ引数からは`workflowRequestId`/`backOfficeTaskId`を除いた。
- タイムスタンプ列(`submitted_at`/`approved_at`/`completed_at`等)は、イベント側に専用の
  `xxxAt`引数を持たせず、`ShouldBeStored`が標準で持つ`$event->createdAt()`
  (`recordThat()`時に自動セットされるメタデータ)をProjectorから直接使う形にした
  (他ドメインでは`Carbon::now()->format(...)`をイベント引数として渡していたが、
  こちらの方が冗長でない)。
- `WorkflowRequestAggregate` / `BackOfficeTaskAggregate`を新設。業務ルール判定
  (ステータス遷移の可否等)はDeviceと同じ理由で集約の再生状態ではなくHandlerが
  `workflow_requests`/`backoffice_tasks`(Projection)の現在値を読んで行う
  (既存テストが`WorkflowRequest::query()->create()`/`BackOfficeTask::query()->create()`で
  イベントを経由せず直接rowを作成しているため)。
- **`App\Listeners\CreateBackOfficeTaskOnApproval`を`App\Domain\Workflow\Reactors\
  CreateBackOfficeTaskOnApprovalReactor`に置き換えた**: 旧実装はLaravelの`StoredEventRecorded`
  イベント(旧`EventStore::append()`が発火)を購読するListenerだったため、`workflow_request.approved`
  が新しい`stored_events`テーブル経由で記録されるようになると発火しなくなる。spatieの
  `Reactor`(Projectorと同じ`auto_discover_projectors_and_reactors`で自動検出される、
  「イベントを見て新しいCommandを発行する副作用」専用の抽象クラス)で置き換え、
  `WorkflowRequestApproved`を購読して`CreateBackOfficeTaskFromApproval`
  コマンドを発行する。旧Listenerは削除した。
- `config/domain.php`の`projectors`配列から`WorkflowRequestProjector` /
  `BackOfficeTaskProjector`(旧インターフェース実装)を削除。
- **監査ログ(UC-M003)・申請履歴表示が`legacy_stored_events`のみを見ていた問題を修正**:
  `AuditLogController`(`/api/audit-log`)と`WorkflowRequestController::history()`
  (`/api/workflow-requests/{id}/history`)はどちらも`App\Models\StoredEvent`
  (`legacy_stored_events`)を直接検索していたため、Workflow/BackOfficeの移行後は
  新しく発生したイベントが監査ログ・履歴表示から見えなくなってしまう欠落が起きるところだった。
  `App\Domain\EventSourcing\Support\EventHistoryQuery`を新設し、`legacy_stored_events`と
  新`stored_events`の両方を検索してマージ・日時降順ソートしたうえで結果を返す形にした
  (新テーブル側は`aggregate_type`列を持たないため、`event_class`の
  `"<aggregate>.<past_tense_verb>"`命名規則を使い`"<aggregate_type>."`前方一致で代用。
  `event_properties`はイベントクラスの公開プロパティ名(camelCase)がそのままキーになるため、
  ユーザーID検索用のキー一覧(`ACTOR_KEYS`)は`Str::camel()`で変換してから使う)。
  この仕組みは移行途中の一時的なものであり、全ドメイン移行後のバックフィル完了後は
  `legacy_stored_events`側の検索を削除できる。

## 監査ログ・申請履歴の再設計(`EventHistoryQuery`廃止)

上記の`EventHistoryQuery`(legacy/新テーブルの横断検索)は、Reactorの副作用漏れと同様に
「本番リリース前の移行期間だけの問題を、恒久的な二重検索の仕組みで解決してしまっていた」
ため、以下の通り作り直した。

- **`AuditLogController`は`stored_events`(新テーブル)のみを検索する形に単純化した**:
  `legacy_stored_events`に残っている未移行ドメインのイベントは、このリファクタリングが
  完了して全ドメインが移行し終わるまで本番投入する予定がないため、移行期間中に限り
  監査ログの対象外としても実害がない。`EventHistoryQuery`クラスは削除し、
  `AuditLogController`が直接`EloquentStoredEvent`を検索する(ロジック自体は
  旧`migratedQuery()`と同じ)。全ドメイン移行後のバックフィル完了後は
  この制限も自然になくなる。
- **`WorkflowRequestController::history()`はEventStoreを直接見るのをやめ、専用の
  履歴Projection(`workflow_request_history_entries`)を新設して参照する形にした**:
  従来は`stored_events`の生イベント(`event_class`・`event_properties`)をそのまま
  APIレスポンスにしていたため、イベントクラス名やプロパティ名(camelCase)の変更が
  そのままAPI・フロントエンドの表示仕様に影響してしまう(Projectionは再生成可能な
  派生データとして扱う、という設計原則にも反する)。
  - `App\Domain\Workflow\Projectors\WorkflowRequestHistoryProjector`が
    `workflow_request.*`イベントから`workflow_request_history_entries`
    (`workflow_request_id` / `action` / `actor_user_id` / `comment` / `occurred_at`)
    を作成する。`action`は`drafted`/`submitted`/`approved`/`returned`/`cancelled`の
    固定値で、イベントクラス名やペイロードの生の形に依存しない。
  - 冪等性は`stored_events.id`(`$event->storedEventId()`)を`stored_event_id`列に
    ユニーク制約付きで保持し、`updateOrCreate`することで担保する(申請は差戻し・再提出を
    繰り返せるため、`workflow_request_id` + `action`の組だけではキーにならない)。
  - `App\Http\Resources\WorkflowRequestHistoryEntryResource`がこのProjectionを
    レスポンス整形する。フロントエンドは`StoredEvent`型・`event_type`ではなく
    `WorkflowRequestHistoryEntry`型・`action`を参照する形に合わせて変更した
    (`frontend/src/api/workflowRequests.ts` / `WorkflowRequestDetailPage.tsx` /
    `utils/statusLabels.ts`の`workflowRequestHistoryActionLabel`)。

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
