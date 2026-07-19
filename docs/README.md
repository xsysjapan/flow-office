# flow-office 設計ドキュメント

汎用勤怠・申請・バックオフィス処理システムの設計ドキュメント一式。

SES専用ではなく、一般的な中小企業・受託会社・シフト勤務会社でも利用できる社内業務基盤を、
Laravel + MySQL + XSERVER 上に構築する。コミュニケーション/掲示板機能は Microsoft Teams に
委譲し、本アプリは勤怠・申請・承認・バックオフィス処理・添付ファイル・CSV出力・監査ログ・
Teams通知に集中する。

## 現在のフェーズ

このリポジトリはまだLaravelプロジェクトが存在しない設計フェーズ(Phase 0)。
コードを書き始める前に、このドキュメント群と `.claude/skills/` のスキルを土台として使う。

## 目次

| # | ファイル | 内容 |
|---|---|---|
| 1 | [01-overview.md](./01-overview.md) | システム概要 |
| 2 | [02-tech-stack.md](./02-tech-stack.md) | 技術前提 |
| 3 | [03-architecture.md](./03-architecture.md) | アーキテクチャ方針 (CQRS + Event Sourcing) |
| 4 | [04-domains.md](./04-domains.md) | 主要ドメイン一覧 |
| 5 | [05-user-roles.md](./05-user-roles.md) | ユーザー種別 |
| 6 | [06-usecases-auth.md](./06-usecases-auth.md) | 認証・ユーザー連携ユースケース (UC-000~) |
| 7 | [07-usecases-attendance.md](./07-usecases-attendance.md) | 勤怠管理ユースケース (UC-A001~) |
| 8 | [08-usecases-calendar-shift.md](./08-usecases-calendar-shift.md) | カレンダー・勤務形態・シフト管理ユースケース (UC-C001~) |
| 9 | [09-usecases-paid-leave.md](./09-usecases-paid-leave.md) | 有給管理ユースケース (UC-P001~) |
| 10 | [10-usecases-workflow.md](./10-usecases-workflow.md) | 汎用申請ユースケース (UC-W001~) |
| 11 | [11-usecases-backoffice.md](./11-usecases-backoffice.md) | バックオフィス処理ユースケース (UC-B001~) |
| 12 | [12-usecases-attachment.md](./12-usecases-attachment.md) | 添付ファイル管理ユースケース (UC-F001~) |
| 13 | [13-usecases-notification.md](./13-usecases-notification.md) | 通知ユースケース (UC-N001~) |
| 14 | [14-usecases-export.md](./14-usecases-export.md) | CSV出力ユースケース (UC-E001~) |
| 15 | [15-usecases-admin.md](./15-usecases-admin.md) | 管理機能ユースケース (UC-M001~) |
| 16 | [16-database-schema.md](./16-database-schema.md) | 主要テーブル案 |
| 17 | [17-events.md](./17-events.md) | 主要イベント一覧 |
| 18 | [18-ui-guidelines.md](./18-ui-guidelines.md) | UI方針 |
| 19 | [19-implementation-phases.md](./19-implementation-phases.md) | 実装順序 (Phase 1~6) |
| 20 | [20-implementation-notes.md](./20-implementation-notes.md) | 実装時の注意 |
| 21 | [21-mvp-scope.md](./21-mvp-scope.md) | 最小MVP範囲 |
| 22 | [22-glossary.md](./22-glossary.md) | 用語集 |
| 23 | [23-usecases-devices.md](./23-usecases-devices.md) | 端末管理ユースケース (UC-D001~) |
| 24 | [24-usecases-authentication-keys.md](./24-usecases-authentication-keys.md) | 認証キー管理ユースケース (UC-K001~) |
| 25 | [25-usecases-integrations-mcp.md](./25-usecases-integrations-mcp.md) | API・MCP連携ユースケース (UC-I001~) |
| 26 | [26-usecases-monthly-import.md](./26-usecases-monthly-import.md) | 作業報告書からの月次勤怠作成ユースケース (UC-R001~) |
| 27 | [27-release-runbook.md](./27-release-runbook.md) | 本番(XSERVER)リリース手順 |

設計ドキュメントとは別に、ローカル環境での動作確認のためのシナリオテスト計画を
[testing/scenario-tests.md](./testing/scenario-tests.md) にまとめている。

## 原則の要約

- **EventStoreを正とする**: `stored_events` が真実の記録。Projection(画面表示用テーブル)は
  いつでも再生成可能な派生データとして扱う。
- **勤怠の正は日次実績・勤務予定・有給付与**: `employee_shift_assignments` /
  `attendance_days` / `attendance_breaks` / `paid_leave_grants` が正。月次勤怠(`attendance_months`)
  はこれらの集計結果であり、直接入力しない。
- **週次勤怠は独立データではない**: 日次勤怠の編集ビュー。保存時は日次単位の編集イベントに分解する。
- **承認者は都度指定**: 固定承認ルートだけでなく、申請時に任意の社員を承認者に指定できる。
- **Teamsは通知専用**: チャット・掲示板・お知らせ機能は作らない。
- **法務判断が必要な値はマスタ化する**: 有給付与ルール、残業計算ルールなどはハードコードせず、
  マスタテーブルで管理し、最終設定は社労士確認を前提とする。
- **操作経路と業務ロジックを分離する**: Web・共有Android打刻リーダー・個人端末・外部端末・
  API・MCPのどの入口から操作されても、共通のCommand→CommandHandlerに集約する。
- **打刻と勤怠編集を区別する**: 打刻は追記のみの事実記録、勤怠編集は日次勤怠の作成・修正。
- **AIは勤怠ルールを決定しない**: Claude等のAIは下書き生成・対話までを担当し、労働時間計算・
  休日判定・締め判定・承認ルールは必ず勤怠管理API側で行う。
