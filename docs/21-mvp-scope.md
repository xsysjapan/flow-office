# 21. 最小MVP

最初に完成させる範囲は以下とする。

- Microsoft SSO
- ユーザー管理
- グループ管理
- FeatureとRole/Permissionによるアクセス制御の土台
- 汎用申請
- 任意承認者選択
- 承認・差戻し
- バックオフィスタスク
- 添付ファイル
- Teams通知
- 勤務形態
- 会社カレンダー
- 日次勤怠
- 月次勤怠
- 有給残数管理の土台
- 監査ログ

将来日付の所属変更、CSV差分確認、外部HRの定期同期は基盤設計に含めるが、初期MVPでは
現在所属の管理と手動設定を優先してよい。詳細な段階分けは[docs/31](./31-user-group-access-foundation.md)
を参照する。

3交代制、年次有給自動付与、複雑な残業計算は初期設計に含めるが、実装は後続フェーズでよい。

## MVPとPhaseの対応

MVPは概ね [Phase 1〜4](./19-implementation-phases.md) の主要部分 + Phase 5 の
「有給残数管理の土台」のみを含む。Phase 5 の自動付与・期限警告・年5日警告、および
Phase 6 の3交代制はMVP後の後続フェーズとする。

(追記) Phase 5 の残りの範囲(有給申請・承認・消化、自動付与、消滅警告、年5日取得義務警告)
はMVP後に実装済み(docs/09-usecases-paid-leave.md UC-P002〜UC-P006)。Phase 6(3交代制)も
MVP後に実装済み(docs/08-usecases-calendar-shift.md UC-C004)。Phase 7(フレックスタイム制)
もMVP後に実装済み(docs/07-usecases-attendance.md「フレックスタイム制」)。Phase 8
(交代制ローテーション自動生成)もMVP後に実装済み(docs/08-usecases-calendar-shift.md UC-C008)。
