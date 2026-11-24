# user-projection-settings-at-replay(利用者の表示用データが、作り直した時点の設定値で作られる不具合の修正)

ステータス: レビュー中

## 変更要望(原文)
> (変更候補の判断 `docs/changesets/candidates-20261124.md` の #14)利用者の表示用データを作り直すとき、作り直した時点の設定値が使われる問題 → 対応する(推奨)。命名統一のリリースの前に直す。

## 原因(調査結果)
Projector が、イベントの時点ではなく再生した時点のシステム設定を読んでいる。
- `users.timezone`(既定のタイムゾーン `SystemSetting::current()->default_timezone`)は次の5か所で読む。
  | 箇所 | イベント(別名) | 条件 |
  |---|---|---|
  | `UserProjector.php:32` | `user.created_manually` | 常に。`attributes` の値より設定が優先される(後置) |
  | `UserProjector.php:54` | `user.onboarded_as_admin` | 常に |
  | `UserProjector.php:77` | `user.created_from_sso_login` | 常に |
  | `UserProjector.php:104` | `user.synced_from_ms365` | 利用者の行が無いとき(新規作成) |
  | `UserManagementProjector.php:53` | `external_hr.import_applied` | 行の利用者がまだ無いとき(新規作成) |
- `external_identities.external_tenant_id`(`m365_tenant_id`)は `UserProjector.php:129`(`linkEntraIdentity`)で読む。
  - 対象のイベントは onboarded_as_admin・created_from_sso_login・synced_from_ms365・`user.sso_account_linked`。
  - イベントの `externalTenantId` が null のとき、設定に戻る(`??`)。
  - 以前の補正(`ExternalTenantIdRebuilder`、論点19 決定9)で旧イベントに値を補ったが、次の2種類は今も再生時の設定に戻る。
    - 補えなかったイベント(該当する行が無い、または行の tenant が null)。
    - 記録した時点で設定が null だった新しいイベント。
- `SystemSetting::current()` は行が無いと `firstOrCreate` で作る。そのため、Projector から呼ぶと、再生の時点の config の値で設定の行がイベント無しに作られる。
- 影響:
  - 設定を変えた後に作り直すと、既存の利用者の `timezone`・`external_tenant_id` が変わる。原則2(Projection はイベントから同じ結果に作り直せる)に反する。
  - 命名統一の `--execute` の検証(作り直しがライブと一致)でも差分になる。
- 他の Projector に、再生の時点の設定・時刻を読む箇所は無かった(調査済み)。Reactor は再生の対象外。

## 設計前提の検証
- 正データと派生データ: `users`・`external_identities` は Projection。正はイベント。イベントに値が無いため、Projection が外の状態に依存している。
- 定義と実態: 「作成された利用者のタイムゾーン」は、作成した時点の既定値という事実。今はイベントに記録されていない。
- 原因の分類: イベントの設計の漏れ(作成時に決まる値をイベントに持たせていない)。
- 横展開: 上の6か所。`add-projection` スキルには「再生の時点の設定値を使わない」が書いてある(変更セット`20261123-rebuild-drift-fixes`)。

## 仕様検討

### 論点1: 新しいイベントに値を持たせる
- 決定: 作成系のイベントを記録するときに、解決したタイムゾーンをイベントに入れる。Projector はイベントの値だけを使い、設定を読まない。
  - `user.created_manually`: `attributes.timezone` に入れる(Handler `CreateUserHandler`)。Projector は attributes の値を使う(設定で上書きしない)。
  - `user.onboarded_as_admin`・`user.created_from_sso_login`: イベントに `timezone` を足す(必須)。
  - `user.synced_from_ms365`: `timezone` を足す。新規作成のときに使う。既存の行があれば使わない(今と同じ)。
  - `external_hr.import_applied`: 新規作成になる行に `timezone` を入れる。
- テナントID: Projector の `??` による設定へのフォールバックをやめる。
  - イベントの `externalTenantId` をそのまま使う(null なら null)。
  - 記録の時点で設定が null なら、事実として null を記録する(今と同じ)。
- 理由: 作成した時点の値は、作成した時点にしか分からない。イベントに持たせれば、作り直しの結果がいつでも同じになる。
- 未確定・要確認事項: なし

### 論点2: 過去のイベントの補正(data-correction)
- 決定: 過去のイベントに値を書き足す。`stored_events` の payload を書き換える(補正イベントは追記しない)。
- 補う値:
  - タイムゾーン(作成系の5種類のイベント。HR取込は新規作成になった行):
    1. その利用者に、作成の後でタイムゾーンを変えるイベント(`user.profile_updated` の timezone など)が無ければ、ライブの `users.timezone`。作成の時点で Projector が書いた値なので、作成の時点の事実と一致する。
    2. 後でタイムゾーンを変えたイベントがあれば、作成の時点の既定値を `system_settings.updated` の履歴から復元する。
       - 復元の手順: 作成の時刻より前の最後の更新の `after.default_timezone`。前に更新が無ければ、最初の更新の `before.default_timezone`。更新が1度も無ければ、ライブの `system_settings.default_timezone`。
       - 最終の結果(ライブの `users.timezone`)は後のイベントで決まるので、どの値でも作り直しの結果は同じ。事実に近い値として履歴を使う。
  - テナントID(`externalTenantId` のキーが無い4種類のイベント): ライブの `external_identities`(同じ entraUserId の MICROSOFT_ENTRA の行)の `external_tenant_id` を入れる。行が無い、または値が null なら、null を明示して入れる(キーを作る)。
- 対応が付かない場合:
  - 利用者の行が無い(削除済み等)場合は、タイムゾーンを履歴から復元する。
  - 復元できなければ、一覧を出して `--execute` を止める。
- コマンド: `events:fix-user-settings-at-replay`。
  - `data-correction` の安全手順に従う。
    - 試し実行が既定。
    - `--execute` で次を行う: バックアップ表、補正ログ(`stored_event_corrections`)、`ReadModelRebuilder` での作り直し、検証(合格しなければトランザクションを戻す)。
    - 冪等: キーがあるイベントは変えない。
  - 合格条件:
    1. 対象のイベントに、補うキーが無い行が0件。
    2. 作り直した後の `users`・`external_identities` が、書き換え前のライブと一致する(全表の `changed_tables == 0`。中間版では、作り直しのずれの修正と合わせて0になる)。
  - 運用コマンド(管理画面)・メンテナンスの対象に登録する。改名・一括操作の補正のコマンドと同じ扱い。
- 作り直しの拒否:
  - 未補正のイベント(キーが無い行)がある間は、作り直しを拒否する。対象は `read-models:rebuild`・`projections:rebuild`・管理画面。
  - 既存の検出器(`LegacyBooleanPropertyDetector` など)と同じ方式。
  - 理由: 補正の前に作り直すと、補う値の元(ライブの行)が変わる。
- 理由: 事実はライブの Projection に残っている(作成の時点の Projector が書いた値)。それをイベントに戻す。後で変えた利用者は、設定の履歴が事実に最も近い。
- 本番の実行には、試し実行の結果を示したうえでユーザーの明示的な許可を得て、記録に残す。
- 未確定・要確認事項: なし

### 論点3: リリース
- 決定: 変更セット`20261123-rebuild-drift-fixes`の中間版(改名前のコード `cd1d40ce` に修正を載せたもの)に、本変更セットも載せる。
- 中間版の手順(docs/27 §3.7)に次を加える。
  - 一括操作の補正の後に、`events:fix-user-settings-at-replay` の試し実行 → `--execute`。
  - そのあとで `read-models:rebuild --dry-run` の差分が0。
- 本変更セットのコードは、真偽値の改名に触れない(利用者のイベントのタイムゾーン・テナントIDだけを扱う)。
- 中間版で `php artisan test` が全件通ること(cherry-pick の衝突の解消は、`intermediate-conflict-resolution.diff` の要領)を受け入れ条件にする。
- 未確定・要確認事項: なし

### 論点4: 対象外
- `PaidLeaveFiscalYearSettingsProjector` の `last_changed_event_id`(`storedEventId`)。作り直しでは `stored_events` の id は変わらない(書き換えは同じ行の payload だけ)ため、差は出ない。
- 古い作り直しの道具(`EventStoreRebuildWriter`・`UserReconciler`)の「現在の既定値を仮定する」前提。過去の移行用で、今後は使わない。
- `system_settings.updated` の payload に、送らなかった項目が入らないこと(テナントIDの履歴が復元できない理由)。論点2ではテナントIDの履歴を使わないため、影響しない。

## 受け入れ条件
- 新しく記録する作成系の5種類のイベントにタイムゾーンが入り、Projector が設定を読まない(`UserProjector`・`UserManagementProjector` から `SystemSetting::current()` が無くなる)。
- テナントIDは、イベントの値だけを使う(設定へのフォールバックが無い)。
- 設定(既定のタイムゾーン・テナントID)を変えてから作り直しても、`users`・`external_identities` が変わらない(シナリオテスト)。
- 補正コマンドについて、次をテストする。
  - 試し実行・実行・冪等性。
  - 後でタイムゾーンを変えた利用者の、履歴からの復元。
  - 行が無い場合の復元・停止。
  - テナントIDの null の明示。
  - バックアップと補正ログ。
  - 未補正の間の作り直しの拒否。
- HEAD と中間版で `php artisan test` が全件通る。
- リハーサル用のDB(旧e2eで作ったDB)で、次の順に実行して、作り直しの差分が0になる。
  - 中間版で、一括操作の補正 → 本補正。
  - HEAD で、命名統一。

## 対象外
- 論点4の各項目。

## ドキュメントへの影響
- `docs/17-events.md`: 5種類のイベントのタイムゾーン、テナントIDの扱い(フォールバックしない)。
- `docs/27`: §3.7(中間版)に本補正の手順。
- `.claude/skills/data-correction`: 前例に1行(任意)。

## モック・アセット
なし

## 実装対象
1. Handler・集約・イベントにタイムゾーンを足す。Projector から設定の読み取りを除く(論点1)。
2. 補正コマンド・検出器・作り直しの拒否(論点2)。
3. テスト(シナリオ・補正・業務ルール)。
4. ドキュメント(別のコミット)。
5. 中間版の作業ツリーでの全件テストとリハーサル。

## 検証方法
- `php artisan test` の全件(HEAD と中間版)。
- リハーサル(受け入れ条件)。

## レビュー履歴

## 実装結果
未着手
