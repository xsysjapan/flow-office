# prod-migration-history-collision

ステータス: 完了

## 変更要望(原文)
> デプロイエラーが発生しています。テーブルがすでにあるようなのですが、問題点というか、発生経緯を調査してください。
>
> （調査結果の報告後)現状のデプロイエラーを解消するようにマイグレーションを修正してください。create if existsなどで対応せず、整合性を持って修正してください。

## 背景・目的
PR#112(有給付与Schedule/Assessmentドメイン)のmainマージ後、本番デプロイの
`php artisan migrate --force`が「table already exists」で失敗し続けている
(`docs/27-release-runbook.md`「8. リハーサルで発見した注意点」と同一パターン)。
`deploy/scripts/activate-release.sh`は`migrate --force`失敗時に`set -e`で停止し
`current`を切り替えないため、ユーザー影響・ダウンタイムは無いが、以後`main`への
pushが全てデプロイされない状態が続く。

## 現状(As-Is)・原因調査

`main`には元々、PR#112が作られる前に以下がマージ・デプロイ済みだった:
- `93711e1`「Phase A: WorkStyleへ通常/比例/シフト判定用の所定労働日数列を追加」
- `14dd62d`「Phase B: 有給付与の法定Policyマスタ(通常/比例/時効)を新設」
- `98f90d2`「fix: MySQLで有給付与ポリシーテーブルの複合ユニーク制約名が識別子長超過するのを修正」
- `b980dd4`「Phase B: paid_leave_schedule_entries Projectionを新設」

これらは本番に`php artisan migrate --force`済み(`.github/workflows/deploy.yml`は
`push: main`トリガーで毎回デプロイし、`test-backend`(`migrate-mysql`ジョブ含む)を
`needs:`で必須化しているため、フレッシュDBでのCI通過を確認した上で本番へも適用される)。

その後、PR#112(chameleonhead氏の独立実装、同一ドメインの別実装)との
コンフリクト解消作業(前回セッション)で、上記の実施済みマイグレーションファイルを
「main側の重複実装」と誤って判断し削除、PR#112側の同名テーブルを作る新マイグレーション
(`2026_09_13_000000`等、ファイル名が異なるだけで同じテーブルを作る)を採用してしまった。
CI(`migrate-mysql`)はフレッシュMySQLコンテナで検証するため矛盾を検知できず、
本番の`migrations`テーブルに残る「旧ファイル名は実行済み」という記録と、実際に本番へ
デプロイされる「新ファイル名(未実行)」が齟齬し、後者の`CREATE TABLE`が
「既に存在する」で失敗する。

衝突している5箇所:

| 旧(本番に適用済み・誤って削除) | 新(PR#112) | スキーマ差分 |
|---|---|---|
| `2026_09_09_090000_add_schedule_days_to_work_styles.php` | `2026_09_13_000003_add_schedule_columns_to_work_styles_table.php` | 差分なし(完全同一) |
| `2026_09_09_100000_create_paid_leave_grant_policies_table.php` | `2026_09_13_000000_...` | `version`(int→string)、`effective_from`/`is_active`追加 |
| `2026_09_09_100001_create_paid_leave_proportional_grant_policies_table.php` | `2026_09_13_000001_...` | `version`(int→string)、`weekly_scheduled_days`(int)→`weekly_scheduled_days_category`(string)、`effective_from`/`is_active`追加 |
| `2026_09_09_100002_create_paid_leave_grant_expiry_policy_table.php` | `2026_09_13_000002_...` | `version`(int→string)、`effective_from`/`is_active`追加 |
| `2026_09_09_100003_create_paid_leave_schedule_entries_table.php` | `2026_09_13_000004_...` | Assessment内訳をフラット列(`assessment_*`)から独立テーブル(`paid_leave_schedule_assessments`)へ分離、`granted_paid_leave_grant_id`→`grant_id`に改称、`is_manually_overridden`/`cancelled_reason`等を新設 |

現状の本番への実害: `current`が切り替わらないため安全側(旧リリースのまま稼働継続)。
ただし解消するまで`main`へのpushが永久にデプロイされない。

## 仕様検討

### 論点1: 修正方針(`hasTable`/`hasColumn`ガード vs 履歴の作り直し)
- 選択肢:
  - A. 新マイグレーション(`2026_09_13_*`)の`Schema::create`を
       `Schema::hasTable()`で囲み、既に存在すれば何もしない
  - B. 削除してしまった旧マイグレーションファイルを元のファイル名・内容で復元し、
       migrationの実行履歴と実体の整合を取り戻した上で、新マイグレーションを
       「旧スキーマ→新スキーマへの変換(ALTER相当)」として書き直す
- 決定: B
- 理由: ユーザーからも「create if existsなどで対応せず、整合性を持って」と明示的に
  指示されている。Aは本番の実テーブルを旧スキーマのまま放置し
  (`effective_from`/`is_active`列が本番だけ無い等)、アプリケーションコードが参照する
  列が存在せず実行時エラーになる。Bはマイグレーション履歴を「実際に何が起きたか」に
  忠実に保ち、本番・フレッシュ環境のいずれでも最終的に同一の新スキーマへ到達できる、
  Laravelマイグレーションの標準的な考え方(過去の適用済みマイグレーションは書き換えず、
  新しいマイグレーションで前進する)に沿う。

### 論点2: 旧データの扱い(移行 vs 破棄)
- 選択肢:
  - A. 旧テーブルを`DROP`して新テーブルを作り直す(データは失われる)
  - B. 旧テーブルを退避(`Schema::rename`)→新スキーマで作成→旧データを新形式へ
       変換してコピー→旧テーブル削除、という手順でデータを保持する
- 決定: B
- 理由: 該当テーブルは全て「versionで世代管理する法定マスタ」または
  「イベントソースから再生成可能なProjection」(CLAUDE.md原則2)であり、実運用上は
  失っても再投入・再生成可能な性質ではあるが、Aは不必要にデータを破棄する。
  「整合性を持って」という指示の趣旨からも、機械的に変換できるデータは保持するBを
  選ぶ方が誠実な対応である。

### 論点3: `paid_leave_grant_policies`等の`version`列の変換ルール
- 選択肢:
  - A. 旧`version`(整数、例: `1`)をそのまま文字列化(`"1"`)する
  - B. `App\Http\Controllers\Api\PaidLeaveController::nextGrantPolicyVersion()`が
       前提とする`v<N>`形式に変換する(`"1"` → `"v1"`)
- 決定: B
- 理由: `nextGrantPolicyVersion()`は正規表現`^v(\d+)$`で`v`始まりのバージョンのみを
  数値として認識し、次バージョンを算出する。Aのまま(プレフィックス無し)だと、
  移行後に新バージョンを作成する際にこの回帰移行データが無視され、
  バージョン採番が意図せず`v1`から再スタートしてしまう(実際には`v1`相当の
  データが既にあるにも関わらず)。Bにすることで、移行後もバージョン採番が
  違和感なく連続する。
- 未確定・要確認事項: 本番の実データ件数・実際の`version`値は未確認(DBへの
  直接アクセス権がないため)。移行ロジックは`version`が整数何であっても
  `'v'.$version`で変換するため、値によらず動作する設計にしている。

### 論点4: `paid_leave_schedule_entries`のAssessment内訳データの移行先
- 選択肢:
  - A. 旧テーブルのフラットなAssessment列(`assessment_*`)は移行せず破棄する
  - B. `assessment_period_start`が非nullの行(Assessment実行済み)について、
       新設の`paid_leave_schedule_assessments`テーブルへ1行ずつ変換して移行し、
       対応する新`paid_leave_schedule_entries.latest_assessment_id`を補完する
- 決定: B
- 理由: 出勤率Assessmentの内訳(分母/分子/判定根拠)は「誰が・いつ・何を根拠に
  判定したか」の監査記録(依頼書§30・§40)であり、Aで破棄すると監査証跡が失われる。
  Bで新しい正規化されたテーブル構造へ機械的に変換・移行できるため、これを選ぶ。
- 未確定・要確認事項: 旧スキーマには`overridden_by_user_id`
  (Assessment上書きを行った操作者)に相当する列が無いため、移行後の
  `overridden_by_user_id`は`null`で補完する(`override_reason`自体は移行するが
  「誰が上書きしたか」の情報は失われる)。実際にOverrideされた行が本番に
  存在するかは未確認だが、影響は監査情報の一部欠落に留まり、業務データ
  (判定結果・理由そのもの)は保持される。

## 仕様確定事項(まとめ)
- 削除していた5つの旧マイグレーションファイルを、元のファイル名・タイムスタンプ・
  内容で復元する(`2026_09_09_090000`/`100000`/`100001`/`100002`/`100003`)。
  `2026_09_09_100000`は本番で実際に適用された`98f90d2`修正後の内容
  (`unique`制約に明示的な短い名前`paid_leave_grant_policies_unique`)で復元する。
- `2026_09_13_000003_add_schedule_columns_to_work_styles_table.php`は、復元した
  `2026_09_09_090000`と列定義が完全に同一のため削除する(冗長な重複マイグレーション)。
- `2026_09_13_000000/000001/000002/000004`(create文)を、「対象テーブルを
  `_legacy`へ退避→新スキーマで作成→旧データを新形式へ変換してコピー→
  `_legacy`テーブル削除」という構成に書き換える。本番・フレッシュ環境いずれも
  直前の復元済み旧マイグレーションで対象テーブルが必ず存在する状態から始まるため、
  `Schema::hasTable()`等の条件分岐は使わない。
- `2026_09_13_000005_create_paid_leave_schedule_assessments_table.php`
  (新設テーブル、衝突なし)は、テーブル作成に加えて、退避された
  `paid_leave_schedule_entries_legacy`からAssessment記録済みの行を
  `paid_leave_schedule_assessments`へ変換・移行し、対応する新エントリの
  `latest_assessment_id`を補完してから`_legacy`テーブルを削除する処理を追加する。
- `version`列の変換は全て「`'v'.$旧version`」形式とする(論点3)。
- 移行ロジックの検証のため、意図的に旧スキーマ+実データを再現してから対象
  マイグレーションの`up()`を直接実行し、変換後のデータを検証するテストを追加する
  (`RefreshDatabase`によるフレッシュDBだけでは移行対象データが0件のため検証できない)。

## 受け入れ条件
- `php artisan migrate --force`を、本番相当(旧スキーマ・旧`migrations`実行履歴を
  持つDB)に対して実行した場合に、「table already exists」等のエラーなく完走する
  (直接検証はできないため、同等の状況を再現した自動テストで代替確認する)。
- 上記テストで、旧スキーマに投入した模擬データが、新スキーマへ正しく変換されて
  保持されていることを確認する(`version`の`v`プレフィックス付与、
  `weekly_scheduled_days`→`_category`の文字列化、Assessment内訳の
  `paid_leave_schedule_assessments`への分離等)。
- 通常のフレッシュDB(CI・新規開発環境)での`php artisan test`が全てPASSし、
  新規マイグレーション構成でも既存機能に回帰が無いことを確認する。
- `work_styles`への列追加が二重に定義されず、1回だけ行われる。

## 対象外
- 本番DBへの直接接続・実データの確認(アクセス権が無いため、想定されるデータ形状に
  基づいた変換ロジックの実装とテストによる検証に留める)。
- Assessment上書き操作者(`overridden_by_user_id`)の遡及的な特定(論点4の通り、
  旧スキーマにその情報が無いため対応不可)。
- この一連の作業以外の、mainとPR#112のコンフリクト解消時に見落とした可能性のある
  他の不整合の網羅的な再調査(今回判明した5テーブルの衝突のみを対象とする)。

## ドキュメントへの影響
変更なし(マイグレーション構造の是正であり、ユースケース・DBスキーマ設計自体に
変更は無いため)。

## モック・アセット
なし

## 実装対象
- `backend/database/migrations/2026_09_09_090000_add_schedule_days_to_work_styles.php`(復元)
- `backend/database/migrations/2026_09_09_100000_create_paid_leave_grant_policies_table.php`(復元)
- `backend/database/migrations/2026_09_09_100001_create_paid_leave_proportional_grant_policies_table.php`(復元)
- `backend/database/migrations/2026_09_09_100002_create_paid_leave_grant_expiry_policy_table.php`(復元)
- `backend/database/migrations/2026_09_09_100003_create_paid_leave_schedule_entries_table.php`(復元)
- `backend/database/migrations/2026_09_13_000003_add_schedule_columns_to_work_styles_table.php`(削除)
- `backend/database/migrations/2026_09_13_000000_create_paid_leave_grant_policies_table.php`(書き換え)
- `backend/database/migrations/2026_09_13_000001_create_paid_leave_proportional_grant_policies_table.php`(書き換え)
- `backend/database/migrations/2026_09_13_000002_create_paid_leave_grant_expiry_policy_table.php`(書き換え)
- `backend/database/migrations/2026_09_13_000004_create_paid_leave_schedule_entries_table.php`(書き換え)
- `backend/database/migrations/2026_09_13_000005_create_paid_leave_schedule_assessments_table.php`(書き換え)
- `backend/tests/Feature/PaidLeaveSchedule/LegacySchemaMigrationReconciliationTest.php`(新規)

## 検証方法
- `cd backend && php artisan test --filter=PaidLeaveSchedule`
- `cd backend && php artisan test`(フルスイート)
- `cd backend && vendor/bin/pint --test <変更ファイル>`

## レビュー履歴
初版。

## 実装結果
- `php artisan test --filter=PaidLeaveSchedule`: 87/87 pass(新規移行検証テスト4件含む)
- `php artisan test`: 1090/1090 pass
- `vendor/bin/pint --test`: 変更した全マイグレーションファイルでエラーなし
- 実際の本番DBに対する`migrate --force`の直接検証は未実施(アクセス権が無いため)。
  上記テストで、本番相当の状態(旧スキーマ+実データ)を再現した上での移行ロジックの
  正しさを確認済み。実際のデプロイでの最終確認が必要。
