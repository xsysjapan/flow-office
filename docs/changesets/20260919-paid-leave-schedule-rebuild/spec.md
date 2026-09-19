# paid-leave-schedule-rebuild

ステータス: 実装中

## 変更要望(原文)
> また、過去の予定を完全に削除して再作成できるコマンドを追加してください。

(補足質問への回答)
- 対象: 有給休暇の付与予定(`PaidLeaveSchedule`ドメインの`paid_leave_schedule_entries`)
- 削除方式: `stored_events`は追記のみの原則を維持するため、物理削除ではなく
  「取消イベントを追記して既存エントリを無効化し、新規の付与予定イベントを追記して
  再作成する」方式とする

> 管理画面のUIから実行できるようにしてください。懸念があれば確認をお願いします。
> 管理画面のUIはすでにあるコマンド実行用の画面です

> また、社員を指定せず、ポリシーを指定するだけで更新できるようにしてください

(補足質問への回答)
- 指定対象: 付与ルール(`paid_leave_grant_rules`)を1件選択する方式(法定付与ポリシーの
  バージョン選択は対象外)
- 対象期間: 開始日・終了日を必須入力にする

> 既存の運用コマンド実行画面はselect入力部品を持たない、との確認への回答:
> IDをテキスト入力で構いませんが、空欄の場合は全ポリシーを対象としてください

> また、洗い替えコマンドは予定に対してのみ有効でGrantには影響しないようにしてください。
> 確定済みの予定も削除して構いませんが、grantは外さないでください
> 予定の開始終了も任意にしてください

(補足質問への回答)
- ルールID・開始日・終了日を全て空欄にした場合(全社員・全期間の一括洗い替え)の
  リスクは許容する
- 現在運用中の会社はまだトライアルでデータ量の懸念は不要

## 背景・目的
`docs/changesets/20260916-paid-leave-policy-change-reapply/`で、法定付与ポリシー・
付与ルールの変更時に**未来分**の未確定Scheduleエントリを自動再作成する仕組みを
実装済み(`PaidLeaveScheduleAggregate::recalculateFutureSchedule()`)。しかし対象は
`candidatesFor($user, today, today+1年)`であり、**過去日付のエントリ**は対象外。
過去分のScheduleエントリにデータ不整合(誤ったCommand再生・移行時の取り込みミス等)が
見つかった場合、既存の仕組みでは是正できず、管理者が「どの付与ルールに紐づく社員を」
「どの過去期間について」洗い替えるかを画面から指定して実行できる必要がある。

なお、本要望と並行して、フロントエンドの「付与予定」管理画面
(`/admin/paid-leave/schedule`)は本セッションの別対応で削除済み。本コマンドは
新規UIを作らず、既存の**運用コマンド実行画面**(`/admin/commands`、
`frontend/src/pages/admin/AdminCommandsPage.tsx`)から実行できるようにする。

## 現状(As-Is)
- `backend/app/Domain/PaidLeaveSchedule/Aggregates/PaidLeaveScheduleAggregate.php`
  - `recalculateFutureSchedule(array $candidates, string $reason, bool $overrideManualEdits = false)`:
    候補配列と既存の未確定エントリ(`FINALIZED_STATUSES`=`Granted`/`Cancelled`以外)を
    比較し、内容一致→no-op、内容不一致→既存エントリをSupersede(取消)して新規候補で
    再作成、候補消滅→Cancel、という洗い替えロジックを既に持つ。ただし呼び出し元
    (`RecalculateFutureScheduleHandler`)は常に`today`以降の候補のみを渡すため、
    過去日付のエントリはこのロジックの対象になったことがない。
  - `FINALIZED_STATUSES`チェックのみで過去/未来の日付そのものによる除外は行っていない
    ため、aggregateのメソッド自体は過去日付候補を渡しても動作する見込み(要実装時検証)。
- `backend/app/Domain/PaidLeaveSchedule/Commands/RecalculateFutureSchedule.php` /
  `Handlers/RecalculateFutureScheduleHandler.php`: 対象ユーザー・候補配列・reason・
  overrideManualEditsを受け取り、aggregateへ橋渡しするだけの薄いハンドラ。
- `backend/app/Domain/PaidLeaveSchedule/Support/ScheduleCandidateGenerator.php`の
  `candidatesFor($user, $from, $to)`: 期間を指定してその時点のルール・法定Policyから
  候補を再計算するステートレスな実装。`$from`/`$to`に過去日付を渡すこと自体は
  シグネチャ上制限されていない(要実装時確認)。
- 既存の取消・再作成イベント(Supersede実行時に発行されるイベント。命名・実装は
  `PaidLeaveScheduleAggregate`内、`docs/17-events.md`の該当箇所を実装時に確認)を
  そのまま流用できる想定。
- 既存の類似運用コマンド: `backend/app/Console/Commands/MigratePaidLeaveAccountsCommand.php`
  (社員ごと一度限りの移行用Artisanコマンド、JSON入力)、
  `backend/app/Console/Commands/RollPaidLeaveSchedulesCommand.php`(日次cronで未来月分の
  Schedule生成のみを行う。既存エントリの見直しはしない)。
- `Granted`(付与済み・確定済み)エントリは原則1・14により無条件では書き換えない
  (取消は別途`PaidLeaveGrantRevoked`等、Account側の専用取消操作が既に存在する)。
- Granted状態のScheduleエントリとAccount側`paid_leave_grants`の関係(調査結果):
  - Scheduleエントリが`Granted`になるのは`PaidLeaveScheduleAggregate::grantEntry()`が
    発行する`PaidLeaveScheduleEntryGranted`イベント(`grantId`・`operatorUserId`を保持)。
    `PaidLeaveScheduleProjector`がこの`grantId`を`paid_leave_schedule_entries.grant_id`列に
    保存する(Aggregate内部状態には保持しない)。
  - Scheduleエントリの取消・置き換え時に発行される`PaidLeaveScheduleEntryCancelled`・
    `PaidLeaveScheduleEntrySuperseded`イベントは、いずれも`grantId`を含まない。
  - `App\Domain\PaidLeaveAccount`側にはSchedule側イベントを購読するReactor/Listenerが
    存在せず、`paid_leave_grants`は`PaidLeaveAccountAggregate`自身のイベント
    (`PaidLeaveGrantCreated`等)のみで更新される一方向の関係。
  - 結論: `FINALIZED_STATUSES`ガードで`Granted`エントリを`recalculateFutureSchedule()`/
    `cancelEntry()`の対象に含めても、アーキテクチャ上Account側`paid_leave_grants`への
    書き込みは一切発生しない(イベントに`grantId`が乗らず、Account側がSchedule側の
    イベントを監視していないため)。
- `backend/app/Domain/PaidLeaveSchedule/Support/ScheduleCandidateGenerator.php`の
  `matchingRuleFor(?WorkStyle $workStyle): ?PaidLeaveGrantRule`(private):
  対象社員の`WorkStyle`に一致する`work_style_id`固定の有効ルールを優先し、
  無ければ`work_style_id`がnullの全社共通ルールをフォールバックとして採用する。
  現状privateであり、外部(社員側からルールを逆引きする用途)からは呼べない。
- 既存の**運用コマンド実行画面**の実装一式(調査結果):
  - UI: `frontend/src/pages/admin/AdminCommandsPage.tsx`(ルート`/admin/commands`、
    管理メニュー「システム」グループ内「運用コマンド」)。パラメータ入力は
    `#[AdminExecutable(ui: [...])]`属性で宣言した`checkbox`/`year-month`/`text`の
    3種類のcontrolのみサポート。
  - Backend: `app/Http/Controllers/Api/AdminCommandController.php`
    (`GET /admin/commands`・`GET /admin/command-runs`・
    `POST /admin/commands/{command}/runs`)。実行は`App\Jobs\RunAdminCommandJob`が
    DBキュー経由で`Artisan::call()`を呼ぶ非同期方式。
  - 登録方式: `app/Console/Commands/`にArtisan Commandクラスを作り、
    `#[AdminExecutable(label: ..., rules: [...], ui: [...])]`属性を付けるだけで
    `AdminCommandRegistry`のリフレクション自動検出により画面に現れる
    (例: `MigrateAttendanceWorkClassificationsCommand.php`)。
  - 権限: `admin_command.view`(閲覧)・`admin_command.execute`(実行)、
    前提フィーチャー`administration.settings`。
  - 監査: 実行のたびに`admin_command_runs`テーブルへ実行者・パラメータ・
    ステータス・出力/エラーが記録される(既存の仕組みをそのまま利用できる)。
  - 実行前確認: `ConfirmActionDialog`(「処理はDBキューへ投入されます」の
    確認ダイアログ)が既に共通で表示される。
  - 重複実行防止: `without_overlapping`オプションで`Cache::lock()`による
    1時間ロックが可能(既存の仕組み)。

## 仕様検討

### 論点1: 対象とするエントリの範囲(ステータス)
- 選択肢:
  - A. `FINALIZED_STATUSES`(`Granted`/`Cancelled`)以外の未確定エントリのみを
       洗い替え対象とする(`recalculateFutureSchedule`と同じ考え方)
  - B. `Granted`(付与済み・確定済み)も含めて洗い替え対象とする。ただしAccount側
       `paid_leave_grants`(実際の付与)には一切影響させない
  - C. `Granted`も含めて洗い替え対象とし、紐づくAccount側の付与も
       `RevokePaidLeaveGrant`で連動して取り消す
- 決定: B
- 理由: ユーザーから「確定済みの予定も削除して構わないが、Grantは外さないでほしい」と
  明示的な指示があった。調査の結果、Schedule側の取消・置き換えイベント
  (`PaidLeaveScheduleEntryCancelled`/`PaidLeaveScheduleEntrySuperseded`)は
  `grantId`を保持せず、`App\Domain\PaidLeaveAccount`側もSchedule側イベントを
  一切購読していないため、`Granted`エントリを取消+再作成してもAccount側
  `paid_leave_grants`への書き込みはアーキテクチャ上発生しない。これによりBが
  安全に実現できる。Cはユーザーの明示的な指示に反するため採用しない。
  `Cancelled`(既に取消済み)は再度取消す意味が無いため、引き続き対象外とする
  (対象は`Granted`を含む「`Cancelled`以外の全ステータス」)。

### 論点2: 対象範囲の指定方法
- 選択肢:
  - A. 社員ID(複数可)+対象期間(開始日・終了日)を必須指定させる
  - B. 付与ルール(`paid_leave_grant_rules`)のIDを1件指定+対象期間(開始日・終了日)を
       必須指定させ、そのルールが現在マッチする社員を自動的に対象とする
  - C. 全社員一括・期間指定なし(全期間)で洗い替えを行う
  - D. Bをベースに、ルールIDを**省略可能**にし、未指定時は対象社員条件を満たす
       全社員(全ルール横断)を対象とする
- 決定: D
- 理由: ユーザーから「社員を指定せず、ポリシーを指定するだけで更新できるように」に
  続けて「(ルール)IDが空欄の場合は全ポリシーを対象としてください」との追加要望が
  あった。ルールを絞った是正(特定ルール変更時の是正)と、全社的な洗い替え
  (是正内容が広範囲に及ぶ場合)の両方を1つのコマンドでカバーできる。対象期間は
  誤操作の影響範囲を限定するため、ルールID指定の有無に関わらず必須のまま維持する
  (ユーザー確認済み)。

### 論点2b: 「付与ルールにマッチする社員」の解決方法
- 選択肢:
  - A. `ScheduleCandidateGenerator::matchingRuleFor()`を`public`(または`protected`+
       新規publicメソッド経由)に変更し、`RollPaidLeaveSchedulesCommand`と同じ対象社員
       条件(`employment_status=active`かつ`hire_date`設定済みかつ
       `paid_leave_auto_grant_enabled=true`)の全社員について
       `matchingRuleFor(currentWorkStyleFor($user))`を呼び、解決結果のルールIDが
       選択したルールIDと一致する社員だけを対象とする
  - B. `paid_leave_grant_rules.work_style_id`が指定ルールと一致する`WorkStyle`を持つ
       社員だけをSQLで直接絞り込む(全社共通ルールへのフォールバック判定は行わない)
- 決定: A
- 理由: Bは「specific work_style向けの有効ルールが無い場合に全社共通ルールへ
  フォールバックする」という`ScheduleCandidateGenerator`の既存マッチングロジックを
  再実装することになり、ロジックの二重管理・将来の仕様変更時の不整合リスクを生む。
  Aは既存のマッチングロジックをそのまま再利用でき(原則9「業務ロジックを複製しない」に
  合致)、全社共通ルール(`work_style_id=null`)を選択した場合も「specific work_style
  向けルールが存在しない社員」だけを正しく対象にできる。
  `matchingRuleFor()`の可視性変更(`private`→`internal`用に`public`化、またはテスト用
  トレイトへの切り出し)は実装時の詳細としてimplementerに委ねる。
  ルールID未指定時(論点2決定D)は、この絞り込みを行わず対象社員条件を満たす全社員を
  対象とする。

### 論点2c: ルールID入力部品と未指定時の扱い
- 選択肢:
  - A. 既存の運用コマンド実行画面(`/admin/commands`)が持つ`checkbox`/`year-month`/
       `text`の3種類のUI controlのみで実装する(ルールIDは`text`で数値文字列として
       入力させ、空欄可とする)
  - B. `AdminExecutable`のUIフレームワーク自体に`select`(ドロップダウン)controlを
       新設し、付与ルール一覧を取得して選ばせる
- 決定: A
- 理由: ユーザーから「IDをテキスト入力で構わない、空欄の場合は全ポリシーを対象に」と
  明示的な指示があった。Bは既存の共通UIフレームワーク(他の運用コマンドにも影響する
  横断的な変更)を拡張する必要があり、本変更セットのスコープを超える。
- 懸念事項: `/admin/paid-leave`(付与ポリシー画面)には現状ルールIDが画面上に
  直接表示されておらず、ルール編集リンク(`/admin/paid-leave/rules/{id}/edit`)の
  URLからIDを読み取る必要がある(視認性が良くない)。本変更セットのスコープでは
  この表示改善は行わない(対象外に明記)が、運用上わかりにくい場合は別途
  「ルール一覧にIDを表示する」変更セットを検討されたい。

### 論点2d: 対象期間(開始日・終了日)を任意にした場合の扱い
- 選択肢:
  - A. 開始日・終了日それぞれ未指定時は、社員ごとに「入社日」〜「今日+1年」
       (既存の`RollPaidLeaveSchedulesCommand`等が使う未来分生成の範囲と同じ考え方)を
       デフォルトとして使う
  - B. 開始日・終了日とも未指定時は、社員の`paid_leave_schedule_entries`に実在する
       最古の`scheduled_on`〜最新の`scheduled_on`を動的に取得して範囲とする
  - C. 開始日未指定は`1900-01-01`等の固定の遠い過去日、終了日未指定は`9999-12-31`等の
       固定の遠い未来日を使う(実質無制限)
- 決定: A
- 理由: ユーザーから「期間も任意にしてほしい」「トライアル運用中でデータ量の懸念は
  不要」との明示的な指示があり、全項目空欄(全社員・全期間の一括洗い替え)を許容する
  前提。Bは対象範囲が既存データに依存し、`ScheduleCandidateGenerator::candidatesFor()`
  はそもそも「入社日以降」の範囲でしか意味のある候補を生成しないため、既存データの
  最小/最大日付を動的取得する複雑さに見合わない。Cは`candidatesFor()`が内部で
  `hire_date`以前を弾く実装(調査済み)のため、`1900-01-01`を渡しても実質的にAと
  同じ結果になる一方、日付として不自然で意図が読み取りにくい。Aは各社員にとって
  意味のある最大範囲(入社日〜合理的な未来の境界)をそのまま使えるため、
  最も自然かつ安全な既定値。

### 論点3: 実行経路
- 選択肢:
  - A. 新規Artisanコマンドを、サーバー運用者がCLIから実行する(UIなし)
  - B. 既存の運用コマンド実行画面(`/admin/commands`、`AdminCommandController`)に
       `#[AdminExecutable]`属性付きのArtisanコマンドとして登録し、管理者がWeb UIから
       実行する
- 決定: B
- 理由: ユーザーから「既にある運用コマンド実行用の管理画面から実行できるように」との
  明示的な指示があった。既存の仕組み(`admin_command.execute`権限チェック、
  `admin_command_runs`への実行監査ログ、実行前確認ダイアログ、DBキュー経由の
  非同期実行)をそのまま流用でき、新規のAPI・認可・監査の仕組みを別途作る必要がない。

### 論点4: 取消+再作成に使うイベント
- 選択肢:
  - A. 既存の`recalculateFutureSchedule`が内容不一致時に使っているSupersede
       (取消+新規作成)の仕組みをそのまま流用する(新規イベント種別を追加しない)
  - B. 過去分洗い替え専用の新規イベント(例: `PaidLeaveScheduleEntryRebuilt`)を追加する
- 決定: A
- 理由: 「取消イベントを追記して無効化し、再作成イベントを追記する」という
  ユーザー要望は、既存のSupersede実装(内容不一致時の洗い替え)と同じ設計。
  対象が過去日付であること以外に業務的な違いはないため、新規イベント種別を
  増やさず既存の仕組みを再利用する(原則2の「Projectionは再生成可能な派生データ」
  にも合致し、イベント種別の重複を避けられる)。

### 論点5: 「完全に削除」の対象(候補が消滅した場合の扱い)
- 選択肢:
  - A. 再計算後の新しい候補と一致しない既存エントリは全て取消(Cancel/Supersede)し、
       新しい候補で完全に置き換える(既存の`recalculateFutureSchedule`と同じ挙動)
  - B. 新しい候補が0件になった場合は既存エントリを取消するだけで再作成しない
- 決定: A
- 理由: ユーザー要望が「完全に削除して再作成」であり、対象期間・対象社員に対する
  Scheduleエントリをその時点の最新ルール・ポリシーに基づいて洗い替える操作として
  一貫させる。候補が0件(例: 対象外の期間)であれば結果的に取消のみになるのは
  `recalculateFutureSchedule`の既存挙動と同じであり、特別扱いしない。

## 仕様確定事項(まとめ)
- 新規Artisanコマンド`RebuildPaidLeaveScheduleCommand`(シグネチャ
  `paid-leave:schedule:rebuild`)を`backend/app/Console/Commands/`に追加し、
  `#[AdminExecutable]`属性を付けて既存の運用コマンド実行画面(`/admin/commands`)に
  登録する。
  - オプション: `--rule-id=<int>`(省略可。`text` control)、`--from=<YYYY-MM-DD>`
    (省略可。`text` control)、`--to=<YYYY-MM-DD>`(省略可。`text` control。指定時は
    `from`以降)、`--reason=<text>`(必須、取消イベントの理由として記録)。
    - `rules`: `'rule-id' => ['nullable', 'integer', 'exists:paid_leave_grant_rules,id']`、
      `'from' => ['nullable', 'date_format:Y-m-d']`、
      `'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']`、
      `'reason' => ['required', 'string', 'max:255']`。
    - `ui`: `rule-id`/`from`/`to`/`reason`いずれも`text` control(既存3種類の中で
      日付・自由入力に対応できるのは`text`のみ。`year-month`は年月単位でしか
      指定できず日単位の`from`/`to`には使えないため採用しない)。
  - 対象社員の決定:
    - `--rule-id`指定時: `RollPaidLeaveSchedulesCommand`と同じ対象社員条件
      (`employment_status=active`かつ`hire_date`設定済みかつ
      `paid_leave_auto_grant_enabled=true`)を満たす社員のうち、
      `ScheduleCandidateGenerator`の`matchingRuleFor(currentWorkStyleFor($user))`
      (可視性を実装時に見直す。論点2b)の解決結果が指定ルールIDと一致する社員のみ。
    - `--rule-id`未指定時: 上記の対象社員条件を満たす全社員(論点2の決定D)。
  - 対象期間の決定(論点2d): 社員ごとに`$from`は指定値、未指定なら`$user->hire_date`、
    `$to`は指定値、未指定なら`today + 1年`を用いる。
  - 処理: 対象社員ごとに`ScheduleCandidateGenerator::candidatesFor($user, $from, $to)`
    で対象期間の候補を算出し、`RecalculateFutureSchedule($userId, $candidates, $reason, overrideManualEdits: true, includeGrantedEntries: true)`
    を発行する(`overrideManualEdits: true`により個別修正済みエントリも、
    `includeGrantedEntries: true`により`Granted`エントリも洗い替え対象に含める)。
  - `PaidLeaveScheduleAggregate::recalculateFutureSchedule()`に第4引数
    `bool $includeGrantedEntries = false`を追加する(デフォルト`false`で既存呼び出し元
    ―`RecalculateScheduleOnUserConditionChangedReactor`・
    `RecalculateScheduleOnWorkStyleChangedReactor`・`ReapplyPaidLeaveSchedulePolicyJob`―
    の挙動は変更しない)。`true`の場合、`FINALIZED_STATUSES`の判定を
    `Cancelled`のみに縮小し(`Granted`は対象に含める)、内容不一致の`Granted`エントリも
    通常のSupersede処理(既存エントリを`PaidLeaveScheduleEntrySuperseded`で無効化し、
    新しい候補を`PaidLeaveScheduleEntryCreated`で作成)に乗せる。この際、
    `PaidLeaveScheduleEntrySuperseded`/`PaidLeaveScheduleEntryCancelled`は`grantId`を
    含まない既存のイベント構造のまま変更しない(論点1の安全性根拠)ため、
    Account側`paid_leave_grants`への書き込みは一切発生しない。
  - `Cancelled`エントリは常に対象外のまま(再取消の意味が無いため)。
  - 対象期間に過去日付を含められるよう、`recalculateFutureSchedule`/`candidatesFor`が
    過去日付を理由に候補生成・比較をスキップしていないことを実装時に確認し、
    もしガードが存在する場合はコマンド呼び出し経路に限り除去する
    (`RollPaidLeaveSchedulesCommand`等、既存の未来月生成用途の挙動は変更しない)。
  - 1社員の失敗が他社員に影響しないよう、`RollPaidLeaveSchedulesCommand`と
    同様に社員単位でtry/catchし、失敗はログ(`report($e)`)+コンソール出力で継続する。
    実行結果(対象社員数・成功/失敗件数)はコンソール出力に残し、
    `admin_command_runs`の実行ログ(既存の仕組み)にも出力として記録される。
  - 実行前確認は、既存の運用コマンド実行画面が共通で表示する`ConfirmActionDialog`
    (「処理はDBキューへ投入されます」)にのみ委ね、コマンド側で対話的な確認
    (`$this->confirm()`)は行わない。UI経由の実行は`RunAdminCommandJob`が
    非対話的に`Artisan::call()`するため、対話的な確認プロンプトは機能しない
    (標準入力を待てず、ジョブがハング/失敗する)。
- 新規イベント種別・新規Command種別は追加しない(既存の`RecalculateFutureSchedule`
  Command・既存のSupersede/Cancelイベントを流用する。論点4)。`RecalculateFutureSchedule`
  Commandクラスに同名の`bool $includeGrantedEntries = false`プロパティを追加し、
  `RecalculateFutureScheduleHandler`からaggregateへ橋渡しする。

## 受け入れ条件
- 管理画面(`/admin/commands`)に「有給休暇付与予定の再作成」(仮称)コマンドが表示され、
  `admin_command.execute`権限を持つ管理者が付与ルールID(省略可)・開始日(省略可)・
  終了日(省略可)・理由(必須)を入力して実行できる。
- ルールIDを指定して実行すると、そのルールに現在マッチする社員のうち、対象期間内の
  `Cancelled`以外の(`Granted`を含む)Scheduleエントリが取消され、最新のルール・
  ポリシーに基づく新しいエントリが作成される(`stored_events`に取消イベント・
  作成イベントが追記される。既存イベントの削除・書き換えは発生しない)。
- ルールID・開始日・終了日を全て空欄にして実行すると、対象社員条件を満たす全社員
  について、各社員の入社日〜今日+1年の範囲で同様の洗い替えが行われる。
- `Granted`エントリが洗い替え対象になった場合でも、Account側`paid_leave_grants`
  (実際の付与レコード)は一切変更されない(取消・作成いずれのイベントも発生しない)。
- 同一パラメータで再実行し、内容に変化が無い場合は取消・再作成が発生しない(冪等)。
- `reason`が未指定、または`to`が`from`より前の場合(両方指定時のみ判定)、実行は
  失敗し(バリデーションエラー)何も変更されない。存在しない`rule-id`を指定した
  場合も同様に失敗する。
- 対象社員の一部でエラーが発生しても、他社員の処理は継続され、実行結果に
  成功/失敗の内訳が残る。
- 実行のたびに`admin_command_runs`に実行者・パラメータ・結果が記録される。
- 関連する自動テスト(新規追加分・既存のPaidLeaveSchedule関連)が全てPASSする。

## 対象外
- `AdminExecutable`のUIフレームワークへの`select`(ドロップダウン)control新設
  (論点2c。既存の`text`/`checkbox`/`year-month`のみで実装する)。
- 付与ポリシー画面(`/admin/paid-leave`)でのルールID表示改善(論点2c懸念事項。
  必要であれば別変更セットで対応)。
- Account側の実際の付与(`paid_leave_grants`)の取消・変更(論点1。是正が必要な場合は
  既存の`RevokePaidLeaveGrant`を使う)。
- 新規イベント種別の追加(論点4)。
- スケジューリング(cron等での定期自動実行)。本コマンドは運用者が都度手動実行する
  想定であり、自動トリガーは設けない。

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`: 管理画面(`/admin/commands`)から過去分Schedule
  エントリを取消+再作成できるコマンドの存在・実行方法(ルールID・開始日・終了日は
  いずれも省略可)・対象範囲(`Granted`も対象、ただしAccount側の実際の付与には
  影響しない)を追記する。
- `docs/17-events.md`: 変更なし(新規イベント種別を追加しないため)。
- 他のdocsファイルは変更なし。

## モック・アセット
なし

## 実装対象
- `backend/app/Console/Commands/RebuildPaidLeaveScheduleCommand.php`(新規、
  シグネチャ`paid-leave:schedule:rebuild`、`#[AdminExecutable]`属性付与)
- `backend/app/Domain/PaidLeaveSchedule/Aggregates/PaidLeaveScheduleAggregate.php`
  (`recalculateFutureSchedule()`に第4引数`bool $includeGrantedEntries = false`を追加。
  `true`時は`FINALIZED_STATUSES`判定を`Cancelled`のみに縮小する)
- `backend/app/Domain/PaidLeaveSchedule/Commands/RecalculateFutureSchedule.php`
  (同名の`includeGrantedEntries`プロパティ追加)
- `backend/app/Domain/PaidLeaveSchedule/Handlers/RecalculateFutureScheduleHandler.php`
  (追加引数をaggregateへ橋渡し)
- `backend/app/Domain/PaidLeaveSchedule/Support/ScheduleCandidateGenerator.php`
  (`matchingRuleFor()`を対象社員解決に再利用できるよう可視性・呼び出し口を調整。
  論点2b)
- テスト:
  - `backend/tests/Unit/PaidLeaveSchedule/PaidLeaveScheduleAggregateTest.php`に
    `includeGrantedEntries: true`で`Granted`エントリが洗い替えられること・
    デフォルト(`false`)では従来どおり`Granted`が保護されることの単体テストを追加
  - `backend/tests/Feature/Console/RebuildPaidLeaveScheduleCommandTest.php`(新規):
    ルールID指定時に該当社員のみが対象になること、ルールID未指定時に全対象社員が
    対象になること、開始日・終了日を省略した場合に社員ごとの入社日〜今日+1年が
    使われること、過去日付・`Granted`エントリの取消+再作成、冪等性(2回目no-op)、
    `Granted`エントリ洗い替え後もAccount側`paid_leave_grants`が一切変更されないこと、
    `reason`未指定・存在しないrule-idでのバリデーションエラーを検証
  - `backend/tests/Feature/AdminCommand/`配下の既存テストパターンに沿って、
    `POST /admin/commands/{command}/runs`経由での実行(`AdminCommandController`・
    `RunAdminCommandJob`込み)も1件検証する
- `docs/09-usecases-paid-leave.md`の追記

## 検証方法
- `cd backend && php artisan test --filter=RebuildPaidLeaveScheduleCommand`
- `cd backend && php artisan test --filter=PaidLeaveSchedule`
- `cd backend && php artisan test --filter=AdminCommand`
- `cd backend && php artisan test`(フルスイート)

## レビュー履歴
- 初版。AskUserQuestionでの確認により、対象=有給休暇付与予定(Scheduleドメイン)、
  削除方式=取消イベント追記+再作成イベント追記、の2点を確定済み。
- 追記1: 「管理画面のUIから実行できるようにしてほしい(既存のコマンド実行用画面を
  使う)」との要望を受け、実行経路をArtisan CLI単体からの実行(論点3旧決定A)から
  既存の運用コマンド実行画面(`/admin/commands`)への`#[AdminExecutable]`登録
  (論点3決定B)に変更。UI経由の非対話実行のため、CLIの対話的確認プロンプト
  (`$this->confirm()`)は撤回し、既存UIの`ConfirmActionDialog`に委ねる方式に変更。
- 追記2: 「社員を指定せず、ポリシーを指定するだけで更新できるように」との要望を
  受け、対象範囲の指定方法を社員ID指定(論点2旧決定A)から付与ルールID指定
  (論点2決定B→D)に変更。ルールにマッチする社員の解決方法を論点2bとして追加。
- 追記3: AskUserQuestionでの確認により、指定対象=付与ルール(法定付与ポリシーの
  バージョンではない)、対象期間の開始日・終了日は必須、の2点を確定。
- 追記4: 「既存のコマンド実行画面はselect入力部品を持たない」ことを確認し
  AskUserQuestionで確認したところ、「IDをテキスト入力で構わない、空欄の場合は
  全ポリシーを対象に」との回答を得たため、論点2の決定をD(ルールID省略可)に、
  論点2cを新設して`text` control採用を確定。
- 追記5: 「洗い替えコマンドは予定に対してのみ有効でGrantには影響しないように
  してください。確定済みの予定も削除して構いませんが、grantは外さないでください」
  「予定の開始終了も任意にしてください」との要望を受け、調査により`Granted`エントリの
  取消・置き換えイベントがAccount側`paid_leave_grants`に波及しない設計であることを
  確認。論点1の決定をB(`Granted`も対象、Accountには無影響)に変更し、
  `includeGrantedEntries`引数を新設。対象期間(開始日・終了日)も省略可能とし、
  未指定時のデフォルト範囲を定める論点2dを新設(決定A: 入社日〜今日+1年)。
  AskUserQuestionにより、全項目空欄時の広範囲な一括洗い替えのリスクは許容する旨、
  および対象企業がトライアル運用中でデータ量の懸念が無い旨を確認済み。

## 実装結果
未着手
