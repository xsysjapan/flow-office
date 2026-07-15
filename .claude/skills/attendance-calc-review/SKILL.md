---
name: attendance-calc-review
description: Use before merging any change to attendance time-calculation logic in flow-office (daily/monthly aggregation, overtime, late-night, holiday work, paid-leave consumption). Provides a self-review checklist against docs/07-usecases-attendance.md, docs/08-usecases-calendar-shift.md, and docs/09-usecases-paid-leave.md so law-sensitive values stay master-data-driven, not hardcoded.
---

# 勤怠集計ロジックのセルフレビュー

勤怠の労働時間・残業・深夜・休日労働・有給消化の計算ロジックは、法令に関わるため
安易に変更しない。変更・追加のたびに以下を確認する。

## 正データの確認

- [ ] 計算の入力は `employee_shift_assignments` (勤務予定) / `attendance_days`・
      `attendance_breaks` (勤務実績) / `paid_leave_grants` (有給付与) から取得しているか。
      月次勤怠 (`attendance_months`) に直接入力させていないか
      (`docs/20-implementation-notes.md`)。
- [ ] 出力 (`attendance_daily_calculations` など) はいつでも正データから再計算できる
      Projectionとして扱われているか (直接手で書き換える経路がないか)。

## 法令・マスタ化の確認

- [ ] 所定労働時間・週所定労働時間・残業計算ルールは `work_styles` /
      `work_calendars` / `work_calendar_days` からマスタ参照しているか
      (ハードコードした「8時間」「40時間」等の定数を埋め込んでいないか、
      `docs/08-usecases-calendar-shift.md` UC-C001参照)。
- [ ] 法定休日と所定休日を区別して扱っているか。
- [ ] 3交代制など日跨ぎ勤務がある場合、`planned_start_at`/`planned_end_at` や
      `actual_start_at`/`actual_end_at` を日付またぎ対応のdatetimeとして扱い、
      深夜0時境界でのバグ(二重カウント/取りこぼし)がないか (`docs/08-usecases-calendar-shift.md`
      UC-C004)。
- [ ] 有給消化は有効期限が近い付与分から優先的に消し込んでいるか
      (`docs/09-usecases-paid-leave.md` UC-P004)。

## 締め・編集制約の確認

- [ ] 締め後(`attendance_days.locked_at` 設定後)の日次勤怠を通常編集で
      変更できないようになっているか。修正が必要な場合は修正申請ワークフロー
      (`docs/10-usecases-workflow.md`) 経由になっているか。
- [ ] 月次提出時点のスナップショット (`attendance_months.snapshot_json`) を
      壊す変更になっていないか (提出後に再計算結果が変わっても、提出時スナップショットは
      保持されるべき)。

## 変更後に確認すること

- [ ] 変更が既存の集計値(労働時間/所定労働/法定内残業/法定外残業/深夜/休日労働)の
      どれに影響するか明示できるか
- [ ] 法的な解釈(何が法定休日か、変形労働時間制の扱いなど)に関わる変更は、
      `docs/20-implementation-notes.md` の「最終設定は社労士確認を前提にする」旨を
      ユーザーに伝えたか
