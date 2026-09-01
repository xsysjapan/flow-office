# 備品管理ブラッシュアップ(管理番号自動採番 + ナビ再編)

ステータス: 実装中

## 変更要望(原文)

> 備品管理をもう少しブラッシュアップしたいです。
> まず、管理番号がを別途管理台帳等で管理する必要がありそうなので、自動採番機能を作成して
> ほしいです。例えばパソコンならばNPC-00001 (ノートPC 1番)のような採番機能を用意して
> ください。管理番号を入力する代わりにカテゴリによって管理番号を自動採番する機能です。
> 別途マスタを用意して、管理番号の採番ができるようにしてください。管理番号の採番を
> 完全にOFFにしても成り立つような機能として設計をお願いします。
> UIの操作についても細かく仕様検討したいです。
> 備品管理がマイページにあるのも微妙です。貸出申請は申請に、バックオフィス業務の備品管理は
> バックオフィスメニューを新設してください。現在承認の配下にある、バックオフィスのタスク
> 一覧も、バックオフィス業務に移動させてください。

## 背景・目的

- 現状、備品の管理番号(`asset_no`)は登録画面で自由入力の文字列(一意制約のみ)であり、
  カテゴリごとの採番ルール(例: ノートPCは`NPC-00001`)を台帳的に管理する仕組みがない。
  番号の重複回避・欠番管理・命名規則の統一を、ユーザーの手作業に依存している。
- 現状のナビゲーションは「備品管理」がマイページ配下の単一メニューにまとまっており、
  一般社員が使う貸出申請(セルフ貸出/申請制)と、バックオフィス担当者が使う備品の登録・
  棚卸・修理・廃棄等の管理業務が同じ画面群に混在している。バックオフィス業務全体を
  1箇所に集約したいというナビ構成上の要望。

## 現状(As-Is)

### 管理番号・カテゴリ
- `backend/database/migrations/..._create_assets_table.php`: `assets`テーブルに
  `asset_no`(string, unique)、`category`(string, 自由入力・マスタ化されていない)。
- `backend/app/Domain/Asset/Commands/RegisterAsset.php` / `RegisterAssetHandler.php`:
  `assetNo`をコマンド引数としてそのまま受け取り`AssetRegistered`イベントに積む。
  DB側の自動採番ロジックは無い。
- `frontend/src/pages/asset/AssetRegisterPage.tsx`: 「管理番号」「カテゴリ」ともに
  自由入力の`Input`。カテゴリの選択肢マスタは存在しない(文字列一致のみ)。
- 参考: `system_settings`単一行テーブル(`app/Models/SystemSetting.php`)への
  カラム追加方式、および`request_types`のような独立マスタテーブル(`code`列で
  ユニーク識別)方式の2パターンが既存にある。

### ナビゲーション
- `frontend/src/routes/routeManifest.ts`:
  - `requests`グループ(ラベル「申請」)に申請一覧・有給・代休・特別休暇・経費精算。
  - `approvals`グループ(ラベル「承認」)に「承認待ち」(`/approvals`)と
    「タスク一覧」(`/backoffice-tasks`、`feature: backoffice.tasks`)が同居。
  - `mypage`グループ(ラベル「マイページ」)に「備品管理」(`/assets`)、
    アカウント設定、API・MCP連携、管理メニューへのリンク。
- `frontend/src/pages/asset/`: `AssetListPage`(検索・一覧・セルフ貸出/返却も含む)、
  `AssetDetailPage`、`AssetRegisterPage`、`AssetEditPage`、`AssetQrRedirectPage`、
  `bulk/BackofficeBulkLendPage`、`bulk/BackofficeBulkReturnPage`、
  `bulk/SelfBulkLoanPage`、`bulk/SelfBulkReturnPage`、`bulk/BulkRelocatePage`が
  1つの`/assets`配下にまとまっている。
- 貸出申請(承認制)は`backend/app/Domain/Asset/Reactors/AssetLoanRequestOnWorkflowRequestReactor.php`
  経由で汎用ワークフロー(`request_types.code = 'asset_loan'`)に乗る設計であり、
  画面としては`frontend/src/pages/workflow/WorkflowRequestListPage.tsx`(申請一覧の
  「その他申請」経由、またはAssetListPageからの申請導線)から起票する。
- バックオフィスタスク一覧: `frontend/src/pages/backOffice/BackOfficeTaskListPage.tsx`
  (`/backoffice-tasks`)、詳細は`BackOfficeTaskDetailPage.tsx`。
- 参考ドキュメント: `docs/34-usecases-asset-management.md`(UC-L01〜UC-L11、
  UC-I01〜UC-I05等)、`docs/11-usecases-backoffice.md`(UC-B001〜UC-B008)。

## 仕様検討

### 論点1: 採番マスタの設計(カテゴリ×プレフィックス×連番)

- 選択肢:
  - A. `system_settings`にJSONカラムを1本追加し、カテゴリ→プレフィックスのマップを
    埋め込む。シンプルだが、カテゴリの追加・削除がJSON編集になり、連番の現在値を
    セーフに更新する行ロックが取りづらい。
  - B. 独立マスタテーブル`asset_number_rules`(1カテゴリ1行、`category`・`prefix`・
    `digit_count`・`next_number`・`enabled`)を新設し、`assets.category`と論理的に
    対応付ける。行単位でロックして連番更新でき、カテゴリ追加・削除もCRUDで完結する。
  - C. Bに加えて`category`自体も独立マスタ化する(`asset_categories`: `code`・
    `name`・`asset_number_rule_id`)。カテゴリのマスタ管理と採番ルールを分離できるが、
    既存`assets.category`が自由入力文字列である現状との移行コストが増える。
- 決定: B(独立マスタ`asset_number_rules`を新設。カテゴリは既存どおり自由入力の
  `assets.category`のまま、`asset_number_rules.category`を同じ文字列で紐付ける)。
- 理由: 今回のスコープは「カテゴリ別の採番ルール管理」であり、カテゴリそのものの
  マスタ化(選択肢C)は別論点(将来的な備品マスタ整備)として切り離す方が影響範囲を
  絞れる。連番はレース条件を避けるため行ロック(`lockForUpdate`)で払い出す必要があり、
  単一行JSON(選択肢A)より独立テーブルの方が安全に更新できる。
- 未確定・要確認事項: なし(Bで確定)。

### 論点2: 採番マスタのカラム構成・命番フォーマット

- 選択肢:
  - A. プレフィックス+固定桁数ゼロパディング連番のみ(例: `NPC-00001`)。
  - B. Aに加えて区切り文字を可変にする(`-`以外に`_`等も選べる)、年度を含める
    (`NPC-2026-00001`)等の拡張フォーマットを最初から用意する。
- 決定: A。カラムは`category`(string, unique)・`prefix`(string, 例: `NPC`)・
  `digit_count`(unsigned int, デフォルト5)・`next_number`(unsigned int, 1始まり)・
  `enabled`(bool)・`separator`は固定で`-`とし別カラムにはしない。
- 理由: 要望の例(`NPC-00001`)を満たすには`プレフィックス-連番`で十分。年度区切り等の
  可変フォーマットは要望に含まれておらず、必要になった時点で別途カラム追加すれば
  後方互換に拡張できる(YAGNI)。区切り文字固定でシンプルにし、将来変更したくなった
  場合のみカラム追加で対応する。
- 未確定・要確認事項: なし。

### 論点3: 採番のON/OFF判定方式(グローバル設定 or ルールの有無)

- 選択肢:
  - A. `system_settings`にグローバルな1スイッチ(`asset_numbering_enabled`)を持ち、
    ON/OFFは全カテゴリ一括。ONの場合のみ`asset_number_rules`側のカテゴリ単位
    `enabled`を見る。
  - B. グローバルスイッチを持たず、「該当カテゴリに有効なルールが存在するか」
    だけで自動採番するかどうかを判定する(ルールが1件も無ければ実質的に
    機能全体がOFFの状態と同義になる)。
- 決定: B(グローバルスイッチは廃止)。
- 理由: グローバルスイッチとカテゴリ単位`enabled`を両方持つと、「スイッチON・
  対象ルールなし」という状態が発生し、「設定はONなのに何も自動採番されない」
  という分かりにくい状態になる。ルールの有無だけで判定すれば状態は「ルールが
  あって有効」「ルールがあって無効」「ルールが無い」の3通りに単純化され、
  「管理番号の採番を完全にOFFにしても成り立つ」という要件も、単に
  `asset_number_rules`に有効な行が1つも無い状態として自然に表現できる
  (=特別なOFFフラグを別途持つ必要がない)。
- 未確定・要確認事項: なし。

### 論点10: 条件に一致するルールが無いカテゴリの扱い(デフォルトルール)

- 選択肢:
  - A. 一致するカテゴリのルールが無ければ常に手入力(デフォルトルールという
    概念自体を持たない)。
  - B. 「デフォルトルール」を1件だけ設定できるようにし、カテゴリ一致ルールが
    見つからない場合はデフォルトルールが有効ならそちらで自動採番、無効・
    未設定なら手入力にフォールバックする。
- 決定: B。`asset_number_rules`に`is_default`(boolean)カラムを追加し、
  `is_default = true`の行(`category`は`NULL`)をちょうど0件または1件だけ
  持てるようにする。判定順序は「①`category`完全一致の有効なルール →
  ②(①が無い場合のみ)デフォルトルール(`is_default=true`かつ`enabled=true`)
  → ③(①②とも無ければ)手入力」とする。①のルールが存在するが`enabled=false`
  の場合はデフォルトにはフォールバックせず手入力とする(そのカテゴリは
  「明示的に自動採番しない」という意思表示のため)。
- 理由: カテゴリマスタ化(論点1で対象外とした)を行わない以上、新しいカテゴリ
  文字列が随時登場しうる。その都度ルールを登録しなくても、少なくとも
  「それ以外は`AST-00001`のような共通の連番で管理する」という運用ができるよう
  デフォルトルールを用意する。①で見つかった個別ルールが`enabled=false`の
  場合にデフォルトへフォールバックしない設計にするのは、論点5(個別カテゴリの
  自動採番OFF)の意図(=そのカテゴリだけは手入力に戻したい)を素直に実現する
  ため。
- 未確定・要確認事項: なし。

### 論点4: 登録画面での採番トリガー方式(自動採番が適用されるカテゴリの場合)

- 選択肢:
  - A. カテゴリ選択と同時に、確定した管理番号をバックエンドから即時払い出して
    フォームに表示する(「採番」ボタンなしで自動)。ユーザーが後からカテゴリを
    変更した場合、直前に払い出した番号を破棄し再採番が必要になる(欠番が生じうる)。
  - B. カテゴリ選択後、「採番する」ボタンを明示的に押させ、押下時にサーバーから
    次番号を払い出す。フォーム送信(保存)が完了して初めて番号を確定消費したと
    見なし、保存前にキャンセル・カテゴリ変更した場合は欠番として許容する
    (連番は「一度払い出したら戻さない」仕様にする=欠番許容)。
  - C. 番号払い出しと登録(保存)を1トランザクションで同時に行う(採番専用APIを
    別途持たず、登録API内で`category`に応じて`asset_no`を採番してから
    `RegisterAsset`コマンドを発行する)。フォーム上は「管理番号: (自動採番されます)」
    という表示のみで、保存ボタン押下時に初めてサーバー側で番号が決まる。
- 決定: C。ただし、ユーザーが保存前に採番結果を確認できるよう、保存確認ダイアログ
  (`ui-interaction-patterns`の保存確認パターン)に確定した管理番号を表示する。
  具体的には、登録画面の「管理番号」欄は入力中の`category`が論点10の判定
  (①カテゴリ一致の有効なルール→②デフォルトルール)により自動採番対象と
  分かっている間は読み取り専用表示(プレースホルダ「保存時に自動採番されます」)に
  切り替え、「作成」ボタン押下→バックエンドが`assets`テーブルへのINSERTと同一
  トランザクション内で該当ルール(カテゴリ一致 or デフォルト)の行をロックして
  次番号を払い出し`AssetRegistered`イベントに積む→レスポンスで確定した
  `asset_no`を返し、遷移後の詳細画面で確認できるようにする。
- 理由: 選択肢A/Bのように登録前に番号を払い出す方式は、カテゴリの選び直しや登録
  キャンセルのたびに欠番が発生する(欠番自体は許容する設計思想でも、無駄な払い出しは
  極力避けたい)。Cなら保存が確定した時だけ連番が消費されるため欠番が最小化され、
  実装も「登録コマンドの一部として採番する」だけで済み、採番専用の中間APIやUI状態
  (「採番済みだが未保存」)を持つ必要がない。
- 未確定・要確認事項: なし。

### 論点5: 手入力とのハイブリッド(自動採番が適用されるカテゴリでも手入力を許すか)

- 選択肢:
  - A. 自動採番が適用されるカテゴリを選んだら管理番号入力欄は完全に隠し、常に
    自動採番のみ。
  - B. 自動採番が適用されるカテゴリでも「手動で管理番号を指定する」チェック
    ボックス/リンクを用意し、必要なら例外的に手入力できるようにする(手入力した
    番号は連番管理外になるため、以後その番号と連番が衝突しないよう一意制約は
    DBの`unique`で保証する)。
- 決定: A(自動採番が適用されるカテゴリでは常に自動採番。手入力の抜け道は
  用意しない)。
- 理由: 台帳としての一貫性を優先する。「例外的に手入力したい」ケースが実際に
  発生した場合は、そのカテゴリ自体の採番ルールを`enabled=false`にする
  (論点10: この場合デフォルトルールへもフォールバックせず手入力になる)ことで
  代替でき、UI上に例外入力の分岐を増やすと「結局番号が重複・飛び番になった」
  という運用事故のリスクを増やすだけになる。
- 未確定・要確認事項: なし。

### 論点6: 既存登録済みデータ・カテゴリ文字列表記ゆれとの整合

- 選択肢:
  - A. 移行(マイグレーション)は行わない。採番マスタは新規登録分にのみ適用され、
    既存の`assets`行の`asset_no`はそのまま(手入力扱いだった過去データとして残る)。
  - B. 既存データの`category`から`asset_number_rules`の初期行を自動生成する
    マイグレーションを用意する。
- 決定: A。ただし、`category`の入力を今回のタイミングで自由入力の`Input`から
  「既存の採番ルールに登録されているカテゴリ + 自由入力」を選べる`combobox`風の
  補完に変える(候補は`asset_number_rules`に登録済みの`category`一覧をAPIから
  取得して表示するが、リストにない文字列の入力も許可する)。これにより表記ゆれ
  (「ノートPC」「ノートパソコン」等)を新規登録時から抑制する。
- 理由: 既存データへの遡及的な採番・書き換えは、過去に手入力された番号との衝突や
  監査ログ(`stored_events`)の整合性を崩すリスクがある。マスタは今後の運用改善の
  ためのものであり、過去データを無理に当てはめる必要はない(論点は「今後の新規
  登録」に閉じる)。カテゴリ表記ゆれの抑制はUI側の補完で十分対応できる。
- 未確定・要確認事項: なし。

### 論点7: 採番マスタの管理画面(CRUD)をどこに置くか

- 選択肢:
  - A. `frontend/src/pages/admin/`配下(管理者向け、`AdminLayout`)に
    `AssetNumberRuleListPage`等を新設し、管理メニューの中に置く。
  - B. 新設するバックオフィスメニュー配下に置く(バックオフィス担当者が直接編集する
    運用を想定)。
- 決定: A(管理メニュー配下)。ただし新設する「バックオフィス」メニューからも
  「採番ルール設定」への導線(リンク1本)を出す。
- 理由: 採番ルール(プレフィックス・桁数・グローバルON/OFF)はマスタデータであり、
  他の`request_types`等のマスタ管理画面と同様に管理者権限(`permission`ベース)で
  保護すべき設定項目。日々の備品登録・棚卸作業(バックオフィス業務)と、年に数回しか
  触らない設定変更(管理者業務)は権限レベルが異なるため、既存の管理メニューの
  マスタ管理群に合流させる方が権限設計に一貫性がある。
- 未確定・要確認事項: なし。

### 論点8: ナビゲーション再編(マイページ分割・バックオフィスメニュー新設)

- 選択肢:
  - A. `routeManifest.ts`の`NavGroupKey`に新たに`backoffice`グループを追加し、
    「タスク一覧」(`/backoffice-tasks`)と「備品管理(バックオフィス業務)」
    (既存`/assets`のうちバックオフィス向け操作: 登録・編集・一括貸与/返却・
    移設・修理・紛失・廃棄・採番ルール設定への導線)をここに集約する。
    貸出申請(セルフ貸出/申請制の起票・自分の貸出状況確認)は`requests`グループに
    「備品貸出」として新設し、`/assets`自体は一般ユーザー・バックオフィス両方が
    使う共通の一覧・検索画面として残す(URLは変更しない)。
  - B. `/assets`配下を`/assets`(一般ユーザー向け閲覧・セルフ貸出)と
    `/backoffice/assets`(バックオフィス向け管理操作)にURL自体を分割する。
  - C. ナビ上の見え方だけ変え(A)つつ、既存ページコンポーネント・ルーティングの
    URLは一切変更しない(ページ自体の中でユーザーの権限に応じて表示するアクション
    ボタンを出し分ける現状の実装を維持する)。
- 決定: A + C の組み合わせ。具体的には:
  - `NavGroupKey`に`"backoffice"`を追加し、`navGroupMeta`に
    `backoffice: { label: "バックオフィス", icon: ... }`を追加する。
  - `requests`グループに「備品貸出」(`to: "/assets"`, `feature: 未設定=誰でも見える`、
    ただし表示ラベル・アイコンで「申請」文脈だと分かるようにする)を追加する
    (既存の`/assets`一覧ページ自体がセルフ貸出/返却/申請導線を兼ねているため、
    URL・ページの新設は不要。ナビからの見出しを「備品管理」→複線化するだけ)。
  - `approvals`グループから「タスク一覧」(`/backoffice-tasks`)を削除し、
    `backoffice`グループに移設する(`feature: "backoffice.tasks"`, 表示条件
    `canSeeBackOfficeTasks`は現状踏襲)。
  - `mypage`グループから「備品管理」(`/assets`)を削除する
    (`requests`グループの「備品貸出」と`backoffice`グループの「備品管理」に
    役割分担して移設するため、マイページには残さない)。
  - `backoffice`グループに、バックオフィス担当者向けの「備品管理」
    (`to: "/assets"`, `feature: "asset.manage"`相当の権限で表示、既存の
    `permission:asset.manage,any`ミドルウェアと整合させる)を追加する。
    つまり同じ`/assets`へのリンクが、一般ユーザーには「申請」グループの
    「備品貸出」として、備品管理権限を持つユーザーには追加で「バックオフィス」
    グループの「備品管理」としても見える(両方出てよい。役割によって入口が
    異なるだけで、遷移先ページ自体は同じ一覧ページで権限に応じた操作が
    出し分けられる現状の実装を維持する)。
- 理由: URL・ページコンポーネントの分割(選択肢B)は影響範囲(ルーティング・
  E2E仕様・既存テスト)が大きく、今回の要望の本質(「メニューの見え方を整理したい」)
  に対して過剰。ナビゲーション定義(`routeManifest.ts`)だけで実現できるA+Cが
  最小の変更で要望を満たす。バックオフィスのタスク一覧移設も同様にナビ定義の
  変更のみで完結する。
- 未確定・要確認事項: なし。

### 論点9: 「バックオフィス」メニューに含める項目の範囲

- 選択肢:
  - A. 今回の要望に明示された2項目(タスク一覧・備品管理)のみを`backoffice`
    グループに入れる。
  - B. 経費精算のバックオフィス処理等、他のバックオフィス業務も併せてこのタイミングで
    移設する。
- 決定: A。
- 理由: 要望文で名指しされているのはタスク一覧と備品管理の2つ。経費精算等
  他機能の再編は本変更セットの対象外とする(論点は最小スコープに閉じる)。
  将来的に他のバックオフィス業務をここに集約したくなった場合は別の変更セットで
  検討する。
- 未確定・要確認事項: なし。

## 仕様確定事項(まとめ)

### バックエンド

1. 新規マイグレーション `create_asset_number_rules_table`:
   - `id`, `category`(string, nullable, unique — `is_default=true`の行のみ
     `NULL`を許容。MySQLのunique indexは複数`NULL`を区別済みとして許すため、
     デフォルト行を複数作れてしまわないようアプリ側(Handler内トランザクション+
     事前チェック)で1件までに制限する), `prefix`(string, 例: `NPC`),
     `digit_count`(unsigned tinyint, デフォルト5), `next_number`(unsigned int,
     デフォルト1), `enabled`(boolean, デフォルトtrue), `is_default`(boolean,
     デフォルトfalse), `created_at`, `updated_at`。
   - 部分インデックス相当の保証が必要な場合はDB制約ではなくアプリ層
     (`ConfigureAssetNumberRuleHandler`内で`is_default=true`かつ`id`が異なる
     行の存在チェック)で担保する(MySQLの部分ユニーク制約非対応のため)。
2. `system_settings`へのカラム追加は行わない(論点3でグローバルスイッチを
   廃止したため)。
3. `AssetNumberRule`モデル(`app/Models/AssetNumberRule.php`)を追加。
   Eloquentモデルで良い(このマスタ自体は`stored_events`を正とする対象外。
   ただしCLAUDE.mdの原則1の例外規定に倣い、採番ルールの作成・変更・連番払い出しは
   監査目的で`stored_events`にも記録する。専用ドメイン
   `App\Domain\AssetNumbering`にCommand
   (`ConfigureAssetNumberRule`, `IssueAssetNumber`)・Event
   (`AssetNumberRuleConfigured`, `AssetNumberIssued`)・Handlerを新設する)。
4. 採番ロジック(`IssueAssetNumberHandler`、引数は登録対象の`category`文字列):
   - まず`asset_number_rules`から`category`完全一致の行を`lockForUpdate()`で
     取得する。
     - 行が存在し`enabled=true` → その行から採番する(下記の払い出し処理へ)。
     - 行が存在し`enabled=false` → 自動採番不可(デフォルトへはフォール
       バックしない)。`RegisterAssetHandler`は手入力`asset_no`を要求する。
     - 行が存在しない → `is_default=true`の行を`lockForUpdate()`で取得する。
       - デフォルト行が存在し`enabled=true` → その行から採番する。
       - デフォルト行が存在しない、または`enabled=false` → 自動採番不可。
         `RegisterAssetHandler`は手入力`asset_no`を要求する。
   - 払い出し処理: 選ばれた行の`next_number`を採番して返し、同一トランザクション
     で`next_number`をインクリメント、`AssetNumberIssued`イベント(採番元の
     `asset_number_rule_id`・払い出した番号・対象`category`を含む)を
     `stored_events`に記録。生成される`asset_no`は`{prefix}-{next_numberを
     digit_countでゼロパディング}`(例: `NPC-00001`。デフォルトルールを
     使った場合もそのルールの`prefix`をそのまま使う)。
5. `RegisterAssetHandler`の変更:
   - `RegisterAsset`コマンドの`assetNo`を`?string`(nullable)に変更。
   - `assetNo`が`null`の場合のみ、`category`をもとに`IssueAssetNumberHandler`を
     呼び出す。採番不可(4.のいずれのケースにも該当せず)の場合は
     バリデーションエラーとし、フロントに「管理番号を入力してください」を
     返す(通常はフロント側が事前に判定して手入力欄を出しているため、
     この経路は主に直接API叩き等への防御)。
   - `assetNo`が非nullの場合(手入力)は現状どおりそのまま使う。
   - `assets.asset_no`のunique制約は維持(採番後の番号も含め一意性を保証)。
6. APIエンドポイント追加(`routes/api.php`, `permission:asset.manage,any`配下):
   - `GET /asset-number-rules` — 一覧取得(管理画面用。デフォルト行も含む)。
   - `PUT /asset-number-rules/{category}` — 単一カテゴリのルール作成・更新
     (`prefix`, `digit_count`, `enabled`)。`next_number`は直接編集させない
     (欠番管理を崩すため。連番のリセットが必要な場合は別途「次番号を手動修正」
     専用の確認ダイアログ付き操作を将来検討するが、今回は対象外)。
   - `PUT /asset-number-rules/default` — デフォルトルールの作成・更新・無効化
     (`prefix`, `digit_count`, `enabled`)。まだ存在しなければ新規作成、
     存在すれば更新する(`category`一致ルートとは別エンドポイントにして
     `is_default`行を一意に扱う)。
   - `GET /asset-number-rules/categories` — 登録済みカテゴリ候補一覧
     (`AssetRegisterPage`のカテゴリ補完に使う。既存`assets.category`のDISTINCT値と
     `asset_number_rules.category`(デフォルト行を除く)のUNIONを返す)。
7. `POST /assets`(登録)のレスポンスに、採番された場合は確定した`asset_no`を含める
   (既存レスポンス形状の`asset_no`フィールドがそのまま該当)。

### フロントエンド

8. `frontend/src/api/assetNumberRules.ts` + `frontend/src/hooks/useAssetNumberRules.ts`
   を新設(`add-api-hook`スキルに沿う)。型は`AssetNumberRule`
   (`category: string | null`(`null`はデフォルト行), `prefix`, `digitCount`,
   `nextNumber`, `enabled`, `isDefault`)。
9. (削除。グローバル設定APIは追加しない。)
10. `AssetRegisterPage.tsx`の変更:
    - カテゴリ入力を`Input`から、登録済みカテゴリ候補(`GET
      /asset-number-rules/categories`)をサジェストする補完付き入力
      (`components/ui/combobox`相当。既存になければ`add-frontend-component`で
      新設)に変更。自由入力も許可する。
    - 現在入力中の`category`について、`asset_number_rules`一覧から
      論点10の判定順(①カテゴリ完全一致かつ`enabled=true` → ②該当なしの場合
      `isDefault=true`かつ`enabled=true`の行 → ③いずれも無ければ対象外)を
      フロント側でも同じロジックで評価し、①②のいずれかに一致する間は
      「管理番号」欄を編集不可表示に切り替え、プレースホルダ文言
      「保存時に自動採番されます」を表示する(入力値はサーバーに送らない
      = `asset_no`を`null`で送信)。③の場合は現状どおり手入力必須。
    - 判定に使う`asset_number_rules`一覧はページ表示時に
      `useAssetNumberRules`で取得する。
    - 保存確認: 自動採番の場合、「作成」ボタン押下時に
      `ui-interaction-patterns`の保存確認ダイアログを表示せず(採番自体は
      1アクションなので確認不要という既存踏襲方針。手入力の場合も現状
      確認ダイアログは無いため据え置き)、保存成功後の遷移先
      (`/assets/{id}`詳細画面)で確定した管理番号を表示することでユーザーに
      結果を伝える。
11. 管理メニュー(`frontend/src/components/AdminLayout/adminNavGroups.ts`)に
    「採番ルール設定」項目を追加し、`AssetNumberRuleListPage`
    (`frontend/src/pages/admin/AssetNumberRuleListPage.tsx`)を新設。
    画面構成:
    - 先頭に「デフォルトルール」1行(常に表示。未作成の場合は「未設定」表示+
      「デフォルトルールを作成」ボタン。作成済みならプレフィックス・桁数・
      有効/無効をインライン編集可能)。
    - その下にカテゴリ別ルールの一覧テーブル(カテゴリ・プレフィックス・桁数・
      現在の次番号(表示のみ・編集不可)・有効/無効)。行編集(プレフィックス・
      桁数・有効/無効)と、新規カテゴリ行の追加ができる。
    - 表全体に、各カテゴリの実際の適用状態(「このルールで採番」/「無効化中
      (手入力)」/「デフォルトにフォールバック」等)が一目でわかるようステータス
      列を出す(実装は`ui-design-system`のバッジ表現に合わせる)。
12. `frontend/src/routes/routeManifest.ts`の変更:
    - `NavGroupKey`に`"backoffice"`を追加。
    - `navGroupMeta`に`backoffice: { label: "バックオフィス", icon:
      Boxes(lucide-react、未使用であれば新規import) }`を追加。
    - `requests`グループに`{ label: "備品貸出", to: "/assets", group:
      "requests" }`を追加(既存の`/assets`ページをそのまま指す。feature制約は
      設けない=現状の`/assets`ページ自体が未認証以外は誰でも見られる設計を踏襲)。
    - `approvals`グループの「タスク一覧」エントリを削除し、`backoffice`グループへ
      移設(`feature: "backoffice.tasks"`, `show: (ctx) =>
      ctx.canSeeBackOfficeTasks`は変更なし)。
    - `mypage`グループの「備品管理」エントリを削除。
    - `backoffice`グループに`{ label: "備品管理", to: "/assets", feature:
      "asset.manage", group: "backoffice" }`を追加(バックオフィス権限
      保持者にのみ見える。featureの実値は`permission:asset.manage,any`と
      対応する既存feature文字列を`AssetListPage`等の既存ガードから確認し
      合わせる)。
    - 上記の結果、`/assets`は「申請」グループから見えるリンクと「バックオフィス」
      グループから見えるリンクの2箇所からアクセス可能になる(URL・ページは1つ)。
13. `App.tsx`のルーティング自体(`<Route path="/assets" .../>`等)は変更しない。
14. `frontend/src/routes/routeManifest.test.ts`等、既存のナビ構成テストがあれば
    グループ変更に合わせて更新する。

## 対象外

- カテゴリ自体の独立マスタ化(`asset_categories`テーブル新設。論点1の選択肢C)。
- 既存登録済み備品データへの遡及的な採番・移行。
- 採番の区切り文字可変化・年度接頭辞等、`プレフィックス-連番`以外のフォーマット。
- `next_number`を管理画面から直接編集する機能(欠番修正の手動オーバーライド)。
- 経費精算など、備品管理・バックオフィスタスク以外のバックオフィス業務のナビ移設。
- `/assets`配下ページの内部実装(登録・編集フォームの他項目、貸出/返却フロー等)の
  UI再設計。今回はカテゴリ入力欄・管理番号欄の挙動変更のみ。

## ドキュメントへの影響

- `docs/34-usecases-asset-management.md`: 「登録」ユースケースに、管理番号の
  自動採番(カテゴリ別ルール・デフォルトルール・ルール0件時は手入力)の仕様を
  追記する。
- `docs/11-usecases-backoffice.md`: 変更なし(タスク一覧の業務仕様自体は変わらず、
  ナビ上の置き場所のみの変更のため)。
- 新規: `docs/34-usecases-asset-management.md`内に採番ルールマスタの節を追加
  (テーブル定義は`docs/16-database-schema.md`に`asset_number_rules`を追記)。
- `docs/17-events.md`: `AssetNumberRuleConfigured` / `AssetNumberIssued`
  イベントを追記。

## モック・アセット

なし(UIワイヤーフレームは既存画面の項目追加・入れ替えレベルのため、テキストでの
仕様確定事項のみで実装可能と判断)。

## 実装対象

- backend:
  - `database/migrations/..._create_asset_number_rules_table.php`
  - `app/Models/AssetNumberRule.php`
  - `app/Domain/AssetNumbering/`(Commands: `ConfigureAssetNumberRule`,
    `IssueAssetNumber` / Events: `AssetNumberRuleConfigured`,
    `AssetNumberIssued` / Handlers)
  - `app/Domain/Asset/Commands/RegisterAsset.php`,
    `Handlers/RegisterAssetHandler.php`(assetNo nullable化・採番呼び出し)
  - `app/Http/Controllers/Api/AssetNumberRuleController.php`(一覧・カテゴリ別
    更新・デフォルト更新・categories)
  - `routes/api.php`(上記エンドポイント追加)
- frontend:
  - `src/api/assetNumberRules.ts`, `src/hooks/useAssetNumberRules.ts`
  - `src/api/systemSettings.ts`(型追加)
  - `src/pages/admin/AssetNumberRuleListPage.tsx`(+ stories/test)
  - `src/components/AdminLayout/adminNavGroups.ts`(採番ルール設定リンク追加)
  - `src/pages/asset/AssetRegisterPage.tsx`(カテゴリ補完・管理番号自動採番表示)
  - `src/routes/routeManifest.ts`(NavGroupKey追加・グループ再編)
  - 関連するstory/testファイルの更新
- docs:
  - `docs/34-usecases-asset-management.md`, `docs/16-database-schema.md`,
    `docs/17-events.md`

## 検証方法

- backend: `cd backend && php artisan test --filter=Asset`
  (採番ハンドラのユニットテスト。`RegisterAssetHandler`のカテゴリ一致採番/
  デフォルト採番/個別ルールOFF時の手入力フォールバック/ルール0件時の手入力の
  各分岐、行ロックによる連番の重複無し確認)。
- frontend: `cd frontend && npm run test -- AssetRegisterPage
  AssetNumberRuleListPage routeManifest`
- frontend: `npm run storybook`でAssetRegisterPageの
  「カテゴリ一致ルールで自動採番」「デフォルトルールで自動採番」
  「ルール無し(手入力)」の3パターンを目視確認。
- 手動確認: (1)ルールを1件も作らない状態で登録→手入力になること、
  (2)特定カテゴリのルールを作成→そのカテゴリで連番が重複なく払い出されること、
  (3)デフォルトルールのみ作成→未登録カテゴリでデフォルトの連番が払い出される
  こと、(4)特定カテゴリのルールを`enabled=false`にする→デフォルトへ
  フォールバックせず手入力に戻ることを確認。
- ナビ確認: 一般ユーザー(備品管理権限なし)で「申請」に「備品貸出」が出て
  「バックオフィス」グループが出ない/または「備品管理」項目のみ出ないこと、
  備品管理権限保持者で「バックオフィス」グループに「タスク一覧」「備品管理」が
  両方出ることを確認。

## レビュー履歴

- 初版。
- 2026-09-01: ユーザーレビューを受け、以下を変更。
  - 論点3を「グローバル設定+カテゴリ単位OFF」から「ルールの有無のみで判定」に
    変更(`system_settings.asset_numbering_enabled`を廃止)。
  - 論点10「デフォルトルール」を新設。カテゴリ一致ルールが無い場合のみ
    デフォルトルールにフォールバックする判定順序を追加。
  - 論点5(個別ルールのOFF)は維持しつつ、OFF時はデフォルトへフォールバック
    しないことを明記(既存の意図どおり)。
  - 上記に伴い、バックエンドの`system_settings`カラム追加・関連APIを削除し、
    `asset_number_rules`に`is_default`カラムを追加。管理画面にデフォルト
    ルールの編集UIを追加。

## 実装結果

未着手。
