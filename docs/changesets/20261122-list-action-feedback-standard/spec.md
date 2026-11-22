# 一覧からの操作と変更操作のフィードバックの標準化(バックオフィスの一括担当者変更のモーダル化を含む)

ステータス: 検討中

## 変更要望(原文)
> ステータスの変更が画面からできなくなっています。また、基本的に変更作業は確認画面なりフィードバックを返してください。
> 今回の担当者一括変更はモーダル画面で変更先の担当者を選ぶようにしてください。
>
> また、一覧からのアクションなどのUXを統一してください。ネットで調べて標準化をお願いします。
> (調査によっては今回の一括変更も再度検討して良いです。)

(AskUserQuestionでの回答: 完了・取消後のステータス変更=遷移ルールに従う(現状維持)/承認の確認=一括・個別とも確認/対応範囲=全画面に適用)

## 背景・目的
変更操作の多くは、確認も成功の知らせも無く、成功したのか分かりにくい。一覧の一括操作も画面ごとに置き場所・入力・確認・結果の示し方が違う。
外部の設計標準に沿ってガイドライン(`ui-interaction-patterns`)と共通部品を定め、全画面に適用する。

## 現状(As-Is)
- 「ステータスの変更が画面からできない」: 再現しなかった(investigatorがAPIのFeatureテスト・Vitestで確認。詳細画面`BackOfficeTaskDetailPage.tsx`:209-231の状態変更は動く)。
  ただし次の3点で「変えられない」と見える: (1)成功しても何も表示されない(上部のバッジだけ変わる) (2)申請種別の遷移ルールで許可されない状態も選択肢に出て、
  選ぶと422になるが、エラーはボタンから離れたカード上部に出る (3)完了・取消のタスクにも状態フォームが出る。
- 棚卸し(investigator、約90画面):
  - 一括操作のある一覧: `ApprovalsPage`(まとめて承認。Card actions直置き、確認なし、成功は選択解除のみ)、`WorkflowRequestListPage`(まとめて取消。
    Card actions直置き、ConfirmActionDialogで理由入力)、`BackOfficeTaskListPage`(担当者を設定=バー内UserPicker・確認なし、選択したタスクを完了=確認なし)、
    `PaidLeaveSchedulePage`(一括付与。確認あり、結果の成功N件/失敗N件と内訳あり)。勤怠の選択モード(`AttendanceSelectionActionBar`)は申請画面への遷移のみ。
    部分失敗時、Approvals・WorkflowListは成功分も選択に残る(再実行で二重実行の恐れ)。
  - 行内操作: 確認なしで状態が変わるもの(連携の有効/無効、自動付与トグル)、確認なしのトークン再発行(旧トークンが失効)。§2.3に反し行内にボタンを複数並べる画面
    (`ExternalIntegrationConnectionsPage`・`DeviceListPage`・`MyIntegrationsPage`)。
  - 詳細・編集画面: 成功時に何も表示しない変更操作が多い(承認・差戻し・提出、備品の約20操作、担当者設定・状態変更、保存後に遷移するだけの編集画面等)。
    成功表示がある画面も、表示方法がばらばら(Badge「保存しました」、text-successの文、なし)。
  - 共通部品: `ConfirmActionDialog`(トリガー内蔵・入力欄・Promiseで成功時に閉じる)、`ui/dialog`、`AttendanceSelectionActionBar`、`PaidLeaveScheduleBulkGrantBar`。
    **トースト基盤は無い**(`ui-interaction-patterns` §2.18「現状Toast基盤は存在しない」)。
- ガイドライン: `.claude/skills/ui-interaction-patterns/SKILL.md` §2.3(行内操作)、§2.12(破壊的操作)、§2.13(状態変更)、§2.18(フィードバック)、§2.21(一括操作)。

## 設計前提の検証
- 正データと派生データ: 画面の操作方法の変更が中心で、データ構造は変えない。追加するのは、詳細画面の選択肢を絞るための`allowed_next_statuses`(遷移ルールから
  計算する派生値。`BackOfficeTaskTransitionPolicy`を使い、新しい正データは持たない)のみ。
- 定義と実態: §2.18の「Toast基盤が無いので成功は画面遷移・一覧更新・インラインで」という方針の結果、成功が伝わらない画面が多い。方針自体を改める。
- 原因の分類(ステータス変更): 実装のミス(フィードバックとエラー位置、許可されない選択肢の表示)。
- 横展開: ユーザー指示により全画面。
- 一覧のページング: 変更なし。

## 外部の標準(調査結果)
- 一括操作: 選択したときだけ表に接して操作バーを出し、選択件数を常に見せ、取消(選択解除)を末尾に置く。バーの操作は5つ程度までで、超える分はメニューへ。
  すべての操作は選択可能な全行に効くようにする(Carbon Data table、オーストラリア政府 Agriculture Design System、Elastic EUI、NN/g「Bulk Actions: 3 Design Guidelines」)。
- 入力が必要な操作: 短い作業はモーダルで入力させる(Atlassian Modal dialog)。実行ボタンは具体的な動詞(Atlassian Designing messages)。
- 実行後: 完了した操作は短い成功通知(Atlassian Flag、Polaris Toast、Material Snackbar)。通知は1件ずつ、エラーをトーストだけで知らせない(Polaris: 持続するエラーはBanner)、
  表示時間は十分に長く(Polaris: 10秒以上)。一括操作は件数と、一部失敗の内訳を示す(一部だけ変わって説明が無いのは代表的な失敗)。
- 確認: 取り消せない・重大な操作に限って確認し、確認文は「操作」ではなく「結果」を書く。取り消せる操作は確認せず、結果を知らせる(Undoがあれば添える)。
出典は本ファイル末尾の「参考」。

## 仕様検討

### 論点1: 成功フィードバックの手段
- 選択肢:
  - A. トースト基盤を導入し、成功は全画面でトーストで知らせる(エラーはインラインのまま)
  - B. 画面ごとのインラインの成功メッセージで統一する
- 決定: A。shadcn/uiが推奨するトーストライブラリ`sonner`を導入し、`components/ui/sonner.tsx`(`Toaster`)を`App`のルートに1つ置く。
  呼び出しは薄いラッパー`notify.success(message, { description? })`(`src/lib/notify.ts`)に限定し、画面から直接`toast()`を呼ばない。
- 規則: 成功のみトーストにする。表示は右下、8秒、ホバー中は止める、同時表示は最大3件。文言は「何がどうなったか」(例「ステータスを処理中に変更しました」
  「3件のタスクの担当者を山田 太郎さんに変更しました」)。「成功しました」等の汎用文言は使わない。エラー・部分失敗はトーストにせず、操作した場所
  (ダイアログ内・ボタンの直近)に`ErrorMessage`で出す。保存後に別画面へ遷移する場合も、遷移先でトーストが見えるので同じくトーストで知らせる。
- 理由: 画面をまたいでも見え(保存→一覧への遷移)、表示位置・文言を全画面で揃えられる。インラインは画面ごとに置き場所が変わり、遷移すると消える。
- 未確定・要確認事項: なし

### 論点2: 変更操作の確認の基準
- 決定(操作の分類。§2.12を置き換える):
  | 分類 | 例 | 確認 | 実行後 |
  |---|---|---|---|
  | 取り消せる・単一対象・フォームで値を選んで実行 | 保存、ステータス変更、担当者変更、有効/無効の切替 | なし | 成功トースト |
  | 取り消せない・重大 | 削除、取消、却下、承認(個別・一括とも。ユーザー回答)、トークン再発行、失効、確定 | 確認ダイアログ(結果を書く) | 成功トースト |
  | 一括操作(すべて) | まとめて承認、担当者を一括変更、一括完了、一括付与、まとめて取消 | 一括操作ダイアログ(対象件数・入力・結果を書く) | 成功トースト(件数)/一部失敗はダイアログ内に内訳 |
- 理由: 外部標準どおり、確認は取り消せない操作に限り、全ての変更に結果の知らせを返す(ユーザー要望「確認画面なりフィードバックを返す」)。一括操作は対象を誤るリスクが高いため、
  取り消せる操作でも件数を示すダイアログを必ず通す。
- 未確定・要確認事項: なし

### 論点3: 一括操作の構成(共通部品)
- 選択肢:
  - A. 画面ごとに今の実装を直す
  - B. 共通部品`BulkActionBar`と`BulkActionDialog`を作り、全ての一括操作をこれで組む
- 決定: B
  - `components/BulkActionBar`: 表の直上(Card本文の先頭)に、選択があるときだけ出す。「n件を選択中」・操作ボタン(最大4つ。超える分は「その他」DropdownMenu)・
    「選択を解除」。モバイルは§2.21の2行構成(1行目: 件数と選択解除、2行目: 横スクロールの操作列)。ボタンはダイアログを開くだけで、バー内に入力欄を置かない
    (前回の「UserPickerを二重描画できない」例外は不要になる)。押せない操作はdisabled+理由(§2.14)。
  - `components/BulkActionDialog`: タイトル(動詞)・対象の要約(「選択した3件」と、必要なら対象の内訳や対象外の件数)・入力欄(children)・結果の説明・
    実行ボタン(「3件を〇〇」)・キャンセル。実行中は閉じられない。実行は1件ずつの結果を集める(`Promise.allSettled`。一括APIがある操作はその結果)。
    全件成功: ダイアログを閉じ、選択を解除し、成功トースト「n件の〜を〜しました」。一部失敗: ダイアログを開いたまま「n件成功/m件失敗」と失敗した行と理由の一覧を出し、
    成功した行は選択から外し、失敗した行だけ選択に残す(再実行で二重実行しない)。閉じたら成功分のトーストを出す。
  - 既存の`AttendanceSelectionActionBar`(申請画面への遷移のみ)と`PaidLeaveScheduleBulkGrantBar`は、`BulkActionBar`/`BulkActionDialog`で置き換える。
- 理由: 置き場所・件数表示・確認・結果表示・部分失敗の扱いを部品の中で一度だけ実装し、画面ごとのずれを無くす。
- 未確定・要確認事項: なし

### 論点4: 行内操作と単一対象の操作
- 決定:
  - 行内に直接置くのは、その一覧の主目的の操作1つまで(§2.3)。2つ以上は行末の「⋯」DropdownMenuにまとめる(`ExternalIntegrationConnectionsPage`・`DeviceListPage`・`MyIntegrationsPage`)。
  - 有効/無効などの切替は確認なしで即実行し、成功トースト(「〇〇を無効にしました」)。失敗時は行の直近にエラーを出し、表示を元に戻す。
  - 確認ダイアログは`ConfirmActionDialog`を使い、文言は結果を書く(「承認すると取り消せません。申請者に承認の通知が届きます。」等)。
  - フォーム送信のエラーは、送信ボタンの直近(フォームの末尾)に`ErrorMessage`で出す(カード上部にまとめない)。
- 未確定・要確認事項: なし

### 論点5: バックオフィスタスクの画面(本要望の直接の対象)
- 一覧の一括操作(`BulkActionBar`): 「担当者を変更」「完了にする」。
  - 「担当者を変更」→`BulkActionDialog`: UserPicker(変更先)、要約「選択した3件(未割り当て1件・割り当て済み2件)」、説明「ステータスは変わりません。未着手のタスクは確認中になります
    (申請種別の遷移ルールで許可されている場合)。新しい担当者と前の担当者に通知されます。」、実行「3件の担当者を変更」。完了・取消の行は選択できない(現状どおり)。
  - 「完了にする」→自分のタスクだけが選ばれているときだけ押せる(現状どおり、理由付きdisabled)。`BulkActionDialog`: 「選択した3件を完了にします。完了すると担当者を変更できなくなります。」、
    実行「3件を完了にする」。結果は一括完了APIの結果(全件成功か全体エラー)。
- 詳細画面:
  - 担当者: 「担当者を変更」ボタン→ダイアログ(UserPicker・説明・「変更する」)。成功トースト「担当者を山田 太郎さんに変更しました」。
  - ステータス: 選択肢は`allowed_next_statuses`(API)と現在の状態だけにする。移れる状態が無ければフォームの代わりに「この状態から変更できるステータスはありません」。
    「更新する」の成功トースト「ステータスを処理中に変更しました」。エラーはフォームの直近。完了・取消後も遷移ルールで許可されていれば変更できる(ユーザー回答=現状維持)。
  - backend: `BackOfficeTaskResource`に`allowed_next_statuses: string[]`(現在の状態を除く全ステータスのうち`BackOfficeTaskTransitionPolicy::isAllowed`が真のもの)を追加。
- 未確定・要確認事項: なし

### 論点6: 全画面への適用範囲
- 決定: 棚卸しの結果(`assets/inventory.md`)の全項目に、論点1〜4を適用する。対象:
  - 一括操作: Approvals(まとめて承認=確認つき一括ダイアログ)、WorkflowRequestList(まとめて取消)、BackOfficeTaskList、PaidLeaveSchedule(一括付与)、
    勤怠の選択モード(`BulkActionBar`へ置き換え。操作は申請画面への遷移のまま)。
  - 確認の追加: 個別の承認(ワークフロー・経費・承認一覧の詳細Dialog)、トークン再発行。
  - 行内操作の整理: ExternalIntegrationConnections、DeviceList、MyIntegrations。
  - 成功トーストの追加: 棚卸しで「フィードバックなし」「遷移のみ」の全変更操作と、既存のインライン成功表示(SystemSettingsの「保存しました。」、UserRoleEditのBadge、
    AttendanceDayの「実績を作成しました。」等)の置き換え。申請送信など既にある成功表示も同じトーストに揃える。
- 理由: ユーザー回答(全画面に適用)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- ガイドライン: `.claude/skills/ui-interaction-patterns/SKILL.md`の§2.3・§2.12・§2.18・§2.21を論点1〜4の内容に書き換え(参照実装を`BulkActionBar`・`BulkActionDialog`・
  `notify`に)。`ui-design-system`にトーストの見た目(色・位置)を追記。`add-page`/`add-frontend-component`のチェックリストに「変更操作は成功トーストと§2.12の分類」を追加。
- frontend 共通: `sonner`追加、`components/ui/sonner.tsx`、`src/lib/notify.ts`、`components/BulkActionBar`、`components/BulkActionDialog`(各story+test)。
  テストでは`notify`をモックして呼ばれた文言を確かめる(テスト用ヘルパーを用意)。
- frontend 画面: 論点5・6の全画面。
- backend: `BackOfficeTaskResource`の`allowed_next_statuses`と、その Feature テスト(遷移マスタあり・なし・WorkflowRequest以外の発生源・完了後)。
- テスト: 各画面のVitestで、確認ダイアログの表示・実行で`notify.success`が期待の文言で呼ばれる・部分失敗の内訳表示と選択の残り方・エラーがフォーム直近に出ることを確かめる。
  e2eは、文言・操作手順が変わる箇所(承認の確認ダイアログ、バックオフィスの担当者変更、一括付与等)を`grep`で探して追随する。

## 受け入れ条件
- バックオフィスの一覧で、担当者の一括変更がモーダルで変更先を選んで行え、結果が件数付きで通知される。一括完了も確認のうえ通知される。
- 詳細画面のステータス変更は、移れる状態だけが選択肢に出て、成功時に通知され、失敗時はフォームの直近にエラーが出る。
- 棚卸しの全画面で、変更操作が成功トーストで知らされ、取り消せない操作と一括操作は確認(ダイアログ)を経る。一括操作は`BulkActionBar`/`BulkActionDialog`で統一され、
  一部失敗時は内訳が出て失敗分だけ選択に残る。
- ガイドライン(スキル)が更新されている。
- Vitest全件・型チェック・lint、backendの関連テストがPASSする。

## 対象外
- Undo(取り消し)機能の追加(取り消し用のCommand/Eventが要るため。トーストには付けない)
- 一括操作用の新しい一括API(既存の単体APIを1件ずつ呼ぶ。バックオフィスの一括完了は既存の一括APIのまま)
- 完了・取消後のステータス変更の禁止(ユーザー回答により現状維持)

## ドキュメントへの影響
- `.claude/skills/ui-interaction-patterns/SKILL.md`、`.claude/skills/ui-design-system/SKILL.md`、`.claude/skills/add-page/SKILL.md`、`.claude/skills/add-frontend-component/SKILL.md`
- `docs/11-usecases-backoffice.md`: 一括操作がダイアログで行われること、詳細画面の選択肢が遷移ルールで絞られること。
- `docs/16`・`docs/17`: 変更なし。

## モック・アセット
- [assets/inventory.md](./assets/inventory.md): 棚卸し結果(画面・操作・現状・適用後)
- [assets/wireframes.md](./assets/wireframes.md): 一括操作バー・一括操作ダイアログ・部分失敗・トーストのワイヤーフレーム

## 実装対象
- backend: `app/Http/Resources/BackOfficeTaskResource.php`、`tests/Feature/BackOffice/`
- frontend: `package.json`、`components/ui/sonner.tsx`、`lib/notify.ts`、`components/BulkActionBar`・`BulkActionDialog`、`App.tsx`、論点6の各画面と test/story、e2eの該当箇所
- skills・docs: 上記

## 検証方法
- `cd frontend && npx vitest run --project unit`、`npx tsc -p tsconfig.app.json --noEmit`、`npx oxlint src`
- `cd backend && ./vendor/bin/phpunit --filter=BackOffice`

## レビュー履歴
初版

## 実装結果
未着手

## 参考
- Carbon Design System, Data table usage: https://carbondesignsystem.com/components/data-table/usage/
- Agriculture Design System, Selectable table with batch actions: https://design-system.agriculture.gov.au/patterns/selectable-table-with-batch-actions
- Elastic EUI, Table selection: https://eui.elastic.co/docs/patterns/tables/table-selection/
- NN/g, Bulk Actions: 3 Design Guidelines: https://www.nngroup.com/videos/bulk-actions-design-guidelines/
- Atlassian, Success messages / Designing messages: https://atlassian.design/foundations/content/designing-messages/success-messages
- Atlassian, Flag: https://atlassian.design/components/flag
- Shopify Polaris, Toast: https://polaris.shopify.com/components/toast
- Material Design, Snackbars: https://material.io/components/snackbars
- Eleken, Bulk actions UX: https://www.eleken.co/blog-posts/bulk-actions-ux
- SaaS UI, Bulk actions UX patterns: https://www.saasui.design/blog/saas-bulk-actions-ux-patterns
