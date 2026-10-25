# 変更セット

コード変更を伴う変更(不具合修正・小規模修正を含む)は、実装前にこのディレクトリ配下へ
`YYYYMMDD-変更名/spec.md` として仕様検討ドキュメント(変更セット)を作成し、ユーザーの
レビューを経てから実装に入る。作成手順・テンプレートは `.claude/skills/changeset/SKILL.md`
を参照。

モック画像等のアセットは各変更セット配下の `assets/` に格納する。

## 一覧

ステータスは `検討中` / `レビュー中` / `実装中` / `完了` のいずれか。フォルダ作成時に
行を追加し、ステータスが変わるたび(`spec.md`側の更新と同時に)この表も更新する。

| フォルダ | タイトル | ステータス |
|---|---|---|
| [20261025-fix-e2e-grant-target-picker](./20261025-fix-e2e-grant-target-picker/spec.md) | e2e(scenario-16)で付与対象の社員を選べない不安定さの修正 | 完了 |
| [20261025-read-model-rebuild-admin-command](./20261025-read-model-rebuild-admin-command/spec.md) | ReadModelの作り直しを運用コマンドにし、開発用シーダーをイベント経由にする | 完了 |
| [20261025-admin-read-model-rebuild](./20261025-admin-read-model-rebuild/spec.md) | 管理画面からReadModelを作り直す(全テーブルを空にしてevent-sourcing:replay) | 完了 |
| [20261024-fix-revoked-grant-remaining](./20261024-fix-revoked-grant-remaining/spec.md) | 有給付与の取消の不具合の修正(取り消した付与に残日数が残る・消化済みの最新付与を取り消せない) | 完了 |
| [20261025-attendance-month-direct-revert](./20261025-attendance-month-direct-revert/spec.md) | 承認済みの月次勤怠を未提出に戻す経路の見直し(バックオフィスの直接取消・取消依頼の承認で即時取消) | 完了 |
| [20261024-attendance-confirmation-revert-entry](./20261024-attendance-confirmation-revert-entry/spec.md) | 月次勤怠画面から「勤怠確定取消依頼」を作成する導線の追加 | 完了 |
| [20261024-paid-leave-release-rehearsal](./20261024-paid-leave-release-rehearsal/spec.md) | PR#121のリリースのリハーサル手順書を作り、リハーサルで見つかった手順の不備を直す | 完了 |
| [20261023-fix-pr121-ci](./20261023-fix-pr121-ci/spec.md) | PR#121のCIの失敗(backendのテスト4件・MySQLのmigration)を直す | 完了 |
| [20261021-fix-scenario-08-paid-leave-batches](./20261021-fix-scenario-08-paid-leave-batches/spec.md) | e2eのscenario-08で失敗している有給バッチの2件を直す | 完了 |
| [20261021-paid-leave-schedule-recalc-on-assignment-change](./20261021-paid-leave-schedule-recalc-on-assignment-change/spec.md) | 勤務形態の割当の削除・月替わりで付与予定を再計算する | 完了 |
| [20261021-paid-leave-same-day-grants](./20261021-paid-leave-same-day-grants/spec.md) | 同じ日に複数の有給を付与できるようにする | 完了 |
| [20261021-paid-leave-aggregate-stream-id](./20261021-paid-leave-aggregate-stream-id/spec.md) | 付与予定と有給口座のAggregateIdを社員から分離する | 完了 |
| [20261021-paid-leave-schedule-reactor-tests](./20261021-paid-leave-schedule-reactor-tests/spec.md) | 付与予定の再計算Reactorのテストを復元し、再計算の不具合を直す | 完了 |
| [20261021-paid-leave-fiscal-mass-grant-and-auto-assessment](./20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md) | 有給の年度はじめ一斉付与を初期値にし、出勤率の自動判定を戻す | 完了 |
| [20261020-paid-leave-schedule-page-restore](./20261020-paid-leave-schedule-page-restore/spec.md) | 有給休暇の付与予定画面の復元(有給休暇画面のボタンから遷移) | 完了 |
| [20261016-excel-note-compensatory-leave](./20261016-excel-note-compensatory-leave/spec.md) | 勤怠管理表(Excel)の備考に休暇を正しく表示する(代休の追加・特別休暇の種別名) | 完了 |
| [20261015-excel-export-submitted-month](./20261015-excel-export-submitted-month/spec.md) | 承認待ち(提出済み)の月次勤怠でExcel勤怠管理表がタイトルだけになる不具合の修正(CSVも同じ条件で出力可能に) | 完了 |
| [20261015-implementer-delegation-prompt](./20261015-implementer-delegation-prompt/spec.md) | implementerへの委譲指示のチェックリスト化 | 完了 |
| [20261015-php84-cloud-setup](./20261015-php84-cloud-setup/spec.md) | クラウドセッション用 PHP 8.4 セットアップスクリプト・SessionStartフック | 完了 |
| [20261009-keep-leave-work-type-on-edit](./20261009-keep-leave-work-type-on-edit/spec.md) | 休暇まわり(申請・承認/休暇申請/残数・使用管理/勤怠)をイベント連携で独立させる | 実装中 |
| [20261009-compensatory-leave-badge](./20261009-compensatory-leave-badge/spec.md) | 代休を取得した勤怠日に代休バッジが表示されない不具合の修正 | 完了 |
| [20260919-paid-leave-schedule-rebuild](./20260919-paid-leave-schedule-rebuild/spec.md) | 過去の有給休暇付与予定を取消+再作成する運用コマンド(付与ルール指定・管理画面実行)の追加 | 完了 |
| [20260919-prod-migration-history-collision](./20260919-prod-migration-history-collision/spec.md) | 本番マイグレーション履歴衝突によるデプロイ失敗の是正 | 完了 |
| [20260916-paid-leave-policy-change-reapply](./20260916-paid-leave-policy-change-reapply/spec.md) | 法定付与ポリシー・付与ルール変更時に未確定Scheduleを再作成する | 完了 |
| [20260914-form-submit-dom-detach-bug](./20260914-form-submit-dom-detach-bug/spec.md) | フォーム送信ボタンがクリック中にDOMから外れる不具合の調査記録 | 検討中 |
| [20260914-port-to-pr112](./20260914-port-to-pr112/spec.md) | PR#112への差分移植(ナビ再編・特定月一斉付与・NeedsReview化・利用開始日ガード・法定付与日数テーブル編集UI) | 完了 |
| [20260913-login-redirect-return-url](./20260913-login-redirect-return-url/spec.md) | ログイン時のリダイレクト元URL復帰対応(Open Redirect対策込み) | 完了 |
| [20260906-paid-leave-schedule-assessment](./20260906-paid-leave-schedule-assessment/spec.md) | 年次有給休暇 付与Schedule/Assessmentドメイン新設(将来付与予定の確認画面) | 完了 |
| [20260906-paid-leave-domain-redesign](./20260906-paid-leave-domain-redesign/spec.md) | 年次有給休暇ドメイン再設計(PaidLeaveAccountAggregate中心の再構築) | 完了 |
| [20260904-paid-leave-auto-grant-per-user-toggle](./20260904-paid-leave-auto-grant-per-user-toggle/spec.md) | 有給・特別休暇の自動付与のユーザーごと有効/無効設定 | 完了 |
| [20260831-asset-management-refinement](./20260831-asset-management-refinement/spec.md) | 備品管理ブラッシュアップ(管理番号自動採番 + ナビ再編) | 完了 |
| [20260830-equipment-management](./20260830-equipment-management/spec.md) | 備品管理機能の追加(貸出・設置・修理・紛失・廃棄・QR操作) | 完了 |
| [20260829-backoffice-task-detail-cleanup](./20260829-backoffice-task-detail-cleanup/spec.md) | 月次勤怠表示コンポーネントの統一(申請詳細・バックオフィスタスク詳細・勤怠参照画面) | 完了 |
| [20260829-attendance-confirmation-revert-ui](./20260829-attendance-confirmation-revert-ui/spec.md) | 月次勤怠の確定取消(UC-A018)フロントエンドUIの追加 | 完了 |
