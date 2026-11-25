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
○別の働き方を指定」の選択も行う。社員の作成経路は、MS365同期・SSO初回ログインに加えて、
社員一覧からの手動作成(下記)がある。既存社員の設定を編集するこの画面が「社員登録時の
体験」に相当する。今月について、`user_work_style_monthly_assignments`に行が無ければ
「会社のデフォルトを使用」、行があれば「別の働き方を指定」として表示し、切り替えは
`AssignUserWorkStyleForMonth`/`RemoveUserWorkStyleMonthlyAssignment`で行う
(docs/16-database-schema.md参照)。

社員の手動作成(`POST /users`、`user.created_manually`イベント)は、外部連携(MS365同期・SSO)を
使わない利用者(協力会社社員など)のための正式な作成経路として残す。
- 権限: `user.create`。全社スコープの権限を持つ利用者は任意の所属で作成できる。グループスコープの権限のみの
  利用者は、権限の対象グループを`group_id`に指定した場合に限り作成できる(指定が無い、または対象外は403)
- 入力項目: `name`・`email`(必須。重複不可)、`employee_number`(重複不可)・`department`・`job_title`・
  `employment_status`(既定`active`)・`account_status`(既定`active`)・`group_id`(任意)。外部連携が正の項目は
  ローカル編集できる項目に限る(`FieldAuthorityService`)
- 所属: 作成と同時に標準グループ`ALL_USERS`(有効な場合)へメンバーとして追加する。`group_id`を指定した場合は
  そのグループにも追加する
- タイムゾーン: 作成時点の`system_settings.default_timezone`を記録時の既定値として`user.created_manually`に
  保存する(以降、システム設定を変えても既存ユーザーは変わらない)

ユーザー詳細(基本情報・入社日・退社日・利用開始日・有給/特別休暇の自動付与)とロール詳細(基本情報・Permission・
Feature構成)は、画面末尾の「保存」1つでまとめて保存する(`PUT /users/{id}/details`・
`PUT /admin/access-control/roles/{role}/definition`)。
- 本文には変更した項目だけを入れ、項目ごとに既存のコマンドを1つの`DB::transaction`でdispatchする(イベントは
  項目ごとの既存イベント。「まとめて保存」のイベントは作らない)。どれかが拒否されたら全て戻し、項目のキー付きの
  422(`errors.{項目キー}`)を返す。画面はその項目の下にエラーを出す
- 入社日と退社日を両方変える場合は、途中の状態が成り立つ順にdispatchする。最後の状態で入社日 ≤ 退社日でなければ422
- 外部人事が管理する項目は画面で入力を無効にし、APIでも422にする
- 働き方の月ごとの割当は行の追加・削除の操作のため、まとめて保存に含めず、別のセクションで操作する
- 項目ごとの既存APIは、API・MCPの互換のために残す

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

運用コマンド`read-models:rebuild {--dry-run}`(運用コマンド画面の「データの作り直し(ReadModel)」。権限は運用コマンドの`admin_command.execute`)が、
全ReadModelのテーブルを空にして全Projectorを`stored_events`の先頭から再生する(変更セット`20261025-read-model-rebuild-admin-command`。
専用画面・専用API・専用の権限・監査イベントは持たない)。

1. 運用コマンド画面から、「試し実行」(`--dry-run`のチェックボックス。最後にロールバックする)の有無を選んで依頼する
   (`POST /admin/commands/read-models:rebuild/runs`。`admin_command_runs`に`queued`の記録ができる)。
2. 実行役`admin-commands:run-pending`(スケジューラーが毎分起動)が`queued`の記録を古い順に実行する(開始まで最大約1分)。
   有給付与予定の判定が`queued`/`running`の間は、作り直しの記録を取り出さず`queued`のまま残す(他の運用コマンドの記録は実行する)。
   コマンドは心拍を書き、60秒待ってから作り直す。SSHから直接`php artisan read-models:rebuild`を実行した場合は、コマンドが自分で記録
   (依頼者なし)を作る(判定の実行中は拒否して終了コード1)。
3. 実行中(`running`かつ心拍が10分以内)は、全APIが503(`{"code":"maintenance",...}`)になる。除外は`GET /maintenance-status`・`GET /auth/me`・
   `GET /access/me`・`GET /onboarding/status`・`GET /admin/commands`・`GET /admin/command-runs`。作り直し以外のスケジュールと実行役も新しく
   起動しない(飛ばされた日次バッチは追い付かないので、日次バッチの時刻(1:00〜2:20)を避けて実行する)。
4. 結果は実行履歴の「出力」に出る。利用者・権限のテーブル(`users`・`memberships`・`groups`・`roles`・`role_assignments`・`permission_role`・
   `role_features`・`group_feature_assignments`)に変更があれば先頭に警告の行、続いて変更のあったテーブルを先に並べた表(テーブル・前・後・変更)。
   同じ内容(`{dry_run, tables, changed_tables}`)を`admin_command_runs.summary`に保存する。時間の上限(180分)を超えた場合は、コミットせずロールバックして失敗にする。

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
