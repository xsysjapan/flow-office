# 15. 管理機能ユースケース

## UC-M001: ユーザー・所属・権限を管理する

1. 人事担当者が「人事・組織」のユーザー、グループ、所属変更、人事データ連携画面を開く
2. 基本情報、現在所属、直近20件の所属変更履歴を確認・更新する。履歴には操作とグループ名を主情報として、
   日時を補助情報として、日本語の状態名を表示し、下書き・予約済みの変更は取り消せる
3. システム管理者が「システム」のアクセス管理画面を開く
4. 必要に応じてユーザーまたは所属グループへスコープ付きRoleを割り当てる
5. グループへFeatureを割り当てるか、ユーザー単位のFeature停止を設定する
6. 有効なFeature・Permissionの計算結果を確認する
7. 外部ID・項目管理元・認証キーは「システム」のID・管理元設定画面で管理する
8. 変更イベントと変更理由を監査ログへ記録する

ユーザーの直接Roleと所属グループ経由Roleは加算する。Featureはグループへ付与し、ユーザーへの
直接付与は行わない。PermissionがあってもFeatureが無効なら、その機能は利用できない。
ここでいう直接Roleは`role_assignments(subject_type=user)`であり、廃止済みの`role_user`や
`User.roles`ではない。固定ユーザーロールによるメニュー・APIの例外許可は設けない。
対象Group未指定を暗黙に全社スコープとして扱わない。詳細は
[docs/31](./31-user-group-access-foundation.md)を正本とする。

人事向けユーザー詳細画面(`UserRoleEditPage.tsx`)で、指示書13章の「働き方: ●会社のデフォルトを使用/
○別の働き方を指定」の選択も行う。本システムには社員を手動で新規作成するフローが無く
(MS365同期・SSO初回ログイン経由のみ)、既存社員の設定を編集するこの画面が「社員登録時の
体験」に相当する。今月について、`user_work_style_monthly_assignments`に行が無ければ
「会社のデフォルトを使用」、行があれば「別の働き方を指定」として表示し、切り替えは
`AssignUserWorkStyleForMonth`/`RemoveUserWorkStyleMonthlyAssignment`で行う
(docs/16-database-schema.md参照)。

人事向けユーザー詳細にはFeature・Role・Permission・付与元・個別停止・外部ID・認証キーを
表示しない。これらはシステム向けの独立画面に配置し、人事担当者には対応Permissionを付与せず、
直URLとAPIの双方で拒否する。

## UC-M002: 申請種別を管理する

1. 管理者が申請種別一覧を開く
2. 申請フォーム項目を設定する
3. バックオフィスタスク生成有無を設定する
4. 保存する

## UC-M003: 監査ログを確認する

1. 管理者が監査ログ画面を開く
2. ユーザー、期間、対象種別で検索する
3. 操作履歴を確認する
4. 必要に応じてCSV出力する

## UC-M004: ReadModelをイベントから作り直す

権限`read_model.rebuild`(global。システム管理者ロールに付与。新規環境は`AccessControlSeeder`、既存環境はマイグレーション
`2026_10_25_100000`が`ChangeRolePermissions`で`role.permissions_changed`を記録して付ける)を持つ管理者が、管理画面「データの作り直し」
(`/admin/read-model-rebuild`)から、全ReadModelのテーブルを空にして全Projectorを`stored_events`の先頭から再生する
(変更セット`20261025-admin-read-model-rebuild`)。

1. 「試し実行」(最後にロールバックする)または「作り直す」(コミットする)を依頼する(`POST /admin/read-model-rebuilds`、body `{"dry_run": bool}`。
   `queued`/`running`の記録があれば409)。記録は`admin_command_runs`(`command_name = 'read-models:rebuild'`)。
2. スケジューラーが毎分起動する`read-models:rebuild --pending`が取り出して実行する(開始まで最大約1分、取り出した後に60秒待つ)。
   有給付与予定の判定が`queued`/`running`の間は取り出さない。
3. 実行中(`running`かつ心拍が10分以内)は、全APIが503(`{"code":"maintenance",...}`)になる。除外は`GET /maintenance-status`・
   `GET /admin/read-model-rebuilds`・`GET /admin/read-model-rebuilds/{id}`・`GET /auth/me`・`GET /access/me`・`GET /onboarding/status`。
   作り直し以外のスケジュールも飛ばす(飛ばされた日次バッチは追い付かないので、1:00〜3:00台を避けて実行する)。
4. 結果(テーブルごとの件数の前後と内容の変化)を履歴で確認する(`GET /admin/read-model-rebuilds/{id}`)。
   依頼者のアカウント・権限が作り直し後に失われる場合、時間の上限(180分)を超えた場合は、コミットせずロールバックして失敗にする。
5. 依頼と終了は監査イベント`read_model_rebuild.requested`・`read_model_rebuild.finished`として`stored_events`に残る。

## 実装上のポイント

- 権限管理 (UC-M001) は [UC-002 MS365ユーザーを同期する](./06-usecases-auth.md#uc-002-ms365ユーザーを同期する)
  で上書きされない、アプリ独自のRole/Permissionとして管理する。氏名・社員番号・所属等は
  `FieldAuthority`に従い、外部HR管理の項目だけを同期対象とする。
- 監査ログ (UC-M003) は専用のProjectionを持たず、`stored_events` を直接検索する
  (`AuditLogController`)。ユーザーでの絞り込みは、`payload`のJSON列に対する
  `LIKE '%"key":<id>%'`形式の近似一致で行う簡易実装であり、値の完全一致を保証しない。
  検索精度・検索性能に関する追加対応や、`audit_log_entries`等の専用Projectionは対象外とする。
  有給口座・付与予定のイベントは社員IDとは別の集約ID(`PaidLeaveAccountAggregate::streamIdFor`・`PaidLeaveScheduleAggregate::streamIdFor`)で
  記録するため、`aggregate_id`での絞り込みに社員IDを入れても出なくなる(ユーザーでの絞り込みは`payload`の`userId`で拾える。
  変更セット`20261021-paid-leave-aggregate-stream-id`)。
