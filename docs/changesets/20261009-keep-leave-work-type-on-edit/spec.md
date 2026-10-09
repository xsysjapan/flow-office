# 休暇設定(work_type)を休暇イベントから決定し、勤怠編集で消えた過去分を補正する

ステータス: 実装中

## 変更要望(原文)
> 代休バッジは表示されましたが、おそらく代休が設定されていた日の労働時間を更新した際に
> ラベルが取れてしまったような気がします。代休だけでなく有給や、特別休暇もですが、
> 当該日の労働時間を編集しても休暇設定が無効にならないように対応をお願いします。

(レビュー時の追加要望)
> 勤怠入力でwork_typeを設定できるのであれば申請する意味が消えてしまいます。
> また、work_typeを変更することで各休暇のusageが作成されるのか確認してください。
> もし矛盾を発生させるのであれば、画面からの書き換えは無効です。
> 申請不要の場合は登録できても良いかもしれませんが、usageも作成されるようにしてください。

> すでに本番で休暇設定を変更してしまいました。元に戻す方法はありますか？

> Eventと同期して欲しいので、ReadModelのみを変更することはデータ補正であっても許容できません。

> 既存のイベントから、画面からの勤怠更新をしている箇所のwork_typeの使用方法を調べて
> 使用されていなければ、過去に遡って画面からの更新は無かったことにしてください。
> その上でイベントをリビルドしたいです。

> 過去の記録を補正して欲しいです。(補正方法の確認に対し「既存イベントを書き換え」を選択)

## 背景・目的
休暇の設定(`attendance_days.work_type`の休暇値)は休暇申請・取消でのみ変化し、常に休暇の
消化記録(usage)と対応しているべきである。現状は(1)勤怠編集で休暇値が消える、(2)申請なしで
休暇値を書ける、(3)休暇値がイベントに記録されずReadModelだけに直接書かれている、の3点で
勤怠集計・残高・イベント履歴が食い違っている。休暇値をイベントから決まるようにし、本番で既に
消えた休暇値をイベント履歴の補正+リビルドで戻す。

## 現状(As-Is)

### 休暇値の書き込み
- 休暇申請・取消のHandlerは、勤怠側のイベントを記録せずに`attendance_days`を直接
  create/saveして`work_type`を設定・解除している(**設計原則2違反**):
  有給 `RequestPaidLeaveHandler.php:179-193`/`CancelPaidLeaveRequestHandler.php:78,96`、
  特別休暇 `RequestSpecialLeaveHandler.php:156-170`/`CancelSpecialLeaveRequestHandler.php:91-99`、
  代休 `RequestCompensatoryLeaveHandler.php:140-159`/`CancelCompensatoryLeaveRequestHandler.php:66-103`。
  全休では`status`も`clocked_out`に直接書き換えている。対象日の勤怠行が無ければ直接createする
  (`attendance_day.created`イベントが無い行ができる)。
- 休暇側で記録されているイベント(`backend/config/event-sourcing.php`):

  | 操作 | event_class | 勤怠日の特定に使えるpayload |
  |---|---|---|
  | 有給 申請 | `paid_leave_account.usage_designated`(aggregate=userId) | usageId, attendanceDayId, usedOn, usageType |
  | 有給 取消 | `paid_leave_account.usage_cancelled` | usageIdのみ(designatedをusageIdで引く) |
  | 特別休暇 申請 | `special_leave.usage_designated` | userId, attendanceDayId, usedOn, usageType |
  | 特別休暇 取消 | `special_leave.usage_reversed` | userId, attendanceDayId, usedOn, usageType |
  | 代休 申請 | `compensatory_leave.usage_designated` | userId, attendanceDayId, usedOn, usageType |
  | 代休 取消 | `compensatory_leave.usage_reversed` | userId, attendanceDayId, usedOn, usageType |

  承認・差戻しでは`work_type`は変わらない。

### 勤怠編集
- `attendance_day.created`/`attendance_day.edited`のpayloadに`workType`があり、
  `AttendanceDayProjector.php:27-42`(created: updateOrCreate)/`:48-56`(edited: findOrFail)が
  無条件に反映する。作成・編集API(`AttendanceController.php:387,435`)は任意文字列を受け付け、
  未送信はnullになる。
- 日次画面(`AttendanceDayPage.tsx`)は、休暇日の保存で`work_type: null`を送る(`:729`、作成`:898`)。
  休暇日でない日は「作業内容」欄(`:777-782`、作成`:952`)の自由文字列を`work_type`として送る。
  編集フォームの初期値が`day.work_type`(`:695`)のため、休暇日を「休暇なし」に切り替えると
  `paid_leave_full`等が作業内容欄に入った状態になる。
- 一括パターン入力(`GeneratePatternAttendanceDaysHandler.php:100,114`)は常にnullを送る。
  MCPは`work_type`を送らない(未送信=null)。
- 「作業内容」として`work_type`に自由入力するUIは`14b64bc`(2026-08-07)から存在する。
  休暇以外の日の`work_type`(作業内容)は画面表示用途で使われている
  (`AttendanceDayPage.tsx:1464-1469`、`AttendanceReferencePage.tsx:515-520`)。

### usageと集計
- `work_type`を変えてもusageは作られない。残高はusageイベントのみから算出、勤怠集計の
  有給・特別休暇日数は`work_type`から算出(`AttendanceCalculator.php:125-160`)。
  `AttendanceRateAssessor.php:72`・`GrantScheduledSpecialLeaveHandler.php:179-180`も`work_type`を参照。
- 承認不要設定(`*_leave_requires_approval=false`)時も休暇申請APIが申請→承認→usage作成を
  1トランザクションで行い、日次画面の休暇指定UIもこのAPIを呼ぶ。

### リビルド
- 勤怠系Projectorにリセット処理は無い。`projections:rebuild`(Spatie replay)で
  `AttendanceDayProjector`を再生すると、既存行に対し最後の`created`/`edited`の`workType`で
  上書きされ、休暇Handlerの直接書き込みは再現されない。
- `attendance:rebuild-calculation-projections`は記録済みの`attendance_day.calculated`を再生する
  だけで再計算しない。`attendance:recalculate-month-snapshots`は対象月の勤怠を
  `AttendanceCalculator`で再計算する(`work_type`を読む)。
- イベント書き換えの前例: `NormalizeAttendanceCalculationEventsCommand`
  (`#[AdminExecutable]`、既定は件数表示のみ、`--apply`でバックアップテーブル作成→1トランザクションで
  `event_properties`をUPDATE)、`StoredEventHistoryNormalizer`(docs/32)。

## 仕様検討

### 論点1: 休暇値をどこで決めるか(根本対応)
- 選択肢:
  - A. 休暇Handlerの直接書き込みをやめ、`AttendanceDayProjector`が休暇の
    `usage_designated`/`usage_cancelled`/`usage_reversed`を購読して`work_type`(と全休時の
    `status`)を設定・解除する
  - B. 休暇Handlerから勤怠側の新しいCommand/Event(休暇値の設定・解除)を発行する
- 決定: A
- 理由: 休暇イベントは既に本番の`stored_events`に記録されているため、Projectorの改修だけで
  過去分も含めてリビルドで休暇値を再現できる。Bでは過去分のイベントが存在しないため、
  別途イベントを追記する必要がある。
- 未確定・要確認事項: なし

### 論点2: 有給の取消イベント(usageIdのみ)から勤怠日を特定する方法
- 選択肢:
  - A. Projectorが`paid_leave_account.usage_designated`受信時に「usageId→勤怠日」の対応を
    勤怠側のProjectionテーブルに保持し、取消時に引く
  - B. 取消受信時に`paid_leave_usages`(有給側Projection)を参照する
- 決定: A
- 理由: Bはリビルド時に有給側Projectorとの再生順序に依存する。Aは勤怠Projector内で完結し、
  再生順序に依存しない。特別休暇・代休も同じ対応表で扱い、ロジックを揃える。
- 未確定・要確認事項: なし

### 論点3: 休暇申請時に勤怠日の行が無い場合
- 選択肢:
  - A. Projectorが`usage_designated`受信時に、payloadの`attendanceDayId`・対象者・`usedOn`で
    行をupdateOrCreateする
  - B. 休暇Handlerから`CreateAttendanceDay`を発行して先に行を作る
- 決定: A
- 理由: 過去に休暇Handlerが直接作った行(`created`イベント無し)も、既存の`usage_designated`から
  再現できる。Bは過去分を再現できない。
- 未確定・要確認事項: なし

### 論点4: 勤怠の作成・編集での`work_type`の扱い(今後)
- 選択肢:
  - A. `EditAttendanceDay`/`CreateAttendanceDay`に`work_location_type`と同じ
    「`work_type`を変更するか」のフラグ(`workTypeProvided`)を導入し、休暇日の編集では
    Handlerがフラグをfalseにしてイベントに記録する(Projectorは`work_type`に触れない)。
    休暇値でない日に休暇値を送られたら422で拒否する。休暇以外の日の作業内容の自由文字列は
    従来どおり反映する。
  - B. 勤怠の作成・編集イベントの`workType`を常に無視する
- 決定: A
- 理由: 休暇以外の日の「作業内容」は画面で使われている正当な入力のため、Bでは失われる。
  Aなら休暇値は休暇イベントだけが決め、作業内容は従来どおり残る。休暇日の編集は
  「`work_type`を変更しない編集」として記録され、要望どおり画面からの書き換えは無効になる。
- 未確定・要確認事項: なし

### 論点5: 申請不要(承認不要設定)の場合の登録経路
- 選択肢:
  - A. 既存の休暇申請API(承認不要時は申請・承認・usage作成を一括で行う)を唯一の経路とする
  - B. 承認不要時に限り勤怠編集で休暇値を書けるようにし、そこからusageを作る
- 決定: A
- 理由: Aで「申請不要なら登録できる・usageも作られる」が既に満たされ、日次画面の休暇指定UIも
  このAPIを使っている。勤怠編集に休暇ドメインの業務を持ち込まない(原則14)。
- 未確定・要確認事項: なし

### 論点6: 本番で消えた休暇値の補正方法(`data-correction`スキル)
- 選択肢:
  - A. 過去のイベント履歴を直接修正する: 休暇が有効な期間(`usage_designated`以降、取消以前)に
    記録された`attendance_day.edited`/`attendance_day.created`のうち、`workType`が休暇値でない
    ものに`workTypeProvided: false`を付与して「`work_type`を変更しなかった編集」に書き換える。
    その後リビルドする。
  - B. 補正イベントを追記する(休暇値を戻すイベントを新設して記録)
- 決定: A
- 理由:
  - 技術的に可能: 対象イベントは`event_class`と、休暇イベントとの`created_at`の前後関係で
    機械的に特定できる。書き換え後の値(`workTypeProvided: false`)は一意に決まる。論点1〜4の
    改修後にリビルドすれば、休暇値は休暇イベントから再現され、書き換えた編集イベントは
    `work_type`に触れないため、正しい最終状態になる。
  - 改竄の妥当性: 対象の`workType`は、日次画面が休暇日に常にnullを送る不具合(`:729`)・
    一括パターン入力・MCPの未送信によって記録された値で、利用者が「休暇を外す」意図で
    行った操作ではない(休暇の解除は休暇取消という別操作で行われ、usageも残っている)。
    書き換えは不具合で誤って記録された事実を正しく記録し直すものである。
  - ユーザーの許可: 補正方法の確認で「既存イベントを書き換え」が選択された。ただし
    `data-correction`スキルに従い、対象範囲・書き換え後の値を本変更セットで提示したうえで
    改めて明示的な許可を得る(下記「未確定・要確認事項」)。
- 未確定・要確認事項: なし(2026-10-09 ユーザーが「仕様確定事項」の補正の項の対象・範囲・
  書き換え内容でのイベント履歴の直接修正を明示的に許可)

### 論点7: 補正後の再計算
- 選択肢:
  - A. リビルド後、休暇値が変わった日について日次計算を再実行し、月次スナップショットを
    `attendance:recalculate-month-snapshots`で再計算する
  - B. `AttendanceDayProjector`のリビルドのみ行う
- 決定: A
- 理由: 記録済みの日次計算結果・月次スナップショットは誤った`work_type`で算出されており、
  Bでは月次集計の休暇日数が直らない。日次計算の再実行は計算結果の新しいイベントを記録する
  通常の計算処理であり、ReadModelの直接書き換えにはあたらない。
- 未確定・要確認事項: なし(日次計算を再実行する既存コマンドは無いことを確認。
  日次計算は各Handlerが`AttendanceCalculator::calculate()`→`AttendanceDayAggregate::calculate()->persist()`で
  記録しているため、同じ処理を呼ぶ再計算コマンドを追加する)

## 仕様確定事項(まとめ)

### 休暇値の判定
- `work_type`が`paid_leave_`/`special_leave_`/`compensatory_leave_`で始まる値を休暇値とする。
  有給側にも`PaidLeaveType::isPaidLeaveWorkType`を追加し、3種の判定を1か所の静的メソッドに
  まとめる。

### Projector(論点1〜3)
- `AttendanceDayProjector`(または同じ`attendance_days`を扱う勤怠側Projector)が以下を購読する。
  - `paid_leave_account.usage_designated`/`special_leave.usage_designated`/
    `compensatory_leave.usage_designated`: `attendanceDayId`の行をupdateOrCreate
    (対象者・勤務日はpayload、有給はaggregate uuid=userIdと`usedOn`)し、`work_type`を
    `<種別>_leave_<usageType>`に設定する。`usageType`が全休なら`status`を`clocked_out`にする
    (既存Handlerと同じ条件)。usageId→勤怠日IDの対応を勤怠側の対応表に保存する。
  - `paid_leave_account.usage_cancelled`/`special_leave.usage_reversed`/
    `compensatory_leave.usage_reversed`: 対応表から勤怠日を特定し、その`work_type`が当該休暇の
    値であればnullに戻す(既存の取消Handlerと同じ挙動)。
- 休暇の申請・取消Handlerから`attendance_days`の直接create/saveを削除する。Handlerが
  直後に行っている日次計算(`AttendanceCalculator::calculate()`)は、usageイベントの永続化
  (=同期Projectorによる`work_type`反映)の後に行う順序にし、行の取得は永続化後に
  `AttendanceDay`を読み直して行う。
- 対応表はマイグレーションで追加するProjectionテーブルとし、リビルドで再生成できること。

### 勤怠の作成・編集(論点4)
- `CreateAttendanceDay`/`EditAttendanceDay`/`AttendanceDayCreated`/`AttendanceDayEdited`に
  `workTypeProvided`(bool)を追加する。旧イベント(キー無し)は`true`として扱う。
- 編集Handler: 編集前の`work_type`が休暇値なら`workTypeProvided=false`でイベントを記録する
  (送られた値は無視、エラーにしない)。休暇値でない日にコマンドの`workType`が休暇値なら
  ドメイン例外→422(「休暇は休暇申請から設定してください」、フィールド`work_type`)。
  それ以外は従来どおり。
- 作成Handler: コマンドの`workType`が休暇値なら422。
- Projector: `workTypeProvided=false`なら`work_type`に触れない。
- フロント: 編集フォームの`workType`初期値は、`day.work_type`が休暇値なら空文字にする
  (`AttendanceDayPage.tsx:695`)。それ以外の送信値・休暇指定UIは変更しない。

### 補正コマンド(論点6・7)
- `#[AdminExecutable]`の運用コマンドを追加する(前例: `NormalizeAttendanceCalculationEventsCommand`)。
  - 対象: `attendance_day.edited`/`attendance_day.created`で、`workTypeProvided`キーが無く、
    `workType`が休暇値でなく、同じ勤怠日について「`usage_designated`以降・対応する取消以前」に
    記録されたもの(`stored_events.id`の順序で判定)。
  - 書き換え: `event_properties`に`workTypeProvided: false`を追加する(`workType`の値自体は
    残す)。
  - 既定は試し実行で、対象イベントID・勤怠日・利用者・日付・現在の`workType`・期待される休暇値の
    一覧を出力する。`--apply`指定時のみ、バックアップテーブル(名前は引数指定、既存なら拒否)へ
    `stored_events`をコピーしてから1トランザクションで書き換える。
  - 冪等: `workTypeProvided`キーがあるイベントは対象外。
- 日次計算の再計算コマンド`attendance:recalculate-days`(`#[AdminExecutable]`)を追加する。
  `--from`/`--to`(勤務日、必須)と任意の`--user=*`で対象を絞り、既定は試し実行(対象件数・
  勤怠日一覧の表示のみ)、`--apply`で各勤怠日に`AttendanceCalculator::calculate()`→
  `AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist()`を実行する
  (既存Handlerと同じ呼び出し方)。
- 書き換え後の手順(本番): `projections:rebuild AttendanceDayProjector` →
  `attendance:recalculate-days`(補正対象の勤務日の範囲) → `attendance:recalculate-month-snapshots`(対象月)。手順と確認用SELECTを
  `docs/`の運用手順に記載する。
- 本番での実行は、試し実行結果をユーザーが確認してからユーザーの指示で行う。

## 受け入れ条件
- 有給・特別休暇・代休(全休・半休)の日に日次編集API(`work_type` null/未送信/休暇以外の値)で
  出退勤時刻を変更すると、時刻は更新され、休暇値・usage・残高は変化しない。
- 休暇値でない日に作成・編集APIで休暇値を送ると422になり、何も変化しない。
- 休暇値でない日の作業内容(自由文字列)は従来どおり保存・表示される。
- 休暇の申請・取消で`work_type`が設定・解除され、その際`attendance_days`の更新は
  イベント(休暇のusageイベント)経由のProjectorでのみ行われる(Handlerに直接書き込みが無い)。
- 空の`attendance_days`・対応表から`AttendanceDayProjector`をリビルドすると、休暇の
  申請→編集→取消を含むシナリオで、リビルド前と同じ`work_type`・`status`になる(テストで検証)。
- 補正コマンドの試し実行は対象一覧を出すだけで何も変更しない。`--apply`で対象イベントだけに
  `workTypeProvided: false`が付き、バックアップテーブルが作られる。再実行しても対象0件。
  補正+リビルド後、編集で消えていた休暇値が戻り、取消済みの休暇は戻らない(テストで検証)。
- 既存のbackend/frontendテストが全てPASSする。

## 対象外
- 代休の`work_type`が`AttendanceCalculator`の休暇日数集計に含まれていない件(別途仕様判断)
- 日次画面の休暇指定UIの見た目・操作の変更(初期値の修正以外)
- `AttendanceDayPage.test.tsx`の既存`it.skip`の解消
- MCP側(`mcp/`)の変更
- 本番での補正コマンドの実行そのもの(手順の用意まで)

## ドキュメントへの影響
- `docs/07-usecases-attendance.md`: 日次勤怠の作成・編集で休暇値は設定・変更できない
  (休暇日の編集では維持、休暇値の新規指定は422)旨を追記。
- `docs/09-usecases-paid-leave.md`(および特別休暇・代休の該当章): 休暇の申請・取消による
  勤怠の休暇値の設定・解除が、usageイベントを購読するProjectorで行われる旨を追記。
- `docs/17-events.md`: `attendance_day.created`/`edited`の`workTypeProvided`追加と、
  勤怠Projectorが購読する休暇イベントを追記。
- `docs/16-database-schema.md`: 対応表(Projectionテーブル)を追記。
- 補正コマンドの運用手順(実行順・確認用SELECT)を`docs/`の運用手順(docs/32と同じ扱い)に追記。

## モック・アセット
なし

## 実装対象
- backend: 休暇値判定、`AttendanceDayProjector`(休暇イベント購読・`workTypeProvided`)、
  対応表のマイグレーション・モデル、勤怠の作成・編集のCommand/Event/Handler(`workTypeProvided`・
  422)、休暇の申請・取消Handler(直接書き込みの削除)、補正コマンド、(必要なら)日次計算の
  再実行コマンド、テスト
- frontend: `AttendanceDayPage.tsx:695`の初期値とテスト
- docs: 上記「ドキュメントへの影響」

## 検証方法
- `cd backend && php artisan test`
- `cd backend && vendor/bin/pint --test`
- `cd frontend && npx vitest run --project=unit src/pages/attendance` と `npm run lint`

## レビュー履歴
- 2026-10-09 初版(休暇日の編集で休暇値を維持する)
- 2026-10-09 work_type変更ではusageが作られず矛盾することを確認。休暇値の新規指定を422で
  拒否し、申請不要時も休暇申請APIを唯一の経路とする論点を追加。
- 2026-10-09 ReadModelのみの補正は不可(ユーザー指示、CLAUDE.md原則2に追記)。休暇値を
  休暇イベントからProjectorで決める根本対応と、`data-correction`スキルに沿った過去イベントの
  直接修正による補正(ユーザーが補正方法として「既存イベントを書き換え」を選択)に全面改訂。
  タイトルを変更。
- 2026-10-09 ユーザーがイベント履歴の直接修正(補正コマンドの対象・範囲・書き換え内容)を明示的に
  許可し、変更セットを承認。日次計算の再計算コマンドを仕様確定事項に追加。ステータスを実装中に更新。

## 実装結果
未着手
