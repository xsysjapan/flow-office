# PR#121のリリースのリハーサル手順書を作り、リハーサルで見つかった手順の不備を直す

ステータス: 完了

## 変更要望(原文)
> リハーサル手順を作成したうえで対応をお願いします。

(対象: 2026-10-23に、PR#121のリリースのリハーサルで受けた報告。問題1〜3と、テストの未完了の事項。
報告の内容は「現状」にまとめた。)

## 背景・目的
- PR#121は、2つのリリースの手順を含む。
  - `docs/27-release-runbook.md` 3.2: 有給口座・付与予定のAggregateIdの分離。
  - 同 3.3: 年度はじめの一斉付与、付与日数の表の区分。
- 報告者は、本番のダンプからSSOの設定を除いた一時的な複製を、隔離したDBに取り込んでリハーサルした。
- 手順書のとおりには、合否の判定ができない点が2つ見つかった。
- このリリースの専用のリハーサル手順書を作る。手順書(3.2・3.3)と比較のツールを直し、次のリハーサルと本番で、同じ手順と合否の条件を使えるようにする。

## 現状(As-Is)と原因
### 問題1: 基準A(付け替え前の指紋)が、手順書のとおりには取れない
- 手順書3.2の手順8は、「付け替えの前(手順4の前)に、全ReadModelを空にして再生し、指紋を取る(基準A)」と書いている。
  手順3で新しいコードを配備した後なので、新しいコードで再生することになる。
- 新しいコードは、付け替えの前のイベントを読めない。
  - 例: `paid_leave_account.usage_designated`(stored event 1627)のpayloadには`userId`が無い。新しい`PaidLeaveUsageDesignated`では、`userId`が必須。
  - 付け替え(`PaidLeaveStreamRekeyer`)で、旧`aggregate_uuid`から`userId`を補う設計だから。
- 手順書3.2の冒頭の「新しいコードは、付け替えの前の履歴を正しく読めない」と矛盾していた(手順8を書いたときの見落とし)。
- 報告者は、旧コードで別の隔離DBを作り、そこで基準Aを取った。このやり方が正しい。

### 問題2: 年度設定の表`paid_leave_fiscal_year_settings`の`last_changed_event_id`だけが違った
- 比較の結果
  - 付与予定の2つの表: 24件→0件、16件→0件(想定どおり)。
  - 85の表: 一致。
  - `paid_leave_fiscal_year_settings`: カレンダーのID・年度開始の月日は一致。`last_changed_event_id`だけが違った。
- 原因
  - migration(`2026_10_21_000005`)は、この表の初期値を今の既定のカレンダーから入れ、`last_changed_event_id`をnullにする。
  - イベントから再生すると、`PaidLeaveFiscalYearSettingsProjector`がイベントのidを入れる。
- この列は、`stored_events.id`を指すメモ。年度開始日の変更のReactorが、これからのイベントと突き合わせるためだけに使う(名前だけの変更と区別する)。過去の値がnullでもidでも、動きは変わらない。
- 比較のツール(`ReadModelFingerprinter`)は、「`stored_events.id`を指す列」を比べないと決めている。しかし、除く列の一覧(`EXCLUDED_COLUMNS`)には`stored_event_id`しか無く、この列を入れ忘れていた。
- 本番では、手順3.2の7(全ReadModelの再生)で、この表もイベントから作り直される。そのため、リリースの後の本番の行は、再生の結果と同じになる。

### 問題3: 一斉付与日が4月から5月へ変わるという警告
- 全社共通ルール(id=1)の一斉付与の月は4月、既定の会社カレンダーの年度開始は5月だった。migration `2026_10_21_000002`が警告を出した。
- ユーザーの確認(2026-10-24): 付与日は5/1で正しい。仕様どおりなので、直すものは無い。
- 今回のデータでは、自動付与の対象者が0名だった(`paid-leave:schedule:reapply-policy`・`roll-schedules`とも0名・0件)。
  - 社員への影響は、このデータでは確かめられていない。
  - 本番のデータで影響を数える手順(個人情報を出さない)が、手順書に無い。

### そのほかの未完了の事項
- backendの全件のテスト: 報告者の環境で、`CompensatoryLeaveRequestAggregateTest::test_request_is_recorded_once`の実行中にPHPのプロセスが途中で終了した。
  - このテストの単独の実行は、成功している。
  - このテストは、PR#121で変更していない。
  - CI(`php artisan test`)と、委譲元の環境の全件(2117件)は、最後まで完走している。
  - 原因は、報告者の環境にあると見られる。調べるには、次の情報が要る。
    - 実行したコマンド
    - 終了コード・シグナル
    - PHPのバージョン・メモリの設定
- E2E: 報告者の環境では、localhost:8000が使用中で、実施できなかった。

## 仕様検討

### 論点1: 基準Aを取る方法
- 選択肢:
  - A. 旧コードで取る。手順2のバックアップを、同じサーバーの別のDBに戻す。手順3(新しいコードの配備)の前に、今動いている旧コードで、そのDBのReadModelを空にして再生し、指紋を取る。
  - B. 本番のDBで、旧コードで全ReadModelを空にして再生し、指紋を取ってから配備する。
  - C. 基準Aは取らず、本番の今のReadModelとBを比べる。
- 決定: A。
  - 別のDBを作れないとき(XSERVERのDBの作成の権限・空き容量は**要確認**)は、Bにする。その場合は、本番で実行する前に、ユーザーの許可を取る。
  - 旧コードで別のDBを動かすときは、環境変数で接続先を変える(`DB_DATABASE=<基準AのDB> php artisan ...`)。Laravelは、`.env`より環境変数を優先する。
  - 実行の前に、接続先を必ず確かめる(`php artisan db:show`、または`SELECT DATABASE()`)。
- 理由:
  - 新しいコードは、付け替えの前の履歴を読めないので、旧コードで取るしかない。
  - Aは本番のDBに触れない。Bは本番のReadModelを作り直すので、手順2のバックアップを戻す以外に戻し方がない。
  - Cは、本番のReadModelと再生の結果の差(別件)が混ざり、付け替えによる差だけを見られない。
- 対象外: 旧コードの`--readmodel-fingerprint`・`--list-readmodels`は、mainにある(PR#121の前から)。旧コードの変更はしない。
- 未確定・要確認事項: XSERVERで、別のDBを作れるか(本番の前に確かめる)。

### 論点2: `paid_leave_fiscal_year_settings`の差の扱い
- 選択肢:
  - A. `last_changed_event_id`を比較のツールの除く列(`EXCLUDED_COLUMNS`)に足す。手順書の合否の条件に書く。
  - B. migrationの初期値を、再生と同じ値(年度開始日が最後に変わったイベントのid)にする。
  - C. 比較のツールは変えず、手順書でこの列の差を許す。
- 決定: A。あわせて、次の2つを合否の条件に書く。
  - この表は、このリリースで新しくできる表なので、基準A(旧コード・旧スキーマ)には無い。そのため、Aとは比べない。
  - かわりに、Bの行が既定の会社カレンダー(`company_calendars`の`is_default=1`の行)と一致することを、SQLで確かめる。比べるのは、カレンダーのID・年度開始の月・日。
  - 一致しなければ止める。
- 理由:
  - この列は`stored_events.id`を指す列で、比較のツールが決めている「比べない列」の種類に当たる。イベントの作り直しでidが振り直されたときも、同じ理由で比べられない。
  - Bは、migrationで過去のイベントをたどる処理が要る。手順3.2の7でイベントから作り直されるので、効果がない。
  - Cは、ツールの出力では差のある表に見えるので、毎回人が判断することになり、見落としやすい。
- 対象外: Projector・Reactorの変更。
- 未確定・要確認事項: なし

### 論点3: 一斉付与日の変更の影響の数え方
- 決定: リハーサル手順書と手順書3.3に、次の数え方を書く。個人を特定できる値は出さない。
  - migrationの出力に出た、ルールの名前と月(ルールの名前は、個人情報ではない)。
  - migrationの前に、SQLで件数だけを数える。
    - 在籍中(`employment_status='active'`)で、自動付与が有効(`paid_leave_auto_grant_enabled=1`)、入社日がある社員の数。
    - 付与予定の、状態ごとの件数。
  - `paid-leave:schedule:reapply-policy`の出力の「作り直した社員」と「付与予定日・日数が変わった行」の件数。
  - 一斉付与の月が、年度開始の月と違うルールに当たる社員の数。ルールの選び方は複雑なので、SQLでは数えない。かわりに、付与予定画面の絞り込みと、作り直しの出力の件数で確かめる。
- 理由: 社員への影響は、付与予定の作り直しを通じてしか出ない(手動の付与には影響しない)。作り直しの出力の件数が、そのまま影響の件数になる。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `docs/changesets/20261024-paid-leave-release-rehearsal/assets/rehearsal-checklist.md`(新規。委譲元が書く)
  - PR#121のリリース(3.2・3.3)のリハーサル手順書。
  - 安全ルール、SSOの設定を除いた複製、旧コードでの基準A、新しいコードでの手順、合否の条件、影響の数え方、結果の記録欄。
- `backend/app/Domain/EventSourcing/Rebuild/ReadModelFingerprinter.php`
  - `EXCLUDED_COLUMNS`に`last_changed_event_id`を足す。
  - docコメントの「2026-10-13時点では stored_event_id のみ」を直す。
  - `--readmodel-diff`(`ReadModelDiffer`)も、同じ除く列を使うか確かめる。使っていなければ、同じにする。
- テスト
  - `tests/Unit/EventSourcing/Rebuild/ReadModelFingerprinterTest.php`: `last_changed_event_id`だけが違う2つの行が、同じ指紋になる。
  - `ReadModelDifferTest.php`: 同じ条件で、差が0件になる(Differが同じ一覧を使う場合)。
- `docs/27-release-runbook.md`
  - 3.2の手順8を、論点1のとおりに直す(基準Aは手順2と3の間に、旧コードで取る)。合否の条件に、論点2を足す。
  - 3.3に、論点3の影響の数え方を足す。
  - リハーサルは、リハーサル手順書に従うと書く。

## 受け入れ条件(本番の前)
- リハーサル手順書のとおりに、もう一度リハーサルする。次をすべて満たす。
  - 基準A(旧コード)とB(新しいコード、付け替え・再生の後)の指紋の差が、次の表を除いて0件。
    - 付与予定の2つの表(`paid_leave_schedule_entries`・`paid_leave_schedule_assessments`。Bでは0件)。
    - `paid_leave_fiscal_year_settings`(Aには無い。Bが既定の会社カレンダーと一致すること)。
  - 付け替えの検査(`--verify`)が通る。
  - 影響の件数を記録し、ユーザーが確認する。
- backendの全件のテストが、CIと同じ条件(`.env`と`php artisan test`)で完走する。止まったときは、終了コード・シグナルを記録して報告する。
- E2E(`scenario-00`・`03`・`08`・`15`)を、ポートが空いている環境で実行し、PASSする。

## 対象外
- 本番のDBへの変更。
- 元のダンプ(`xsys_flowoffice.sql`)の変更・表示。
- `CompensatoryLeaveRequestAggregateTest`の調査(報告者の環境の情報を受け取ってから行う)。
- 付与日の仕様(5/1で正しいことを、ユーザーが確認済み)。

## ドキュメントへの影響
- `docs/27-release-runbook.md` 3.2・3.3
- `docs/changesets/README.md`

## モック・アセット
- `assets/rehearsal-checklist.md`

## 検証方法
- `./vendor/bin/phpunit tests/Unit/EventSourcing/Rebuild`
- CIと同じ条件で、backendの全件を流す。

## レビュー履歴
初版(2026-10-24)。ユーザーの依頼「リハーサル手順を作成したうえで対応をお願いします」(2026-10-24)を受けて作成した。

## 実装結果
- リハーサル手順書`assets/rehearsal-checklist.md`(委譲元)。
- `ReadModelFingerprinter::EXCLUDED_COLUMNS`に`last_changed_event_id`を足した(implementer)。`ReadModelDiffer`は`normalizedRows()`を使うので、変更は要らなかった。
- テストを足した
  - `ReadModelFingerprinterTest`: `last_changed_event_id`だけが違えば一致し、月が違えば不一致になる。
  - `ReadModelDifferTest`: `last_changed_event_id`だけが違う行の差は0件。
- `docs/27-release-runbook.md`
  - 3.2: 手順1を直し、手順2a(旧コードでの基準A)を足した。手順8の合否の条件を直した。
  - 3.3: 手順1a(影響の数え方)を足した。
- 検証(委譲元で実行): `tests/Unit/EventSourcing/Rebuild` 241件PASS。
- 残る事項(本番の前): 手順書のとおりの再リハーサル、XSERVERで別のDBを作れるかの確認、全件のテストが止まった件の情報(終了コード・シグナル・PHPのバージョン)、E2E。

## リハーサルの結果(2回目、2026-10-24。実施者の報告)
- コード: 基準Aは旧コード`1213507`、リハーサルは`654f50e`。MySQL 8.0.46、タイムゾーンは+09:00。
- 準備: 2つのDBでSSOの設定を除き、モック・通知を無効にした(通知はログ、キューは同期)。
- `stored_events`: 3,343行。
- 基準A: 旧コードで再生し、87表の指紋を取った。
- migration: 7件すべて成功。一斉付与日の4月→5月の警告を確認した(仕様どおり)。自動付与の対象は0名。
- 付け替えの試し実行
  - 有給口座: 20行、IDの対応3件、7ストリーム(版の変わる行29行)。
  - 付与予定: 44イベントを取り除く。
  - 事前条件: すべて合格。
- 付け替えの本実行: 3,299行を書いた(3,343−44と一致)。`--verify`・`--swap`は成功。
- 再生と比較
  - 新しいコードで88表を再生した。
  - 付与予定の2表: 24→0行、16→0行。
  - 年度設定の表: Aには無い。Bの1行は、既定のカレンダーと一致。
  - 残りの85表: 件数・ハッシュが一致。行の単位の比較でも差は無い。
- 作り直し: `reapply-policy`・`roll-schedules`は成功。対象は0名、変更は0件。
- 判定: 受け入れ条件のうち、リハーサルの項目(指紋の比較・`--verify`・影響の件数)を満たす。
- 検証できなかったこと: 自動付与の対象の社員がいる場合の作り直しの結果。本番も同じデータなら対象は0名。この場合の動きは、PHPUnitのテストで確かめている(`ReapplyPaidLeaveSchedulePolicyCommandTest`・`FiscalYearStartChangeScenarioTest`)。
- 未完了: XSERVERで別のDBを作れるかの確認、backendの全件のテストの途中停止の情報、E2E。
