# flow-office

汎用勤怠・申請・バックオフィス処理システム。Laravel + MySQL + XSERVER。

## 現在のフェーズ

このリポジトリはまだLaravelプロジェクトが存在しない**設計フェーズ**。実装を始める前に
必ず `docs/` を読むこと。特に以下は全機能に共通する制約なので、実装前に必ず目を通す。

- `docs/03-architecture.md` — CQRS + Event Sourcing 風アーキテクチャ
- `docs/20-implementation-notes.md` — 実装時の注意点(共通チェックリスト)
- `docs/19-implementation-phases.md` — 実装順序 (Phase 1〜6)
- `docs/21-mvp-scope.md` — 最小MVP範囲

設計ドキュメント全体の目次は `docs/README.md` にある。

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

## ドキュメント構成

`docs/README.md` の目次を参照。ユースケースは `docs/06`〜`docs/15` に、テーブル定義は
`docs/16-database-schema.md`、イベント一覧は `docs/17-events.md` にある。

## 開発でよく使うパターン (スキル)

`.claude/skills/` 配下に、この設計に沿った実装を素早く・一貫して行うためのスキルがある。
該当する作業をする際は必ず参照すること。

- `add-domain-event` — 新しいドメインイベント(Command/Event/Projector反映)を追加する
- `add-projection` — 新しいProjection Table + Projector + 再生成コマンドを追加する
- `add-workflow-request-type` — 新しい汎用申請種別(申請種別マスタ)を追加する
- `add-teams-notification` — 新しいTeams通知種別をDBキュー経由で追加する
- `attendance-calc-review` — 勤怠集計ロジック変更時のセルフレビューチェックリスト

## Laravelプロジェクトを開始する時

Phase 1着手時は `docs/02-tech-stack.md` の技術前提(DB queue, cron前提のスケジューラ、
Entra ID SSO)に従うこと。常駐プロセス(supervisor常駐workerなど)をXSERVER上で前提に
しない。
