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
│   ├── Events/                1イベント1クラス。短い別名はconfig/event-sourcing.phpへ登録
│   ├── Handlers/               CommandHandler。検証 → イベント追記 → (必要なら)正データ更新
│   ├── Projectors/            Projection Table を再生成可能な形で更新する(あるドメインのみ)
│   └── Services/               ドメインロジック(計算・判定など)
├── Domain/EventSourcing/       CQRS+ESの共通実装(Command/CommandHandler、CommandBus、履歴補正)
├── Http/Controllers/Api/       Projection TableまたはEloquentモデルを読み取り、Commandを発行する
├── Http/Resources/             API レスポンス整形
├── Models/                     Eloquentモデル(正データ用・Projection用の両方をここに置く)
├── Listeners/                  Laravelイベントのリスナー
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

- **`stored_events.event_class` はPHPクラス名ではなく短い文字列**(`shift_pattern.created` の
  ような形式)。`config/event-sourcing.php`の`event_class_map`でPHPイベントクラスへ対応付ける。
  `enforce_event_class_map = true`のため、追加イベントの別名登録は必須である。
- ProjectorとReactorはSpatieの`auto_discover_projectors_and_reactors`で自動検出され、イベント
  永続化と同じ処理内で呼ばれる。再生にはSpatie標準の`event-sourcing:replay`を使用する。
- CommandHandlerは検証の上で必ず1つ以上のイベントを`stored_events`に追記する。イベントを
  書かない状態変更は作らない。

### spatie/laravel-event-sourcing への移行(完了)

独自`EventStore`、独自Projector contract、`ProjectStoredEvent`は廃止済み。通常のイベント保存と
監査ログ検索はSpatie標準の`stored_events`だけを使用する。`legacy_stored_events`は過去DBとの
スキーマ互換のため残すが、アプリケーションから新規書き込みしない。本番履歴の最終補正は
`docs/32-stored-event-history-normalization.md`を参照する。

## 効率的なコード参照

- 「〇〇ドメインを直す」場合は`app/Domain/<DomainName>/`配下の対象イベント1本分
  (Command/Event/Handler、該当すればProjector)だけを読めば足りる。他ドメインの
  `Domain/`は基本的に読まなくてよい。
- ルーティング・レスポンス形状の確認は`routes/api.php`と該当`Http/Controllers/Api/`
  1ファイルに絞る。
- 横断的な調査(「このイベントを発行している箇所を全部探す」等)はGrepで
  `event_type`文字列を検索する。範囲が広がりそうならExploreサブエージェントに委譲する。
- `add-domain-event`・`add-projection`等のスキルに沿って1ドメイン分完結する実装は、
  対象ドメイン名と参照ファイルを指定してgeneral-purposeサブエージェントに実装自体を
  委譲し、メインの会話コンテキストを消費させない(ルート`CLAUDE.md`参照)。

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
