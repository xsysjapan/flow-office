# user-projection-settings-at-replay(利用者の表示用データが、作り直した時点の設定値で作られる不具合の修正)

ステータス: 実装中

## 変更要望(原文)
> (変更候補の判断 `docs/changesets/candidates-20261124.md` の #14)利用者の表示用データを作り直すとき、作り直した時点の設定値が使われる問題 → 対応する(推奨)。命名統一のリリースの前に直す。

## 原因(調査結果)
Projector が、イベントの時点ではなく、再生した時点のシステム設定を読んでいる。

`users.timezone` は、既定のタイムゾーン(`SystemSetting::current()->default_timezone`)を次の5か所で読む。

| 箇所 | イベント(別名) | 条件 |
|---|---|---|
| `UserProjector.php:32` | `user.created_manually` | 常に。`attributes` の値より設定が優先される(後置) |
| `UserProjector.php:54` | `user.onboarded_as_admin` | 常に |
| `UserProjector.php:77` | `user.created_from_sso_login` | 常に |
| `UserProjector.php:104` | `user.synced_from_ms365` | 利用者の行が無いとき(新規作成) |
| `UserManagementProjector.php:53` | `external_hr.import_applied` | 行の利用者がまだ無いとき(新規作成) |

`external_identities.external_tenant_id` は、テナントID(`m365_tenant_id`)を `UserProjector.php:129`(`linkEntraIdentity`)で読む。
- 対象のイベントは onboarded_as_admin・created_from_sso_login・synced_from_ms365・`user.sso_account_linked`。
- イベントの `externalTenantId` が null のとき、設定の値を使う(`??`)。
- 以前の補正(`ExternalTenantIdRebuilder`、論点19 決定9)で、旧イベントにキーを補った。それでも次の2つは、今も再生時の設定の値になる。
  - キーを補えなかったイベント。
  - 記録した時点で設定が null だったイベント。

ほかにも次の問題がある。
- `SystemSetting::current()` は行が無いと `firstOrCreate` で作る。そのため Projector から呼ぶと、再生した時点の config の値で、設定の行がイベント無しに作られる。

影響:
- 設定を変えた後に作り直すと、既存の利用者の `timezone`・`external_tenant_id` が変わる。原則2(Projection はイベントから同じ結果に作り直せる)に反する。
- 命名統一の `--execute` の検証(作り直しがライブと一致すること)でも、差分になる。

網羅性: Projector で設定を読むのは、この6か所だけ(調査とレビューで確認)。
- `users` の行は `user.migrated_from_legacy` でも作られるが、attributes の値をそのまま使う。
- `external_identities` の行は `ExternalIdentityLinked`・HR取込でも作られるが、設定は読まない。
- Reactor は再生の対象外。

## 設計前提の検証
- 正データと派生データ: `users`・`external_identities` は Projection で、正はイベント。イベントに値が無いため、Projection が外の状態に依存している。
- 定義と実態: 「作成された利用者のタイムゾーン」は、作成した時点の既定値という事実。今はイベントに記録されていない。
- 原因の分類: イベントの設計の漏れ。作成時に決まる値を、イベントに持たせていない。
- 横展開: 上の6か所。`add-projection` スキルに「再生の時点の設定値を使わない」がある(変更セット`20261123-rebuild-drift-fixes`)。

## 仕様検討

### 論点1: 新しいイベントに値を持たせる
- 決定: イベントを記録するときに、解決したタイムゾーンをイベントに入れる。
  - `user.created_manually`: Handler の `CreateUserHandler` で `attributes.timezone` に入れる。Projector は attributes の値を使い、設定で上書きしない。
  - `user.onboarded_as_admin`・`user.created_from_sso_login`・`user.synced_from_ms365`: イベントクラスに `?string $timezone = null` を足す。
    - 新しく記録するときは常に値を入れる。synced は、新規作成にならない場合も入れる。
    - Projector が使うのは、行を作るときだけ。
  - `external_hr.import_applied`: `rows[i].is_new` が true の行に、`rows[i].timezone` を入れる。`is_new` は記録した時点から payload にある。
  - 型を null 許容・既定値 null にする理由: 旧イベント(キーが無い)を読み込めるようにするため。
    - 読み込めないと、補正するまでの間に集約の取り出しが例外になる。取り出すのはログイン・プロフィール更新・MS365同期(`UserAggregate::retrieve`)。
    - symfony/serializer は、null を許さず既定値も無い引数が無いと例外を出す。
- Projector は設定を読まない(`UserProjector`・`UserManagementProjector` から `SystemSetting::current()` を除く)。
  - 行を作るときにタイムゾーンが null なら、例外にする(「未補正のイベント」)。設定の値は使わない。未補正のまま作り直すことは論点2の拒否で防ぐ。
  - テナントIDは、`??` による設定の値への切り替えをやめ、イベントの値をそのまま使う(null なら null)。記録した時点で設定が null なら、null を事実として記録する(今と同じ)。
- 古い移行の道具(`UserReconciler`・`EventStoreRebuildWriter`)が作る作成系のイベントにも、タイムゾーンを入れる(今と同じく現在の既定値)。既存のテスト(`ExternalTenantIdRebuildTest`・`EventStoreRebuilderTest`・`SeededDataRebuildScenarioTest`)が通る状態を保つ。
- `ScenarioSeeder` など、Handler を通らずに集約を呼ぶ箇所も値を渡す(grep で全て)。
- 各イベントクラスの docblock(「旧イベントは null で、Projector は設定値を読む」)と、`ReadModelTableLister.php:29-36` の説明を、実態に合わせて直す。
- 理由: 作成した時点の値は、作成した時点にしか分からない。イベントに持たせれば、作り直しの結果がいつでも同じになる。
- 未確定・要確認事項: なし

### 論点2: 過去のイベントの補正(data-correction)
- 決定: 過去のイベントに値を書き足す。`stored_events` の payload を書き換え、補正イベントは追記しない。
- 対象とキー(検出器の判定もこれを使う):
  1. `user.created_manually` の `attributes.timezone`。
  2. `user.onboarded_as_admin`・`user.created_from_sso_login`・`user.synced_from_ms365` の `timezone`(全件)。
  3. `external_hr.import_applied` の、`is_new` が true の行の `rows[i].timezone`。
  4. 4種類のイベントの `externalTenantId`(onboarded_as_admin・created_from_sso_login・synced_from_ms365・sso_account_linked)。キーが無いもの。
- タイムゾーンに補う値:
  - その利用者の作成のイベント(上の1〜3)より後に、`users.timezone` を書くイベントがあるかを、`stored_events.id` の順で見る。
    - 書くイベント: `user.profile_updated`(`after` に timezone があるもの)、`user.migrated_from_legacy`(`attributes.timezone`)、作成系のイベント(`updateOrCreate` で上書きする)。
  - 後に書くイベントが無い場合: ライブの `users.timezone` を入れる。作り直しの結果がライブと一致する値。
  - 後に書くイベントがある場合: 作成の時点の既定値を、設定の履歴から復元する。どの値でも最終の結果は後のイベントで決まる。
  - synced の、新規作成にならないイベント: 設定の履歴から復元した値を入れる。結果に影響しない。
  - 設定の履歴からの復元:
    - `system_settings.updated` のうち、`after` に `default_timezone` のキーがあるものだけを `stored_events.id` の順に見る。
    - 対象のイベントより前の最後の更新の `after.default_timezone` を使う。
    - 前に更新が無ければ、最初の更新の `before.default_timezone` を使う。更新が1度も無ければ、ライブの `system_settings.default_timezone` を使う。
    - 監査イベントの導入(2026-08-08)より前の変更は記録に無い。そのため、これは「事実に最も近い値」とする。
  - 試し実行では、ライブの値と設定の履歴から復元した値が違う利用者を一覧に出す(参考)。
- テナントIDに補う値:
  - 同じ entraUserId の、ライブの `external_identities`(MICROSOFT_ENTRA)の `external_tenant_id` を入れる。null なら null を明示する。
  - キーがあって値が null で、ライブが null 以外のイベントがある場合: フォールバックをやめると、作り直しの結果がライブと変わる。そのため、ライブの値で上書きする。試し実行で件数と一覧を出す。
  - キーがあって値が null 以外のイベントは変えない。
  - ライブの行が無いイベントは、止める(一覧を出して `--execute` しない)。null を入れると、作り直しで行ができてライブと一致しないため。
- 止める条件: ライブの行が無い利用者のタイムゾーンを、設定の履歴から復元できない場合。`users.timezone` は NOT NULL なので、実際には「行が無い」場合だけ。
- 安全手順(`data-correction`):
  - 試し実行が既定。
  - スナップショット(`snapshots`)に利用者の集約の行があれば止める(前例どおり)。
  - バックアップ表を作り、補正ログ(`stored_event_corrections`、補正キー `user_settings_at_replay`)を残す。
  - 冪等: キーがあるイベントは変えない(上の上書きの場合を除く)。
- 作り直しの拒否:
  - 未補正のイベント(上の1〜4のキーが無い行)がある間は、作り直しを拒否する。
  - 拒否の対象: `read-models:rebuild`・`projections:rebuild`・管理画面に加え、自分で作り直しを行う補正のコマンド(`events:fix-calendar-bulk-operation-target-ids`、HEAD の `events:rename-boolean-properties`)。
  - 新しい検出器 `LegacyUserSettingsDetector` で判定する。
- 本番の実行には、試し実行の結果を示したうえでユーザーの明示的な許可を得て、記録に残す。
- 未確定・要確認事項: なし

### 論点3: 一括操作の補正とまとめて1回で行う(中間版)
- 問題: 2つの補正は、別々に順に実行すると成り立たない。
  - 一括操作の補正を先にすると、その中の作り直しが未補正の利用者イベントで止まる(論点2の拒否・論点1の例外)。
  - 利用者の補正を先にすると、その作り直しで一括操作の旧IDの行が消え、対応表を作れなくなる。
- 決定: 中間版では、新しいコマンド `events:fix-rebuild-determinism` で、2つの書き換えを1回のトランザクションでまとめて行う。
  1. 書き換えの前に、ライブの表から2つの対応表を作る(一括操作の旧ID→新ID、利用者のタイムゾーン・テナントID)。
  2. 両方の書き換えを行う。
  3. `ReadModelRebuilder` で1回だけ作り直す。
  4. 検証する。合格しなければ全て戻す。
- 検証の合格条件:
  1. 一括操作の補正の合格条件(変更セット`20261123-rebuild-drift-fixes` 論点3)。
  2. `users`・`external_identities` が、書き換え前のライブと一致する。
  3. その他の全表が変わらない。`changed_tables` が `calendar_bulk_operation_targets` だけ(id の分)。
- 試し実行では、2つの補正の試し実行の結果をまとめて出す。
- 単独のコマンドの扱い:
  - `events:fix-calendar-bulk-operation-target-ids` と `events:fix-user-settings-at-replay` は、それぞれ相手の検出器が陽性なら止め、`events:fix-rebuild-determinism` を案内する。
  - 片方だけが必要な環境では、単独で使える。
- 運用コマンド(管理画面)・メンテナンスの対象(`ReadModelRebuildRunService::MAINTENANCE_COMMANDS`)に、3つとも登録する。
- docs/27 §3.7(中間版の手順)の補正を、`events:fix-rebuild-determinism` に置き換える。
  - 中間版は、改名前のコード(`cd1d40ce`)に変更セット`20261123-rebuild-drift-fixes`と本変更セットのコードを載せたもの。
  - 本変更セットのコードは、真偽値の改名に触れない。
- 受け入れ条件: 中間版で `php artisan test` が全件通る。衝突の解消は、`intermediate-conflict-resolution.diff` の要領で行う。
- 実行の方法(ユーザー回答 2026-11-24「管理者でログインし、管理画面からコマンドを実行したい」):
  - 補正の前でも、管理者のログインと管理画面は動く(論点1の型で、旧イベントを読める)。
  - 補正のコマンドは、管理画面の運用コマンドから試し実行・実行する。実行中は `ReadModelRebuildRunService` のメンテナンスで他の操作を止めるので、同時の書き込みは起きない。
  - docs/27 §3.7(中間版)の手順は「配備 → 管理画面で試し実行 → 結果の確認と許可 → 管理画面で実行 → 作り直しの試し実行で差分0」にする。`php artisan down` と SSH での実行は不要。配備そのものの短い停止は除く。
  - マイグレーションで補正する案は採らない。理由: 試し実行・検証と戻し・本番実行の許可ができなくなる(`data-correction`)。
- 開発・ステージング・e2e の DB も、新しいコードにしたら `events:fix-rebuild-determinism` を実行する。docs/27 と backend/CLAUDE.md に書く。
  - 補正の前でも、集約の取り出し(ログイン等)は動く(論点1の型)。止まるのは作り直しだけ。
- 未確定・要確認事項: なし

### 論点4: 対象外
- `PaidLeaveFiscalYearSettingsProjector` の `last_changed_event_id`(`storedEventId`)。書き換えは同じ行の payload だけなので、作り直しでも `stored_events` の id は変わらず、差は出ない。
- `system_settings.updated` の payload に、送らなかった項目が入らないこと。タイムゾーンの復元は、キーのある更新だけを見るので扱える。テナントIDは履歴を使わない。

## 受け入れ条件
- 新しく記録する対象のイベント(論点2の1〜3)に、タイムゾーンが入る。
- Projector が設定を読まず、テナントIDは設定の値に切り替えない。
- 設定(既定のタイムゾーン・テナントID)を変えてから作り直しても、`users`・`external_identities` が変わらない(シナリオテスト)。
- 補正前の旧イベントがあっても、集約の取り出し(ログイン・プロフィール更新・MS365同期)が動く。
- 補正(単独とまとめて)について、次をテストする。
  - 試し実行・実行・冪等性
  - 後でタイムゾーンを変えた利用者の、履歴からの復元
  - HR取込の `is_new` の行
  - synced の、新規作成にならないイベント
  - テナントID: null の明示、キーが null でライブが null 以外のときの上書き、行が無いときの停止
  - バックアップと補正ログ、スナップショットの確認
  - 未補正の間の作り直しの拒否(補正のコマンドを含む)
  - 2つの補正をまとめた1回の作り直しでの合格
- `SsoTenantIdReplayTest` の旧イベントのテスト(設定の値に切り替わることを確かめていたもの)は、「補正するまで作り直しを拒否する」「補正後は記録した値になる」に書き換える。
- HEAD と中間版で、`php artisan test` が全件通る。
- リハーサル用のDB(旧e2eで作ったDB)で、次の順に行い、作り直しの差分が0になる。
  1. 中間版で `events:fix-rebuild-determinism` を実行する。
  2. HEAD で命名統一を行う。

## 対象外
- 論点4の各項目。

## ドキュメントへの影響
- `docs/17-events.md`: 5種類のイベントのタイムゾーンと、テナントIDの扱い(設定の値に切り替えない)。
- `docs/27`: §3.7 の補正を `events:fix-rebuild-determinism` に置き換え、開発・ステージングでも実行することを書く。
- `backend/CLAUDE.md`: 既存の開発DBで補正を実行することを1行。
- `.claude/skills/data-correction`: 次を書く。
  - 前例に1行。
  - SKILL.md:74-75(必須の項目を足すと、新しいコードが旧イベントを読めない)に、「null 許容と既定値で旧イベントを読めるようにし、補正するまで作り直しを拒否する」という対処を1行。

## モック・アセット
なし

## 実装対象
1. Handler・集約・イベントにタイムゾーンを足す。Projector から設定の読み取りを除く。古い道具・シーダーも直す(論点1)。
2. 補正の Planner・コマンド(単独とまとめて)、検出器、作り直しの拒否(論点2・3)。
3. テスト(受け入れ条件)。
4. ドキュメント(別のコミット)。
5. 中間版の作業ツリーで全件テストを行い、リハーサルする。

## 検証方法
- `php artisan test` の全件(HEAD と中間版)
- リハーサル(受け入れ条件)

## レビュー履歴
- 2026-11-24 ユーザー確認: ログインできないと管理画面からコマンドを実行できず困る、との指摘。改訂版では補正の前でもログインが動くことを説明し、管理画面からの実行を手順に明記した(論点3)。マイグレーションで補正する案は、上の理由で採らないことを合意した。
- 2026-11-24 提示前の独立設計レビュー(general-purpose)の指摘を反映:
  - 高:
    - 2つの補正を順に実行すると、互いを止める → 論点3で1回にまとめる。
    - 必須の項目を足すと、補正前に集約を取り出せない → null 許容と既定値にし、Projector は null で例外。
    - 補正のコマンドが検出器を見ない → 拒否の対象に加える。
  - 中:
    - ライブの値を「事実」と呼ぶ理由付け → ライブは「作り直しがライブと一致する値」とし、違いを試し実行に出す。
    - タイムゾーンを書くイベントの列挙。
    - HR取込の `is_new`。
    - synced の、新規作成にならないイベント。
    - 合格条件の範囲。
    - キーが null でライブが null 以外のテナントID。
    - 既存のテスト・古い道具への影響。
    - 履歴の復元の細部(キーのある更新だけ、id の順、監査の導入前)。
  - 低: 行が無いときの停止、docblock・ReadModelTableLister の説明、スナップショットの確認、補正キーの名前、MAINTENANCE_COMMANDS。

## 実装結果
未着手
