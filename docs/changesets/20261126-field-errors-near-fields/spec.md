# field-errors-near-fields(入力のエラーを項目の近くに出す)

ステータス: 完了

## 変更要望(原文)
変更候補の判断 `docs/changesets/candidates-20261124.md` の #6:
> 入力のエラーを項目の近くに出す(§2.19) → 対応する。共通の仕組みを作り、全フォームに当てる

## 背景・目的
`ui-interaction-patterns` §2.19 は「Error は対象の入力項目の近くに表示する」「Submit Error(全体)と Field Error(項目)を区別する」と定めるが、ほとんどのフォームは backend の 422 の項目エラーを、フォームの上・下のまとめての表示(`ErrorMessage`)に並べている。どの項目のエラーか分かりにくい。

## 現状(As-Is)(調査 2026-11-26、investigator)
- 仕組み:
  - `ApiError` は 422 の `errors: Record<string, string[]>` を保持する(`api/client.ts`)。
  - `utils/fieldErrors.ts`: `fieldErrorOf(error, key)`(そのキーの最初のメッセージ)、`nonFieldError(error, knownKeys)`(項目に出さないキーだけを残した ApiError、全て項目に出すなら null)。
  - `ErrorMessage`: ApiError の message と `errors` の全キーの値を並べる(キーで絞らない)。
  - `FormField` の `error` は入力の下に `<p>` で出す。
- 項目の下に出している画面: 利用者詳細(`UserRoleEditPage`)・ロール詳細(`RoleDefinitionPage`)・有給の付与ルール(`PaidLeaveGrantRuleEditPage`・`PaidLeaveGrantStepsEditor`、`steps.{i}.*` を行へ。ただし `ErrorMessage` にも全キーが出て二重)・`PaidLeavePolicyPage`。
- それ以外のフォーム(棚卸し `20261123-form-layout-standard/inventory.md` のページ P1〜P31・Dialog D1〜D31 の大半)は `ErrorMessage` のまとめての表示だけ。toast だけのものは無い。
- クライアントの入力検証:
  - 項目の下に出している: `RevertMonthConfirmationDialog`、`PaidLeaveScheduleAssessmentPanel`。
  - 理由の必須を `Error` にして `ConfirmActionDialog` の上部に出している: 勤怠の日の削除理由(`AttendanceDayPage`)、経費の取消理由(`ExpenseClaimDetailPage`)、申請の取消・却下理由(`WorkflowRequestDetailPage`)。
  - 送信ボタンを無効にしてヒントの `<p>` を出している: 外部連携の設定、経費区分、入力プリセット、申請の承認者、付与表の重複。
- 入れ子のキー:
  - `steps.{i}.*`(付与ルール): 行へ振り分け済み。
  - `permission_ids.*`・`feature_ids.*`(ロール): リストの下へ。
  - `field_definitions.{i}.*`(経費区分の項目定義): 行の対応なし。
  - 画面に対応する入力が無いキー(例: `ics_file`)がありうる。
- まとめての処理(資産の一括操作 P10、代休の手動付与 P19、一括の Dialog D29・D30)は、利用者・行ごとの成否の一覧で、項目のエラーではない。

## 設計前提の検証
- 正データと派生データ: 画面の表示だけ。API の 422 の形(`errors.{キー}`)は変えない。
- 定義と実態: 規則(§2.19)と実装がずれている。規則を正とし、実装を寄せる。
- 横展開: 対象はページと Dialog・Sheet の全フォーム(棚卸しのとおり)。インラインの作成フォームも同じ仕組みを当てる(項目の表示だけで、インライン作成の見直し(課題 #12)とは独立)。

## 仕様検討

### 論点1: 共通の仕組み
- 選択肢:
  - A. 画面ごとに `fieldErrorOf`・`nonFieldError` を呼ぶ(今の2画面の形)。
  - B. hook `useFieldErrors(error, fields)` を作る。`fields` は画面が項目に出すキーの一覧。返り値 `{ of(key), rest }`。`of` は項目のメッセージ、`rest` は項目に出さないキーだけを残したエラー(`ErrorMessage` に渡す)。
- 決定: B。ただし React の hook にはせず、純粋な関数 `fieldErrors(error, fields)` にする(hook の規則を背負う理由が無いため。必要なら呼び出し側で `useMemo`)。
- 型: `fieldErrors<K extends string>(error: unknown, fields: readonly K[])`。`of(key: K)` のキーは `fields` の中に限る(キーの食い違いを型で捕まえる)。
- 条件で描く項目(新規のときだけの項目、モードで切り替わる項目、タブで隠れる項目)は、`fields` も同じ条件で組み立てる(描かれていない項目のキーを `fields` に入れると、エラーがどこにも出なくなるため)。
- 理由: 項目に出したキーを `ErrorMessage` から外し忘れると二重に表示される(付与ルールで起きている)。キーの一覧を1か所で渡し、両方を同じ一覧から作れば外し忘れが起きない。
- 未確定・要確認事項: なし

### 論点2: どのキーをどこに出すか
- 決定:
  0. キーの出どころ: backend の validate(FormRequest・Controller)に加えて、Handler 等の `ValidationException::withMessages`(例: `approver_user_id`・`company_calendar_id`・`core_time_start`・`work_style_id`)。
  1. キーが画面の1つの入力に対応する → その `FormField` の `error`。
  2. `{配列}.{i}.{項目}` で、画面に行ごとの入力がある → その行の項目の下(付与ルールの形)。行に項目の入力が無い・行が見えない(タブで隠れる等)場合は、行またはタブに「(エラーあり)」を付け、メッセージは行の下。
  3. `{配列}.*`(選択の一覧。ロールの Permission 等)→ 一覧の下。
  4. 画面に対応する入力が無いキー、キーの無いエラー(409・500 等)→ 今と同じまとめての表示(`ErrorMessage`)。Submit Error として送信ボタンの近く(§2.18 の位置)。
  5. 422 で項目のエラーがあるときは、ボタンの近くのまとめての表示の見出しを「入力内容を確認してください。」にする(Laravel の 422 の message は最初の項目のエラーの文で、項目の下と二重になり、§2.18 の「API の生のメッセージを出さない」にも反するため)。すべてのキーを項目に出した場合も、この見出しだけを残す(長い画面でエラーの項目が画面の外にあると、保存を押しても何も起きないように見えるため)。
- 理由: §2.19 の「Submit Error と Field Error を区別する」。対応が無いキーを捨てるとエラーが見えなくなるので、まとめての表示に残す。
- 未確定・要確認事項: なし

### 論点3: クライアントの入力検証
- 決定:
  - 理由の必須等を `Error` にして Dialog の上部に出しているもの(勤怠の日の削除理由、経費の取消理由、申請の取消・却下理由)は、その項目の `FormField` の `error` に出す。送信を押した後に出す(入力前から赤くしない)。
  - 送信ボタンを無効にしてヒントを出す形(外部連携の設定・経費区分・入力プリセット・承認者・付与表の重複)は変えない。押せない理由の表示(§2.18)で、エラーではないため。
- 未確定・要確認事項: なし

### 論点4: 対象外にするもの
- 決定: まとめての処理の結果(資産の一括操作 P10、代休の手動付与 P19、一括の Dialog D29・D30・D31)は、行・利用者ごとの成否の一覧を今のまま使う。項目の入力(理由など)があり、その項目のキーで 422 が返るものだけ論点2を当てる。
- 入力の無い確認の Dialog(承認・取消の確認だけ等)は、エラーは今のまま上部に出す。
- 未確定・要確認事項: なし

### 論点5: エラーの読み上げ
- 決定: 変更セット`20261126-form-field-aria`の `FormField` が、エラーの `<p>` に id を付け、入力に `aria-invalid`・`aria-describedby` を付ける。本変更は `FormField` の `error` に出すだけで、読み上げにも効く。
- 実装の順序: `20261126-form-field-aria` → `20261126-unsaved-changes-guard-pages` → 本変更。離脱警告の変更セットと同じ画面のファイル(state と JSX)と `ConfirmActionDialog` に触れるので並行させない。離脱警告で dirty の判定関数を外に出し state の形が変わることを前提に、その後のコードに当てる。
- エラー時のフォーカス移動は対象外(全フォームの送信処理に手を入れることになるため。必要なら別の候補にする)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `frontend/src/utils/fieldErrors.ts` に `fieldErrors<K extends string>(error: unknown, fields: readonly K[])` を足す。返り値:
  - `of(key: K): string | undefined`(完全一致)
  - `ofRow(prefix, index, key?)`: `{prefix}.{index}.{key}`(key を省くと `{prefix}.{index}` そのもの)
  - `ofAny(prefix): string[]`: `{prefix}.{数字}`(および `{prefix}` 自身)のメッセージを全部(選択の一覧の下に出す)
  - `rest`: 項目に出さないキーだけを残し、見出しを「入力内容を確認してください。」にした ApiError。項目のエラーが無い 422 でない場合は元のエラーのまま。エラーが無ければ null。
  - `fields` には `{prefix}.*.{key}`・`{prefix}.*` を書ける。`*` は数字1区切りだけに一致する。
- 各フォーム: mutation の `error` を `fieldErrors` に通し、項目は `FormField error={of('キー')}`、`ErrorMessage` には `rest` を渡す。1つの画面に mutation が複数あるときは mutation ごとに使う。同じフォームの mutation で同時に1つしか失敗しないもの(例: 申請の作成 `createRequest.error ?? submitRequest.error`)は、まとめた1つのエラーに使ってよい。
- キーの一覧は、backend の該当の validate(FormRequest または Controller の `validate`)を読んで決める。画面に入力の無いキーは入れない。
- 実装者は、各フォームのキーと出し先の対応を `実装結果` の表に残す(フォーム・mutation・項目に出したキー・まとめての表示に残したキー)。
- 既存の2画面(利用者詳細・ロール詳細)は `fieldErrors` に置き換える(ロールの `permission_ids.N` は `ofAny` で一覧の下へ)。付与ルールの二重表示を直す。
- 経費区分の項目定義(`field_definitions.{i}.*`)は行の下に出す。
- テスト:
  - 各フォーム: 「`fields` の全キーと未知のキー1つを含む 422 を返すと、全メッセージが画面にちょうど1回ずつ出て、見出しは『入力内容を確認してください。』」を1件(条件で描く項目はその条件の状態で)。
  - `fieldErrors` の単体テスト(完全一致・行・`ofAny`・ワイルドカードは数字1区切りだけ・未知のキーが rest に残る・見出しの置き換え・ApiError 以外・422 以外)。
- 行(`ofRow`)・一覧(`ofAny`)に出すエラーは `FormField` を通らないため、変更セット`20261126-form-field-aria`の読み上げの属性は効かない(本変更の範囲外。表示だけを揃える)。
- スキル: `ui-interaction-patterns` §2.19 に「422 の項目エラーは `fieldErrors` で項目の下に出し、残りをまとめての表示に出す。`fields` は描く項目と同じ条件で組み立てる」と書く。`add-page` のフォームの手順に1行足す。

## 受け入れ条件
- 棚卸しのフォーム(論点4の対象外を除く)で、backend の 422 の項目エラーが、その項目の下に出る。まとめての表示には同じメッセージが出ない。
- 画面に入力の無いキー・キーの無いエラーは、まとめての表示に出る(消えない)。
- 削除理由・取消理由・却下理由の未入力が、その項目の下に出る。
- 各画面のテストと `useFieldErrors` のテストがあり、単体テスト全件が通る。
- 変えた画面を通る E2E の spec だけを流して通る。

## 対象外
- まとめての処理の結果の一覧、入力の無い確認の Dialog
- 押せない理由のヒントの形
- エラー時のフォーカス移動
- API の 422 の形の変更
- 読み上げの属性(変更セット`20261126-form-field-aria`)

## ドキュメントへの影響
- `.claude/skills/ui-interaction-patterns/SKILL.md` §2.19、`.claude/skills/add-page/SKILL.md`
- docs/: 変更なし(画面の表示のみ)

## モック・アセット
なし

## 実装対象
- `frontend/src/utils/fieldErrors.ts`(+テスト)
- 棚卸しのフォームのページ・Dialog(`frontend/src/pages/**`・`frontend/src/components/**`)。ディレクトリ単位(admin・asset・attendance・expense・paidLeave・workCalendar・workflow・backOffice・その他)で分けて並列に実装する。

## 検証方法
- `npx tsc -p tsconfig.app.json --noEmit`、`npx vitest run --project unit`(全件)
- E2E: エラーの文言で絞り込んでいる spec を grep し、変えた画面を通る spec だけを流す。

## レビュー履歴
- 2026-11-26 初版。
- 2026-11-26 実装前の独立設計レビュー(general-purpose)の指摘を反映:
  - `{配列}.*` を引く手段が無くエラーが見えなくなる → `ofAny` を追加、`ofRow` を拡張、`*` は数字1区切り。
  - 項目に出したつもりで出ない漏れ → キーを型で `fields` に限る、条件で描く項目は `fields` も同じ条件、各フォームのテストを全キー+未知のキーで確かめる形に。
  - まとめての表示に同じ文が出る(Laravel の message)・全部を項目に出すとボタンの近くに何も出ない → 見出しを「入力内容を確認してください。」にして常に残す。
  - キーの出どころに Handler の `withMessages` を追加。hook ではなく純粋な関数に。mutation をまとめて扱う場合を明記。実装の順序と衝突を明記。

## 実装結果
コミット: `fieldErrors`(共通の関数と `ErrorMessage` の空の一覧)、`5faf7049`(勤怠)、`1dc90fec`(経費・申請・バックオフィス・連携)、管理・初回設定、カレンダー・休暇・備品。

- 対象の全フォームで、422 の項目エラーを項目・行(`ofRow`)・選択の一覧(`ofAny`)の下に出し、残りを見出し「入力内容を確認してください。」のまとめての表示に出す。フォームごとのキーと出し先の対応は、各コミットの委譲の報告に基づき、主な判断だけを下に書く。
- 委譲元の判断で加えたこと:
  - 勤怠のダウンロード(`api/exports.ts`)が 422 の本文を捨てていたので、JSON なら `ApiError` を投げるようにした(`Accept: application/json` を付ける)。
  - 経費の単票入力(`SingleExpenseItemForm`)は送信の直後に入力を空にしていたため、422 で入力が消えエラーだけが残った。保存に成功したときだけ空にする。
  - 付与ルールの行のエラーの二重表示を解消(`PaidLeaveGrantStepsEditor` は `rowErrors` を受け取る形に)。
- 行の添字のずれ: 送信の本文が空の行を省く画面(勤怠の日の休憩・遅刻早退、カレンダーの一括操作の個別指定)は、本文の添字を画面の行に戻して出す。
- 日付をキーに持つ行(`day_overrides.{日付}.*`)は `*` が数字だけに一致するため、描いた日付ごとに完全一致のキーを `fields` に並べる。
- 項目の下に出さず、まとめての表示に残したもの(理由):
  - チェックボックスの項目(`requires_primary_membership`、システム設定の承認・通知、`includes_descendants`、端末の設定の3つ)。`FormField` を持たないため。資産番号の `is_active` と MS365 の `m365_mock_enabled` だけは下に出した。
  - 週の残業の振分(`allocations.*`)、カレンダーの日別の保存(`days.*`)、経費のまとめて登録(`items` の行ごとの検証で、backend が添字の無いキーで返す)。画面の行と対応しないため。
  - 入力が Dialog の外にある確認の Dialog(運用コマンドの実行、シフト表の公開、カレンダーの一括操作の確定)。Dialog の中には見出しと残りを出し、項目のエラーは Dialog を閉じると入力の下に見える。
- 残課題(本変更の対象外として記録):
  - 経費の下書きの初回の保存で 422 になると、画面の作り直しで単票入力が消える。
  - 備品の貸与・場所などの Dialog の `onConfirm` で投げるクライアント検証の Error が表示されない(既存)。
  - 働き方の月次の「勤務予定を自動生成」の失敗のまとめての表示が、確認の表示を閉じるため見えない(既存)。
  - `FieldErrors.of` は `fields` に無いキーも返す。`FormField` を描く条件と `fields` の条件を揃えないと二重表示になる(スキルに書いた)。
- 委譲元の検証: tsc 成功、単体テスト全件 1841 件成功。

- E2E(エラーの表示・理由の入力を通る6本: scenario-02・03・06・14・15・99): 32件すべて成功。spec の修正は無し。
