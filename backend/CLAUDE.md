# backend/

Laravel API。CQRS + Event Sourcing 風の設計を採用している。設計原則の全体像はリポジトリ
ルートの `CLAUDE.md` と `docs/03-architecture.md` を参照。ユースケース・DBスキーマ・
イベント一覧は `docs/06`〜`docs/17` にある。

## セットアップ

```
cd backend
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite   # ローカル開発はsqlite、本番はMySQL
php artisan migrate --seed
php artisan serve                # http://localhost:8000
php artisan test
```

API仕様書(Swagger UI)の生成手順は `docs/02-tech-stack.md` を参照。

## ディレクトリ構成

```
app/
├── Domain/<DomainName>/       ドメインごとのCQRS+ES実装。詳細は下記
│   ├── Commands/              1コマンド1クラス
│   ├── Events/                1イベント1クラス。eventType()は文字列(例: 'shift_pattern.created')
│   ├── Handlers/               CommandHandler。検証 → イベント追記 → (必要なら)正データ更新
│   ├── Projectors/            Projection Table を再生成可能な形で更新する(あるドメインのみ)
│   └── Services/               ドメインロジック(計算・判定など)
├── Domain/EventSourcing/       CQRS+ESの共通実装(Command/CommandHandler/Projectorのcontract、
│                               EventStore、CommandBus)。ここは技術的な土台でありドメインを持たない
├── Http/Controllers/Api/       Projection TableまたはEloquentモデルを読み取り、Commandを発行する
├── Http/Resources/             API レスポンス整形
├── Models/                     Eloquentモデル(正データ用・Projection用の両方をここに置く)
├── Listeners/                  ProjectStoredEvent(EventStore追記のたびにProjectorへ同期反映)ほか
├── Jobs/                       DBキュー経由のジョブ(Teams通知など)
├── Console/Commands/           cron駆動のバッチ(月次警告・Projection再生成など)
└── Support/                    横断的なユーティリティ(LocalDateTimeなど)

tests/
├── Feature/<DomainName>/       HTTP経由のE2Eに近いテスト。app/Domain/ のグルーピングに合わせる
└── Unit/                       単体テスト

routes/api.php   APIルート定義(単一ファイル)
database/        migrations(タイムスタンプ順、素朴なLaravel構成) / factories / seeders
```

## CQRS + Event Sourcing の実装ルール

- **`stored_events.event_type` はPHPクラス名ではなく短い文字列**(`shift_pattern.created` の
  ような形式)。ProjectorやListenerはこの文字列でマッチングし、PHPクラスを復元しない。
  そのためDomainイベントのクラス名・namespaceを後から変更しても、既存の`stored_events`行の
  再生(`projections:rebuild`)には影響しない。
- Projectorは`config/domain.projectors`に登録したものだけが`ProjectStoredEvent`リスナー経由で
  同期的に呼ばれる(常駐workerを前提にしないため、リクエスト内で同期実行する。
  `docs/02-tech-stack.md`参照)。**現時点でProjectorが実装されているのは`Attendance`ドメインの
  `AttendanceDailyCalculationProjector`のみ**で、他のドメイン(PaidLeave/SpecialLeave/Workflow/
  BackOffice等)はHandlerが正データのEloquentモデルを直接更新している。これらのドメインの
  正データテーブルは今のところ`projections:rebuild`で再生成できない状態にある。新しいドメインで
  「画面表示用に再生成可能な派生データ」を作る場合は`.claude/skills/add-projection`に従い
  Projectorとして実装すること。
- CommandHandlerは検証の上で必ず1つ以上のイベントを`stored_events`に追記する。イベントを
  書かない状態変更は作らない。

## テスト

- `tests/Feature/<DomainName>/`は`app/Domain/<DomainName>/`のグルーピングに合わせる。
  ドメイン横断のテスト(監査ログ・Swaggerドキュメント生成など)は`tests/Feature/`直下に置く。
- `php artisan test` で Feature/Unit 両方を実行する。

## 開発でよく使うパターン (スキル)

`.claude/skills/` 配下のスキルを、該当する作業の際は必ず参照すること。

- `add-domain-event` — 新しいドメインイベント(Command/Event/Projector反映)を追加する
- `add-projection` — 新しいProjection Table + Projector + 再生成コマンドを追加する
- `add-workflow-request-type` — 新しい汎用申請種別(申請種別マスタ)を追加する
- `add-notification` — 新しいメール通知種別をDBキュー経由で追加する
- `attendance-calc-review` — 勤怠集計ロジック変更時のセルフレビューチェックリスト

## 常駐プロセスを前提にしない

XSERVER上ではDB queue + cron前提の`schedule:run`のみで運用する。supervisor常駐worker
などを前提にしない(`docs/02-tech-stack.md`)。
