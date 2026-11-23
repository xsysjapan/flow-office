# boolean-column-naming(真偽値の列・イベント項目の命名の統一と、paid_leave_usages の確定フラグの二重化の解消)

ステータス: 検討中

## 変更要望(原文)
> 別途調べたい点: 有給の消化記録のテーブル(paid_leave_usages)に、is_confirmed と confirmed という似た列が2つあります。…
> →今回調査してください。
> 妥当な標準化案を出して対応をお願いします。イベントの作り直しも含めた対応をお願いします。

(関連: 変更セット`20261123-boolean-query-param`の「フラグ変数の扱いも標準化して、見直しをお願いします」)

## 背景・目的
- `paid_leave_usages` が「確定済みか」という同じ事実を `is_confirmed` と `confirmed` の2列で持っている(設計原則2・変更セットの「同じ事実を複数の場所で持たない」に反する)。
- 真偽値の列名が `is_*`・`*_enabled`・`requires_*`・接頭辞なし(`offline`・`attended`・`cancelled`)・命令形(`allow`・`require_location`・`prohibit_*`)などで揃っておらず、
  読んだときに真偽値か・何が真なのかが分かりにくい。命名ルールを決め、既存の列とイベントの項目名もルールに合わせる(イベントの作り直しを含む)。

## 現状(As-Is)

### paid_leave_usages の is_confirmed / confirmed(調査結果)
- 列: `is_confirmed`(2026_08_07 migration、旧有給ドメイン用)、`confirmed`・`cancelled`(2026_09_07 migration、変更セット`20260906-paid-leave-domain-redesign`で新ドメイン用に追加)。
- 書き手は `PaidLeaveUsageAllocationProjector` のみ。`PaidLeaveUsageDesignated` で3列とも false、`PaidLeaveUsageConfirmed` で `is_confirmed` と `confirmed` を**同時に** true、`PaidLeaveUsageCancelled` で `cancelled` を true(:99-130)。
  同じ行で2列の値が食い違う経路は現コードに無い(旧 Projector は削除済み。ReadModel は replay で全行が新 Projector から作られる)。`:39` のコメント「旧ドメインの is_confirmed には触れない」は実装と食い違っている。
- 読み手: `is_confirmed` … `AttendanceExcelBuilder.php:135-139`(`is_confirmed AND NOT cancelled`)、3種の UsageResource(API出力は `is_confirmed` のみ)、frontend `api/types.ts:858,872,886`。
  `confirmed` … `WarnFiveDayObligationHandler.php:85-89`(`confirmed AND NOT cancelled`)のみ。`cancelled` … 上記2か所と `PaidLeaveController.php:818-822`。
- イベントの payload に `is_confirmed`/`confirmed`/`cancelled` のキーは無い(確定・取消はイベントの種類で表す)。集約 `PaidLeaveAccountAggregate` は usage ごとに `confirmed`/`cancelled` を状態として持つ(DBの列ではない)。
- `special_leave_usages`・`compensatory_leave_usages` は `is_confirmed` の1列だけ。取消は行の削除で表す(paid は行を残して `cancelled=true`。docs/16:1248「監査可能に残す」)。
- docs/16 には `confirmed`・`cancelled` の説明(:1242-1248)だけがあり、`is_confirmed` の記載が無い。

### 真偽値の列の棚卸し(71組 / 48種)
全て `boolean NOT NULL`、model の casts は全て `boolean`、API 応答は true/false。外部(mcp・docs/25)からの列名の利用は無い。docs/33 は外部連携の `enabled` に2か所言及。
命名の内訳: `is_*` 15種、`*_enabled` 8種、`requires_*`/`*_requires_approval` 9種、`allow*` 3種、その他(接頭辞なし・命令形)13種。
イベントとの関係: 同名(snake)のキーが payload の配列内にある 26組、camelCase の同義プロパティがある 17組、別名 2組、payload に無い(ReadModel のみ)26組。

## 設計前提の検証
- 正データと派生データ:
  - `paid_leave_usages` は ReadModel(イベントから再生成)。確定の正は `PaidLeaveUsageConfirmed` イベントの有無で、列は2つとも派生。派生列が二重になっているだけなので、列を1つにしてリビルドすれば正しい状態になる。
  - 改名対象の列のうち、Projector が書く表は ReadModel(リビルドで再生成)。`system_settings`・`external_integration_connections`・`asset_number_rules` は管理APIから直接更新する正データ(設計原則1の例外。監査イベントを同時に記録)で、列の改名は migration の `renameColumn` でデータを保ったまま行う。
- 定義と実態: docs/16 に `is_confirmed` が無く、実装の読み手は2列に割れている(定義と実態の不一致)。
- イベントの意味: 対象イベントの意味(何が起きたか)は変えない。項目名(プロパティ名・配列のキー)だけを新しい名前にする。過去のイベントは「最初から新しい名前で記録されていた」状態に作り直す(`data-correction`の方法2。移行イベントは作らない)。
- 原因の分類: 設計の誤り(新ドメイン導入時に旧ドメインの派生列を残したまま新しい列を足した)。対症療法(読み手を片方に寄せるだけ)ではなく、列を1つにする。
- 横展開: special / compensatory の usages は1列で問題なし。命名は全ての真偽値の列・それに対応するイベント項目・API キー・frontend の型を対象にする。

## 仕様検討

### 論点1: paid_leave_usages の確定フラグをどちらに寄せるか
- 選択肢:
  - A. `is_confirmed` に寄せ、`confirmed` を削除する
  - B. `confirmed` に寄せ、`is_confirmed` を削除する
- 決定: A
- 理由: special / compensatory の usages・3種の API 応答・frontend の型が `is_confirmed` で、論点3の命名ルール(状態は `is_*`)にも合う。B は API と他の2表との名前が割れる。
- 細則: `cancelled` は命名ルールに合わせ `is_cancelled` に改名する(paid だけが取消行を残すため、paid だけが持つ列のままでよい)。
  `WarnFiveDayObligationHandler` は `is_confirmed AND NOT is_cancelled`、`AttendanceExcelBuilder`・`PaidLeaveController::usagesForUser` は `is_cancelled` に置き換える。
  集約 `PaidLeaveAccountAggregate` の内部状態のキー(`confirmed`/`cancelled`)は DB の列でもイベント項目でもないため、命名ルールの対象(列・イベント項目・API)に合わせて `isConfirmed`/`isCancelled` に揃える(永続化されないため作り直しは不要)。
- 未確定・要確認事項: なし

### 論点2: paid_leave_usages の修正方法
- 選択肢:
  - A. migration で `confirmed` を削除・`cancelled` を `is_cancelled` に改名し、Projector を直して ReadModel を作り直す(`read-models:rebuild`)
  - B. イベントの作り直し
- 決定: A
- 理由: イベントに該当する項目が無く、列は ReadModel の派生のため、Projector の修正と ReadModel の作り直しで足りる(`data-correction`「ReadModelの作り直し」)。
  migration では、念のため移行前に `is_confirmed <> confirmed` の行数を数えてログに出す(0件の想定。0件でなくても、作り直しで新 Projector の値に揃う)。
- 未確定・要確認事項: なし

### 論点3: 真偽値の命名ルール
- 選択肢:
  - A. すべて `is_*` に寄せる(`is_offline_allowed`・`is_approval_required` 等)
  - B. 「述語として読める名前」に揃え、意味ごとに形を決める(下表)
  - C. 新しい列からだけ適用する
- 決定: B(既存の列にも適用する。論点4)
- 理由: A は `paid_leave_requires_approval`・`notification_mail_enabled` のような既に読みやすく多数派の名前まで変えることになり、改名の範囲が過大になる。B は読みやすい既存の形(`is_*`・`*_enabled`・`requires_*`)を正とし、
  それに外れる形(接頭辞なしの名詞・過去分詞、命令形)だけを直す。C はユーザーの依頼「イベントの作り直しも含めた対応」に合わない。
- ルール(列名 snake_case / イベントのプロパティ・集約の状態は同じ語の camelCase / API キーは列名と同じ / frontend の型は API キーと同じ):

  | 意味 | 形 | 例 |
  |---|---|---|
  | 状態・属性(〜である) | `is_*` | `is_active`、`is_default`、`is_offline`、`is_cancelled` |
  | 持っている・経験した(〜がある/した) | `has_*` | `has_attended`、`has_clocked_out`、`has_core_time_violation` |
  | 機能・設定の有効化 | `*_enabled` | `notification_mail_enabled`、`punch_type_auto_detection_enabled` |
  | 要否(〜を必要とする) | `requires_*` または `<対象>_requires_*` | `requires_attachment`、`paid_leave_requires_approval` |
  | 許可・禁止(〜を許す/禁じる) | `allows_*` / `prohibits_*`(三人称単数の動詞) | `allows_offline`、`prohibits_self_privileged_role_assignment` |
  | その他の性質(〜する) | 三人称単数の動詞で始める | `crosses_midnight`、`includes_descendants` |
  - レコード自体の有効/無効は `is_active`(`enabled` は使わない。`*_enabled` は機能・設定のオン/オフに使う)。
  - 否定形の名前(`is_not_*`・`disabled`)は使わない。
- 未確定・要確認事項: なし

### 論点4: 改名する列(ルールに外れる既存の列)
- 決定: 次の表のとおり改名する(ルールに合う列は変えない)。

  | # | テーブル.列 | 新しい名前 | 書き手 | イベント側の項目(旧→新) |
  |---|---|---|---|---|
  | 1 | paid_leave_usages.confirmed | (削除。is_confirmed に統一) | Projector | なし |
  | 2 | paid_leave_usages.cancelled | is_cancelled | Projector | なし |
  | 3 | devices.allow_offline | allows_offline | Projector | DeviceRegistered/DeviceSettingsUpdated `allowOffline`→`allowsOffline` |
  | 4 | devices.require_location | requires_location | Projector | 同 `requireLocation`→`requiresLocation` |
  | 5 | devices.auto_detect_punch_type | punch_type_auto_detection_enabled | Projector | 同 `autoDetectPunchType`→`punchTypeAutoDetectionEnabled` |
  | 6 | attendance_punches.offline | is_offline | Projector | AttendancePunchRecorded `offline`→`isOffline` |
  | 7 | company_calendars.allow_daily_holiday_override | allows_daily_holiday_override | Projector | CompanyCalendarCreated/Updated `allowDailyHolidayOverride`→`allowsDailyHolidayOverride` |
  | 8 | work_styles.system_generated | is_system_generated | Projector | WorkStyleCreated/Updated の `attributes` 配列のキー `system_generated`→`is_system_generated` |
  | 9 | attendance_daily_calculations.core_time_violation | has_core_time_violation | Projector | AttendanceDayCalculated の `calculation` 配列のキー `core_time_violation`→`has_core_time_violation` |
  | 10 | group_types.primary_membership_required | requires_primary_membership | Projector | GroupTypeCreated/Updated `primaryMembershipRequired`→`requiresPrimaryMembership` |
  | 11 | role_assignments.include_descendants | includes_descendants | Projector | RoleAssignmentCreated/Updated `includeDescendants`→`includesDescendants` |
  | 12 | leave_attendance_rate_days.attended、special_leave_attendance_rate_days.attended | has_attended | Projector | なし |
  | 13 | leave_attendance_rate_attendance_days.clocked_out、special_leave_attendance_rate_attendance_days.clocked_out | has_clocked_out | Projector | なし |
  | 14 | authentication_key_device_rules.allow | is_allowed | 書き手なし(読み取りのみ) | なし |
  | 15 | external_integration_connections.enabled | is_active | 管理API直接(正データ)+監査イベント | ExternalIntegrationConnectionCreated/Updated/Deleted の `before`/`after` 配列のキー `enabled`→`is_active` |
  | 16 | asset_number_rules.enabled | is_active | Handler直接(正データ)+監査イベント | AssetNumberRuleConfigured `enabled`→`isActive` |
  | 17 | system_settings.prohibit_self_privileged_role_assignment | prohibits_self_privileged_role_assignment | 管理API直接(正データ)+監査イベント | SystemSettingsUpdated の `before`/`after` 配列のキー(同名)を新しい名前に |

- 変えない列(ルールに合う): `is_*` 全般、`*_enabled`(`notification_mail_enabled`・`m365_mock_enabled`・`core_time_enabled`・`auto_break_enabled`・`compensatory_leave_enabled`・`*_auto_grant_enabled`)、
  `requires_*`・`*_requires_approval`、`crosses_midnight`、`is_holiday_day`。`users.*_auto_grant_enabled` のイベント(`PaidLeaveAutoGrantEnabledSet.enabled` 等)はイベント名が対象を表しているので変えない。
- 理由: 表の各列は、接頭辞なしの名詞・過去分詞(`offline`・`attended`・`cancelled`)、命令形(`allow`・`require_*`・`prohibit_*`)、受け身の後置(`*_required`)、
  レコード有効化への `enabled` で、論点3のルールに外れる。
- 未確定・要確認事項: なし

### 論点5: イベントの作り直しの方法(data-correction)
- 選択肢:
  - A. `stored_events` の作り直し(新しい項目名で記録されていた状態に置き換える)をコマンドで行い、旧名の読み替えをコードに残さない
  - B. イベントクラスで旧名・新名の両方を読めるようにする(upcaster)
  - C. 移行イベントを追記する
- 決定: A
- 理由: `data-correction` の方針(「イベントの改名は必ず作り直し。旧系統の読み替え・移行イベントを残さない」)。改名は機械的に決まるため、過去の事実が決められない部分は無い。
- 変換規則:
  - 対象: 論点4の表の「イベント側の項目」欄にあるイベントクラスの `stored_events` 行(`event_class` で特定)。
  - `event_properties` の JSON で、表に書いたキー(トップレベルのプロパティ、または表に書いた配列 `attributes`・`calculation`・`before`・`after` の中のキー)だけを新しい名前にする。値は変えない。
    表に無いイベント・表に無いキーは変えない(同じ名前のキーが他のイベントにあっても触らない)。
  - id・集約ID・`aggregate_version`・`created_at`・`meta_data` は変えない(イベントの数・順序は変わらないため、id の振り直しは不要)。
  - 1行ずつの変換なので、作り直しは「部分的な書き換え」(`data-correction` の方法1)の安全手順で行う: 運用コマンド、試し実行が既定、バックアップ表、変換前の payload を補正ログに残す、冪等(新しい名前が既にあれば何もしない)。
- 実行: 運用コマンド `events:rename-boolean-properties {--execute}` を新設し、管理画面の運用コマンドの一覧にも載せる(前例 `backoffice:migrate-assignment-events`)。
  1つのトランザクションで「対象イベントの書き換え → `ReadModelRebuilder::rebuild`」を行い、検証に失敗したら全て戻す。メンテナンス・排他は `read-models:rebuild` の仕組みに乗せる。
- 再現性: 書き換え後に全 ReadModel をリビルドすると、改名した列に旧列と同じ値が入る(テストで確認)。
- 削除するもの: 旧名の読み替えは作らない。リリース後に作り直しのコマンドは残す(他環境での実行用。次のリリースで削除する)。
- 未確定・要確認事項: なし

### 論点6: リリース手順(配備と作り直しの順序)
- 決定: 1回のリリースで次の順に行う。
  1. メンテナンス開始(`read-models:rebuild` と同じ仕組み)。
  2. 新しいコードを配備し、migration を実行する(列の改名・`paid_leave_usages.confirmed` の削除。正データの3表は `renameColumn` でデータを保つ)。
  3. `events:rename-boolean-properties` を試し実行し、対象件数を確認 → `--execute` で書き換えと ReadModel の作り直し。
  4. 検証(論点7)→ メンテナンス終了。
- 理由: 新しいコードは新しい項目名のイベントしか読めないため、配備から書き換えまでの間はイベントの読み込みを止める必要がある。
  旧コードで基準値(改名する列の値の集計)を取ってから配備する。
- 本番の実行には、試し実行の結果を示したうえでユーザーの明示的な許可を得る(`data-correction`)。
- 未確定・要確認事項: なし

### 論点7: 検証
- 決定: 配備前(旧コード・旧DB)に、改名する各列の「表ごとの真/偽の件数」を基準値として取る。作り直し後(新コード)に新しい列名で同じ集計を取り、一致すること。
  `paid_leave_usages` は旧 `is_confirmed` の真偽件数 = 新 `is_confirmed`、旧 `cancelled` = 新 `is_cancelled`。手順書に、どちらのコード・DBで実行するかを書く。
- 未確定・要確認事項: なし

### 論点8: 調査中に見つかった関連の疑い(別扱い)
- `AttendanceDifferenceDetector.php:80-81` が休暇の重なり判定で usages の行の存在だけを見ており、取消済み(paid の `is_cancelled`)や未確定の行も重なりとして扱う可能性がある。
- `PaidLeaveController::usagesForUser`(:818)が未確定の usage も返している(意図どおりかは画面側の使い方次第)。
- 決定: 本変更セットでは列名の置き換えだけ行い、判定の中身は変えない。2件は不具合かどうかの確認が要るため、ユーザーに報告し別の変更セットで扱う。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
1. migration(新規1本): 論点4の表の列を改名(`renameColumn`)し、`paid_leave_usages.confirmed` を削除する。down は逆の改名と `confirmed`(boolean default false)の再作成。
   削除前に `is_confirmed <> confirmed` の件数をログに出す。
2. backend のコード: 論点4の表の列・イベントのプロパティ/配列キーを新しい名前に置き換える(models の fillable・casts、Projector、Controller の validate、Resource、Handler、Command、Aggregate、
   `EventStoreRebuildWriter`・`StoredEventHistoryNormalizer` の直接参照、seeder、factory)。`PaidLeaveUsageAllocationProjector` は `is_confirmed`・`is_cancelled` だけを書き、`:39` のコメントを直す。
   `WarnFiveDayObligationHandler` は `is_confirmed`・`is_cancelled` で判定。`PaidLeaveAccountAggregate` の usage 状態キーを `isConfirmed`/`isCancelled` に。
3. API: validate・Resource のキーを新しい名前にする(入力を受けるもの: devices の3項目、company_calendars、group_types、role_assignments、external_integration_connections、asset_number_rules、system_settings)。旧名は受け付けない。
4. frontend: `api/types.ts` ほか型・画面・テスト・stories・e2e のキーを新しい名前にする。
5. 運用コマンド `events:rename-boolean-properties {--execute}`: 論点5の変換規則。管理画面の運用コマンド一覧に追加。
6. ドキュメント: docs/16(列名・`paid_leave_usages` の is_confirmed/is_cancelled の説明)、docs/17(イベントの項目名)、docs/20(命名ルール。`20261123-boolean-query-param` で作る「真偽値の扱い」の節に命名ルールを書く)、docs/33(外部連携の `enabled`→`is_active`)。
7. テスト:
   - migration と作り直しコマンド: 旧イベントの生の行(旧項目名)を入力に、試し実行(件数・変換前後)→ 実行 → 全ReadModelを空にしてreplay → 新しい列に期待値が入る、まで通すテスト(論点4の各イベントを1件以上)。冪等(2回目は変換0件)。
     対象外のイベント・同名キーが変わらないこと(例: `PaidLeaveAutoGrantEnabledSet.enabled` は変わらない)。
   - paid_leave_usages: 指定→確定→取消の各段階で `is_confirmed`・`is_cancelled` が期待どおり、`WarnFiveDayObligationHandler` と Excel の残日数が確定かつ未取消だけを数える(既存テストの列名の置き換え)。
   - 既存の backend 全件・frontend 単体全件・e2e 全件がPASS。

## 受け入れ条件
- `paid_leave_usages` に確定フラグが `is_confirmed` の1列だけあり、取消は `is_cancelled`。年5日取得義務の警告・勤怠Excelの有給残日数が従来と同じ値になる。
- 論点4の17項目が新しい名前になり、DB・イベント・API・frontend で同じ語(snake/camel)になっている。旧名がコード(migration の履歴を除く)に残っていない。
- 作り直しコマンドで旧項目名のイベントが新項目名に書き換わり、replay 後の ReadModel が書き換え前と同じ値(列名だけ新しい)になることがテストで確認できる。
- docs/20 に命名ルールがあり、docs/16・17・33 が新しい名前になっている。
- backend 全件・frontend 単体全件・型チェック・e2e 全件がPASS。

## 対象外
- 論点8の2件の判定の見直し
- ルールに合う既存の列の改名(論点4の「変えない列」)
- 真偽値以外の列の命名

## ドキュメントへの影響
- docs/16-database-schema.md、docs/17-events.md、docs/20-implementation-notes.md、docs/33-usecases-attendance-external-api.md(上記6)
- docs/27-release-runbook.md: 今回のリリース手順(論点6・7)を追記
- `.claude/skills/data-correction/SKILL.md`: 前例として本コマンドを1行追記

## モック・アセット
なし

## 実装対象
- backend: 新規 migration、`app/` の該当箇所(上記2)、新規 `app/Console/Commands/RenameBooleanEventPropertiesCommand.php`(運用コマンド一覧への登録)、tests
- frontend: `src/api/types.ts` ほか該当箇所、tests、stories、e2e
- docs/skills: 上記

## 検証方法
- backend: CI と同じ条件で全件(`cp .env.example .env && php artisan key:generate && php artisan test`、後で `.env`・sqlite を削除)
- frontend: `npx vitest run --project unit`、`npx tsc -p tsconfig.app.json --noEmit`、e2e 全件
- 作り直しのリハーサル: 旧コードで e2e のデータを作った DB を用意し、基準値を取る → 新コードを配備・migrate → コマンドの試し実行・実行 → 基準値と比較

## レビュー履歴
初版

## 実装結果
未着手
