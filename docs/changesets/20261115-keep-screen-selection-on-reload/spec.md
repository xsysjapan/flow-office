# 画面の選択状態(対象社員・年月・絞り込み・ページ)をリロード後も保つ

ステータス: 完了

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

### 論点3: 対応する状態の範囲(横展開)
- 選択肢:
  - A. 勤怠参照の社員名だけ直す。
  - B. 現在URLパラメーターに載っている項目だけを対象に、既存のURL同期の書き方を揃える。URLに載って
    いない状態(`useState`のみ)を新たにURLへ移すことはしない。
  - C. 「画面に何を表示するか」を決める状態はすべてURLへ移す(週次勤怠の週・勤務カレンダー一覧と働き方一覧の
    ページ・管理範囲の絞り込み・端末一覧の詳細パネル等)。
- 決定: B
- 理由: ユーザー指示(2026-11-16)「現在、URLパラメーターに設定している項目のみ対応。既存実装の中で整合性を
  保つ対応」。初版ではCとしていたが撤回した。
  - 週次勤怠の`?start=`は読み取りのみで画面から書いていないため、「URLに設定している項目」に含めず対象外。
  - `useState`のみで持つ状態(上表の週次勤怠の移動・ページ・絞り込み・詳細パネル等)は対象外。
- 未確定・要確認事項: なし

### 論点4: URL同期済み画面の書き方の統一(履歴・キー名・不正値)
- 選択肢:
  - A. 共通hookを新設して書き方を揃える。
  - B. 共通hookは作らず、既存の参照実装(承認一覧`ApprovalsPage`の`updateParams`: `setSearchParams`の関数形式で
    `prev`を複製し、`{ replace: true }`)に各画面を合わせる。
- 決定: B。揃える規則は次のとおり。
  1. 履歴は`replace`(既存の多数派・参照実装と同じ)。`push`になっている勤怠参照・勤怠月次一覧・ロール定義・
     経費プリセット一覧・休暇申請一覧3種(有給・特別休暇・代休)のページ`requestPage`を`replace`へ。
  2. 画面の対象社員のキー名は`userId`(休暇の管理画面3つと同じ)。勤怠参照の`user`を`userId`へ改める
     (旧`?user=`は読み込み時に`userId`として扱い、URLを`userId`へ置き換える。現状`?user=`を組み立てる
     リンク・e2eはないことを確認済み)。その他の既存キー名(`requestPage`・監査ログの`user_id`等の
     API由来のsnake_case)は変えない。
  3. 年は`YYYY`、年月は`YYYY-MM`。不正値は既定値として表示し、URLからそのキーを消す(表示とURLを一致させる)。
     対象: 勤怠参照の`yearMonth`(現在は表示だけ既定値でURLはそのまま)、承認一覧の`yearMonth`(現在は検証なし
     でAPIへ渡す。不正なら絞り込みなしとして扱う)、勤怠月次一覧の`year`(現在は検証なし。不正ならキーなしと同じ「すべての年」)。
  4. 既定値の書き方は各画面の現状を維持する(勤怠参照の「今月」ボタンで`yearMonth`を書く挙動等は変えない)。
     休暇申請一覧の`requestPage`は1ページ目に戻すときにキーを消す(他の一覧の`page`と同じ)。
- 理由: ユーザー指示「既存実装の中で整合性を保つ」に沿い、新しい仕組みは足さず参照実装に合わせる。
  `replace`は既存の多数派。
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
- 画面ごとの変更(論点4。書き方は`ApprovalsPage`の`updateParams`に合わせる):

| 画面 | 変更 |
|---|---|
| 勤怠参照 `pages/attendance/AttendanceReferencePage.tsx` | `user`→`userId`(旧キーを読み替えてURLを置き換え)、replace、不正な`yearMonth`はURLから消す |
| 勤怠月次一覧 `pages/attendance/AttendanceMonthsPage.tsx` | push→replace、不正な`year`はURLから消して「すべての年」(キーなしの既定表示) |
| 承認一覧 `pages/approvals/ApprovalsPage.tsx` | 不正な`yearMonth`はURLから消して絞り込みなし(APIへ渡さない) |
| ロール定義 `pages/admin/RoleDefinitionPage.tsx` | push→replace |
| 経費プリセット一覧 `pages/expense/ExpenseEntryPresetListPage.tsx` | push→replace |
| 休暇申請一覧3種(`MyPaidLeavePage`・`MySpecialLeavePage`・`MyCompensatoryLeavePage`) | `requestPage`をpush→replace、1ページ目はキーを消す |
| 有給付与設定・特別休暇管理・代休管理 | 変更なし(UserPickerの修正で社員名が出る) |

- 各画面のテスト: `MemoryRouter`の`initialEntries`にURLを与えて状態が復元されることを確認する(勤怠参照は
  社員名と年月が表示されること)。URLの書き換え(`user`→`userId`・不正なキーの削除)は、テスト内で
  `useLocation()`の`search`を表示する小さな部品を一緒に描画して検証する。push/replaceの違いは
  テストで直接確かめにくいため、`setSearchParams`呼び出しに`{ replace: true }`を渡していることをコード
  レビューで確認する。
- `ui-interaction-patterns` §2.10に論点4の規則(replace・社員は`userId`・不正値はキーを消す・参照実装は
  `ApprovalsPage`の`updateParams`)と「社員などIDで選ぶ入力は、IDだけ渡されても名前を表示できること」を追記。

## 受け入れ条件
- 勤怠参照で社員・年月を選んでリロードすると、社員名・年月・勤怠が選んだまま表示される。
- 有給付与設定・特別休暇管理・代休管理でも、社員を選んでリロードすると社員名が表示される。
- 承認者に権限で絞った`UserPicker`でも、選んだ社員の名前は権限に関係なく表示される。
- 旧URL`/admin/attendance?user=<id>`を開くと同じ社員が表示され、URLが`userId`になる。
- 不正な年月・年のURL(勤怠参照・承認一覧・勤怠月次一覧)を開くと既定の表示になり、URLからそのキーが消える。
- 論点4の対象画面で、URLに載る選択・ページを変えてもBrowser Backで1つ前の選択に戻らず、前の画面へ戻る。
- backend・frontendの関連テスト、`npm run build`(型チェック)、lintがPASSする。

## 対象外
- 現在URLに載っていない状態(`useState`のみ)のURL化: 週次勤怠の週の移動(`?start=`は読み取りのみのまま)、
  勤務カレンダー一覧・働き方一覧のページ、管理範囲の絞り込み、端末一覧の詳細パネル、月次/週次/日次の
  共通タブ、付与ルールの展開と氏名絞り込み、運用コマンドの選択、3交代制シフト表、フォーム入力中の値・
  一括操作のチェック(論点3)。
- 共通hookの新設、既に規則どおりの画面の書き換え、既存のキー名(snake_case・`requestPage`等)の改名。
- `MultiUserPicker`の名前表示(初期値付きで使う画面がなく症状が出ない)。
- e2eの追加(既存e2eが壊れていないかの確認のみ)。

## ドキュメントへの影響
- `.claude/skills/ui-interaction-patterns/SKILL.md` §2.10: 論点4の規則とID選択入力の名前表示を追記。
- `docs/`配下のユースケース: 画面のURL形式は記載がないため変更なし(実装時に`grep`で`?user=`の記載がない
  ことを確認する)。
- API仕様: OpenAPI注釈(`/users/search`の`ids[]`)を更新。

## モック・アセット
なし

## 実装対象
- backend: `app/Http/Controllers/Api/UserController.php`(search)、`tests/Feature/UserManagement/UserSearchAndAccessTest.php`
- frontend:
  - `src/api/users.ts`、`src/hooks/useUsers.ts`
  - `src/components/UserPicker/`(本体・story・test)
  - `src/pages/attendance/`(AttendanceReferencePage・AttendanceMonthsPage)
  - `src/pages/approvals/ApprovalsPage.tsx`
  - `src/pages/admin/RoleDefinitionPage.tsx`、`src/pages/expense/ExpenseEntryPresetListPage.tsx`
  - `src/pages/paidLeave/MyPaidLeavePage.tsx`、`src/pages/specialLeave/MySpecialLeavePage.tsx`、
    `src/pages/compensatoryLeave/MyCompensatoryLeavePage.tsx`
  - 上記各画面のテスト、`searchUsers`をモックしている既存テストのうち必要なもの
- `.claude/skills/ui-interaction-patterns/SKILL.md`

## 検証方法
- `cd backend && php artisan test --filter=UserSearchAndAccessTest`
- `cd frontend && npm run test`、`npm run build`、`npm run lint`

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
- 2026-11-16 ユーザー指示「現在、URLパラメーターに設定している項目のみ対応。既存実装の中で整合性を保つ対応」
  により範囲を縮小。論点3をB(URL済みの項目のみ)に変更し、`useState`のみの状態のURL化(週次勤怠の週・
  ページ・管理範囲・端末一覧の詳細パネル)と共通hookの新設を撤回。論点4は参照実装`ApprovalsPage`に合わせる
  方針とし、既定値の扱いは各画面の現状維持とした。ユーザー承認済みとして実装中へ。
- 2026-11-16 実装中の修正: 勤怠月次一覧の不正な`year`を「今年」としていたが、キーなしの既定表示は
  「すべての年」であり、キーを消すと表示とURLが一致しなくなるため「すべての年」に改めた(規則3どおり)。

## 実装結果
- コミット: `132ff6a`(backend)、本変更セットを完了にしたコミット(frontend・スキル)
- backend: `app/Http/Controllers/Api/UserController.php`(search に`ids[]`、OA注釈)、
  `tests/Feature/UserManagement/UserSearchAndAccessTest.php`(6件追加)
- frontend: `api/users.ts`・`hooks/useUsers.ts`(`useUsersByIds`)、`components/UserPicker/`(本体・story `WithValue`・
  test 6件)、`pages/attendance/AttendanceReferencePage`・`AttendanceMonthsPage`、`pages/approvals/ApprovalsPage`、
  `pages/admin/RoleDefinitionPage`、`pages/expense/ExpenseEntryPresetListPage`、休暇申請一覧3種(各テスト追加)
- `.claude/skills/ui-interaction-patterns/SKILL.md` §2.10に規則4点を追記
- 検証:
  - `./vendor/bin/phpunit tests/Feature/UserManagement/UserSearchAndAccessTest.php`: 12 passed
  - `npx vitest run --project unit`: 149ファイル、1142 passed / 2 skipped / 失敗0
  - `npx tsc -p tsconfig.app.json --noEmit`: エラー0、`npm run lint`: error 0(warningは変更前と同じ20件)
  - storybookプロジェクト(ブラウザ実行)はこの環境で起動できず未実行。story `WithValue`は型チェックのみ
- 受け入れ条件との対応: 勤怠参照のリロード後の社員名・年月(AttendanceReferencePage.test)、休暇管理3画面と
  権限で絞ったUserPickerの名前表示(UserPicker.test: permissionを渡さずidsで取得)、旧`?user=`の置き換え・
  不正な年月/年のキー削除(各ページテストのLocationProbe)、replace化(コードレビューで全`setSearchParams`に
  `{ replace: true }`を確認)。
- ドキュメント: `docs/`配下で`?user=`を参照するのは過去の変更セット(20260829)の記録のみで、変更不要。
