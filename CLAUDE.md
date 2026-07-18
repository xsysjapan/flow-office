# flow-office

汎用勤怠・申請・バックオフィス処理システム。バックエンド(Laravel + MySQL + XSERVER)と
フロントエンド(Vite + React + TypeScript)を分離したモノレポ構成。

## リポジトリ構成

```
backend/     Laravel API (Sanctumトークン認証)。実装の詳細は backend/CLAUDE.md を参照
frontend/    Vite + React + TypeScript のSPA。Storybook導入済み
mcp-server/  ClaudeなどのAIアプリへ勤怠操作ツールを提供するMCPサーバー(TypeScript)。
             backend/のAPIを呼び出すクライアントであり、勤怠計算ロジックは持たない
             (docs/25-usecases-integrations-mcp.md)。詳細は mcp-server/README.md を参照
docs/        設計ドキュメント(全体の目次は docs/README.md)
.claude/     開発でよく使うパターンをまとめたスキル
```

バックエンドとフロントエンドは別々にデプロイされることを前提にする。認証はSanctumの
Bearerトークン方式(Cookieベースではない)。

## 絶対に外してはいけない設計原則

1. **EventStoreを正とする**: 状態変更は必ず Command → CommandHandler → `stored_events` の
   流れで行う。イベントを書かない状態変更を作らない。
2. **Projectionは再生成可能な派生データ**: 画面表示用テーブルはイベントから再生成できる
   前提で設計する。Projectionを直接手で書き換えない。
3. **勤怠の正は日次実績・勤務予定・有給付与**: `employee_shift_assignments` /
   `attendance_days` / `attendance_breaks` / `paid_leave_grants` が正。月次勤怠
   (`attendance_months`)は集計結果であり、直接入力させない。
4. **週次勤怠は日次勤怠の編集ビュー**: 独立データとして持たない。保存時は日次単位の
   編集イベントに分解する。
5. **承認者は都度指定**: 固定承認ルートに加え、申請時に任意の社員を承認者に指定できる
   ようにする。
6. **バックオフィス処理は承認とは別ステータス系列**で管理する。
7. **Teamsは通知専用**。チャット・掲示板・お知らせ機能は作らない。
8. **法務判断が必要な値はマスタ化する**(有給付与ルール、残業計算ルールなど)。
   ハードコードしない。最終設定は社労士確認が前提。
9. **操作経路と業務ロジックを分離する**: Web・モバイル・共有Android打刻リーダー・個人端末・
   外部端末・API・MCPのどこから操作されても、最終的には共通のCommand→CommandHandlerへ
   集約する。入口ごとに勤怠計算ロジックを複製しない(docs/03-architecture.md 3.5)。
10. **打刻と勤怠編集を区別する**: 打刻(`attendance_punches`への追記のみ、削除・上書きしない)
    と、勤怠編集(`attendance_days`の作成・修正)は別の操作として扱う(docs/03-architecture.md 3.6)。
11. **AIは勤怠ルールを決定しない**: Claude等のAIアプリ・MCPサーバーは読解・下書き生成・
    対話に限定し、労働時間計算・休日判定・休暇残高・締め判定・承認ルール等は必ず勤怠管理API
    側で行う。AI推定値はユーザー確認前に正データとして確定させない(docs/03-architecture.md 3.7)。
12. **端末・認証キー・アプリ連携は別概念として扱う**: 打刻専用の端末エンティティを作らず、
    端末(`devices`)・認証キー(`authentication_keys`)・API/MCP連携(`application_integrations`)
    を独立したドメインとして設計する。生体情報(指紋画像・顔画像・特徴量)は保存せず、外部
    認証端末が発行した利用者IDと認証結果のみを保存する(docs/23〜docs/25参照)。

これらはバックエンドAPIの設計原則。詳細は `docs/03-architecture.md` と
`docs/20-implementation-notes.md` を参照。

## ドキュメント構成

`docs/README.md` の目次を参照。ユースケースは `docs/06`〜`docs/15` に、テーブル定義は
`docs/16-database-schema.md`、イベント一覧は `docs/17-events.md` にある。

## バックエンド (backend/)

Laravel API。実装を始める前に必ず `docs/02-tech-stack.md` と `docs/03-architecture.md`
を読むこと。

```
cd backend
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite   # ローカル開発はsqlite、本番はMySQL
php artisan migrate --seed
php artisan serve                # http://localhost:8000
php artisan test
```

### 開発でよく使うパターン (スキル)

`.claude/skills/` 配下に、この設計に沿った実装を素早く・一貫して行うためのスキルがある。
該当する作業をする際は必ず参照すること。

- `add-domain-event` — 新しいドメインイベント(Command/Event/Projector反映)を追加する
- `add-projection` — 新しいProjection Table + Projector + 再生成コマンドを追加する
- `add-workflow-request-type` — 新しい汎用申請種別(申請種別マスタ)を追加する
- `add-teams-notification` — 新しいTeams通知種別をDBキュー経由で追加する
- `attendance-calc-review` — 勤怠集計ロジック変更時のセルフレビューチェックリスト

### 常駐プロセスを前提にしない

XSERVER上ではDB queue + cron前提の`schedule:run`のみで運用する。supervisor常駐worker
などを前提にしない(`docs/02-tech-stack.md`)。

## フロントエンド (frontend/)

Vite + React + TypeScript。UIコンポーネントはStorybookで確認できる。

```
cd frontend
npm install
cp .env.example .env   # VITE_API_BASE_URL をbackendのURLに合わせる
npm run dev             # http://localhost:5173
npm run storybook       # http://localhost:6006
npm run build
npm run test             # Vitest単体テスト(jsdom)
npm run test:storybook   # Storybookのstoryをブラウザで描画するテスト
npm run build-storybook
```

`VITE_API_BASE_URL`(既定値 `http://localhost:8000/api`)経由でbackendのAPIを呼び出す。
認証はSanctumのBearerトークンで、`localStorage`に保存する(`frontend/src/api/client.ts`)。

コンポーネントを追加する際は必ず `.stories.tsx` と `.test.tsx` を一緒に作ること。

### 開発でよく使うパターン (スキル)

- `add-frontend-component` — 新しいUIコンポーネント(story/test付き)を追加する
- `add-api-hook` — 新しいbackend APIエンドポイントに対応する型・APIクライアント関数・
  React Queryフックを追加する
- `add-page` — 新しい画面(ルーティング込み)を追加する
