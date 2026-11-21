# 選択状態の復元の横展開(複数社員選択の名前表示・URLの不正値・書き方の残り)

ステータス: 完了

## 変更要望(原文)
> 他に同様な課題はありますか？見つけて対処をお願いします

(前の変更セット`20261115-keep-screen-selection-on-reload`の続き。その際のユーザー指示「現在、URLパラメーターに
設定している項目のみ対応してください。既存実装の中で整合性を保つ対応をお願いします」は本変更セットにも適用する)

## 背景・目的
前の変更セットで「IDだけ渡されると名前が空欄になる社員選択(UserPicker)」と「URL同期の書き方の不統一」を
直した。同じ種類の欠陥が残っている部品・画面を洗い出し、同じ規則で揃える。

## 現状(As-Is)
調査(investigator)の結果のうち、メインで裏付けを取ったものを中心に記す。

### A. 名前が出ない選択部品(UserPickerと同じ欠陥)
- `components/MultiUserPicker/MultiUserPicker.tsx:31` 選択済み社員の表示名`labels`を内部の`useState`だけで持ち、
  `:102` 名前が無いとUUIDを表示する。IDから名前を引く処理がない。
- 実害が出る経路(`components/GrantTargetPicker/GrantTargetPicker.tsx`。有給付与設定・特別休暇管理・代休管理の一括付与):
  - 「個人を指定」で社員を選ぶ→「グループを指定」→「個人を指定」に戻すと、`:88`の条件分岐で
    MultiUserPickerが作り直され、選択済みの社員が全員UUIDで並ぶ(親の`individualLabels`は残っているが渡していない)。
  - グループ指定で一括付与して一部失敗すると、`resetIndividualIds`で失敗者が個人指定に戻るが、名前を知らないため
    UUIDで並ぶ。
- 副次: `:35-40`で親への通知`onLabelsChange`を`setLabels`の更新関数の中で呼んでいる(描画中に親のstateを更新する形)。
- 他の選択部品(GroupPicker・CategoryCombobox・AssetPicker)は初期値付きで使う箇所がなく実害なし。

### B. URLの値が既定表示になるのにキーが残る/未検証の画面(前回の規則3の残り)
| 画面 | キー(型) | 現状 |
|---|---|---|
| 監査ログ `pages/admin/AuditLogPage.tsx:32-34` | `from`・`to`(日付)、`page` | 日付未検証(日付入力に生の文字列が出てAPIへ)、`page`は負数・小数も通る |
| 利用者一覧 `pages/admin/UserListPage.tsx:50-54,77` | `group_type_id`(正の整数)、`group_id`(UUID)、`account_status`(列挙)、`page` | `group_type_id`は`Number()`のみ(NaNがAPIへ)、`account_status`未検証 |
| 資産一覧 `pages/asset/AssetListPage.tsx:84-93` | `management_type`・`lending_status`・`installation_status`(列挙、backendの定数と画面の選択肢は一致)、`page` | 選択肢にない値でも絞り込みが効き、セレクトは「すべて」 |
| 経費プリセット一覧 `pages/expense/ExpenseEntryPresetListPage.tsx:55-67` | `category_id`(正の整数)、`page` | `Number()`のみ |
| 有給取得予定 `pages/paidLeave/PaidLeaveSchedulePage.tsx:124-127` | `filter`(列挙)、`scheduled_on_from`・`scheduled_on_to`(日付) | `filter`は既定に戻すがキーが残る、日付未検証 |
| 通知 `pages/notifications/NotificationsPage.tsx:25-26` | `status`(列挙) | 既定に戻すがキーが残る |
| 端末一覧・バックオフィスタスク一覧・申請一覧・承認一覧・休暇申請一覧3種 | `page`・`unassignedPage`・`minePage`・`requestPage` | 不正値を1ページ目として表示するがキーが残る |
| 勤怠月次一覧 `pages/attendance/AttendanceMonthsPage.tsx` | `year`、`page` | 形式は正しいが選択肢にない年(例 1999)はセレクトが「すべての年」なのに一覧0件。`page`の範囲外は最終ページを表示するがURLは残る |
| 週次勤怠 `pages/attendance/WeekAttendancePage.tsx:35-37` | `start`(日付) | 未検証(不正値で Invalid Date、不正な週がAPIへ) |
| 社員を選ぶ管理画面(勤怠参照・有給付与設定・特別休暇管理・代休管理) | `userId`(UUID) | 形式未検証(UUIDでないと名前取得が422で「不明な社員」、URLには残る) |

### C. 書き方の残り(前回の規則1の残り)
- `pages/specialLeave/SpecialLeaveAdminPage.tsx:624-636`・`pages/paidLeave/PaidLeavePolicyPage.tsx:808-820`・
  `pages/compensatoryLeave/CompensatoryLeaveAdminPage.tsx:200-212`・`AttendanceMonthsPage`の更新が
  `new URLSearchParams(searchParams)`(関数形式でない)。replaceは付いており実害はほぼないが参照実装と異なる。

### D. 名前を一覧から引くため一部が出ない表示
- 経費プリセット一覧 `ExpenseEntryPresetListPage.tsx:178`・編集 `ExpenseEntryPresetEditPage.tsx:117,269`は
  経費区分名を`useExpenseCategories()`(既定は**有効な区分のみ**)から引く。無効化した区分を使うプリセットは
  区分名が空になり、URLの`category_id`が無効な区分だと絞り込みは効くのにセレクトは「すべて」と表示される。
- 管理範囲の変更履歴の社員名(`UserManagementAccessPage.tsx:738-742`)は一覧の1ページ目から引くフォールバックが
  あるが、backendが常に`user_name`を返す(`UserManagementController.php:114`)ため実害なし。

## 設計前提の検証
- 正データと派生データ: 画面の表示とURLのみ。`stored_events`・Projectionへの影響なし。
- 定義と実態: §2.10(前回追記)の規則「不正値は既定表示にしてキーを消す」「関数形式・replace」「IDだけ渡されても
  名前を表示」から外れている箇所が上記。
- 原因の分類: 実装のミス(規則の適用漏れ)。
- 横展開: 調査は`components/`の選択部品全件と、`useSearchParams`を使う全ページ。
- 一覧のページング: 変更なし。

## 仕様検討

### 論点1: MultiUserPickerの名前の出し方
- 選択肢:
  - A. UserPickerと同じく、名前を知らないIDを`useUsersByIds`でまとめて取得して表示する(取得中は「読み込み中…」、
    該当なしは「不明な社員」)。
  - B. 親(GrantTargetPicker)が持つ`individualLabels`をpropsで渡す。失敗者の再選択(親も名前を知らない)は直らない。
- 決定: A。あわせて`onLabelsChange`の呼び出しを更新関数の外へ出す。
- 理由: 部品単体で全経路が直り、UserPickerと同じ規則になる。
- 未確定・要確認事項: なし

### 論点2: 不正値の扱いと範囲
- 選択肢:
  - A. Bの表の全キーに前回の規則3(既定表示にしてURLからキーを消す)を当てる。正当な値を誤って消さないよう、
    キーごとに判定を決める。
  - B. APIエラーになり得るものだけ直す。
- 決定: A。判定はキーごとに次のとおり(当てはまらない値だけを消す)。
  - 日付: `YYYY-MM-DD`で暦に存在する日(`from`・`to`・`scheduled_on_from`・`scheduled_on_to`・`start`)。
    週次勤怠の`start`は月曜以外でも正当(既存どおり`mondayOf`で週に寄せ、URLは書き換えない)。
  - ページ(`page`・`unassignedPage`・`minePage`・`requestPage`): 1以上の整数。
  - 数値ID(`group_type_id`・`category_id`): 正の整数。
  - UUID(`userId`・`group_id`): 8-4-4-4-12桁の16進、大文字小文字を区別しない、版は問わない(backendの`uuid`ルールと同じ)。
  - 列挙値: 資産一覧の3区分(画面の選択肢=backendの定数)、利用者一覧の`account_status`(`utils/userLabels.ts`の
    `ACCOUNT_STATUS_OPTIONS`)、有給取得予定の`filter`・通知の`status`(既存の判定関数をそのまま使う)。
  - 勤怠月次一覧の`year`: 4桁で、かつ画面の年の選択肢に含まれる。`page`: 最終ページを超えるときは最終ページに
    合わせる(1なら消す)。どちらもログインユーザーの入社日から選択肢・ページ数が決まった後に判定する
    (計算できない間は何もしない)。
  - 判定しないもの(理由):
    - 申請一覧の`status`・`subjectType`、承認一覧の`status`: 画面の選択肢にない正当な値(`rejected`・
      `special_leave_request`等)をbackendが受け付けるため、画面の選択肢で判定すると正当な値を消す。
    - `group_id`・`category_id`の存在確認: 見える一覧が権限・有効/無効で変わるため形式だけにし、存在しない値は
      APIの応答(0件・エラー)に任せる。
    - 監査ログの`user_id`: 自由入力の欄で入力途中の値を消してしまうため。
- 理由: 前回決めた規則を残りの画面へ揃える。正当な値を消さないことを優先して判定を限定した。
- 週次勤怠の`start`: 前回は「画面から書かない」ため対象外としたが、URLから読んで表示を決めている項目であり、
  不正値で画面が壊れる(Invalid Date)ため検証だけは行う。週の移動をURLへ書く変更はしない(前回の決定どおり)。
- 未確定・要確認事項: なし

### 論点3: 無効化した経費区分の名前
- 選択肢:
  - A. 経費プリセット一覧・編集で区分の取得を無効込み(`useExpenseCategories(true)`)にする。一覧の絞り込みの
    選択肢には無効な区分も含め、名前の後ろに「(無効)」を付ける(URLの`category_id`が無効な区分でも表示と一致させる)。
    編集画面の区分の選択肢は有効な区分のみのまま、現在の値が無効な区分ならその1件だけ「(無効)」付きで残す。
    保存時の項目構成(`fieldSetForCategory`)も無効込みの一覧から引く。
  - B. 現状のまま。
- 決定: A
- 理由: 既存データの値から名前が出ない、という同じ種類の欠陥。新規の選択で無効な区分を選ばせない方針は保つ。
  影響: 無効な区分を使うプリセットを編集保存したとき、項目構成が今の汎用('generic')から区分本来の構成になる
  (本来の挙動に戻る)。backendは区分の存在だけを確かめるため保存できる。
- 未確定・要確認事項: なし

### 論点4: 範囲とURLに載せる基準
- ユーザー指示(2026-11-21): 前回の「URLパラメーターに設定している項目のみ」は「今回の範囲を既存の実装範囲に絞る」
  意図であり、必要と思われるものは追加してよい。UXガイドラインにURLパラメータとして保持するかの基準を用意して
  対応する。
- 選択肢:
  - A. 基準を`ui-interaction-patterns` §2.10に定め、前回の調査表の全画面をその基準で判定し直して、載せるべきものを
    URL化する。
  - B. 基準だけ定め、URL化は今後の画面から。
- 決定: A。基準(§2.10「URLに載せるかの基準」に追記済み)の要旨:
  - 載せる: 一覧の検索・絞り込み・並び替え・ページ / 画面の表示対象の選択(対象社員・対象期間)/ 詳細パネルの
    対象ID / そのページ専用のタブ・表示切り替え。
  - 載せない: フォーム入力中の値 / 一括操作のチェック・行の展開 / 展開した行の中の絞り込み / Dialog・Sheetの開閉 /
    複数画面に埋め込まれる共通部品の内部状態 / 操作結果の通知・秘密情報。
  - 他画面からの初期値(`date`・`dates`・`type`)はプリフィルとして一度だけ読む。
  - 判断の軸: 「その状態のままURLを同僚に送ったら、同じ表示を見せたいか」。
- 基準による判定(前回の変更セットの調査表の`useState`のみの状態):
  | 画面 | 状態 | 判定 | URLのキー |
  |---|---|---|---|
  | 週次勤怠 | 表示中の週 | 載せる(表示対象の期間) | `start`(既存の読み取りキー。その週の月曜) |
  | 勤務カレンダー一覧・働き方一覧 | ページ | 載せる(一覧のページ) | `page` |
  | 管理範囲 | グループ種別の絞り込み | 載せる(一覧の絞り込み) | `group_type_id`(利用者一覧と同じ) |
  | 端末一覧 | 詳細パネルの対象端末 | 載せる(詳細パネルの対象ID) | `deviceId` |
  | 運用コマンド | 選んだコマンド | 載せる(どのフォームを開いているか) | `command` |
  | 勤怠参照ほか | 月次/週次/日次タブ・表示中の週/日 | 載せない(埋め込み共通部品) | — |
  | 有給付与設定・特別休暇管理 | 付与ルールごとの氏名絞り込み | 載せない(展開した行の中) | — |
  | 勤怠出力・カレンダー一括操作・シフト登録/3交代制シフト表・働き方の月次割当・システム設定・各種新規/編集・運用コマンドのパラメータ | 入力値 | 載せない(フォーム) | — |
  | 申請一覧・バックオフィス・勤怠月次詳細等 | 一括選択のチェック | 載せない | — |
  | 勤務カレンダー詳細 | 年追加のモーダル内の選択 | 載せない(Dialog) | — |
  | 休暇申請画面 | `date`/`dates` | プリフィル(現状どおり一度だけ読む) | — |
  | 経費申請 | `category`/`mode` | プリフィル+既存の書き込み(現状どおり) | — |
- 対象外: 管理範囲の変更履歴の社員名(backendが常に名前を返し実害なし)、GroupPicker等の初期値なしで使う部品。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `MultiUserPicker`:
  - `labels`に無いIDを`useUsersByIds`で取得し「氏名(メール)」を表示。backendの上限(100件)に合わせ100件ずつに分けて
    取得する(`useQueries`等)。取得中は「読み込み中…」、該当なしは「不明な社員」(解除ボタンのaria-labelも同じ)。
  - 取得した名前も`labels`へまとめ、`onLabelsChange`で親へ渡す(一部失敗後の結果表示で名前を出すため)。
    `onLabelsChange`は更新関数の外(`useEffect`等)で呼ぶ。
  - テスト: 初期値のIDから名前が出る / 取得中 / 該当なし / 101件以上で分割して取得される / 選んだ名前はそのまま(既存)/
    取得した名前が`onLabelsChange`で渡る。
  - `GrantTargetPicker`のテスト: 個人→グループ→個人で名前が残る / 一部失敗のリセット後に失敗者が名前で並び、
    `onResolvedChange`のラベルにも名前が入る。
- 論点2の各画面: 判定は各ページ内の小関数(または共通の小さな判定関数を`utils/`に1つ。新規ファイル名は実装時に
  衝突確認)で行い、不正なら既定表示にして`useEffect`でキーを削除(replace・関数形式。前回の
  `AttendanceReferencePage`・`ApprovalsPage`と同じ書き方)。各ページのテストに、不正値のURLで既定表示・キー削除を
  確かめるケースを1件以上、正当な値(例: 月曜以外の`start`、大文字のUUID)が消えないケースを該当画面に1件追加。
- 論点3: 上記のとおり。テストの`fetchExpenseCategories`のモックは`includeInactive`の引数に応じて返す値を変え、
  無効な区分のプリセットの区分名が一覧・編集で出ることを確かめる。
- C: 3画面と勤怠月次一覧の更新を関数形式にする(挙動は変えない)。
- 論点4のURL化(書き方は前回の規則: 関数形式・replace・不正値は既定表示にしてキー削除):
  - 週次勤怠: 前週/次週/今週への移動で`start`(移動先の月曜`YYYY-MM-DD`)を書く。キーがない初期表示は今週で、
    URLに書かない。`start`は表示の正としてURLから毎回読む(`useState`の初期値コピーをやめる)。月曜以外の正しい日付は
    その週として表示し、URLは書き換えない。週が変わったら選択中の日・一括入力の下書き・メッセージを今と同じく
    リセットする(現在の移動ハンドラで行っているリセットを、URLの`start`の変化に対して行う)。
  - 勤務カレンダー一覧・働き方一覧: `page`(1ページ目はキーなし)。
  - 管理範囲: `group_type_id`(未選択はキーなし。数値IDの判定)。
  - 端末一覧: `deviceId`。パネルを閉じたらキーを消す。対象端末は現在のページの一覧に無くても既存の
    `fetchDevice(deviceId)`で取得して表示する(取得で404ならキーを消す)。
  - 運用コマンド: `command`(コマンド名)。キーなしは先頭のコマンド(URLに書かない)。一覧に無い名前は先頭のコマンドに
    してキーを消す。コマンドを変えたらパラメータの入力はリセット(現状どおり)。
  - 各画面のテストに、URLから状態が復元されること・操作でURLが書かれることを確かめるケースを追加。

## 受け入れ条件
- 一括付与で「個人→グループ→個人」と切り替えても、選択済み社員が名前で表示される。一部失敗で戻った社員も名前で出る。
- Bの表の各キー(論点2で判定しないものを除く)に不正値を入れたURLを開くと、既定の表示になりURLからそのキーが消える。
  正当な値(月曜以外の週の開始日・大文字のUUID等)は消えない。
- グループ指定の一括付与が一部失敗したとき、結果表示と個人指定に戻った失敗者が名前で表示される。
- 無効化した経費区分を使うプリセットの区分名が一覧・編集で表示される。
- 週次勤怠で週を移動してリロードすると同じ週が表示される。勤務カレンダー一覧・働き方一覧のページ、管理範囲の
  絞り込み、端末一覧の詳細パネル、運用コマンドの選択もリロード後に保たれる。
- `ui-interaction-patterns` §2.10にURLに載せるかの基準がある。
- frontendの単体テスト全件・型チェック・lintがPASSする。

## 対象外
論点2の「判定しないもの」と論点4の対象外のとおり。backendの変更なし。

## ドキュメントへの影響
- `.claude/skills/ui-interaction-patterns/SKILL.md` §2.10: URLに載せるかの基準、不正値の判定の目安、既定値の書き方、
  「IDだけ渡されても名前を表示」の参照にMultiUserPickerを追記(実装前に追記済み)。
- `docs/`配下: 変更なし。

## モック・アセット
なし

## 実装対象
- `frontend/src/components/MultiUserPicker/`、`frontend/src/components/GrantTargetPicker/GrantTargetPicker.test.tsx`
- `frontend/src/pages/`: admin(AuditLog・UserList・DeviceList・UserManagementAccess・AdminCommands)、
  workCalendar(WorkCalendarList・WorkStyles)、backOffice(BackOfficeTaskList)、
  notifications(Notifications)、休暇申請一覧3種(MyPaidLeave・MySpecialLeave・MyCompensatoryLeave)、、asset(AssetList)、expense(ExpenseEntryPresetList・Edit)、
  workflow(WorkflowRequestList)、approvals(Approvals)、paidLeave(PaidLeaveSchedule・PaidLeavePolicy)、
  specialLeave(SpecialLeaveAdmin)、compensatoryLeave(CompensatoryLeaveAdmin)、attendance(AttendanceMonths・
  WeekAttendance・AttendanceReference)と各テスト
- `.claude/skills/ui-interaction-patterns/SKILL.md`

## 検証方法
- `cd frontend && npx vitest run --project unit`、`npx tsc -p tsconfig.app.json --noEmit`、`npm run lint`

## レビュー履歴
- 2026-11-21 初版
- 2026-11-21 独立設計レビュー(反証)の指摘を反映: `group_id`はUUID(数値として判定すると正当な値を全部消す)、
  UUIDの判定条件(版を問わない)、MultiUserPickerの取得した名前を親へ渡す(一部失敗後の結果表示)・100件ずつの取得、
  範囲の解釈(論点4)を明記、週次勤怠`start`を検証する理由と月曜以外を正当とする旨、取りこぼし(通知・有給取得予定の
  `filter`・各画面のpage系・`account_status`・勤怠月次一覧の選択肢にない`year`)を追加、申請一覧・承認一覧の
  ステータス類と監査ログの`user_id`を判定しない理由、勤怠月次一覧の判定時期、経費区分のテストのモックと
  保存時の項目構成への影響を追記。
- 2026-11-21 ユーザー指示「範囲は既存の実装範囲に絞る意図。必要なら追加してよい。UXガイドラインにURLパラメータとして
  保持するかの基準を用意して対応」を受け、論点4を「範囲とURLに載せる基準」に改めた。基準を§2.10に追記し、前回
  対象外とした週次勤怠の週・勤務カレンダー一覧と働き方一覧のページ・管理範囲の絞り込み・端末一覧の詳細パネル・
  運用コマンドの選択を基準により対象に加えた。ユーザー承認として実装中へ。

## 実装結果
- コミット: `9400cbf`(判定関数)、`bd28dac`(MultiUserPicker・経費区分)、`a44cc05`(管理・資産・通知・バックオフィス)、
  本変更セットを完了にしたコミット(勤怠・休暇・申請・勤務カレンダー)。基準の追記は`51c7ccf`。
- 主な変更:
  - `utils/searchParamValidators.ts`(日付・年月・年・ページ・数値ID・UUID・列挙値の判定)+テスト21件
  - `MultiUserPicker`: 名前を知らないIDを100件ずつ取得して表示、取得した名前を`onLabelsChange`で親へ渡す
    (内部stateへは混ぜず合成して通知。混ぜると取得キーが変わり再取得で表示がちらつくため)
  - 経費プリセット一覧・編集: 無効込みの区分で名前を解決、一覧の選択肢に「(無効)」付きで含める、編集は現在の無効区分のみ残す
  - 不正値の判定とキー削除: 論点2の全画面
  - URL化(論点4): 週次勤怠`start`、勤務カレンダー一覧・働き方一覧`page`、管理範囲`group_type_id`、端末一覧`deviceId`
    (一覧に無い端末は`useDevice`で取得、404でキー削除)、運用コマンド`command`
  - 書き方: 休暇の管理画面3つ・勤怠月次一覧の更新を関数形式に
  - e2e `scenario-06-attendance-corrections.spec.ts`のコメントのみ更新
- 仕様からの補足判断(レビューで採用):
  - 週次勤怠: 週が変わったときのリセット(選択モード・選択日・一括入力の下書き・メッセージ)は従来は行っていなかったが、
    URLで週が変わる経路(リンク・Back)でも選択が別の週に残らないよう新たに行う。
  - 働き方一覧の範囲外の`page`は従来どおり最終ページを表示し、URLは書き換えない(形式不正のみ削除)。
  - 監査ログ・利用者一覧の「フィルターをクリア」は検証後の値で判定(不正値だけのURLでは出ない)。
  - 休暇の管理画面のテストの社員IDを`user-3`からUUIDに置き換えた(UUID判定の導入に伴う。アサーションは不変)。
- 検証(委譲元で再実行):
  - `npx vitest run --project unit`: 151ファイル、1211 passed / 2 skipped / 失敗0
  - `npx tsc -p tsconfig.app.json --noEmit`: エラー0、`npm run lint`: error 0
  - 並行実行中に一部テストのタイムアウトが出たが、単独・全件の再実行ではすべてPASS(負荷による)。
- 受け入れ条件: MultiUserPicker・GrantTargetPickerのテスト(切り替え・一部失敗後の名前)、各画面の不正値・正当値のテスト
  (LocationProbe)、経費区分のテスト、URL化した各画面の復元・書き込みのテスト、§2.10の基準の追記で確認。
- ドキュメント: §2.10に追記済み。`docs/`配下は変更なし。
