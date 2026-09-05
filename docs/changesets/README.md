# 変更セット

規模のある変更(新しいAPI/画面/イベントの追加など)は、実装前にこのディレクトリ配下へ
`YYYYMMDD-変更名/spec.md` として仕様検討ドキュメント(変更セット)を作成し、ユーザーの
レビューを経てから実装に入る。作成手順・テンプレートは `.claude/skills/changeset/SKILL.md`
を参照。

モック画像等のアセットは各変更セット配下の `assets/` に格納する。

## 一覧

ステータスは `検討中` / `レビュー中` / `実装中` / `完了` のいずれか。フォルダ作成時に
行を追加し、ステータスが変わるたび(`spec.md`側の更新と同時に)この表も更新する。

| フォルダ | タイトル | ステータス |
|---|---|---|
| [20260904-paid-leave-auto-grant-per-user-toggle](./20260904-paid-leave-auto-grant-per-user-toggle/spec.md) | 有給・特別休暇の自動付与のユーザーごと有効/無効設定 | 実装中 |
| [20260831-asset-management-refinement](./20260831-asset-management-refinement/spec.md) | 備品管理ブラッシュアップ(管理番号自動採番 + ナビ再編) | 完了 |
| [20260830-equipment-management](./20260830-equipment-management/spec.md) | 備品管理機能の追加(貸出・設置・修理・紛失・廃棄・QR操作) | 完了 |
| [20260829-backoffice-task-detail-cleanup](./20260829-backoffice-task-detail-cleanup/spec.md) | 月次勤怠表示コンポーネントの統一(申請詳細・バックオフィスタスク詳細・勤怠参照画面) | 完了 |
| [20260829-attendance-confirmation-revert-ui](./20260829-attendance-confirmation-revert-ui/spec.md) | 月次勤怠の確定取消(UC-A018)フロントエンドUIの追加 | 完了 |
