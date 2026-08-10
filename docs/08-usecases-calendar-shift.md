# 8. カレンダー・勤務形態・シフト管理ユースケース

## UC-C001: 年度カレンダーを作成する

1. 管理者が年度を作成する
2. 会社休日、祝日、年末年始、創立記念日などを登録する
3. 法定休日と所定休日を区別して登録する
4. 年間所定労働日数を計算する
5. カレンダーを公開する

労働基準法上、労働時間は原則として休憩を除き1日8時間・週40時間以内、休日は少なくとも
毎週1日または4週4日が基本となるため、カレンダー設計ではこの前提をチェックできるようにする。
(出典: e-Gov 法令検索)

## UC-C002: 勤務形態を作成する

1. 管理者が勤務形態を登録する
2. 雇用区分(正社員・契約社員・パート・アルバイト・嘱託等)を選択する(`employment_categories`。
   任意設定。労働時間制度とは独立した軸であり、雇用区分だけで残業計算・適用除外を決定しない)
3. 労働時間制度(`work_time_system`: 通常勤務`fixed` / 1か月単位変形労働時間制
   `monthly_variable` / 裁量労働制`discretionary` / 管理監督者`manager_supervisor` /
   フレックスタイム制`flex`)を設定する
4. 所定労働時間、所定休憩、週所定労働時間を設定する。裁量労働制の場合はみなし時間
   (`deemed_daily_minutes`)も設定する。フレックスタイム制の場合は清算期間の起算日
   (`settlement_start_day`)、コアタイム(`core_time_enabled`/`core_time_start`/
   `core_time_end`)、勤務可能時間帯(`flexible_time_start`/`flexible_time_end`)を設定する
   (docs/07-usecases-attendance.md「フレックスタイム制」参照)
5. 対応するカレンダーを紐づける(任意。シフト制などカレンダーに依存しない勤務形態は
   `company_calendar_id`を未設定にできる)
6. 残業計算ルールを設定する
7. シフト制(`is_shift_based`)の場合、法定休日の与え方(`legal_holiday_rule`: 毎週1日
   `weekly` / 4週4日以上の変形休日制`four_weeks_four_days` / 決めない方式`undetermined`)
   を設定する(UC-C005・UC-C007参照)

「正社員・通常勤務」「正社員・裁量労働制」「パート・シフト勤務」のように、雇用区分と
労働時間制度の組み合わせごとに別の`work_styles`レコードとして登録する。シフト制
(`is_shift_based`)はそれ自体が労働時間制度ではなく、`fixed`/`monthly_variable`と組み合わせて
使うスケジュールの与え方である(3交代制など)。

一覧画面(`WorkStylesAndShiftsPage.tsx`)には、指示書16.1節の管理者向け集計列として
適用社員数・使用中の勤務シフト数・設定不備の警告・最終更新日を表示する
(docs/16-database-schema.md「一覧画面の集計列」参照)。

裁量労働制・管理監督者は労働時間の算定方法・残業計算ルールの適用対象から外れる特殊な制度だが、
法定休日を与える義務(UC-C005)は労働時間制と無関係に適用されるため、これらのシフト制勤務者にも
UC-C005のチェックは適用する。

労使協定・本人同意の管理(協定の届出情報、本人同意の取得・撤回等)は本システムのスコープ外
とする。裁量労働制・管理監督者は、みなし時間の計算・残業/休日割増の適用除外という計算ロジック
のみを実装し、適法性の証跡管理は行わない(最終的な適用可否の判断は会社側の責任とする)。

## UC-C003: 働き方ごとのカレンダーを作成する

1. 管理者が勤務形態を選択する
2. 会社カレンダーをベースにする
3. 働き方ごとに勤務日・休日を調整する
4. 年間所定労働日数を確認する
5. 公開する

## UC-C004: 3交代制シフトを作成する

1. 管理者が日勤、準夜勤、深夜勤、公休、明け休みなどのシフトパターン(`shift_patterns`:
   `code`/`name`/`start_time`/`end_time`/`crosses_midnight`/`break_minutes`/
   `prescribed_work_minutes`)を登録する。`prescribed_work_minutes=0`のパターンは
   公休・明け休みなど非労働日を表す
2. 管理者が対象社員・対象日・シフトパターン・(必要なら)法定休日指定を選び、日別に
   シフトを割り当てる(`employee_calendar_entries.shift_pattern_id`)。日跨ぎ勤務
   (`shift_patterns.crosses_midnight`)は`planned_start_at`/`planned_end_at`を
   datetimeで保持することで日付境界のバグを避ける
3. 割り当てた直後は下書き(`is_published=false`)で、対象社員にはまだ表示されない
4. 管理者が対象組織Group/Membershipまたは対象社員・対象月を選び、公開前チェックを
   確認する。法定休日不足(UC-C005のロジックを再利用)・連続勤務日数(`work_styles.
   max_consecutive_work_days`、未設定ならチェックしない)・月間予定時間(週40時間平均の
   法定枠)の3つを警告として表示する。警告があっても後続の操作はブロックしない
5. 対象部署・対象月を指定して公開する。下書き中の該当シフトが`is_published=true`になり、
   対象社員へTeams通知される(`employee_calendar_entry.published`)

シフト生成そのもの(カレンダー日区分に基づく一括生成、UC-C003)と、3交代制のシフトパターン
日別割当は別の入力経路として共存する。同じ`employee_calendar_entries`テーブルを使うが、
`shift_pattern_id`が設定されているかどうかで区別できる。

## UC-C005: シフト制勤務者の月次まとめ承認時に法定休日要件を確認する

1. 承認者が承認待ちの月次勤怠一覧(UC-A009 手順1〜3)を開く
2. 対象社員の勤務形態がシフト制(`work_styles.is_shift_based`)の場合のみ、その勤務形態に
   設定された法定休日の与え方(`work_styles.legal_holiday_rule`)に従って判定する
   - 毎週1日(`weekly`): 対象月に含まれる各週で、法定休日(`employee_calendar_entries.is_legal_holiday`)
     が少なくとも1日与えられているかを確認する。週の起算曜日はカレンダーマスタ
     (`company_calendars.week_starts_on`)に従う
   - 4週4日以上の変形休日制(`four_weeks_four_days`): 勤務形態ごとに設定した起算日
     (`work_styles.four_week_period_start_date`)からの4週間ごとの期間で、法定休日が
     4日以上与えられているかを確認する
   - 決めない方式(`undetermined`): 対象月に含まれる各週で、`LegalHolidayResolver`が
     法定休日を解決できるか(指定または自動推定できるか)を確認する。解決できない週
     (週内に休みの予定が1日も無い週)を不足として警告する(UC-C007参照)
3. 要件を満たしていない週・期間があれば、その期間・不足日数を警告として表示する
4. 警告があっても承認そのものはブロックしない(最終判断は承認者・社労士確認に委ねる)
5. 承認する(UC-A009手順4以降へ)

固定時間制など非シフト制の勤務形態は対象外とする(所定の週休制ですでに法定休日が
確保されている前提のため)。裁量労働制であっても、シフト制(`is_shift_based`)を採用して
いる場合はチェック対象になる(労働時間の算定方法と休日付与義務は別の規制のため)。

判定結果はイベントとして記録せず、月次確認・承認画面の表示のたびに
`employee_calendar_entries` から都度再計算する(UC-A006の警告表示や
`docs/20-implementation-notes.md`の「Projectionは再生成可能」の考え方と同様、
状態変更を伴わない読み取り専用の確認情報のため)。

## UC-C006: 1か月単位変形労働時間制の所定労働時間を編集する

1. 管理者が対象社員・対象日の勤務予定(`employee_calendar_entries`)を選ぶ
2. あらかじめ所定労働時間(始業・終業・休憩)を設定する(特定の日に8時間を超える所定労働時間を
   設定できる)
3. 変更理由を入力する
4. 保存する
5. `employee_calendar_entry.plan_changed` イベントを記録する

以下の場合は編集できない。

- 対象日に既に勤務実績(`attendance_days.actual_start_at`)がある場合。既に発生した時間外労働を
  シフトの事後変更で通常勤務へ振り替えることを防ぐため
  (`docs/20-implementation-notes.md`「シフトの事後変更で残業時間を消去する」の禁止に対応)
- 勤務形態が1か月単位変形労働時間制(`work_time_system=monthly_variable`)の場合、変更後の
  所定労働時間が変形期間(`work_styles.variable_period_start_day`を起算日とする1か月間)全体で
  法定労働時間の総枠(40時間 × 期間日数 ÷ 7)を超える場合

変形期間の起算日を跨ぐ月は、起算日をその月の末日にクランプする(例: 起算日31日で2月を跨ぐ
場合は2月末日を起算日とする)。

日8時間・週40時間の判定は、あらかじめ設定した所定労働時間(`employee_calendar_entries`の
`planned_start_at`/`planned_end_at`/`planned_break_minutes`)が8時間・40時間を超える場合、
その所定時間を超えた部分のみを法定時間外とする(`AttendanceCalculator`の日次判定、
`WeeklyOvertimeCalculator`の週次参考情報のいずれも対象)。所定労働時間を設定していない
(`fixed`など)勤務形態は従来通り8時間・40時間を基準にする。

## UC-C007: 法定休日「決めない方式」の週の法定休日を指定する

1. 社員本人または管理者が、対象社員・対象週(週の起算日`week_start_date`)を選ぶ
2. その週のどの日を法定休日にするかを指定する
3. 指定理由を入力する
4. 保存する
5. `attendance.legal_holiday_designated` イベントを記録する

法定休日「決めない方式」(`legal_holiday_rule=undetermined`)は、どの日が法定休日かを
勤務予定作成時に固定しない。日次・週次の計算(`AttendanceCalculator`/`WeeklyOvertimeCalculator`)
では、`LegalHolidayResolver`が以下の優先順位で対象週の法定休日を解決する。

1. 本ユースケースによる指定(`legal_holiday_designations`)があればそれを使う
2. 指定が無ければ、その週の勤務予定(`employee_calendar_entries.is_working_day=false`)の
   うち、最も遅い日を自動的に法定休日とみなす
3. 週内に休みの予定が1日も無い場合は法定休日を解決できない(UC-C005で警告表示)

指定日はその週(起算日から7日間)の範囲内である必要があり、勤務形態が決めない方式
(`undetermined`)でない場合は指定できない。同じ週への再指定は、既存の指定を置き換える
(履歴は`stored_events`に残る)。指定・再指定によって法定休日の判定が変わるため、その週に
既にある出勤日(`attendance_days`)の日次計算を再実行する。ただし締め・承認済みの日は
対象外とする(`AttendanceEditGuard`)。指定・再指定は対象週を含む月が承認済み以降になるまで
いつでも行える。

## UC-C008: 交代制勤務のローテーションパターンから勤務予定を自動生成する

1. 管理者がシフト制の勤務形態を選び、ローテーションパターン(`rotation_patterns`)を作成する。
   A勤・B勤・C勤・休のような繰り返し周期を、`shift_patterns`から順番(`sequence`、0始まり)に
   選んで登録する(例: A・A・休・B・B・休・C・C・休の9日周期)。A勤・B勤・C勤を別々の
   働き方として作らない(指示書 8.1節)
2. 管理者が社員ごとにローテーション基準(`employee_rotation_assignments`: ローテーション
   パターン・開始日・開始位置)を割り当てる。1人につき現在有効な基準は1件のみで、
   切り替え時は上書きする
3. 保存前に、開始日・開始位置から実際のカレンダーへ展開した結果をプレビューできる
   (`POST /rotation-patterns/{id}/preview`。永続化しない)
4. 管理者が対象期間(開始日〜終了日)を指定して、日別の勤務予定(`employee_calendar_entries`)
   を一括生成する。生成された行は下書き(`is_published=false`)扱いで、UC-C004手順4〜6の
   公開前チェック・公開フローをそのまま利用できる
5. 生成後、個別の日をUC-C004手順3のシフトパターン割当で上書きできる
   (`employee_calendar_entries.is_manually_overridden=true`になる)
6. 再生成時は次のいずれかを選ぶ(指示書 8.8節)
   - 未編集日のみ再生成する(既定・安全側): 個別上書き済みの日は変更しない
   - 個別上書きも含めてすべて再生成する: 個別上書きも生成結果で上書きする
   - どちらのモードでも、既に勤務実績(打刻・実績入力)がある日、および月次承認済み以降で
     ロックされている日(`AttendanceEditGuard`)は自動上書きしない(スキップする)

日跨ぎ勤務(`shift_patterns.crosses_midnight`)は`planned_start_at`/`planned_end_at`を
datetimeで保持することでUC-C004と同じ方法で扱う。勤務日の帰属は勤務開始日を原則とする
(生成した`employee_calendar_entries.work_date`がその勤務の所属日)。

班単位管理(複数社員に同じローテーションを一括割当てる)は将来拡張とし、初期実装は
社員個別の割当のみとする。データモデル(`employee_rotation_assignments`が社員単位で独立)は
将来の班単位拡張を妨げない構造にしている。

## UC-C009: 会社カレンダー本体とカレンダー年度を分離して管理する

1. 管理者が会社カレンダー本体(`company_calendars`: 名称・週起算曜日・タイムゾーン・年度開始月日
   〔`fiscal_year_start_month`/`fiscal_year_start_day`、既定4/1〕・デフォルトフラグ・祝日ソース
   参照などの継続設定)を作成する。本社・支店で祝日の扱いや週起算曜日が異なる場合は本体を
   複数作る(単一組織前提のシステムであり、`company_id`のようなテナント列は導入しない)
2. 本体配下にカレンダー年度(`company_calendar_years`: 年度・開始日・終了日・下書き/公開/廃止の
   状態)を作成する。UC-C001手順1「年度を作成する」はこの本体+年度の2階層で行う。年度の
   `starts_on`/`ends_on`は、作成時点の本体の`fiscal_year_start_month`/`fiscal_year_start_day`
   から計算した確定値として保存する
3. 年度単位で会社カレンダー日(`company_calendar_days`)を登録する。年度は下書きのまま自由に
   編集でき、公開(UC-C001手順5)で初めて対象勤務形態の従業員に適用される
4. 既存年度を複製して翌年度を作成できる(曜日区分のみ引き継ぎ、祝日・会社休日は引き継がない)
5. カレンダー年度を廃止・下書きへの差し戻しができる。ただし対象年度に締め済み月
   (`attendance_months`承認済み以降)が1件でもある場合はどちらも行えない
6. 管理者は本体の`fiscal_year_start_month`/`fiscal_year_start_day`をいつでも変更できる。この
   変更は既に生成済みの年度の`starts_on`/`ends_on`には遡って反映されず、以後新しく生成される
   年度(UC-C011の即時生成・UC-C014の定期バッチ生成)にのみ新しい設定が使われる

`work_styles.company_calendar_id`は年度ではなく本体を参照するため、年度が切り替わっても勤務形態側の
再設定は不要になる。デフォルトカレンダー(`company_calendars.is_default`)は組織内に常に高々1件。

- `GET/POST /api/company-calendars`、`PATCH /api/company-calendars/{id}`、
  `POST /api/company-calendars/{id}/set-default`
- `GET/POST /api/company-calendars/{id}/years`、`POST /api/company-calendar-years/{id}/duplicate`
- `POST /api/company-calendar-years/{id}/publish`・`/unpublish`・`/archive`

## UC-C010: 会社カレンダー日の祝日属性と勤務区分を分離して扱う

1. 会社カレンダー日(`company_calendar_days`)は、祝日か否かという外部由来の事実
   (`is_public_holiday`)と、勤務日にするか所定休日にするかという会社の判断
   (`schedule_state`: `WORK`/`OFF`)を別の列として持つ
2. 管理者が個別の日の`schedule_state`を編集する(変更理由の入力必須)。祝日出勤を会社の
   既定にする場合は`is_public_holiday=true`のまま`schedule_state=WORK`にできる
3. 会社カレンダー日の変更前に、その日を基準に生成済みの従業員予定への影響範囲(対象社員数・
   件数)をプレビュー表示し、必要ならUC-C013の一括操作へ引き継ぐ
4. 生成元(標準生成/祝日同期/手動変更)を新しい順に履歴表示できる

廃止済み年度配下の会社カレンダー日は編集できない。

- `PATCH /api/company-calendar-days/{id}`、`GET /api/company-calendar-days/{id}/impact`・`/sources`
- `POST /api/company-calendar-days/{id}/revert`

## UC-C011: 標準カレンダーを自動生成する(オンボーディング)

1. 管理者がオンボーディングで会社カレンダー本体(デフォルト)を作成した時点では、当年度・
   全日分の会社カレンダー日を同期的には生成しない。生成ロジック自体はUC-C014(定期バッチ)に
   一本化し、標準の曜日ルール(土日=所定休日、平日=勤務日)+祝日ソースがあれば同期する処理を
   入口(オンボーディング/バッチ)ごとに複製しない
2. オンボーディング画面は`GET /api/onboarding/calendar-status`でバッチによる生成状況
   (未生成/生成済み)を表示する。「今すぐ生成する」操作は、次回バッチ実行を待たずに
   UC-C014手順1〜3と同じ生成ロジックをその場で1回実行するもので、バッチと同じべき等性を
   共有する(バッチと二重に生成しない)
3. 生成された年度は下書き状態のまま作成する(自動生成が勝手に本番運用へ影響しないため)。
   管理者がオンボーディング画面でプレビュー→祝日ソース設定の案内→会社独自の休日追加→公開、
   の順に進める。「後で設定する」で公開をスキップでき、その場合カレンダー基準の一括生成
   (UC-C013)は未公開の間実行できない
4. 定期バッチの実行(または「今すぐ生成する」)を待つ間に従業員予定の取得が必要な場合は、
   UC-C014手順5の暫定計算による読み取りフォールバックで対応する(`company_calendar_years`/
   `company_calendar_days`には書き込まない)

- `GET /api/onboarding/calendar-status`、`POST /api/onboarding/calendar/generate-now`・`/skip`

## UC-C014: カレンダー年度を定期バッチで生成する

1. cronで定期実行するバッチ(日次)が、`company_calendars`ごとに以下を確認する
2. その本体に年度が1件も無ければ、本体の`fiscal_year_start_month`/`fiscal_year_start_day`から
   計算した現在年度・次年度を`draft`状態で生成する(標準の曜日ルールで会社カレンダー日を
   作成し、祝日ソースが設定されていれば同期する。祝日同期に失敗しても曜日ルールだけで生成は
   成立させ、祝日反映は次回同期に委ねる)
3. 最新の年度の`ends_on`が今日から6か月以内で、かつ次の年度が存在しなければ、前年度の曜日
   ルール・法定休日ルール・祝日ソース設定を引き継いで次年度を`draft`状態で生成する(単発の
   臨時休業・災害対応休日等、その年度限りの個別上書きは引き継がない)
4. バッチはべき等であり、同じ状態に対して何度実行しても重複生成しない。バッチの実行(全体・
   本体単位のいずれか)が失敗しても、既存の年度データは変更しない(部分適用を避ける)
5. システム初回導入時など、直近のバッチ実行(またはUC-C011「今すぐ生成する」)を待たずに
   従業員予定の取得が必要な場合に備え、対象年月に対応する年度が存在しない読み取り要求が
   来た場合は、読み取り経路側で標準ルール(曜日ルールのみ。祝日ソースは考慮しない)から
   暫定計算した予定を返し、レスポンスに`provisional: true`を含める。これは該当年度がバッチ
   により生成・公開されるまでの間の代替であり、暫定計算の結果は`company_calendar_years`/
   `company_calendar_days`に書き込まない(一括操作プレビュー時の「暫定予定」とは別概念。
   docs/22-glossary.mdの用語整理を参照)

`generated_from`はUC-C011の即時生成・本UC-C014のバッチ生成のいずれも`standard_template`
(生成ロジック自体が共通のため)。バッチによる生成であることは
`company_calendar_year.batch_generated`イベントの記録有無で区別する
(docs/17-events.md参照)。

- (バッチ実行はAPIエンドポイントを持たない。cronジョブから`GenerateCompanyCalendarYears`
  相当のコマンドを直接実行する)

## UC-C012: 祝日iCalendarソースを同期する

1. 管理者が祝日iCalendarソース(`holiday_calendar_sources`: 名称・ics_url)を登録し、会社
   カレンダー本体に紐づける(1本体につき1ソースまで)
2. cronジョブまたは管理者の手動実行で同期する。ics_urlのVEVENTを`ics_uid`単位で
   `holiday_calendar_events`に差分反映し、紐づく全カレンダー年度の`company_calendar_days`の
   `is_public_holiday`・`public_holiday_name`を更新する
3. 対象日の直近の生成元が「手動」の場合は自動上書きせず競合一覧に積み、管理者が日ごとに
   「祝日区分を優先」か「会社の設定を維持」かを選んで解決する
4. 取得・パース失敗時は`sync_status=failed`とし、`company_calendar_days`は一切変更しない
   (部分適用を避ける)。同期実行ごとに、その実行が変更した日だけを同期前の状態へ取り消せる
5. ソースを無効化すると以後の自動同期は停止するが、既に反映済みの祝日データは保持する

同期は`ics_uid`単位で冪等であり、同一フィードを何度同期しても重複登録しない。

- `POST /api/holiday-calendar-sources`、`POST /api/holiday-calendar-sources/{id}/sync`・
  `/disable`
- `GET /api/holiday-calendar-sources/{id}/sync-history`・`/conflicts`、
  `POST /api/holiday-calendar-sources/{id}/conflicts/resolve`
- `POST /api/holiday-calendar-sync-runs/{id}/revert`

## UC-C013: 従業員予定を個別に上書き・複数社員分をまとめて変更する

1. 管理者が対象社員・対象日を選び、従業員予定(`employee_calendar_entries`)の
   `schedule_state`(`WORK`/`OFF`/`LEAVE`)を個別に上書きする(変更理由の入力必須)。
   対象日に既に勤務実績がある場合は編集できない(UC-C006と同じガード)
2. 所定休日への休日出勤の予定(`entry_type=HOLIDAY_WORK`)を登録でき、同時に振替休日
   (`entry_type=SUBSTITUTE_HOLIDAY`)を指定できる
3. 複数社員・複数日にまたがる変更は、一括操作(`calendar_bulk_operations`)としてプレビュー
   →競合ポリシー選択→確定適用→(必要なら)取消、の順で行う。既存UC-C003(カレンダー基準の
   一括生成)・UC-C008(ローテーション一括生成)は、この一括操作の仕組み(それぞれ
   `operation_type=calendar_apply`/`rotation_generate`)を内部的に経由する形に統合するが、
   UC-C003・UC-C008自体の本文・手順は変更しない
4. プレビューは何も保存しない「暫定予定」を返すだけで、確定適用して初めて
   `employee_calendar_entries`に反映される。競合ポリシーの既定(`skip_existing`)は対象日に
   既に行が存在する日をスキップし、行が無い日のみ新規作成する。`overwrite`は個別上書き済み
   かどうかにかかわらず既存行を上書きし、`fail_on_conflict`はプレビュー時点で対象範囲内に
   1件でも既存行があれば一括操作全体を実行不可にする。どのポリシーでも実績あり・締め済みの
   日は常にスキップする
5. 確定適用後は`bulk_operation_id`から生成された従業員予定を逆引きできる。取消は
   `previous_snapshot`の内容へ戻すが、取消時点で実績・締め済みになった対象は取消対象から
   除外し、除外件数を結果に含める(全体を失敗にはしない)
6. 対象日について従業員予定が`UNASSIGNED`(未割当)のままの場合、その日は会社カレンダー日の
   `schedule_state`をそのまま継承した扱いになる(明示的なレコードが無いことと同義ではない)
7. 従業員本人は公開済み(`is_published=true`)の予定のみ閲覧できる。公開・公開取消は既存
   UC-C004手順4〜6を一般化した操作として扱う

- `GET/PATCH /api/employee-calendar-entries`、`POST /api/employee-calendar-entries/holiday-work`
- `POST /api/employee-calendar-entries/publish`・`/unpublish`
- `POST /api/calendar-bulk-operations/preview`、`POST /api/calendar-bulk-operations`
- `GET /api/calendar-bulk-operations`・`/{id}`、`POST /api/calendar-bulk-operations/{id}/revert`

## 注意点

- 法令・就業規則・36協定・変形労働時間制の運用は会社ごとに異なるため、法定休日/所定休日の
  区別や残業計算ルールは全てマスタ化し、ハードコードしない。最終設定は社労士確認を前提とする。
- どの法定休日制度(毎週1日 / 4週4日以上 / 決めない方式)を採用するかは勤務形態ごとに
  異なりうるため、会社単位ではなく`work_styles`にマスタ化する。勤務形態の設定画面にも、
  その勤務形態に適用される休日要件を説明として表示する(UC-C005)。
- 「決めない方式」は労使協定を前提とする制度ではなく、単に法定休日の曜日を固定しない
  運用(シフト制で休日が週によって変わる場合など)を想定している。自動推定・指定の
  ロジックは`LegalHolidayResolver`に集約する。
- 3交代制など日跨ぎ勤務は `planned_start_at` / `planned_end_at` を datetime で保持し、
  日付境界のバグ(深夜0時をまたぐ計算誤り)を避ける。
- UC-C005の法定休日要件チェックは、通常勤務のシフト割当(Phase 4)・3交代制のシフトパターン
  割当(Phase 6、UC-C004)のどちらの`employee_calendar_entries`にも共通して適用される
  ([19-implementation-phases.md](./19-implementation-phases.md) 参照)。

### 会社カレンダー・従業員予定のバリデーション・状態遷移・エラー処理

UC-C009〜UC-C014(会社カレンダー本体・年度、会社カレンダー日、祝日同期、従業員予定、
一括操作、定期バッチ生成)に共通する業務ルール・状態遷移・エラーケースの要点。

**バリデーション**

- 会社カレンダー本体名は組織内で一意。カレンダー年度は同一本体内で年度重複不可、開始日は
  終了日より前。デフォルトカレンダーは常に高々1件。
- `fiscal_year_start_day`は`fiscal_year_start_month`の月内で有効な日であること
  (例: `fiscal_year_start_month=2`のとき`fiscal_year_start_day`に30・31は不可)。
- 会社カレンダー日の`schedule_state`は`WORK`/`OFF`のいずれか必須(未設定を許容しない)。
  祝日(`is_public_holiday=true`)でも`schedule_state=WORK`にできる(祝日出勤運用の許容)。
- 従業員予定は、勤務実績がある日・締め済み以降の月は`schedule_state`・`entry_type`・時刻を
  変更できない(UC-C006と同じ「事後変更で残業時間を消去することを防ぐ」ガード)。
- `HOLIDAY_WORK`(休日出勤予定)は対象日が会社カレンダー上「所定休日」でなければ登録
  できない。`SUBSTITUTE_HOLIDAY`(振替休日予定)は対応する休日出勤予定なしに単独登録
  できない。
- 会社カレンダー日・従業員予定・一括操作のいずれも、変更理由の入力を必須とする。
- 一括操作は、`conflict_policy`によらず勤務実績がある日・締め済み以降の日を常に保護
  (スキップ)する。個別上書き済みの日を上書きするかどうかのみポリシーで変わる。

**状態遷移**

- カレンダー年度: `draft → published → archived`(`archived`からの復帰は無い)。
  `published → draft`(締め済み月が無い場合のみ、下書きに戻せる)。
- 会社カレンダー日の`schedule_state`は`WORK`⇔`OFF`を双方向に遷移できるが、`archived`年度
  配下では遷移不可。
- 従業員予定の`schedule_state`は`UNASSIGNED → WORK/OFF/LEAVE`(個別上書き・一括操作)、
  実績・締めが無ければ再編集可、実績・締めが無い場合に限り取消で戻せる。`is_published`は
  `schedule_state`とは独立した軸(下書き⇔公開済み)。
- 一括操作(`calendar_bulk_operations.status`)は`applied → reverted`のみを許容する終端型の
  遷移(プレビューは永続化しないため状態を持たない)。
- 祝日ソースの`sync_status`は`pending/synced ⇔ failed`、無効化は`sync_status`とは独立した
  フラグとして扱う(直近の同期結果を保持したまま停止する)。
- 会社カレンダー日区分と従業員予定の関係は次の優先順位で解決する: (1) 従業員予定が
  `UNASSIGNED`以外ならその`schedule_state`を使う、(2) `UNASSIGNED`または未作成なら会社
  カレンダー日の`schedule_state`を使う、(3) 勤務形態に`company_calendar_id`が無い場合は従業員予定が
  必須の入力となり、`UNASSIGNED`のままの日は「予定未確定」として警告対象にする。

**主なエラーケース**(`snake_case`のエラーコード)

- `calendar_name_duplicate` / `calendar_year_duplicate` / `calendar_year_invalid_range` /
  `calendar_year_archived_readonly` / `calendar_year_has_closed_months` /
  `calendar_fiscal_year_start_day_invalid`: カレンダー本体・年度の重複・範囲・廃止済み
  書き込み・締め済み月ありでの下書き化/廃止の拒否・年度開始日が開始月内に存在しない設定。
- `calendar_day_reason_required` / `calendar_day_archived_year`: 会社カレンダー日編集時の
  理由未入力・廃止済み年度への書き込み。
- `holiday_source_fetch_failed` / `holiday_source_parse_failed` /
  `holiday_sync_conflict_unresolved`: 祝日同期の取得・パース失敗、未解決競合が残る状態での
  警告。
- `assignment_reason_required` / `assignment_locked_by_actual` /
  `assignment_locked_by_closing` / `assignment_holiday_work_requires_off_day` /
  `assignment_substitute_requires_holiday_work`: 従業員予定編集時の理由未入力・実績ロック・
  締めロック・休日出勤/振替休日の前提条件違反。
- `bulk_operation_reason_required` / `bulk_operation_empty_target` /
  `bulk_operation_calendar_not_published` / `bulk_operation_rotation_not_assigned` /
  `bulk_operation_not_revertible` / `bulk_operation_partial_revert` /
  `bulk_operation_conflict_detected`: 一括操作の理由未入力・対象0件・未公開カレンダー参照・
  ローテーション未割当・取消不可・部分取消(警告付き成功)・`fail_on_conflict`指定時に
  対象範囲内に既存行が1件でもあった場合の実行拒否(1件も適用しない)。
- `onboarding_already_completed` / `onboarding_calendar_unpublished_blocks_generation`:
  標準カレンダー自動生成の冪等応答、未公開カレンダーでの一括生成拒否。
- 共通: `forbidden`(権限マトリクス違反)、`validation_failed`(汎用バリデーション)。
