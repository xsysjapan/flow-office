# boolean-column-naming(真偽値の列・イベント項目の命名の統一と、paid_leave_usages の確定フラグの二重化の解消)

ステータス: 実装中

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
全て `boolean NOT NULL`、model の casts は全て `boolean`、API 応答は true/false。mcp・docs/25 からの列名の利用は無い。docs/33 は外部連携の `enabled` に2か所言及。
**リポジトリ外の利用者**: 共有Android打刻リーダー(端末アプリ、docs/23:54-68)が、打刻 API(`DevicePunchController.php:55,80` の入力 `offline`、`AttendancePunchResource.php:26` の出力 `offline`)と、端末のペアリング・心拍の応答(`DeviceController::claimPairing`:179・`heartbeat`:332-345 が返す `DeviceResource` の `allow_offline`・`require_location`・`auto_detect_punch_type`)を使う。
命名の内訳: `is_*` 15種、`*_enabled` 7種+`enabled` 1種、`requires_*`/`*_requires_approval` 9種、`allow*` 3種、その他(接頭辞なし・命令形)13種。
イベントとの関係: 同名(snake)のキーが payload の配列内にある 26組、camelCase の同義プロパティがある 17組、別名 2組、payload に無い(ReadModel のみ)26組。

## 設計前提の検証
- 正データと派生データ:
  - `paid_leave_usages` は ReadModel(イベントから再生成)。確定の正は `PaidLeaveUsageConfirmed` イベントの有無で、列は2つとも派生。派生列が二重になっているだけなので、列を1つにしてリビルドすれば正しい状態になる。
  - 改名対象の列のうち、Projector が書く表は ReadModel(リビルドで再生成)。docs/16:1653 は `devices`・`paid_leave_usages`・`authentication_key_device_rules` を「正データ(Command経由)」に分類しているが、
    実際は Command→イベント→Projector で書かれ、正はイベント(設計原則1・2)。docs/16:1653 の分類を「イベントが正、表は Projector が書く ReadModel」に直す。
    打刻(`attendance_punches`)・勤務予定は設計原則3・10で「正」とされるが、その正はイベント(`AttendancePunchRecorded` 等)であり、表は Projector がイベントの集約IDをキーに書く。
    キー名の書き換えは id・事実・順序を変えないため、打刻の追記のみの原則(設計原則10)に反しない。`system_settings`・`external_integration_connections`・`asset_number_rules` は管理APIから直接更新する正データ(設計原則1の例外。監査イベントを同時に記録)で、列の改名は migration の `renameColumn` でデータを保ったまま行う。
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
  集約 `PaidLeaveAccountAggregate` の内部状態のキー(`confirmed`/`cancelled`)は永続化されない内部の配列で、同じ意味の配列キーを `UnallocatedUsageAllocator` も使っているため、変えない(命名ルールの対象は列・イベント項目・API・frontend の型)。
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
  | イベントの中だけにある「その時点の事実」 | `was*`(過去形)も可 | `wasReissued`、`wasFirstLogin`(既存。変えない) |
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
  | 8 | work_styles.system_generated | is_system_generated | Projector | WorkStyleCreated の `attributes` 配列のキー `system_generated`→`is_system_generated`(Updated は `UpdateWorkStyleHandler.php:22` がこのキーを除くため入らない) |
  | 9 | attendance_daily_calculations.core_time_violation | has_core_time_violation | Projector | AttendanceDayCalculated の `calculation` 配列のキー `core_time_violation`→`has_core_time_violation` |
  | 10 | group_types.primary_membership_required | requires_primary_membership | Projector | GroupTypeCreated/Updated `primaryMembershipRequired`→`requiresPrimaryMembership` |
  | 11 | role_assignments.include_descendants | includes_descendants | Projector | RoleAssignmentCreated/Updated `includeDescendants`→`includesDescendants` |
  | 12 | leave_attendance_rate_days.attended、special_leave_attendance_rate_days.attended | has_attended | Projector | なし |
  | 13 | leave_attendance_rate_attendance_days.clocked_out、special_leave_attendance_rate_attendance_days.clocked_out | has_clocked_out | Projector | なし |
  | 14 | authentication_key_device_rules.allow | allows_use | 書き手なし(読み取りのみ。`AuthenticationKey.php:107`) | なし |
  | 15 | external_integration_connections.enabled | publishing_enabled | 管理API直接(正データ)+監査イベント | ExternalIntegrationConnectionCreated の `after`、Updated の `before`/`after`、Deleted の `before` のキー `enabled`→`publishing_enabled` |
  | 16 | asset_number_rules.enabled | is_active | Handler直接(正データ)+監査イベント | AssetNumberRuleConfigured `enabled`→`isActive` |
  | 17 | system_settings.prohibit_self_privileged_role_assignment | prohibits_self_privileged_role_assignment | 管理API直接(正データ)+監査イベント | SystemSettingsUpdated の `before`/`after` 配列のキー(同名)を新しい名前に |

- 変えない列(ルールに合う): `is_*` 全般、`*_enabled`(`notification_mail_enabled`・`m365_mock_enabled`・`core_time_enabled`・`auto_break_enabled`・`compensatory_leave_enabled`・`*_auto_grant_enabled`)、
  `requires_*`・`*_requires_approval`、`crosses_midnight`、`is_holiday_day`。`users.*_auto_grant_enabled` のイベント(`PaidLeaveAutoGrantEnabledSet.enabled` 等)はイベント名が対象を表しているので変えない。
- #15 を `is_active` にしない理由: 同じ表に `status`(`active`/`disconnected`)があり、`is_active=false かつ status=active` という紛らわしい状態ができる。この列は送信(連携の公開)をするかの設定なので `*_enabled`。
- 理由: 表の各列は、接頭辞なしの名詞・過去分詞(`offline`・`attended`・`cancelled`)、命令形(`allow`・`require_*`・`prohibit_*`)、受け身の後置(`*_required`)、
  レコード有効化への `enabled` で、論点3のルールに外れる。
- 未確定・要確認事項: なし

### 論点5: イベントの作り直しの方法(data-correction)
- 選択肢:
  - A. `stored_events` の作り直し(新しい項目名で記録されていた状態に置き換える)をコマンドで行い、旧名の読み替えをコードに残さない
  - B. イベントクラスで旧名・新名の両方を読めるようにする(upcaster)
  - C. 移行イベントを追記する
- 決定: A。`data-correction` の方法2(イベントの改名は作り直し。旧系統の読み替え・移行イベントを残さない)を、イベントの数・順序が変わらないため id を保ったまま行ごとの書き換えで行う。
- 理由: 改名は機械的に決まり、過去の事実が決められない部分は無い。
- 変換規則:
  - 対象: 論点4の表の「イベント側の項目」欄にあるイベントクラスの `stored_events` 行(`event_class` で特定)。
  - `event_properties` の JSON で、表に書いたキー(トップレベルのプロパティ、または表に書いた配列 `attributes`・`calculation`・`before`・`after` の中のキー)だけを新しい名前にする。値は変えない。
    配列そのものが無い行(Created の `before`、Deleted の `after` 等)は何もしない。表に無いイベント・キーは変えない(同じ名前のキーが他のイベントにあっても触らない)。
  - 旧キーが無い行(項目追加前の古いイベント): 現在の Projector が欠落時に使っている値で新キーを補う(`CompanyCalendarCreated/Updated` の `allowsDailyHolidayOverride` は true(`?? true`)、
    `AttendancePunchRecorded` の `isOffline` は false、`calculation.has_core_time_violation` は false)。補った後は、改名したプロパティから既定値を外し必須にする
    (書き換え漏れや旧キーのイベントが黙って既定値に化けないように。spatie の逆シリアライズは無いキーを既定値にする)。補う値の一覧は実装時に Projector の `??` から機械的に洗い出し、実装結果に列挙する。
  - 冪等: 新キーが既にある行は何もしない(配備後に新キーで記録された行を含む)。
  - id・集約ID・`aggregate_version`・`created_at`・`meta_data` は変えない。`source_event_id` 等の stored_events への参照も変わらない。事前条件として `snapshots` が0行であることを確かめる(`EventStoreRebuildWriter.php:289` と同じ)。
  - 変換前の内容は、実行前に作るバックアップ表(`CREATE TABLE ... LIKE` + `INSERT ... SELECT`。表名を記録)で残す。補正ログ `stored_event_corrections` には書かない
    (書くと `EventStoreRebuildWriter::preconditionViolations`(:272)が `leave:rebuild-event-store` を拒否するため)。
- 実行: 運用コマンド `events:rename-boolean-properties {--execute}` を新設し、`MAINTENANCE_COMMANDS`(`ReadModelRebuildRunService`)に加える。試し実行が既定(対象の event_class ごとの件数、補う件数、旧→新の例)。
  `--execute` は1つのトランザクションで「書き換え → `ReadModelRebuilder::rebuild` → 検証(論点7)」を行い、検証に失敗したら全て戻す。件数の多いイベント(`attendance_day.calculated`・`attendance_punch.recorded`)を含むため、書き換えのループで心拍を打つ。
  本番は SSH で実行する(論点6。メンテナンス中は管理画面が使えない)。管理画面の運用コマンド一覧には、他環境・リハーサル用に載せる。
- 旧キーが残っている間の保護: 前例 `LegacyBackOfficeAssignmentDetector` と同じく、対象の event_class に旧キーを持つ行がある間は `read-models:rebuild`・`projections:rebuild` を拒否する。`event-sourcing:replay` は使わない(手順書に書く)。
- 未実行の移行コマンドとの順序: 旧キーのイベントを読む移行(`leave:rebuild-event-store` 等)が未実行の環境では、それらを旧コードで先に実行してから本変更を配備する(手順書に書く)。
- 再現性: 書き換え後に全 ReadModel をリビルドすると、全表の内容が書き換え前と一致する(列名だけ新しい。論点7)。
- 削除するもの: 旧名の読み替えは作らない。作り直しのコマンドは他環境の実行のため本リリースでは残し、次のリリースで削除する。
- 未確定・要確認事項: なし

### 論点6: リリース手順(配備と作り直しの順序)
- 決定: docs/27 §3.2 の前例と同じく、1回のメンテナンス窓で次の順に行う。
  0. リハーサル(本番の写しで): 所要時間・`max_binlog_cache_size`・メモリを測る。
  1. 旧コードで、基準値の取得と `read-models:rebuild --dry-run`(既存のずれが0であること)。
  2. `php artisan down`、cron(`schedule:run`)とキュー(`queue:work`)を止め、端末・API・MCP の受付を止める。
  3. frontend と backend を同じ窓で配備し、migration を実行する(列の改名・`paid_leave_usages.confirmed` の削除。正データの3表は `renameColumn` でデータを保つ)。
  4. SSH で `events:rename-boolean-properties` を試し実行 → `--execute`(書き換え・ReadModel の作り直し・検証)。
  5. `php artisan up`、cron・キューを再開。
- 理由: `read-models:rebuild` のメンテナンスはコマンドの実行中しか効かず(`ReadModelRebuildRunService::isRunning()`)、配備から書き換えまでの間に新しいコードが旧キーのイベントを読むと
  必須項目が無くて例外になる(`DeviceAggregate::retrieve`・ロール割当・働き方の更新、毎分の cron)。そのため `down` と cron・キューの停止で全体を止める。
  frontend と backend を同じ窓で配備するのは、旧画面が旧キーを送って設定が既定値に戻るのを防ぐため(論点10)。
- 切り戻し: 検証に失敗した場合はコマンドのトランザクションが戻る。配備後に戻す場合は、(a) `stored_events` をバックアップ表から戻す、(b) `migrate:rollback`(down で `confirmed` を作り直した後 `UPDATE paid_leave_usages SET confirmed = is_confirmed`)、
  (c) 旧コードを配備して `read-models:rebuild`。手順書に書く。
- 本番の実行には、リハーサルと試し実行の結果を示したうえでユーザーの明示的な許可を得る(`data-correction`)。
- 未確定・要確認事項: なし

### 論点7: 検証
- 決定:
  - コマンドの合格条件: (1) 対象の event_class に旧キーを持つ行が0件、(2) `ReadModelRebuilder` の結果で全表の内容が書き換え前と一致(`changed_tables == 0`。列名の変更は比較の前に新しい列名へ対応づける)。
  - 手順書の確認: 旧コードで取った基準値(改名する各列の表ごとの真/偽の件数、`paid_leave_usages` は旧 `is_confirmed`・`cancelled`)と、新コードで新しい列名で取った値が一致。手順書には、各手順をどちらのコード・DBで実行するかを書く。
- 未確定・要確認事項: なし

### 論点8: 調査中に見つかった関連の疑い(別扱い)
- `AttendanceDifferenceDetector.php:80-81` が休暇の重なり判定で usages の行の存在だけを見ており、取消済み(paid の `is_cancelled`)や未確定の行も重なりとして扱う可能性がある。
- `PaidLeaveController::usagesForUser`(:818)が未確定の usage も返している(意図どおりかは画面側の使い方次第)。
- 決定: 本変更セットでは列名の置き換えだけ行い、判定の中身は変えない。2件は不具合かどうかの確認が要るため、ユーザーに報告し別の変更セットで扱う。
- 未確定・要確認事項: なし

### 論点9: 端末アプリ(リポジトリ外)との互換
- 選択肢: A. 互換期間を設ける / B. 端末向け API は改名しない / C. 端末アプリと同時に一斉改名 / D. 端末の項目は改名の対象外
- 決定: A(ユーザー回答「互換期間を設ける」)
- 細則: DB・イベント・管理画面の API と画面は新しい名前にする。端末アプリが使う API だけ、旧名と新名の両方を受け、両方を返す:
  - 打刻(`DevicePunchController`)の入力: `is_offline` を正とし、無ければ `offline` を読む(両方あって値が違えば 422)。出力(`AttendancePunchResource`)は `is_offline` と `offline` の両方。
  - ペアリング・心拍の応答(`DeviceController::claimPairing`・`heartbeat` が返す端末設定): 新名(`allows_offline`・`requires_location`・`punch_type_auto_detection_enabled`)と旧名の両方を返す。
  - 旧名の扱いは端末向けの Resource/Controller の1か所にまとめ、「端末アプリの更新後に削除する」コメントを付ける。旧名の受付の終了は、端末アプリの更新後に別の変更セットで行う(docs/23 に互換期間中である旨を書く)。
- 未確定・要確認事項: なし

### 論点10: 管理画面 API で旧名を送られたとき
- 決定: 管理画面向けの入力 API(端末設定・会社カレンダー・グループ種別・ロール割当・外部連携・採番ルール・システム設定)では、旧名のキーを `prohibited`(422)にする。
- 理由: 新 backend は旧名を無視するため、旧画面(開いたままのタブ)が旧名で送ると、設定が既定値に戻ったり(`DeviceController.php:214-216` の `?? true`)、黙って更新されなかったりする。
  失敗させて再読み込みを促す。frontend と backend は同じメンテナンス窓で配備する(論点6)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
1. migration(新規1本): 論点4の表の列を改名(`renameColumn`)し、`paid_leave_usages.confirmed` を削除する。down は逆の改名と `confirmed`(boolean default false)の再作成。
   削除前に `is_confirmed <> confirmed` の件数をログに出す。
2. backend のコード: 論点4の表の列・イベントのプロパティ/配列キーを新しい名前に置き換える(models の fillable・casts、Projector、Controller の validate、Resource、Handler、Command、seeder、factory)。
   レビューで分かった直接参照(漏れやすいもの): `Rebuild/Reconcile/GroupTypeReconciler.php`・`RoleAssignmentReconciler.php`、`EffectiveAccessResolver.php:59`、`ExternalIntegrationConnectionController::toResource`(:181)・`auditPayload`(:165)、
   `ApplyExternalHrImportHandler.php:61`、`ExternalIntegrationPublisherResolver.php:105`、`IssueAssetNumberHandler.php:30,35`、`AuthenticationKey.php:107`、`AbstractLeaveAttendanceRateProjector.php:262,349,357`、
   `GrantScheduledSpecialLeaveHandler.php:175`、`AttendanceRateAssessor.php:77`、`FlexSettlementSummaryCalculator.php:61`、`UpdateCompanyCalendarDaysHandler.php:84`、`PrivilegeAssignmentPolicy.php:13`、`SystemSetting.php:87`、
   OpenAPI の注釈(`CompanyCalendarController` :83,141 ほか)。実装時に旧名ごとに grep し、残してよい箇所(migration の履歴・作り直しコマンドの変換表・端末互換・別の意味の同じ語)を実装結果に列挙する。`PaidLeaveUsageAllocationProjector` は `is_confirmed`・`is_cancelled` だけを書き、`:39` のコメントを直す。
   `WarnFiveDayObligationHandler` は `is_confirmed`・`is_cancelled` で判定。
3. API: validate・Resource のキーを新しい名前にする(入力を受けるもの: devices の3項目、company_calendars、group_types、role_assignments、external_integration_connections、asset_number_rules、system_settings)。
   管理画面向けは旧名を `prohibited`(論点10)。端末向け(打刻・ペアリング・心拍)は互換期間として旧名も受け・返す(論点9)。
4. frontend: `api/types.ts` ほか型・画面・テスト・stories・e2e のキーを新しい名前にする。
5. 運用コマンド `events:rename-boolean-properties {--execute}`: 論点5の変換規則・検証(論点7)。`MAINTENANCE_COMMANDS` と管理画面の運用コマンド一覧に追加。旧キーが残っている間 `read-models:rebuild`・`projections:rebuild` を拒否する検出器を追加。
6. ドキュメント: docs/16(列名・`paid_leave_usages` の is_confirmed/is_cancelled の説明・:1653 の分類)、docs/17(イベントの項目名)、スキル `api-design`(`20261123-boolean-query-param` が作る)の「命名」の節に命名ルール(論点3)を追記(列・イベント項目の命名は `add-projection`・`add-domain-event` から参照)、
   docs/33(外部連携の `enabled`→`publishing_enabled`)、docs/23(端末向け API の互換期間)、docs/07:354・docs/19・docs/22・docs/31・docs/34:225-232・docs/testing/scenario-tests.md の旧名。
   `.claude/skills/add-domain-event`・`add-projection` から命名ルールへの参照を1行。
7. テスト:
   - migration と作り直しコマンド: 旧イベントの生の行(旧項目名)を入力に、試し実行(件数・変換前後)→ 実行 → 全ReadModelを空にしてreplay → 新しい列に期待値が入る、まで通すテスト(論点4の各イベントを1件以上)。
     **入力の値は既定値と逆にする**(`offline=true`、`allowDailyHolidayOverride=false`、`core_time_violation=true`、`allowOffline=false` 等。既定値と同じだと書き換え漏れでも通るため)。
     旧キーの無い古いイベントに論点5の値が補われる。旧キーと新キーの行が混在しても冪等(2回目は変換0件)。対象外のイベント・同名キーが変わらない(例: `PaidLeaveAutoGrantEnabledSet.enabled`)。
     旧キーが残っている間 `read-models:rebuild` が拒否される。`changed_tables == 0` を確かめる。
   - API: 管理画面向けで旧名が 422(論点10)。端末向けで旧名・新名の両方を受け、両方を返す(論点9)。`EffectiveAccessResolver` の出力と `auditPayload` が新しいキー。
   - paid_leave_usages: 指定→確定→取消の各段階で `is_confirmed`・`is_cancelled` が期待どおり、`WarnFiveDayObligationHandler` と Excel の残日数が確定かつ未取消だけを数える(既存テストの列名の置き換え)。
   - 既存の backend 全件・frontend 単体全件・e2e 全件がPASS。

## 受け入れ条件
- `paid_leave_usages` に確定フラグが `is_confirmed` の1列だけあり、取消は `is_cancelled`。年5日取得義務の警告・勤怠Excelの有給残日数が従来と同じ値になる。
- 論点4の17項目が新しい名前になり、DB・イベント・API・frontend で同じ語(snake/camel)になっている。旧名ごとの grep で、残っているのが実装結果に列挙した「残してよい箇所」だけである。
- 端末アプリ向けの API が旧名・新名の両方で動く。
- 作り直しコマンドで旧項目名のイベントが新項目名に書き換わり、replay 後の ReadModel が書き換え前と同じ値(列名だけ新しい)になることがテストで確認できる。
- スキル `api-design` に命名ルールがあり、docs/16・17・33 が新しい名前になっている。
- backend 全件・frontend 単体全件・型チェック・e2e 全件がPASS。

## 対象外
- 論点8の2件の判定の見直し
- ルールに合う既存の列の改名(論点4の「変えない列」)
- 真偽値以外の列の命名

## ドキュメントへの影響
- 上記6の docs、skills
- docs/27-release-runbook.md: 今回のリリース手順(論点6・7)と切り戻しを追記
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
- 初版
- 2026-11-23 提示前の独立設計レビュー(general-purpose)の指摘を反映(ユーザー確認: 端末アプリは「互換期間を設ける」):
  - 高: 端末アプリ(リポジトリ外)が改名対象を使う → 論点9(互換期間)
  - 高: 旧キーが黙って既定値に化ける → 欠落キーの補完と既定値の除去、旧キー0件を合格条件に(論点5・7)
  - 高: 旧画面の旧キー送信で設定が既定値に戻る → 管理画面 API は旧名を prohibited、frontend と backend を同じ窓で配備(論点10・6)
  - 高: 配備から書き換えまでメンテナンスが効かない、切り戻しが無い → docs/27 §3.2 と同じ down・cron/キュー停止・SSH 実行、切り戻し手順(論点6)
  - 高: 既定値と同じ入力のテストは漏れを捕まえない → 入力を既定値と逆に
  - 中: 直接参照の列挙の誤り・漏れ、docs の漏れ、docs/16:1653 の分類、rebuild の拒否、規模の見積もり、補正ログを使わない、全表一致(changed_tables==0)→ 反映
  - 中: #15 `is_active` は `status` と紛らわしい → `publishing_enabled`。低: #14 は `allows_use`、#8 は Created のみ、イベントだけの `was*` をルールに追記、集約の内部キーは変えない

## 実装結果
未着手
