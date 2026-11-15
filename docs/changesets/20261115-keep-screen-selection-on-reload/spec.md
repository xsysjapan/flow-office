# 画面の選択状態(対象社員・年月・絞り込み・ページ)をリロード後も保つ

ステータス: レビュー中

## 変更要望(原文)
> 勤怠参照画面で対象社員、年月を選択した状態で画面をリロードすると、選択が外れてしまいます。
> 他の画面でも一貫性がない状態がないようにプログラムの見直しをお願いします。

## 背景・目的
画面に何を表示するかを決める選択(対象社員・年月・週・絞り込み・ページ)は、リロード・Browser Back・
URL共有で失われないことが`ui-interaction-patterns` §2.10の規則。勤怠参照画面で規則どおりに見えない
不具合を直し、同じ種類の取りこぼしが他の画面にもないかを見直して揃える。

## 現状(As-Is)

### 勤怠参照画面(`/admin/attendance`)の原因
- `frontend/src/pages/attendance/AttendanceReferencePage.tsx:644-661` は対象社員を`?user=`、
  年月を`?yearMonth=`としてURLに載せており、**リロード後も値そのものは残る**(年月はURLから復元され、
  月次/週次/日次のタブも表示される)。
- 外れて見える原因は社員選択の共通部品 `frontend/src/components/UserPicker/UserPicker.tsx`:
  - `:33` 表示ラベル`selectedLabel`は`useState`で持ち、`:62-64` 一覧から選んだ瞬間にしか設定されない。
  - `:50` `{value ? selectedLabel : placeholder}` のため、**URL等から`value`だけ渡された場合(リロード・
    Browser Back・URL共有・編集画面の初期値)は社員名が空欄**になる。プレースホルダーも出ないため
    「選択が外れた」ように見える。
- 原因の分類: 実装のミス(部品が「親から値だけ渡される」経路を想定していない)。データ設計の問題ではない。
- リロードでクエリが落ちる経路がないことも確認済み(`RequireAuth`・ログイン復帰・`redirectTarget`・
  `.htaccess`はいずれも`search`を保持する)。年月はURLから復元される。サイドバーの「勤怠参照」を押し直すと
  クエリなしのURLへ遷移して初期化されるのは仕様(メニューは画面の入口)。

### 同じ部品を使う画面(同じ症状が出得る)
`UserPicker`はURLから社員を復元する画面(勤怠参照・有給付与設定`PaidLeavePolicyPage`・特別休暇管理
`SpecialLeaveAdminPage`・代休管理`CompensatoryLeaveAdminPage`の`?userId=`)で同じく社員名が空欄になる。
その他、申請・経費・資産・シフト等の入力フォームでも使われている(計20画面)。

### 画面ごとの状態の持ち方(調査結果)
リロードで失われる「表示を決める状態」(フォーム入力中の値・一括操作のチェック・展開状態は除く):

| 画面 | 失われる状態 | 備考 |
|---|---|---|
| 勤怠参照 `AttendanceReferencePage` | 社員名の表示(上記) | 値はURLに残る |
| 週次勤怠 `WeekAttendancePage` | 前週/次週で移動した週 | `?start=`は読むだけで書かない |
| 勤務カレンダー一覧 `WorkCalendarListPage` | ページ | `useState` |
| 働き方一覧 `WorkStylesPage` | 一覧のページ | `useState` |
| 管理範囲 `UserManagementAccessPage` | グループ種別の絞り込み | `useState` |
| 端末一覧 `DeviceListPage` | 詳細パネルの対象端末 | 参照実装: 承認一覧の`?requestId=` |

`useState`だが今回の対象外とするもの(論点3): 有給付与設定・特別休暇管理の付与ルールごとの氏名絞り込み
(展開した行の中にあり複数同時に開ける)、運用コマンドの選択(実行パラメータの入力フォームの一部)、
シフト画面の3交代制シフト表の対象部署・対象月(登録・公開フォームの一部)。

同種の部品: `MultiUserPicker`(`components/MultiUserPicker/MultiUserPicker.tsx:31,102`)も名前を知らない
IDはUUIDのまま表示するが、現在の利用箇所(`GrantTargetPicker`の新規フォーム)は初期値が常に空で、
症状が出ない。

URL化済み画面間の不統一:
- 履歴: 大半(承認・利用者・資産・監査ログ等13画面)は`replace`、勤怠参照・勤怠月次一覧・ロール定義・
  経費プリセット一覧・休暇申請一覧のページ(`requestPage`)は`push`。
- 社員のキー名: 勤怠参照のみ`user`、休暇の管理画面3つは`userId`。
- 不正値: 承認一覧は`yearMonth`を検証せずAPIへ渡す。勤怠参照は不正な年月を今月として表示するがURLは
  そのまま。
- 共通部品なし: 各画面が`useSearchParams`と独自の更新関数を書いている。
- テスト: 承認一覧はURLから状態を復元するテストがあるが、勤怠参照にはない。`UserPicker`のテストは
  一覧から選ぶ経路のみ。

### 社員名の取得手段
- `GET /users/{user}`は`user.view`権限が必要で、勤怠参照を使える人(上長等)が持つとは限らない。応答も重い。
- `GET /users/search`(`backend/app/Http/Controllers/Api/UserController.php:157`)は誰でも使える軽量版
  (`UserSearchResource`: id・氏名・メール・部署・役職)だが、ID指定の絞り込みがない。

## 設計前提の検証
- 正データと派生データ: 変更対象は画面の表示状態(URL)と社員名の表示のみ。勤怠・申請などのデータの
  書き込み経路には触れない。`stored_events`・Projectionへの影響なし。
- 定義と実態: §2.10「Search / Filter / Sort / Page / 必要に応じてTab・詳細パネルの対象IDをURLへ」と実装の
  ずれは上表のとおり。履歴の`push/replace`とキー名は§2.10に定めがないため画面ごとにばらついた。
- 原因の分類: 勤怠参照は部品の実装ミス。他画面の取りこぼしは規則(§2.10)の適用漏れと、規則が履歴・
  キー名・不正値を決めていなかったことによる不統一。後者を防ぐため規則の追記と共通hookを選択肢に含める。
- 横展開: 上表の全画面を対象ごとに判断した(論点3)。
- 一覧のページング: 一覧の新設・ページング方式の変更はない(既存のページ番号をURLへ移すだけ)。

## 仕様検討

### 論点1: リロード後の社員名の出し方
- 選択肢:
  - A. `UserPicker`が、`value`に対応する社員名を知らないときにAPIで取得して表示する。取得には
    `GET /users/search`へID指定の絞り込み`ids[]`を追加して使う(誰でも使える軽量版のまま)。
  - B. `GET /users/{user}`で取得する。`user.view`権限がない人は名前が出ない。応答が重い。
  - C. 社員名もURLに載せる。URLが冗長になり、名前変更で古い名前が出る。
  - D. 各画面で社員名を取得して`UserPicker`へ渡す。20画面に同じ処理を書くことになる。
- 決定: A
- 理由: 部品の中で直せば使っている全画面(URL復元・編集画面の初期値・Browser Back)がまとめて直る。
  検索APIは既に誰でも使える範囲(氏名・メール・部署・役職)しか返さないため、ID指定を足しても
  見える情報は増えない。
- 未確定・要確認事項: なし

### 論点2: 社員名の取得中・取得できないときの表示
- 選択肢:
  - A. 取得中は「読み込み中…」、該当なし(削除・不正なID)は「不明な社員」と表示する。
  - B. 取得できるまで空欄。
- 決定: A
- 理由: 空欄は今回の「外れたように見える」原因そのもの。不正なIDのときも選択中であることが分かり、
  選び直せる。
- 未確定・要確認事項: なし

### 論点3: URLへ移す状態の範囲(横展開)
- 選択肢:
  - A. 勤怠参照の社員名だけ直す。
  - B. 「画面に何を表示するか」を決める状態はすべてURLへ移す(上表の全画面)。フォーム入力中の値・
    一括操作のチェック・行の展開状態はURLに載せない。
  - C. Bに加えて、勤怠参照・承認等で共通の月次/週次/日次タブ(`AttendanceMonthReferenceTabs`)の
    タブ・表示中の週/日もURLへ移す。
- 決定: B
- 理由: 依頼の「他の画面でも一貫性がない状態がないように」に応え、§2.10の対象(絞り込み・ページ・
  詳細パネルの対象ID・タブ)に当たる取りこぼしを揃える。Cの共通タブは承認一覧の詳細パネル・
  バックオフィスタスク詳細・申請詳細にも埋め込まれ、1画面に複数並ぶ・他画面のURLキーと衝突するため、
  コメントで「URL化しない」と明記された既存の判断を維持する(リロードで月次タブへ戻るのは仕様)。
  対象ごとの判断:
  - 週次勤怠: 移動した週を`?start=YYYY-MM-DD`へ書く(今週ならキーを消す)。
  - 勤務カレンダー一覧・働き方一覧: `?page=`。
  - 管理範囲: グループ種別の絞り込みを`?group_type_id=`(利用者一覧と同じキー名)。
  - 端末一覧: 詳細パネルの対象端末を`?deviceId=`(承認一覧の`requestId`と同じ扱い)。
  - 対象外(フォーム入力中): 勤怠出力・カレンダー一括操作・シフト登録・3交代制シフト表・働き方の月次割当・
    資産の一括操作・運用コマンド(コマンド選択で実行パラメータが切り替わる入力フォーム)・システム設定・
    各種新規/編集画面。対象外(一時的な選択): 一括操作のチェック・行の展開と、展開した行の中の絞り込み
    (有給付与設定・特別休暇管理の付与ルールごとの氏名絞り込み。複数の行を同時に開けるため1つのキーに
    対応せず、展開状態を残さない以上リロード後に効果が見えない)。
  - `MultiUserPicker`: 対象外。初期値付きで使う画面がなく症状が出ない。将来初期値付きで使う場合は
    §2.10の追記(ID選択入力の名前表示)に従って直す。
- 未確定・要確認事項: なし

### 論点4: URLの書き方の統一(履歴・キー名・不正値・既定値)
- 選択肢:
  - A. §2.10に統一規則を追記し、共通hookで実装をそろえる。既存画面は今回の対象画面と、規則から外れて
    いる画面(push・キー名・不正値)を合わせる。
  - B. 規則だけ追記し、実装は今回触る画面だけ直す。
- 決定: A。統一規則は次のとおり。
  1. 履歴は`replace`(絞り込み・ページ・社員・年月の変更で履歴を積まない。Browser Backは前の画面へ戻る)。
     勤怠参照・勤怠月次一覧・ロール定義・経費プリセット一覧・休暇申請一覧のページを`push`から`replace`へ。
  2. キー名は、既存画面に同じ概念のキーがあればその名前に合わせる(例: グループ種別は`group_type_id`)。
     新しい概念はcamelCase。画面の対象社員は`userId`とし、勤怠参照の`user`を`userId`へ改める
     (旧`?user=`は読み込み時に`userId`として扱い、URLを`userId`へ置き換える。現状`?user=`を組み立てる
     リンク・e2eはないことを確認済み)。その他の既存キー名(`requestPage`・監査ログの`user_id`等の
     API由来のsnake_case)は変えない。
  3. 年は`YYYY`、年月は`YYYY-MM`、日付は`YYYY-MM-DD`、ページは1以上の整数。不正値は既定値として表示し、
     URLからそのキーを消す(表示とURLを一致させる)。承認一覧の`yearMonth`・勤怠月次一覧の`year`にも
     この検証を入れる。
  4. 固定の既定値(1ページ目・既定のステータス等)はURLに書かない(書かれていたら消す。休暇申請一覧の
     `requestPage=1`も消す)。日付で変わる既定値(今月・今週)は、キーがない初期表示ではURLに書かないが、
     利用者が選んだ(「今月」ボタン・前後移動で今週に戻った場合を含む)ときは書く。URL共有・翌日以降の
     リロードで別の月・週に変わらないようにするため。
  5. 絞り込みを変えたら`page`を消す(既存の承認一覧と同じ)。
  - 共通hook `frontend/src/hooks/useSearchParamsState.ts`(仮称)を設け、「キーごとの読み取り(検証・
    既定値)」「部分更新(nullで削除、`replace`)」を提供する。今回変更する画面はこのhookを使う。
    既に規則どおりに動いている画面(利用者一覧・資産一覧・監査ログ等)は書き換えない。
- 理由: 規則がなかったことが不統一の原因で、規則だけでは次の画面でまたばらつく。共通hookで既定の動作を
  規則どおりにする。正しく動いている画面の書き換えは回帰リスクに見合わないため行わない。
  `replace`は既存の多数派で、承認一覧(参照実装)と同じ。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

### backend
- `GET /users/search`に`ids[]`(UUIDの配列、最大100件)を追加。指定時は`id IN (ids)`で絞り込み、
  `q`・`permission`と併用可。`ids`指定時は`per_page`の既定値を`ids`の件数にする(1ページで全件返す)。
  権限・応答形式(`UserSearchResource`)は変えない(`q`を空にすれば既に全社員を取得できるため、公開範囲は
  広がらない)。OpenAPI注釈を更新。
- テスト(`backend/tests/Feature/UserManagement/UserSearchAndAccessTest.php`):
  ids指定で該当者だけ返る / 存在しないIDは無視される / 不正なUUID・101件以上は422 / 一般社員でも使える。

### frontend
- `api/users.ts`の`searchUsers`に省略可能な第4引数`ids?: string[]`を足して再利用する(新しいAPI関数は
  作らない。既存のページテストが`vi.spyOn(usersApi, 'searchUsers')`でモックしているため、同じ関数なら
  既存テストのモックがそのまま効く)。`hooks/useUsers.ts`に`useUsersByIds(ids)`を追加
  (クエリキー`['users', 'by-ids', ids]`、`ids`が空なら無効)。名前の取得では`permission`を渡さない
  (選んだ後に権限を失った社員も名前が出るようにする)。
- `UserPicker`:
  - 一覧から選んだときの名前はそのまま表示する(今と同じ)。
  - `value`があり、その社員の名前を知らない(初回表示・親から値が変わった)ときは`useUsersByIds([value])`で
    取得し「氏名(メール)」を表示。取得中は「読み込み中…」、該当なしは「不明な社員」。
  - `value`が未指定のときはプレースホルダー(今と同じ)。
  - テスト: value付きで描画すると取得した氏名が出る / 取得中表示 / 該当なしで「不明な社員」/
    一覧から選んだ名前が出る(既存)/ valueが外から変わると新しい社員名になる。storyにvalue付きを追加
    (既存storyと同じくクエリキー`['users','by-ids',[id]]`へデータを差し込む)。
  - 既存のページテストで`searchUsers`のモックが`ids`指定の呼び出しに対象社員を返さず「不明な社員」になる
    場合は、モックを`ids`に応じて返すよう直す(アサーションは弱めない)。
- 共通hook `hooks/useSearchParamsState.ts`(論点4の規則1・4・5を既定の動作にする)とテスト。
- 画面ごとの変更(論点3・4):

| 画面 | 変更 |
|---|---|
| 勤怠参照 | `user`→`userId`(旧キーを読み替え)、replace、不正な年月はURLから消す。年月の変更(「今月」ボタン含む)は常に`yearMonth`を書く(規則4) |
| 週次勤怠 | 前週/次週/今週への移動で`?start=`(その週の月曜)を書く。月曜以外の正しい日付は月曜に寄せてURLも書き換える。不正な日付は今週としてキーを消す。週が変わったら選択中の日・一括入力の下書きは今と同じくリセット |
| 勤怠月次一覧 | push→replace、`year`の検証 |
| 承認一覧 | `yearMonth`の検証(不正ならキーを消して既定の表示) |
| 勤務カレンダー一覧・働き方一覧 | `?page=` |
| 管理範囲 | `?group_type_id=` |
| 有給付与設定・特別休暇管理・代休管理 | 変更なし(UserPickerの修正で社員名が出る) |
| 端末一覧 | `?deviceId=`(パネルを閉じたらキーを消す) |
| ロール定義・経費プリセット一覧・休暇申請一覧3種のページ | push→replace(休暇申請一覧は`requestPage=1`を書かない) |

- 各画面のテスト: `MemoryRouter`の`initialEntries`にURLを与えて、選択状態が復元されることを確認する
  (勤怠参照は社員名と年月が表示されること)。URLの書き換え(`user`→`userId`・不正なキーの削除・
  月曜への寄せ)は、テスト内で`useLocation()`の`search`を表示する小さな部品を一緒に描画して検証する。
- e2e `frontend/e2e/scenario-06-attendance-corrections.spec.ts:164`付近の「`?start=`が無いと当週から
  表示し直す」旨のコメントを新しい挙動に合わせて直す(テスト本体は変えない)。
- `ui-interaction-patterns` §2.10に論点4の規則・共通hook・「社員などIDで選ぶ入力は、IDだけ渡されても
  名前を表示できること」を追記。

## 受け入れ条件
- 勤怠参照で社員・年月を選んでリロードすると、社員名・年月・勤怠が選んだまま表示される。
- 有給付与設定・特別休暇管理・代休管理でも、社員を選んでリロードすると社員名が表示される。
- 承認者に権限で絞った`UserPicker`でも、選んだ社員の名前は権限に関係なく表示される。
- 論点3の各画面で、表の状態がリロード後も保たれる。
- 旧URL`/admin/attendance?user=<id>`を開くと同じ社員が表示され、URLが`userId`になる。
- 不正な年月・日付のURLを開くと既定の表示になり、URLからそのキーが消える。
- 社員・年月・絞り込み・ページを変えてもBrowser Backで1つ前の選択に戻らず、前の画面へ戻る。
- backend・frontendの関連テスト、`npm run build`(型チェック)、lintがPASSする。

## 対象外
- 月次/週次/日次の共通タブ(`AttendanceMonthReferenceTabs`)のタブ・週・日のURL化(論点3)。
- 付与ルールの展開とその中の氏名絞り込み、運用コマンドの選択、3交代制シフト表の対象部署・対象月(論点3)。
- `MultiUserPicker`の名前表示(論点3)。
- フォーム入力中の値・一括操作のチェック・行の展開状態の保持。
- 既に規則どおりの画面の共通hookへの書き換え、既存のキー名(snake_case・`requestPage`等)の改名。
- e2eの追加(既存e2eが壊れていないかの確認のみ)。

## ドキュメントへの影響
- `.claude/skills/ui-interaction-patterns/SKILL.md` §2.10: 統一規則・共通hook・ID選択入力の名前表示を追記。
- `docs/15-usecases-admin.md` 等のユースケース: 画面のURL形式は記載がないため変更なし(実装時に
  `grep`で`?user=`の記載がないことを確認する)。
- API仕様: OpenAPI注釈(`/users/search`の`ids[]`)を更新。

## モック・アセット
なし

## 実装対象
- backend: `app/Http/Controllers/Api/UserController.php`(search)、`tests/Feature/UserManagement/UserSearchAndAccessTest.php`
- frontend:
  - `src/api/users.ts`、`src/hooks/useUsers.ts`、`src/hooks/useSearchParamsState.ts`(新規)+テスト
  - `src/components/UserPicker/`(本体・story・test)
  - `src/pages/attendance/`(AttendanceReferencePage・WeekAttendancePage・AttendanceMonthsPage)
  - `src/pages/approvals/ApprovalsPage.tsx`
  - `src/pages/workCalendar/`(WorkCalendarListPage・WorkStylesPage)
  - `src/pages/admin/`(UserManagementAccessPage・DeviceListPage・RoleDefinitionPage)
  - `src/pages/expense/ExpenseEntryPresetListPage.tsx`、休暇申請一覧3種(MyPaidLeave・MySpecialLeave・MyCompensatoryLeave)
  - 上記各画面のテスト、`searchUsers`をモックしている既存テストのうち必要なもの
  - `e2e/scenario-06-attendance-corrections.spec.ts`(コメントのみ)
- `.claude/skills/ui-interaction-patterns/SKILL.md`

## 検証方法
- `cd backend && php artisan test --filter=UserSearchAndAccessTest`
- `cd frontend && npm run test`(変更画面・UserPicker・共通hook)、`npm run build`、`npm run lint`
- e2eのうち勤怠参照・休暇管理・承認を通るspecの実行(環境が許せば)

## レビュー履歴
- 2026-11-15 初版
- 2026-11-15 独立設計レビュー(反証)の指摘を反映。原因特定(UserPicker)とクエリが落ちる経路がないことは
  妥当と確認された。対応:
  - 有給付与設定・特別休暇管理の氏名絞り込み`?q=`を撤回し対象外へ(展開した行ごとの絞り込みで、1キーに
    対応しない)。運用コマンド`?command=`も入力フォームの一部として対象外へ。3交代制シフト表・
    `MultiUserPicker`を対象外として明記。
  - キー名規則を「既存の同概念キーに合わせる」に改め、管理範囲を`group_type_id`へ。
  - 既定値の規則4を、日付で変わる既定値(今月・今週)は選んだら書く、に修正(URL共有で月がずれるため)。
  - `/users/search`の`ids`指定時の`per_page`、名前取得で`permission`を渡さないことを明記。
  - `searchUsers`に引数を足して再利用する(既存テストのモックを生かす)、クエリキー・storyの差し込み方を明記。
  - 週次勤怠の月曜への寄せと下書きのリセット、`year`の検証、`requestPage=1`の削除、URL書き換えのテスト方法、
    e2eコメントの修正を追記。

## 実装結果
未着手
