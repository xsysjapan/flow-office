# list-search-form-standard

ステータス: 完了

## 変更要望(原文)
> 承認とタスク一覧の検索フォームの作りが統一されていません。UXを統一したいのでルール化してもらえますか？

追加の回答(仕様確認時):
- キーワード検索の反映方式: 「どちらがいいのでしょうか」→ 推奨案(入力中に自動反映)で提案する
- 主軸(承認一覧の状態・タスク一覧の担当者区分)の見せ方: 「一覧の形式は統一してください」
- 適用範囲: 「検索のある全一覧」

## 背景・目的
一覧ごとに検索フォームの置き場所・ラベルの有無・反映タイミング・クリアの文言・タブとセレクトの使い分けがばらばらで、
画面を移るたびに操作を覚え直す必要がある。`ui-interaction-patterns`に検索フォームの標準形を定め、共通部品を作り、
検索のある全一覧をその形にそろえる。

## 現状(As-Is)
`ui-interaction-patterns` §2.20 は「[検索] [フィルター▼]」の図と数行の方針だけで、配置・ラベル・反映方式・クリア文言・タブの基準を定めていない。
§4.1 は List の参照実装を `ApprovalsPage` としている。共通の FilterBar / 検索入力部品は存在しない。

検索・絞り込みのある一覧(13画面)の現状:

| # | 画面 | 項目 | ラベル | 反映 | クリア | URL | 置き場所 | 主軸タブ |
|---|---|---|---|---|---|---|---|---|
| 1 | `approvals/ApprovalsPage` `/approvals` | 状態(select, 既定=承認待ち)、年月 | 可視 | 即時 | 「フィルターをクリア」 | useSearchParams | Card本文 `flex-wrap` | なし(状態はselect) |
| 2 | `backOffice/BackOfficeTaskListPage` `/backoffice-tasks` | 担当者区分タブ(件数)、キーワード、ステータス(既定=未完了) | aria-labelのみ | キーワードは「検索」ボタン/Enter、他は即時 | 「クリア」 | KeepingBackState | 専用Card「タスク検索」`flex-col sm:flex-row` | あり(件数付き) |
| 3 | `workflow/WorkflowRequestListPage` `/requests` | 状態、種別 | 可視 | 即時 | 「フィルターをクリア」 | useSearchParams(`subjectType`) | Card本文 | なし |
| 4 | `admin/UserListPage` `/admin/users` | キーワード、グループ種別、グループ、アカウント状態、Microsoft未連携・外部HR管理(checkbox) | キーワード・selectはplaceholder/aria-labelのみ | 即時(1打鍵ごと) | 「フィルターをクリア」 | useSearchParams | Card本文 2段 | なし |
| 5 | `asset/AssetListPage` `/assets` | キーワード + 個別テキスト6項目 + select3項目 | 可視 | 即時(1打鍵ごと) | 「フィルターをクリア」 | useSearchParams | Card本文 | なし |
| 6 | `admin/AuditLogPage` `/admin/audit-log` | 対象タイプ・対象ID・イベント種別・ユーザーID(text)、期間(DatePicker×2) | 可視 | 即時(1打鍵ごと) | 「フィルターをクリア」 | useSearchParams | Card本文 grid3列 | なし |
| 7 | `expense/ExpenseEntryPresetListPage` `/expenses/presets` | 名称(text)、経費区分 | 可視 | 即時(1打鍵ごと) | 空状態のみ「検索条件をクリア」 | KeepingBackState | Card本文 grid2列 | なし |
| 8 | `paidLeave/PaidLeaveSchedulePage` `/admin/paid-leave/schedule` | 状態(URLキー`filter`)、社員名、付与予定日(DateRange) | 可視 | 社員名はblur/Enter、他は即時 | 空状態のみ | KeepingBackState(`user_name`) | Card本文 | なし |
| 9 | `notifications/NotificationsPage` `/notifications` | すべて/未読/既読(ボタン群) | ボタン文言 | 即時 | 空状態「すべて表示する」 | useSearchParams | Cardのactions | 擬似タブ(件数なし)。APIは`paginate(20)`だが画面がpageを渡さずPaginationも無いため**21件目以降が見えない**(既存不具合) |
| 10 | `admin/UserManagementAccessPage` `/admin/groups` | グループ種別 | aria-labelのみ | 即時 | 空状態のみ「絞り込みをクリア」 | useSearchParams | Card本文 | なし |
| 11 | `attendance/AttendanceMonthsPage` `/attendance/months` | 年 | aria-labelのみ | 即時 | なし | useSearchParams | Cardのactions | なし |
| 12 | `admin/DeviceListPage` `/admin/devices` | 削除済みを表示(トグルボタン) | ボタン文言 | 即時 | なし | useSearchParams(`show_deleted`) | Cardのactions | なし |
| 13 | `attendance/AttendanceReferencePage` `/admin/attendance` | 対象社員、年月 | 一部 | 即時 | なし | useSearchParams | Card本文 | なし |

バックエンドの検索語パラメータ: `q`(users, assets, expense-entry-presets)、`search`(backoffice-tasks)、`user_name`(paid-leave schedules)。
件数: backoffice-tasks のみ `meta.assignee_counts`(タブ軸以外の条件=検索語・ステータスを適用した件数。`BackOfficeTaskController::assigneeCounts`)。
承認対象一覧(`GET /workflow-requests/to-approve`、`WorkflowRequestController::indexToApprove` 88〜120行)・通知(`GET /notifications/mine`、`NotificationController` 31〜39行)は件数メタなし。
承認対象一覧の `status=all` は状態で絞らないため、承認者が設定された**他人の下書き(draft)も含まれる**(`WorkflowRequestProjector.php:29` で下書き作成時に approver_user_id が入る。`WorkflowRequestController.php:86` のコメント「draftを検索させない」と実装が食い違う既存不具合)。
`rejected` は status として受け付けるが画面の選択肢には無い。
一覧取得hookのうち `useAuditLog`・`useMyNotifications` は `placeholderData: keepPreviousData` を指定していない(条件変更のたびに表がLoadingに置き換わる)。
通知のベル(`components/NotificationBell`)も `GET /notifications/mine?status=unread` を使っている。

関連ドキュメント: `docs/18-ui-guidelines.md`(画面一覧・戻り先)。

## 設計前提の検証
- 正データと派生データ: 一覧はいずれもProjection/ReadModelの読み取りのみ。追加する件数(`meta.status_counts`)も読み取りクエリで算出し、
  書き込み・イベントは発生しない(設計原則1・2に影響なし)。
- 定義と実態: §2.17・§2.20・§2.21の既存ルール(Initial/Filtered Empty・Pagination・条件変更で選択解除)は実装の多数派と一致しており、
  今回はこれを前提に足りない部分(配置・ラベル・反映方式・文言・タブ基準)を加える。ただし次の既存ルールは今回の決定で書き換える:
  §2.10「`useSearchParams`を使う」→`useSearchParamsKeepingBackState`(論点7)、§2.21「バーは表の直上(Cardの本文の先頭)」→「表の直上(フィルターバーの下)」(論点2)、§2.1 List Pageの図。
- 既存不具合: 通知一覧のページング欠落、承認対象一覧の「すべて」に他人の下書きが混ざる件は、件数タブを足すと表に出る(件数と表示が合わない)ため今回あわせて直す(論点8)。
- イベントの意味: イベントに関わらない。
- 原因の分類: 不具合ではなく、ルール未整備による不統一。
- 横展開: 依頼は承認・タスクの2画面だが、回答により検索のある全一覧(上表)を対象にする。#13 勤怠参照は「一覧の検索」ではなく
  「表示対象(社員・月)の選択」(§2.10 の表示対象の選択)なので対象外とする(論点8)。
- 一覧のページング: 通知(#9)はAPIがページングしているのに画面が1ページ目しか出していないため、`page`のURL同期と`Pagination`を加える。
  #8 付与予定・#10 グループはページングなしのまま(付与予定は対象者数、グループは組織数で頭打ちになり、件数が大きく増えないため。既存判断を維持)。

## 仕様検討

### 論点1: キーワード検索の反映タイミング
- 選択肢:
  - A. 入力中に自動反映(最後の入力から300ms後)。「検索」ボタンは置かない。Enterは待たずに即反映
  - B. キーワードだけ「検索」ボタン/Enterで反映、セレクト等は即時反映(現タスク一覧)
  - C. 全項目を「検索」ボタンで反映
- 決定: A
- 理由: セレクト・年月・チェックボックスは全画面で即時反映しており、キーワードも即時にすれば「何を変えたら反映されるか」が1通りになる。
  B は同じフォームの中で2方式が混在し、ボタンを押し忘れて結果が変わらない混乱が起きる(今回の不統一の根)。C は選ぶたびに押す手間が増え、既存の多数派(即時)から外れる。
  1打鍵ごとに問い合わせている現状(#4・#5・#6・#7)は負荷とURL書き換えが多いので、300msの待ちで抑える。
- 細則: 日本語入力の変換中(`compositionstart`〜`compositionend`)は反映しない。フォーカスが外れたとき(blur)・Enterでは待ちを打ち切って即反映。
  変換確定(`compositionend`)で待ちを開始する(Safari等は最後のinputイベントがcompositionendより前に来るため、inputイベントだけを見ると確定文字が反映されない)。
  前後の空白は除いてから反映し、空ならキーを消す。入力欄の表示は除去しない(「山田 」の末尾空白は打ち続けるために残す)。反映中もフォーカス・カーソル位置は保つ。
  URLの値が**外から**変わった(戻る・クリア・タブ切替で値が変わった)ときだけ入力欄の表示を合わせる。判定は「直前に自分が反映した値と異なる」こととし、反映待ちの間は合わせない。
  画面を離れたら(アンマウント)予約中の反映を取り消す(詳細画面へ移ったあとに発火して遷移先のURLを書き換えないため)。発火時の`onChange`はrefで最新を参照する
  (`useSearchParamsKeepingBackState`のsetterはlocation.stateが変わるたびに作り直される)。
  入力欄に`maxLength`を付け、バックエンドの検証上限に合わせる(タスク`search`100、プリセット`q`100、付与予定`user_name`200。他はバックエンドの上限を実装時に確認し、上限が無ければ100)。
  反映待ちの300ms以内にCSVダウンロード等を押すと、反映済み(URL)の条件で動く。これは許容する。
- 未確定・要確認事項: なし

### 論点2: 一覧の構成(置き場所と並び順)
- 選択肢:
  - A. 一覧Cardの本文先頭に「主軸タブ → フィルターバー → 一括操作バー → 表 → ページング」の順で置く。検索専用Cardは作らず、Cardのactionsに絞り込みを置かない
  - B. 検索専用のCardを一覧Cardの上に置く(現タスク一覧)
  - C. 画面ごとに任せる
- 決定: A
- 理由: 13画面中10画面がCard本文に置いており多数派。actionsは「新規作成・CSVダウンロード」等の画面の操作の場所とし、絞り込みと混ぜない
  (#9・#11・#12 は actions に絞り込みがあり、操作ボタンと見分けにくい)。専用Cardは検索と結果が視覚的に分かれ、タブ(結果の切り替え)との上下関係も崩れる。
  一括操作バーは「選んだ行への操作」なので表の直上(フィルターバーの下)に置く(§2.21の「Cardの本文の先頭」を書き換える)。
- 細則: 一覧の2回目以降の読み込み中・取得エラーは表の場所にだけ出し、主軸タブとフィルターバーは常に残す(条件を直せなくならないように。§2.16の具体化)。
  初回読み込みのみ`LoadingState`で置き換えてよい。一覧取得hookは`placeholderData: keepPreviousData`を必須にする。

承認一覧(作業キュー型)の例:
```
┌ Card: 承認一覧 ───────────────────────────────────────┐
│ [承認待ち 12] [承認済み 30] [差戻し済み 2] [取消 1] [すべて 46]   ← 主軸タブ │
│ 年月                                                              │
│ [2026年11月 ▼]   [検索条件をクリア]                  ← フィルターバー │
│ [3件を選択中] [まとめて承認する]               [選択を解除]  ← 選択時のみ │
│ Table                                                             │
│ Pagination                                                        │
└───────────────────────────────────────────────────┘
```

タスク一覧(作業キュー型・キーワードあり)の例:
```
┌ Card: タスク一覧 ─────────────────────────────────────┐
│ [未割り当て 5] [自分のタスク 3] [他の担当者 8] [すべて 16]          │
│ キーワード                                ステータス                │
│ [タイトル・種別・担当部署・担当者名___]   [未完了 ▼]   [検索条件をクリア] │
│ Table / Pagination                                                │
└───────────────────────────────────────────────────┘
```
- 未確定・要確認事項: なし

### 論点3: 主軸タブ(タブとセレクトの使い分け)
- 選択肢:
  - A. 「作業キュー型」の一覧(自分が処理すべきものを消化する一覧)は、主となる1軸を件数付きタブにする。「台帳型」(記録を探す一覧)はタブを使わずフィルターバーだけ
  - B. 全一覧で状態をタブにする
  - C. 全一覧で状態をセレクトにする(タスクの担当者区分もセレクトに戻す)
- 決定: A
- 理由: ユーザー回答「一覧の形式は統一してください」を、同じ種類の一覧は同じ形にする、と解釈した。
  承認・タスク・通知は「残りがいくつあるか」を見て消化する一覧で、件数付きタブは残数がひと目で分かる。台帳型(申請・社員・備品・監査ログ等)は
  複数条件を組み合わせて探す一覧で、1軸だけタブに出すと条件の優先度が誤って伝わる。B は台帳型で選択肢が多い状態(備品の貸出状態等)に合わない。
  C はタスク一覧の件数表示を失う。
- 細則:
  - 作業キュー型は今回 承認一覧(状態)・タスク一覧(担当者区分)・通知(すべて/未読/既読)の3画面。新しい一覧を作業キュー型にするかは変更セットで判断する。
  - タブは1画面1段まで。選択肢は5つ以下の固定値。既定タブは原則「要対応」(承認待ち・未割り当て)。既定タブを先頭に置き、「すべて」は既定でなければ末尾。
    既定を要対応以外にする場合は変更セットに理由を書く(今回は通知のみ。論点8)。
  - タブごとにInitial Emptyの文言を持つ(例: 「承認済みの申請はありません。」。タスク一覧の`ASSIGNEE_TABS.emptyTitle`と同じ形)。
    Filtered Empty(「条件に一致する…」+「検索条件をクリア」)にするかの判定(`isFiltered`)は**タブを除いた**フィルターバーの条件だけで行う。
  - **全タブに件数を出す**(「承認待ち 12」)。件数は「タブの軸以外の条件(キーワード・フィルター)を適用した件数」(タスク一覧の既存仕様と同じ)。
    件数はAPIの `meta.<軸>_counts` で一覧の取得と同じ応答で返す(別APIを呼ばない)。aria-labelにも件数を含める。
  - タブは `ui/tabs` を使い、モバイルは横スクロール(現タスク一覧と同じ)。タブはURLに載せる(既定値は載せない)。
  - タブを切り替えたら page を消し、選択を解除する。フィルターバーの条件と詳細パネルの対象ID(承認一覧の`requestId`)は保つ。「検索条件をクリア」でタブは戻さない。
  - タブ軸は「状態」として独立したセレクトをフィルターバーに重複して置かない。承認一覧の状態セレクトはタブに置き換える。
    タスク一覧のステータス(未完了・各ステータス)は担当者区分とは別軸なのでフィルターバーのセレクトとして残す。
- 未確定・要確認事項: なし

### 論点4: フィルターバーの項目とラベル
- 選択肢:
  - A. 全項目に可視ラベル(`FormField`、項目の上)。キーワード欄のラベルは「キーワード」とし、placeholderに検索対象を書く
  - B. キーワード欄はplaceholderのみ・セレクトはaria-labelのみでコンパクトにする(現タスク・社員一覧)
- 決定: A
- 理由: §2.19「PlaceholderをLabel代わりにしない」に合わせる。可視ラベルが既に多数派(8画面)。入力後はplaceholderが消え、何で絞っているか分からなくなる。
- 細則:
  - 並び順: キーワード → 主な状態・区分のセレクト → その他のセレクト・チェックボックス → 年月・期間 → 「検索条件をクリア」(末尾)。
  - キーワード欄: ラベル「キーワード」、placeholderは「タイトル・種別・担当部署・担当者名」のように検索対象を「・」区切りで列挙(「〜で検索」は付けない)。`type="search"`。
    対象が1項目だけなら、ラベルをその項目名にする(例: 社員名で探す付与予定は「社員名」、プリセットは「名称」)。
  - セレクトの「すべて」の選択肢文言は「すべて」に統一(「すべての種別」「全アカウント状態」等は使わない。ラベルで何のすべてかは分かる)。
  - チェックボックスはラベルを右に置く(`FormField`は使わない)。高さはほかの項目の入力欄の行にそろえる。
  - 期間は `DateRangePicker` 1つにする(開始・終了の DatePicker 2つに分けない)。年月は `YearMonthPicker`。
  - キーワード以外の自由入力の絞り込み(備品の管理番号、監査ログの対象ID、付与予定の社員名等)も同じ部品(論点9の`TextFilterInput`)を使い、反映方式をそろえる。
  - レイアウト: `flex flex-wrap items-end gap-x-4 gap-y-3`。`sm`未満は各項目が全幅で縦に並ぶ(`w-full`)。`sm`以上の幅は キーワード `sm:w-72`、セレクト `sm:w-44`、
    年月 `sm:w-48`、期間 `sm:w-72` を基本とする(選択肢文言が長い場合のみ広げる)。これらは共通部品 `ListFilterBar` が持つ。
- 未確定・要確認事項: なし

### 論点5: 項目が多い一覧(直接表示する項目の上限)
- 選択肢:
  - A. 直接表示はクリアボタンを除き4項目まで。残りは「詳細条件」ボタンで開閉する領域(フィルターバーの直下にインラインで展開)にまとめる
  - B. 全項目を常に表示する
- 決定: A
- 理由: §2.20「頻繁に使うFilterのみ直接表示し、低頻度Filterは追加Filterにまとめる」を具体化する。備品(10項目)・監査ログ(6項目)・社員(6項目)は全表示だと表より検索欄の方が大きくなる。
  Dialog/Sheetにしない理由: 条件を見ながら結果を確かめる操作で、開閉のたびに結果が隠れると比べにくい。
- 細則:
  - ボタン文言「詳細条件」。詳細条件に既定以外の値が入っている数をボタンに出す(「詳細条件(2)」)。
  - 詳細条件に値が入った状態でURLから開いたときは最初から開いておく。開閉状態自体はURLに載せない(§2.10 一時的な操作状態)。
  - 各画面の振り分け: 
    - 備品: 直接=キーワード・管理区分・貸出状態・設置状態 / 詳細=管理番号・名称・カテゴリ・シリアル番号・通常配置場所・現在設置場所
    - 監査ログ: 直接=イベント種別・ユーザーID・期間 / 詳細=対象タイプ・対象ID
    - 社員: 直接=キーワード・アカウント状態・グループ種別・グループ / 詳細=Microsoft未連携・外部HR管理
- 未確定・要確認事項: なし

### 論点6: クリアとフィルター有効の表示
- 選択肢:
  - A. 文言は「検索条件をクリア」に統一。フィルターバー末尾(既定以外の条件があるときだけ表示)と、Filtered Emptyの両方に置く
  - B. 「フィルターをクリア」に統一(現多数派5画面)
- 決定: A
- 理由: §2.17 の既定文言が「検索条件をクリア」で、キーワードも含めて消す操作なので「フィルター」より範囲が正しい。空状態の文言(4画面で既に使用)とインラインの文言が別になる不統一も解消できる。
- 細則:
  - クリアの対象はフィルターバー(詳細条件を含む)の全項目と page。主軸タブ・詳細パネルの対象ID(`requestId`等)は戻さない。
  - 押下後はキーワード欄の表示も空にする(論点1)。ボタンは `variant="secondary"`。項目が1つだけのフィルターバーでも同じく出す。
  - フィルター有効の表示は「検索条件をクリア」ボタンの出現と「詳細条件(n)」で足りるものとし、各項目の個別ハイライトはしない。
- 未確定・要確認事項: なし

### 論点7: 状態の保持(URL)とキー名
- 選択肢:
  - A. 全一覧で `useSearchParamsKeepingBackState` を使い、キーワードのURLキーは `q`、作業キューのタブ・主な状態は `status`(タスク一覧の担当者区分は既存の `assignee`)に統一する
  - B. フックとキーは画面ごとのまま
- 決定: A
- 理由: §2.10・§2.26 に沿い、詳細から戻ったときに条件を復元する。`useSearchParams` のままの画面は詳細→戻るで戻り先情報を失う。キーワードのキー名をそろえると同じ操作が同じURLになる。
- 細則:
  - URLキーの変更: 付与予定 `user_name`→`q`、`filter`→`status`。API層(`src/api/*.ts`)でバックエンドのパラメータ名(`user_name`・`filter`・タスクの`search`)に変換し、バックエンドのパラメータ名は変えない。
  - 変更前のキーで開かれたURL(ブックマーク)は既定表示になる(読み替えはしない。社内の内部画面でブックマークの影響が小さいため)。
  - それ以外のキー(`subjectType`・`yearMonth`・`show_deleted`等)は変えない。
  - 条件・タブを変えたら `page` を消す。更新は §2.10 の書き方(関数形式・null削除・`replace: true`)。
- 未確定・要確認事項: なし

### 論点8: 対象画面
- 選択肢:
  - A. 表の #1〜#12 を対象。#13 勤怠参照、詳細画面内の小さな絞り込み(特別休暇・有給ポリシーの「社員名で絞り込み」、各管理画面の使用状況の対象社員)は対象外
  - B. #13 や詳細内の絞り込みも含める
- 決定: A
- 理由: #13 は「どの社員のどの月を見るか」の表示対象の選択で、条件を組み合わせて一覧を探す検索ではない(前月/次月ナビを持つ別の型)。詳細内の絞り込みは行ごとに独立した一時的な操作(§2.10 URLに載せない側)で、一覧の検索フォームとは役割が違う。
- 細則(画面ごとの変更):
  - #1 承認: 状態セレクト → 件数付き主軸タブ(承認待ち/承認済み/差戻し済み/取消/すべて)。フィルターバーは年月のみ。
    `GET /workflow-requests/to-approve` に `meta.status_counts`(submitted/approved/returned/cancelled/all。year_month適用後)を追加。
    既存不具合の修正として、`status=all` と件数から**draftを除く**(コメントどおり下書きは承認者に見せない)。`rejected`はタブを作らず「すべて」にだけ含める(現行の選択肢にも無く、タブを5つ以下に保つ)。
    `all`の件数=各タブ+rejectedの合計になる。
    タブごとのInitial文言: 承認待ち「承認待ちの申請はありません。」/承認済み「承認済みの申請はありません。」/差戻し済み「差戻し済みの申請はありません。」/取消「取消された申請はありません。」/すべて「承認対象の申請はありません。」。
    「すべて」等のタブでもチェックボックスは承認待ちの行にだけ出す(現行の`isRowActionable`どおり)。
    画面名を状態に依存しない「承認一覧」に変える(Card見出し、`routes/pageHierarchy.ts`、`routes/routeManifest.ts`のナビ文言)。ホーム等の「承認待ち n件」は承認待ちの件数を指すので変えない。
  - #2 タスク: 専用Card「タスク検索」を廃止し一覧Card本文へ(一覧Cardの見出しは現行のまま)。エラー時もフィルターバーを残す(論点2)。キーワードは可視ラベル+自動反映、「検索」ボタン廃止、「クリア」→「検索条件をクリア」、ステータスに可視ラベル。
  - #3 申請: 「すべての種別」→「すべて」、フック変更、文言変更のみ。
  - #4 社員: キーワード・セレクトに可視ラベル、詳細条件、自動反映(300ms)。
  - #5 備品: 詳細条件への振り分け、自動反映(300ms)。
  - #6 監査ログ: 期間をDateRangePickerへ(URLキー`from`/`to`は維持)、詳細条件、自動反映(300ms)。`useAuditLog`に`keepPreviousData`。
    DateRangePickerは開始日が無いと値を持てないため「終了日だけ」の指定はできなくなる(`?to=`だけのURLは期間なしとして扱いURLから`to`を消す)。監査ログで終了日だけ指定する用途は乏しいため許容する。
  - #7 プリセット: フィルターバーへ、インラインクリア追加、ラベル「名称」、自動反映(300ms)。
  - #8 付与予定: 社員名を自動反映へ、URLキー変更(stories の `?filter=` も更新)、インラインクリア追加。台帳型とする(既定が「すべて」で、状態・社員・期間を組み合わせて確認する一覧であり、消化すべき残数を追う一覧ではないため)。付与予定日超過のAlert内ボタンは状態を「付与予定日超過」に切り替える既存動作のまま。
  - #9 通知: actionsのボタン群 → 件数付き主軸タブ(すべて/未読/既読。既定は現行どおり「すべて」で先頭)。既定を要対応(未読)にしない理由: 未読はベルで件数を常に見られ、
    通知一覧は既読も含めて振り返る用途が主なため。現行の既定を変えると開いたときの見え方が変わる。
    `GET /notifications/mine` に `meta.status_counts`(all/unread/read)を追加。`page`のURL同期と`Pagination`を追加(既存不具合)。`useMyNotifications`に`keepPreviousData`とpage引数。
    ベルも同じAPIを呼ぶため件数集計がベルの取得のたびに走るが、索引(recipient)で軽く許容する。ベルの表示(`status=unread`のtotal)が変わらないことをテストで確認する。
    タブごとのInitial文言: すべて「通知はありません。」/未読「未読の通知はありません。」/既読「既読の通知はありません。」。フィルターバーは持たない。
  - #10 グループ: グループ種別をフィルターバー(可視ラベル)へ、インラインクリア追加、空状態の文言を「検索条件をクリア」に。
  - #11 月次勤怠: 年セレクトをactionsからフィルターバー(ラベル「年」)へ。#13と違い、表示されている月の一覧を年で絞る絞り込みなので対象にする。
    年の選択肢は入社日以降の年だけなので Filtered Empty は起きない(既存の空状態のまま)。既定以外の年を選んでいるときは「検索条件をクリア」を出す。
  - #12 端末: 「削除済みを表示」トグルボタンをactionsからフィルターバーのチェックボックス「削除済みの端末も表示」へ(現行どおり`with_trashed`=削除済みも含めて表示)。
    オンのときは既定以外として「検索条件をクリア」を出す。含めて表示するだけで件数は減らないため Filtered Empty は使わず、空状態は現行の文言のまま。
- 未確定・要確認事項: なし

### 論点9: 共通部品
- 選択肢:
  - A. 共通部品を作り、全画面で使う: `ListFilterBar`(レイアウト・クリアボタン・詳細条件の開閉)、`TextFilterInput`(自由入力の絞り込み欄。300ms待ち・IME・Enter/blur即反映・URL値との同期)、`ListQueueTabs`(件数付きタブ)
  - B. ルールだけ決めて各画面で書く
- 決定: A
- 理由: 反映方式・IME対応・幅・文言は画面ごとに書くと再びずれる。12画面に横展開するので部品化の効果が大きい。
- 細則: いずれも `frontend/src/components/` に `add-frontend-component` の3点セット(コンポーネント+story+test)で作る。URL操作は部品に持たせず、
  値と `onChange` を受け取る(URL同期は各ページで §2.10 の書き方)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

### ルール(`ui-interaction-patterns` §2.20 を書き換え)
1. 一覧Card本文の先頭に「主軸タブ(作業キュー型のみ)→ フィルターバー → 一括操作バー → 表 → Pagination」。検索専用Cardを作らない。Cardのactionsに絞り込みを置かない。
2. 作業キュー型(承認・タスク・通知)は主となる1軸を件数付きタブに。件数はタブ軸以外の条件を適用し、一覧APIの `meta.<軸>_counts` で返す。既定は要対応(例外は変更セットに理由を書く)で先頭、「すべて」は既定でなければ末尾。
   タブごとにInitial文言を持ち、Filtered判定はタブを除いた条件で行う。台帳型はタブを使わない。
3. フィルターバーは全項目に可視ラベル。並び: キーワード → 主な状態 → その他 → 年月・期間 → クリア。キーワードのラベル「キーワード」、placeholderは検索対象の列挙。「すべて」の文言統一。期間はDateRangePicker。
4. 直接表示は4項目まで。残りは「詳細条件(n)」でインライン展開。
5. 反映: 全項目即時。テキストは300ms待ち・IME変換中は反映しない(確定で待ち開始)・Enter/blurで即時・アンマウントで取消・外からの変化だけ表示に反映・maxLengthはバックエンドに合わせる。「検索」ボタンを置かない。
5a. 読み込み・エラー: 2回目以降は表の場所だけ。タブとフィルターバーは常に残す。一覧hookは`keepPreviousData`。
6. クリア: 「検索条件をクリア」をフィルターバー末尾(既定以外のときだけ)とFiltered Emptyに。タブは戻さない。
7. URL: `useSearchParamsKeepingBackState`、キーワード`q`、条件・タブ変更で`page`削除・選択解除。
8. 共通部品 `ListFilterBar`・`TextFilterInput`・`ListQueueTabs` を使う。一括操作バーはフィルターバーの下・表の直上。

### バックエンド
- `GET /workflow-requests/to-approve`(`WorkflowRequestController::indexToApprove`): 応答に `meta.status_counts` = `{submitted, approved, returned, cancelled, all}`。
  承認者として自分が対象の申請について、`status`以外の条件(`year_month`)を適用した件数。`all`(一覧・件数とも)からdraftを除く。rejectedは`all`にだけ含まれる。
  それ以外の一覧結果・パラメータは変えない。OpenAPI注記を更新。
- `GET /notifications/mine`: 応答に `meta.status_counts` = `{all, unread, read}`。自分宛て通知の件数。OpenAPI注記を更新。
- テスト(Feature): 各件数が状態ごとに正しいこと、`year_month`を指定すると件数もその月に絞られること、他人宛ての申請・通知を数えないこと、
  ページ・`status`指定に件数が影響されないこと、`status=all`に他人の下書きが含まれないこと、`status=unread`のtotal(ベル)が従来どおりであること。

### フロントエンド
- 共通部品(各 component + story + test):
  - `ListFilterBar`: props `children`(直接表示項目)、`advanced?`(詳細条件の項目)、`advancedActiveCount?`、`isFiltered`、`onClear`。
    `isFiltered`のときだけ末尾に「検索条件をクリア」。`advanced`があるとき「詳細条件」/「詳細条件(n)」ボタンで開閉、初期状態は `advancedActiveCount > 0` なら開。レイアウトと幅の既定(論点4)を持つ。
  - `TextFilterInput`: props `label`(必須。キーワード欄は「キーワード」)、`placeholder`、`value`(URLの値)、`onChange(value: string | null)`、`maxLength`。
    内部に入力中の値を持ち、論点1の細則どおり動く(300ms待ち、変換中は発火せず確定で待ち開始、Enter/blurで即発火、反映値は前後空白除去・空はnull、
    `value`が直前に自分が反映した値と異なるときだけ表示を合わせる、アンマウントで取消、onChangeはref参照)。
  - `ListQueueTabs`: props `tabs: {value, label, count?}[]`、`value`、`onChange`、`aria-label`。タブ表示「ラベル 件数」、aria-labelに件数、`overflow-x-auto`。
- API層: 承認・通知の型に `status_counts` を追加。付与予定のAPI関数は `q`→`user_name`、`status`→`filter` に変換。タスクは既存どおり `search`。
- hook: `useAuditLog`・`useMyNotifications`に`placeholderData: keepPreviousData`(通知はpage引数も)。
- 画面: 論点8の細則のとおり #1〜#12 を変更。

### 実装の進め方(2段階)
- 段階A: ルール(スキル書き換え)・共通部品3つ・件数メタ(バックエンド)・作業キュー型3画面(#1 承認・#2 タスク・#9 通知)。完了後に実画面で自動反映・タブ・戻るを確認する。
- 段階B: 台帳型9画面(#3〜#8・#10〜#12)への横展開。段階Aで部品の不具合を固めてから広げ、手戻りを小さくする。

### テスト
- 共通部品のVitest: TextFilterInputで「入力→300ms後に1回だけonChange」「変換中は発火せずcompositionendで待ち開始」「Enter/blurで即発火」「外からのvalue変更で表示更新」
  「自分が反映した値が戻ってきても入力中の表示(末尾空白含む)を上書きしない」「アンマウント後に発火しない」「空白のみはnull」。
  ListFilterBarで「既定ならクリア非表示」「詳細条件(n)の表示」「advancedActiveCount>0なら初期展開」。ListQueueTabsで件数表示とaria-label。
- 各ページのテスト: 既存テストを新しい文言・部品に合わせて更新し、少なくとも「条件変更でURLが更新されpageが消える」「検索条件をクリアで条件が消えタブは残る」を確認する。
  承認・通知はタブの件数表示とタブごとのInitial文言、承認はタブ切替でrequestIdが残り選択が解除されること、通知はページング。取得エラー時にフィルターバーが残ること(#2)。
- e2e: 文言・セレクタが変わる spec を更新(少なくとも `scenario-04-expense-claim.spec.ts`(`getByLabel('状態')`、/approvals・/backoffice-tasks)、`scenario-09-cross-domain.spec.ts`。他は実装時に grep で洗い出す)。
- stories: 変更した各ページの stories を更新(付与予定の `?filter=` 等)。

## 受け入れ条件
- `ui-interaction-patterns` §2.20 に本仕様のルール(構成・作業キュー型/台帳型・ラベル・詳細条件・反映方式・クリア・URL・読み込み・共通部品)が書かれ、§2.1・§2.10・§2.21・§4.1 が整合している。
- #1〜#12 の全画面が、可視ラベル付きのフィルターバーを一覧Card本文に持ち、「検索」ボタンがなく、キーワードは入力停止後に自動で反映され、クリア文言が「検索条件をクリア」である。
- 承認・タスク・通知の一覧が件数付きの主軸タブを持ち、件数がキーワード・フィルターを反映している。承認一覧の「すべて」に他人の下書きが出ない。通知一覧で21件目以降をページングで見られる。
- 付与予定の一覧で `?q=山田&status=...` のURLが動作する。
- backend のテスト(件数メタ)、frontend の Vitest・typecheck・lint、変更したe2eがPASSする。

## 対象外
- #13 勤怠参照、詳細画面内の絞り込み(特別休暇・有給ポリシー・代休管理の社員名絞り込み/対象社員選択)
- バックエンドの検索パラメータ名の変更(`search`・`user_name`・`filter`は維持)、検索対象項目の追加・変更
- 並び替え(ソート)の導入、ページングの有無の変更、ページあたり件数の変更
- 一覧の表の列・行の操作の変更。空状態のInitial文言は、主軸タブを持つ画面(タブごとの文言を持たせる)以外は変えない
- 旧URLキー(`user_name`・`filter`)の読み替え

## ドキュメントへの影響
- `.claude/skills/ui-interaction-patterns/SKILL.md`: §2.20 書き換え、§2.1 List Page の図、§2.10「`useSearchParams`を使う」→`useSearchParamsKeepingBackState`、§2.21 バーの位置の文言、
  §2.16 に「2回目以降の読み込み・エラーは表の場所だけ」、§2.17 のFiltered例(変更なしで一致することを確認)、§4.1 参照実装に共通部品と参照画面(List参照を`BackOfficeTaskListPage`等に更新)、§5 チェックリストに検索フォームの項目を追加。
- `docs/18-ui-guidelines.md`: 一覧画面の検索フォームは `ui-interaction-patterns` §2.20 に従う旨を1行追記。
- `docs/13-usecases-notification.md`・`docs/10-usecases-workflow.md`: 一覧の件数(状態別)を返す旨を該当UCに追記(該当UCが無ければ変更なしと記録)。

## モック・アセット
なし(論点2のワイヤーフレームを参照)

## 実装対象
- backend: `app/Http/Controllers/Api/WorkflowRequestController.php`(承認対象一覧)、`app/Http/Controllers/Api/NotificationController.php`、対応する `tests/Feature/`
- frontend 共通部品: `src/components/ListFilterBar/`、`src/components/TextFilterInput/`、`src/components/ListQueueTabs/`
- frontend API・hook: `src/api/workflowRequests.ts`、`src/api/notifications.ts`、付与予定のAPI関数、関連型、`src/hooks/useAuditLog.ts`、`src/hooks/useNotifications.ts`
- frontend 画面: #1〜#12 のページと各テスト・stories、e2e spec、`src/routes/pageHierarchy.ts`・`src/routes/routeManifest.ts`(承認一覧の名称)
- backend: 承認対象一覧からdraftを除く修正(`WorkflowRequestController::indexToApprove`)
- skill/docs: 上記「ドキュメントへの影響」

## 検証方法
- `cd backend && php artisan test --filter='WorkflowRequest|Notification'`
- `cd frontend && npm run typecheck && npm run lint && npm run test -- --run`
- 変更した e2e spec の実行
- 実画面で承認・タスク・備品一覧を開き、キーワード入力→自動反映、詳細条件、クリア、詳細→戻るで条件復元を確認(スクリーンショット)

## レビュー履歴
- 初版
- 2026-11-22 提示前の独立設計レビュー(general-purpose)の指摘を反映:
  - 高: 通知はAPIがページングしているのに画面が1ページ目のみ → #9にページング追加(既存不具合として範囲に含める)
  - 高: タブ化で承認一覧の空文言・isFilteredが壊れる → タブごとのInitial文言を必須化、Filtered判定はタブを除く
  - 高: 通知の既定タブが規則と矛盾 → 規則を「既定タブを先頭、すべては既定でなければ末尾」に直し、通知の既定=すべての理由を明記
  - 高: 外からのvalue同期で入力中の文字が消える/アンマウント後の発火で遷移先URLを書き換える → 自己反映値との比較・アンマウント取消・ref参照を細則とテストに追加
  - 中: 承認「すべて」に他人の下書き・rejectedが件数内訳にない → draft除外(既存不具合修正)、rejectedは「すべて」にだけ含める
  - 中: エンドポイント名の誤り → `/workflow-requests/to-approve`(`indexToApprove`)に訂正
  - 中: §2.21・§2.10・§2.1の書き換えが未記載 → 設計前提の検証とドキュメントへの影響に追記
  - 中: 監査ログの終了日だけ指定ができなくなる → 許容と明記
  - 中: IMEの終わり方のブラウザ差 → compositionendで待ち開始
  - 中: 取得失敗でフィルターごと消える/maxLength → フィルターバーを常に残す・maxLengthをバックエンドに合わせる
  - 中: keepPreviousData未指定のhook → 必須化しuseAuditLog・useMyNotificationsを対象に追加
  - 中: 承認のタブ切替時のrequestId・選択、画面名、端末のshow_deleted、自由入力欄の部品、スコープ過大 → それぞれ決定を明記、部品名を`TextFilterInput`に、実装を2段階に分割
  - 低: ワイヤーフレームの不整合、#11の線引き、#8の分類理由、stories・e2eの書き漏れ、ベルへの影響 → 修正・明記

## 実装結果
- コミット(ブランチ `claude/great-turing-6ul4k2`): 52ac364(スキル§2.20等)、45f76e4(共通部品)、d6f17a9(backend 件数メタ・draft除外)、1cae336(タスク一覧)、
  d628359(承認一覧・通知一覧・名称変更)、d7f89ac(プリセット・付与予定)、029c525(申請一覧・グループ・月次勤怠・端末)、9edb716(社員・備品・監査ログ)、
  069d1d6・d0b09c4(e2e追随・docs/10・docs/13)
- 変更ファイル(主なもの): `.claude/skills/ui-interaction-patterns/SKILL.md`、`docs/18-ui-guidelines.md`・`docs/10-usecases-workflow.md`・`docs/13-usecases-notification.md`、
  `backend/app/Http/Controllers/Api/{WorkflowRequestController,NotificationController}.php` と Feature テスト、
  `frontend/src/components/{ListFilterBar,TextFilterInput,ListQueueTabs,FormField}/`、#1〜#12 の各ページ・テスト・stories、`api/{workflowRequests,notifications,types}.ts`、
  `hooks/{useNotifications,useAuditLog}.ts`、`routes/{pageHierarchy,routeManifest}.ts`、`e2e/scenario-{04,09,10,14,15,99}`
- 検証結果:
  - backend: `phpunit --filter 'WorkflowRequest|Notification'` 79件pass、CI相当の全件 `php artisan test` 2359件pass
  - frontend: `vitest --project unit` 全件 1504件pass(2 skip。CalendarBulkOperationsPage の1件が並列実行時に5秒タイムアウトしたが単独再実行でpass、本変更と無関係)、`tsc -p tsconfig.app.json` エラーなし、変更ファイルの oxlint 指摘なし
  - e2e: 全69件(scenario-13含む)のうち初回67件pass、2件(scenario-15のクリアボタン重複、scenario-99の詳細条件)はspecの追随漏れ → 修正後に両ファイル再実行で 9/9・10/10 pass
  - 実画面確認(幅1280/390): 段階A・B の全項目合格(件数付きタブ、可視ラベル、検索ボタンなし、300ms自動反映とフォーカス保持、詳細条件(n)と初期展開、`?to=`のみの除去、戻るで条件復元、幅390で横スクロールなし)
- 受け入れ条件: すべて満たした(上記)。
- 対象外で見つかった既存不具合: `/admin/users` の「Microsoft未連携」「外部HR管理」チェックで `external_unlinked=true` が backend の boolean 検証に弾かれ422になる(本変更前から同じ。別途対応をユーザーに確認)。
