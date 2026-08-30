# 備品管理機能の追加

ステータス: 実装中

## 変更要望(原文)

> 新しい業務を増やしたいです。現状の実装に合わせて検討をお願いします。まずは変更セットの作成をお願いします。
>
> 備品管理機能 実装指示書(詳細はチケット本文を参照。要点は以下)
>
> 会社が保有する備品について、備品登録・貸出・返却・貸出申請/承認・設置/移設・修理・紛失・廃棄・QRコードを利用した操作・検索/参照を管理する。購入・調達申請は既存の経費申請等の対象とし、本機能の対象外。備品管理は「購入後、会社の備品として登録するところ」から開始する。既存システムがCQRS+Event Sourcingを採用しているため、業務上重要な変更はEventStoreへイベントとして記録し、Read Modelは再構築可能な設計とする。実装開始前に、既存コード・ドキュメント・CQRS/ES実装・ユーザー/権限管理・申請ワークフロー・QR関連実装を確認したうえで設計を提示すること。

(指示書全文はユーザーとの元メッセージに記載。本spec.mdはその要件を既存実装に合わせて具体化したもの)

## 背景・目的

貸出品(PC・スマートフォン等)と設置品(会議室モニター等)を横断して、誰が何を持っているか・
どこに設置されているかを一元管理し、貸出/返却/設置/修理/紛失/廃棄という業務操作の履歴を
EventStoreに正として残す。既存の経費・勤怠・申請ワークフロー等と同じCQRS+ES・権限基盤に
統合し、備品管理専用の別基盤を作らない。

## 現状(As-Is)

- CQRS/ES基盤: `backend/app/Domain/EventSourcing/`。`Contracts\Command`(空interface)・
  `Contracts\CommandHandler`(`handle()`)を実装し、`CommandBus`(`config/domain.php`の
  `command_handlers`マップでCommand→Handlerを解決)が`DB::transaction`内で実行する。
- Aggregate: `Spatie\EventSourcing\AggregateRoots\AggregateRoot`を継承し、UUID主キー(呼び出し元採番)。
  例: `App\Domain\Attendance\Aggregates\AttendanceDayAggregate`。業務メソッド内で`recordThat(new XxxEvent(...))`。
- `stored_events`(`backend/database/migrations/2026_07_23_044800_create_stored_events_table.php`):
  `aggregate_type, aggregate_uuid, aggregate_version, event_version, event_type, payload, metadata, occurred_at`。
  `unique(['aggregate_uuid','aggregate_version'])`により楽観的並行制御を実現。
- `event_type`はスネークケースの`<ドメイン>.<過去形動詞>`(例: `workflow_request.submitted`)。
  `config/event-sourcing.php`の`event_class_map`に登録必須(`enforce_event_class_map=true`)。
- Projector: `app/Domain/<Domain>/Projectors/<Domain>Projector.php`。再生成は
  `php artisan event-sourcing:replay`+`RebuildProjectionsCommand`等。
- 権限: `App\Domain\AccessControl\`。`AccessControlCatalog::PERMISSIONS`にコード+日本語名+
  スコープ(`global`/`group`/`self`)を1箇所登録するだけでDB反映(`access-control:sync-catalog`)。
  判定は`EffectiveAccessResolver::hasPermission($user, $code, $scopeTarget, $callerUserId)`。
  ルートでは`permission:xxx.yyy,any`ミドルウェア。固定ロール名(「バックオフィス」等)には
  依存せず、Permissionコード単位で判定する(既存踏襲。例: `backoffice_task.execute`)。
- 申請ワークフロー: `request_types`(`code, name, form_schema(JSON), requires_backoffice_task,
  backoffice_task_type, is_active`)+`workflow_requests`(`request_type_id, applicant_user_id,
  approver_user_id, status, form_data, submitted_at, approved_at, ...`)。承認者は申請時に
  任意の社員を指定(固定ルートではない)。`workflow_requests.subject_type`/`subject_id`の
  ポリモーフィック関連で、各ドメイン(`PaidLeave`/`CompensatoryLeave`/`ShiftSwap`/`Expense`等)の
  実データと連携し、業務ロジック(残高計算等)は統合ワークフロー側に持たせない
  (docs/03-architecture.md 3.9)。新規申請種別追加は`.claude/skills/add-workflow-request-type/`。
- QR関連: 専用のQR画像生成基盤はない。既存の考え方は「サーバーは識別子/URLのみ払い出し、
  QR画像描画はフロント側で行う」(例: `docs/23-usecases-devices.md`のペアリングQR、
  `authentication_keys.key_type=QR_CODE`は打刻認証キーの一種であり本機能とは別概念)。
- フロント: 一覧+詳細+アクションボタン型の画面は`frontend/src/pages/workflow/
  WorkflowRequestListPage.tsx`/`WorkflowRequestDetailPage.tsx`が典型パターン。APIフックは
  `src/api/attendance.ts`+`src/hooks/useAttendance.ts`のように、型・client関数・
  `useQuery`/`useMutation`ラッパーを1ファイルずつ用意する構成(`add-api-hook`スキル)。

## 仕様検討

### 論点1: ドメイン境界(Aggregate設計)をどう切るか

まず「申請」という概念を一旦排除し、申請が存在しない`self_service`/`backoffice`だけの世界で
`AssetAggregate`を設計してから、「`approval`方式のときだけ、申請の存在を貸与の前提条件として
追加する」という順序で検討する(ユーザー指摘: 申請対象エンティティの状態を先に設計し、
申請がない状態を基準に、申請がある場合の扱いを後付けする)。

- 申請なしの世界(`self_service`/`backoffice`)での`AssetAggregate`:
  - `LendAsset(assetId, borrowerUserId, lentByUserId, expectedReturnAt?)` — 1つのCommandのみ。
    前提: `lending_status = available`。結果: `asset.loaned`(新規採番した`loanId`を含む)。
  - `ReturnAsset(assetId, loanId, returnedByUserId, returnNote?)` — 前提: `loanId`が現在
    アクティブな貸出であること。
  - `self_service`と`backoffice`はどちらも「今すぐ貸す」という同一の業務行為であり、
    Aggregateから見れば区別する理由がない。違いは**誰が・誰に対して呼び出してよいか**という
    入口(Controller/権限チェック)の差でしかなく、CLAUDE.md 9番「操作経路と業務ロジックを
    分離する」に従い、Aggregate内では分岐させない。
- 「申請がある」場合(`approval`)の乗せ方:
  - `approval`方式の資産に対して`LendAsset`を呼べるのは`asset.manage`権限保有者のみ
    (`backoffice`と同じ入口)。加えて、呼び出し前に「対象の`borrowerUserId`に対する
    承認済み・未貸与の貸出申請(`workflow_requests`, `subject_type=AssetLoanRequest`,
    `subject_id=assetId`)が存在すること」をControllerもしくはCommandHandlerの前段チェックで
    要求する。この前提条件チェックのみが`approval`固有の差分であり、`LendAsset`という
    Aggregate操作自体は増えない。
  - 承認済み申請に基づいて貸与した場合は、`asset.loaned`イベントのpayloadに
    `loanRequestId`(nullable)を含める。これにより「申請ありの貸出」と「申請なしの貸出」を
    同じイベント形状で表現でき、`asset_loans.loan_request_id`にそのまま反映できる
    (指示書40番の`asset_loans.loan_request_id nullable`要件と一致)。
  - 貸与成立後、`loanRequestId`が入っている`asset.loaned`イベントをReactorが検知し、
    対応する申請の表示ステータス(後述`asset_loan_requests`Projection)を`lent`に更新する。
  - 同一資産に対して承認済み・未貸与の申請が複数存在する場合にどれを`loanRequestId`として
    使うかは、システムが自動選択するのではなく、**バックオフィスが貸与操作の際に承認済み
    申請一覧(該当資産・該当borrowerUserIdでフィルタ)から明示的に1件選ぶ**UIとする
    (論点2-3参照。重複申請自体は許容し、選定は人手に委ねる)。
- 結論: **Aggregateは`AssetAggregate`1つだけ**。貸出・返却・設置・修理・紛失・廃棄・削除・
  削除など、備品本体に対するすべての業務操作をこの1つのAggregate上のイベントとして記録する。
  「貸出申請」専用のAggregate(`AssetLoanRequestAggregate`)は作らない(論点2参照)。

### 論点2: 貸出申請をどう実装するか(専用Aggregateを作らない)

- 選択肢:
  - A. `request_types`に「備品貸出申請」を1件追加し、`workflow_requests.subject_type=AssetLoanRequest`
    のポリモーフィック連携で実装する。承認プロセスの状態遷移(提出/承認/却下/取下げ/取消)は
    `workflow_requests`の既存Command/Eventだけで完結させ、備品ドメイン側には専用の
    Command/Event/Aggregateを一切作らない。
  - B. 備品ドメイン内に`AssetLoanRequestAggregate`を作り、申請の承認/却下/取下げ等を
    専用Command/Eventとして持つ(検討当初の案)。
- 決定: A。
- 理由: 検討当初はB案(専用Aggregateで承認プロセスを持つ)を採用していたが、実際に
  `AssetLoanRequestAggregate`が持つべき「その業務ドメイン固有の不変条件・計算ロジック」を
  洗い出したところ、`PaidLeave`/`Expense`等が専用ドメインを持つ理由(残高計算・金額計算等の
  workflow_requestsだけでは表現できない業務ロジック)に相当するものが、備品貸出申請には
  存在しないと判明した。備品貸出申請が持つのは「どの備品を申請したか(`asset_id`)」
  「利用目的(`purpose`)」という**ただのデータ**のみであり、「承認されただけでは貸出中に
  しない」という制約も、専用Aggregateの不変条件としてではなく、論点1で設計した通り
  `AssetAggregate`側の`LendAsset`呼び出し条件として満たせる。したがって書き込み側
  (Command/Aggregate)を独立させる理由がなく、`workflow_requests`の既存の状態遷移
  (提出→承認/却下/取下げ/取消)をそのまま使うA案が最小の実装で済む。
  - 承認者は既存`workflow_requests.approver_user_id`の仕組み(申請時に任意の社員を指定)を
    そのまま使うが、`approval`方式の貸与実行を`asset.manage`権限保有者に限定しているのに
    合わせ、申請UIでも approver の選択肢は`asset.manage`権限保有者に絞る。
  - 取下げ(UC-L10)・却下(UC-L09。論点2-2参照)は`workflow_requests`の既存/汎用操作を
    そのまま使う。
  - 備品詳細画面での「今誰が申請中か」等の検索・表示のため、`asset_loan_requests`という
    **Projectionテーブルのみ**を用意する。これは書き込みロジックを持たない純粋な読み取り
    専用の非正規化ビューで、`workflow_requests`側のイベント(`request_type=asset_loan`の
    ものだけ)を購読するReactor/Projectorが更新する。

### 論点2-2: 却下(UC-L09)をどこで実装するか

- 背景: `workflow_requests`は現状「提出→承認」「提出→差し戻し(SUBMITTED状態のみ、
  申請者が編集して再提出可能な非終端状態、`WorkflowRequestReturned`イベント)」
  「取消(`CancelWorkflowRequest`)」しか持たず、指示書UC-L09が求める「編集不可・再提出不可の
  終端的な却下」に相当する状態がない。差し戻しで代替できないか確認したところ、
  ユーザーから「却下は申請業務(ワークフロー)の内数であり、貸出ドメイン(備品ドメイン)に
  却下の概念を持ち込まない」との判断を得た。
- 選択肢:
  - A. `workflow_requests`本体に新しい終端状態`REJECTED`と`Reject`系Command/Event
    (`RejectWorkflowRequest`/`WorkflowRequestRejected`、却下理由`reason`を持つ)を追加する。
    これは全申請種別(経費・休暇等)で共通利用可能な汎用機能となる。
  - B. 備品ドメイン側に却下Command/Eventを持たせ、`workflow_requests`側は差し戻しや取消で
    間に合わせる。
- 決定: A。却下は`workflow_requests`(汎用申請ワークフロー)の機能として追加し、備品ドメイン
  (論点2で述べた通り専用Aggregateすら持たない)には却下の概念を一切持たせない。備品ドメイン
  側は`workflow_requests`が`REJECTED`になったことをReactorで検知し、`asset_loan_requests`
  Projectionを`rejected`表示に反映するだけ(備品ドメイン自身は却下を判断・記録しない)。
- 理由: ユーザー指示に加え、CLAUDE.md 14番の原則「進行状況(workflow_requests)はドメイン
  横断で統合的に扱い、各ドメイン固有の業務ロジックは統合ワークフロー側に混入させない」の
  逆方向(=進行状況側の概念を個別ドメインに重複実装しない)にも合致する。却下は経費・休暇等
  他の申請種別でも将来必要になりうる汎用概念であり、`workflow_requests`に1回実装すれば
  全申請種別で使い回せる。
- 影響: `workflow_requests`テーブルに`rejected_at`(timestamp, nullable)・`rejection_reason`
  (text, nullable)を追加し、`WorkflowRequestStatus`に`REJECTED`を追加する。既存の他申請種別
  (経費・休暇等)には却下を実行するUIをまだ出さない(却下ボタンは備品貸出申請の承認画面にのみ
  表示する)が、Command/Event自体は汎用実装とする。既存申請種別の承認画面に却下ボタンを
  出すかどうかは本変更セットの対象外とし、必要になれば別途変更セットを起票する。

### 論点2-3: 同一資産に対する複数の進行中申請(重複申請)をどう扱うか

- 背景: `approval`方式では1申請=1資産個体(UC-L07)だが、同じ資産に対して複数ユーザーが
  同時に貸出申請を提出し、バックオフィスが両方承認してしまうケースを妨げる仕組みが
  現状のspecにはない。貸与自体は`AssetAggregate`の排他制御により二重貸出にはならないが、
  「先に貸与された側以外の、承認済み・未貸与のまま残る申請」をどう扱うかが未定義だった。
- 選択肢:
  - A. 同一資産に対して進行中(pending/approved・未貸与)の申請は同時に1件までという制約を
    設け、新規申請の提出時点でシステムが重複を拒否する。
  - B. 制約は設けず、複数の進行中申請を許容する。貸与時にバックオフィスが承認済み申請
    一覧から手動で1件選んで貸与し、貸与済みにならなかった他の承認済み申請は、必要に
    応じてバックオフィスが手動で承認済み取消(`CancelWorkflowRequest`)する運用とする。
- 決定: B(運用で対応する)。
- 理由: ユーザーの判断による。A案は`request_types`の`asset_loan`だけの特殊ルールとして
  提出時点のバリデーションを追加する必要があり(既存の`workflow_requests`提出処理は
  申請種別ごとの重複チェックを持たない)、実装・テストの複雑さが増す。B案であれば
  `workflow_requests`側に一切手を入れずに済み、実際の運用頻度(同一資産への同時申請)も
  高くないと想定されるため、まずは運用でカバーし、実際に問題が顕在化すれば別途制約を
  追加する変更セットを起票する。
- 影響: 貸与操作画面(バックオフィス貸与・QR一括貸与)では、`approval`方式の資産を
  貸与する際、対象資産・対象borrowerUserIdに紐づく承認済み・未貸与の申請が複数あれば
  一覧から選択させるUIとする(1件しかなければ自動選択でよい)。「取り忘れた承認済み申請」
  が検索一覧に残り続けることは許容し、備品検索(35番)の申請状況フィルタで可視化できれば
  十分とする。

### 論点3: 貸出備品と設置備品を1つのAggregate/テーブルにまとめるか、分離するか

- 選択肢:
  - A. `AssetAggregate`を1つにし、`management_type`(`lending`/`installation`)で分岐。
    状態(`status`)は貸出用・設置用で別カラム(`lending_status`/`installation_status`)を
    持たせ、UIやCommand側で管理区分に応じたバリデーションを行う。
  - B. `LendingAssetAggregate`と`InstallationAssetAggregate`を完全に別Aggregate・別テーブルにする。
  - C. Aggregateは1つ(`AssetAggregate`)にするが、状態は単一の巨大enumに統合する。
- 決定: A。
- 理由: 指示書27項「管理区分変更を許容する(貸出用→設置常設等)」があるため、区分変更を
  1つのAggregate内のイベント(`AssetManagementTypeChanged`)として自然に表現できるA案が
  適する。B案では区分変更時にAggregateをまたぐ移行処理が必要になり複雑化する。C案は
  指示書13番「貸出備品と設置備品で状態遷移を分けること。無理に一つの巨大なstatus enumへ
  統合しない」に反するため却下。カテゴリ・名称・管理番号・シリアル番号等の共通属性は
  `AssetAggregate`の共通イベントで管理し、`lending_status`/`installation_status`は
  `management_type`に応じて片方のみ有効な値を持つ(もう片方はnull)。

### 論点4: 場所(location)をどう表現するか

- 選択肢:
  - A. 指示書7〜9項の通り自由記述の`location_text`カラムのみとし、`locations`マスタは
    作らない。将来`location_id`へ移行しやすいよう、Projectionのカラム名を`location_text`
    (将来`location_id`と併存させられる名前)にしておく。
  - B. 現時点で`locations`テーブルを先行して作り、`location_id`のみを持たせる。
- 決定: A。
- 理由: 指示書48項で明確に「将来可能性だけを理由に現時点で拠点管理機能を実装しない」と
  指定されているため。カラム名を`location_text`とすることで、将来`location_id`カラムを
  追加しても意味が衝突しない。

### 論点5: 貸出備品の「通常配置場所」と設置備品の「現在設置/保管場所」をどのProjectionで持つか

- 選択肢:
  - A. `asset_placements`テーブルを貸出備品・設置備品で共用し、`purpose`カラム等で
    意味(通常配置場所 vs 現在設置場所)を区別する。
  - B. Projectionを分離する: 貸出備品の通常配置場所は`assets`テーブル自身のカラム
    (`default_location_text`、貸出中でも不変・履歴は`asset_default_location_changes`等の
    別Projectionで追跡)として持ち、設置備品の設置/保管場所遷移は`asset_placements`
    (現在有効な1件+履歴)として別途持つ。
- 決定: B。
- 理由: 指示書42項で「貸出備品と設置備品では(placementの)意味が異なる…この違いをドメイン
  モデル・命名・Projectionで明確にすること」「テーブルを共通化すること自体を目的にしない」と
  明記されている。貸出備品の通常配置場所は「現在有効/無効」という区間概念ではなく単一の
  現在値(履歴は変更時にのみ追記)であるのに対し、設置備品の設置場所は「開始/終了」を持つ
  区間の連続であり、意味・更新頻度・UIが異なるため素直に分離する。

### 論点6: 貸出方式(`lending_method`)と通常配置場所必須制約をどこで検証するか

- 選択肢:
  - A. Command層(CommandHandler)でガード(DBのProjectionを読んで検証)し、Aggregate自体は
    不変条件を意識しない薄いレイヤーにする(既存Attendanceドメインの「締め後判定は
    Aggregate外のGuardで行う」方針を踏襲)。
  - B. Aggregate内部にAssetの全状態を再構築して不変条件チェックまで持たせる。
- 決定: A。
- 理由: 既存`AttendanceDayAggregate`が業務ルール判定を専用Guardクラス+Projection参照で
  行っている既存パターンに合わせる。`self_service`への変更時は`AssetLendingMethodChangeGuard`
  (仮称)が現在の`assets`Projectionを読み、`default_location_text`がnullなら変更コマンドを
  `ValidationException`等で拒否する。

### 論点7: QRコードの実装方式

- 選択肢:
  - A. 備品ごとに一意なQRトークン(例: `EQ-00121`のような管理番号そのもの、または
    別途ランダムなQRコード文字列)をProjectionに持たせ、QR画像自体はフロントエンドで
    (例: `qrcode.react`等のクライアントサイドライブラリで)描画する。再発行は
    QRトークンを新しい値に差し替えるだけで、管理番号・履歴は変わらない。
  - B. サーバー側でQR画像(PNG/SVG)を生成し保存する。
- 決定: A。
- 理由: 既存のdevicesペアリングQR運用(サーバーはURL/トークンのみ払い出し、画像描画は
  フロント)を踏襲する。指示書29項「QR内に可変情報(名称・状態・貸出先等)を埋め込まない、
  識別情報のみ」とも整合する。QRの中身は「備品詳細へ遷移できる識別子(管理番号 or 専用
  QRトークン)」のURLとする。QRトークンは管理番号と別に持たせ(`asset_qr_tokens`相当、
  もしくは`assets.qr_token`+再発行時は`AssetQrCodeReissued`イベントで値を更新)、
  管理番号(`asset_no`)は不変・QRトークンのみ再発行対象とする。

### 論点8: 一括QR操作(セルフ貸出/一括貸与/一括返却/一括移設)のトランザクション方式

- 選択肢:
  - A. スキャン→対象リスト追加は完全にフロント側のローカル状態(サーバー未送信)とし、
    「確定」操作時にのみ1回のAPIリクエストで対象備品ID配列を送信、バックエンド側で
    備品ごとに個別Command発行(1備品=1Aggregate=1トランザクション)し、失敗した備品は
    その旨をレスポンスに含めて返す(部分成功を許容)。
  - B. スキャンの都度サーバーに検証APIを呼び、対象リスト自体をサーバー側セッション等で
    保持する。
  - C. 一括操作用の専用Aggregate(`BulkOperationAggregate`)を作り、複数Assetの状態変更を
    1つのAggregateトランザクションにまとめる。
- 決定: A。
- 理由: 指示書45番で「無理に複数Aggregateを巨大な単一Aggregateへ統合しない」と明示されて
  おり、C案は却下。CQRS/ES基盤の`CommandBus`はコマンド単位のトランザクション(Aggregate単位)
  を前提としており、複数Aggregateにまたがる一括処理をアプリケーション層のループで
  1件ずつCommand発行するA案が既存アーキテクチャに最も自然に乗る。スキャン時点の検証
  (B相当の考慮)は、フロントが確定前に都度「検証専用の軽量GET API」
  (`GET /api/assets/{asset}/loan-eligibility`等)を呼んで対象リストに追加可否を判定する
  ことで実現し、サーバー側にセッション状態は持たせない(ステートレス)。確定時に
  CommandHandler側で改めて全項目を再検証する(指示書30・52番の「確定時にも再検証する」)。

### 論点9: 削除(`AssetDeleted`)と廃棄(`AssetDisposed`)の実装

- 選択肢:
  - A. どちらもAggregate上のイベントとして記録し、削除時はProjectionから物理削除
    (`assets`テーブルの行を消す)、廃棄時はProjectionの`status`を`disposed`に更新する
    (行は残す)。削除可否(貸出中/承認待ち申請あり/修理中でないか)はCommandHandlerが
    Projectionを見て検証する。
  - B. 削除は論理削除(`deleted_at`)で行う。
- 決定: A。
- 理由: 指示書26番「EventStoreが履歴の正本であるため、削除時はRead Modelから対象備品を
  削除して構わない」と明示されているため、Projectionの物理削除で問題ない
  (EventStore側の`stored_events`は削除しない)。論理削除カラムは不要な複雑化になるため
  採用しない。

### 論点10: 権限設計

- 選択肢:
  - A. `AccessControlCatalog::PERMISSIONS`に備品管理専用のPermissionコードを新規追加する
    (例: `asset.view`/`asset.manage`/`asset.self_borrow`/`asset.loan_request`)。
  - B. 既存の`backoffice_task.execute`等の汎用Permissionを流用し、新規コードを増やさない。
- 決定: A(ただし最小限。一般ユーザーの基本操作にはPermissionを新設しない)。
- 理由: 既存の`attendance.read`/`attendance.update`のように「ドメイン.操作」単位で
  Permissionを切る既存パターンに合わせつつ、指示書43番の一般ユーザー欄が「特別な権限を
  前提としない基本操作」として書かれていることから、検索・QRセルフ貸出・セルフ返却・
  自分の貸与確認・貸出申請作成・自分の申請取下げについては専用Permissionを作らず、
  認証済みユーザーであれば誰でも操作可能とする(ユーザーに確認済み)。
  新設するPermissionコードは以下の1つのみとする(論点1・2で`approval`方式の貸与実行も
  `backoffice`と同じ入口に統一したため、承認専用のPermissionも不要になった):
  - `asset.manage`(scopes: `global`) — 備品登録・編集・削除・QR発行/再発行・管理区分変更・
    貸出方式変更・設置・移設・撤去・修理・紛失・発見・廃棄・`backoffice`/`approval`方式の
    `LendAsset`実行・一括貸与・返却・一括返却。加えて、備品貸出申請(`asset_loan`)の
    approver(承認者)として選択できるのも本Permission保有者に限定する
    (申請の承認自体は既存`workflow_requests`の仕組み(指定されたapprover本人のみ実行可)を
    そのまま使うため、承認専用の別Permissionは不要)。
  将来的に一部社員だけ備品管理機能自体を非公開にしたい場合は、既存のFeature(機能単位の
  ON/OFF、`AccessControlCatalog`のFeature側)で「備品管理」機能を切り出し、Feature未付与の
  ユーザーには画面自体を出さないという既存の使い分け(Permission=操作粒度、
  Feature=機能公開可否)で対応する(本変更では新規Feature追加は行わない)。

### 論点11: DB制約・楽観的並行制御

- 選択肢:
  - A. `stored_events`の既存`unique(aggregate_uuid, aggregate_version)`制約による
    楽観的並行制御をそのまま踏襲し、Aggregate固有の追加ロックは持たない。
  - B. 備品貸出のような競合が起きやすい操作について、Projection側にも
    `SELECT ... FOR UPDATE`等の追加排他制御を入れる。
- 決定: A(既存踏襲)。ただし一括貸与/一括返却のようにループで複数Aggregateへ
  Commandを発行する処理では、1件ごとに独立したトランザクション・バージョンチェックを行い、
  ある備品で`aggregate_version`衝突(=同時に別操作で状態が変わった)が起きた場合はその
  備品のみ失敗として扱い、他の備品の処理は継続する。
- 理由: 既存のAttendance等のドメインもAggregate単位のバージョン制約のみで運用しており、
  追加のDBロック機構を持ち込む必然性がないため。

## 仕様確定事項(まとめ)

### ドメイン構成(`backend/app/Domain/Asset/`)

- Aggregate: `AssetAggregate`(備品本体。UUID主キー)**のみ**。申請専用のAggregateは作らない
  (論点1・論点2)。
- Command一覧(すべて`AssetAggregate`宛て):
  `RegisterAsset`, `UpdateAssetDetails`(名称・カテゴリ・シリアル番号等), `DeleteAsset`,
  `ChangeAssetManagementType`, `ChangeAssetLendingMethod`, `ReissueAssetQrCode`,
  `SetAssetDefaultLocation`(通常配置場所の設定/変更。貸出備品のみ),
  `LendAsset(assetId, borrowerUserId, lentByUserId, expectedReturnAt?, loanRequestId?)`
  (`self_service`/`backoffice`/`approval`すべてこの1つのCommandを使う。差は呼び出し側の
  権限・前提条件チェックのみ。論点1参照)、
  `ReturnAsset(assetId, loanId, returnedByUserId, returnNote?)`,
  `InstallAsset`, `RelocateAsset`, `RemoveAssetFromInstallation`(撤去→保管),
  `StartAssetRepair`, `CompleteAssetRepair`,
  `ReportAssetLost`, `RecoverAssetFromLost`, `DisposeAsset`。
- 貸出申請(`workflow_requests`側): 専用Command/Eventは追加しない。既存の
  `SubmitWorkflowRequest`/`WithdrawWorkflowRequest`(取下げ)/`ApproveWorkflowRequest`/
  `CancelWorkflowRequest`(承認済み取消)をそのまま使う。却下は論点2-2で追加する汎用
  `RejectWorkflowRequest`を使う。`request_types`に`code=asset_loan`(`form_schema`に
  `asset_id`・`purpose`を定義)を1件追加するのみで、備品ドメイン固有のCommand/Eventは
  一切追加しない。
- Event一覧(`event_type`、`asset.`プレフィックスのみ。備品ドメイン独自の申請イベントはない):
  `asset.registered`, `asset.details_updated`, `asset.deleted`,
  `asset.management_type_changed`, `asset.lending_method_changed`,
  `asset.qr_code_reissued`, `asset.default_location_set`,
  `asset.loaned`(`loanId`・貸与者・借用者・返却期限・`loanRequestId`(nullable)を含む),
  `asset.returned`, `asset.installed`, `asset.relocated`, `asset.removed_from_installation`,
  `asset.repair_started`, `asset.repair_completed`,
  `asset.reported_lost`, `asset.recovered_from_lost`, `asset.disposed`。
  申請の進行状況は`workflow_request.submitted`/`.approved`/`.rejected`/`.withdrawn`/
  `.cancelled`(汎用イベント)のみで表現する。`asset_loan_requests`Projectionはこれらの
  イベントを購読するReactorが更新する(備品ドメイン独自の申請イベントは持たない)。

### 状態遷移

- 貸出備品`lending_status`: `available → loaned → available`(返却)。
  `available ⇄ repair`(修理開始/完了)。`available/loaned → lost`(紛失、貸出中の場合は
  借用者情報を保持したまま遷移)。`lost → available`(発見。発見時は「発見時点で貸出中扱い
  だったか」を`asset.recovered_from_lost`のpayloadに残し、UIで貸出継続 or 返却済み扱いを
  選ばせる)。`available → disposed`(廃棄。`loaned`/`repair`中は先に返却/修理完了させる
  ことを必須とし、直接廃棄はできない)。
- 設置備品`installation_status`: `stored → installed → stored`(撤去)。
  `stored/installed → repair → stored`(修理完了後は保管に戻し、必要なら改めて設置)。
  `stored/installed → lost → stored`。`stored → disposed`。
- 貸出申請`status`(`asset_loan_requests`、すべて`workflow_requests`側イベントの反映結果):
  `pending → approved → lent`(貸与時、`asset.loaned`イベントの`loanRequestId`をキーに
  Reactorが反映)、`pending → withdrawn`、`approved → cancelled`(承認済み取消)、
  `pending → rejected`(論点2-2、`workflow_request.rejected`の反映)。いずれも備品ドメイン
  自身のCommandでは発生しない。

### 貸出方式(`lending_method`)と`LendAsset`呼び出し条件

`LendAsset`/`ReturnAsset`はAggregate上は1種類のみ。`lending_method`ごとの違いは
呼び出し側(Controller/Guard)の前提条件としてのみ表現する。

- `self_service`: `default_location_text`必須(`ChangeAssetLendingMethod`のGuardで検証)。
  `LendAsset`を呼べるのは本人のみ、かつ`borrowerUserId = lentByUserId = 呼び出し本人`という
  制約をController側で強制。
- `backoffice`: `asset.manage`権限保有者が任意の`borrowerUserId`を指定して`LendAsset`を
  呼べる。`default_location_text`任意。
- `approval`: `asset.manage`権限保有者のみ`LendAsset`を呼べる(`backoffice`と同じ入口)。
  加えて、対象`borrowerUserId`に対する承認済み・未貸与の`asset_loan_requests`
  (`workflow_requests`側で`approved`)が存在することをController/CommandHandlerの前段
  チェックで要求し、存在すればその`loanRequestId`を`LendAsset`に渡す。`default_location_text`
  任意。
- `self_service`への貸出方式変更は`default_location_text`が設定済みの場合のみ許可
  (Guardで検証)。

### 削除可否ガード

`DeleteAsset`は以下のいずれかに該当する場合は`ValidationException`で拒否する:
`lending_status=loaned`、`lending_status=repair`、`installation_status=installed`、
`installation_status=repair`、承認待ち(`pending`)または承認済み未貸与(`approved`)の
`asset_loan_requests`が存在する場合。指示書26番の「進行中の業務」の例には「設置中」が
明記されていなかったため確認したところ、ユーザーより「設置中も削除禁止にする」との
判断を得た(現物がどこかに設置されたままシステム上だけ消えることを避けるため、
先に撤去(`RemoveAssetFromInstallation`)してから削除する運用とする)。

### 一括QR操作API

各一括操作(セルフ一括貸出/一括返却、バックオフィス一括貸与、一括返却、一括移設)は
共通パターンで実装する:
1. `GET /api/assets/by-code/{code}`または`GET /api/assets/{asset}`でスキャン都度に
   対象1件の適格性を検証するエンドポイントを叩き、フロント側リストに追加(サーバーには
   何も保存しない)。
2. 確定操作は1リクエストで対象`asset_id`配列を送信する専用エンドポイント
   (例: `POST /api/assets/bulk-self-loan`)。バックエンドはループで各Assetへ`LendAsset`/
   `ReturnAsset`等のCommandを発行し(自己貸出・バックオフィス貸与・返却・一括移設のいずれも
   同じCommandを使う。論点1参照)、成功/失敗を配列で返す(部分成功を許容、失敗理由を含める)。

### QRコード

- `assets.qr_token`(ランダム文字列、unique)を持つ。QR画像自体はフロントでレンダリング
  (ライブラリはfrontend側の変更セット/実装時に既存依存を確認し選定)。
- QRの中身は`{フロントのベースURL}/assets/qr/{qr_token}`のような識別URL(名称・状態等の
  可変情報は含めない)。
- 再発行(`ReissueAssetQrCode`)は`qr_token`を新しいランダム値に差し替えるのみ。`asset_no`
  (管理番号)・履歴は変更しない。

### 削除 vs 廃棄

- 廃棄(`DisposeAsset`)は業務イベント。Projection上は`status=disposed`のまま残し一覧・
  検索対象にも表示する(フィルタで絞り込み可能にする)。
- 削除(`DeleteAsset`)はProjectionから物理削除するが、`stored_events`は一切変更しない。

## 対象外

- 購入申請・調達管理・発注管理・仕入先管理・在庫管理・消耗品管理。
- 貸出予約(`asset_reservations`相当)。
- 拠点/部屋/棚/座席マスタ(`locations`テーブル)。
- 複雑な施設管理、備品セット管理(付属品はProjectionの備考テキストで表現、独立備品として
  登録したい場合は個別に`RegisterAsset`する)。
- 棚卸機能。
- 返却期限超過の通知(`expected_return_at`カラム自体は持つが、督促通知は本変更セット対象外。
  将来`add-notification`スキルで追加できるようにイベント名は用意しておく)。
- `workflow_requests`共通スキーマへの大規模な変更(却下理由カラムなど、既存申請種別と
  共有が必要な変更は実装時に最小限のマイグレーションに留め、他ドメインの挙動を変えない)。

## ドキュメントへの影響

- `docs/README.md`: 目次に新規ドキュメント(下記)へのリンクを追加する。
- 新規作成: `docs/26-usecases-asset-management.md`(ユースケース一覧: UC-A/UC-L/UC-I番号で
  本spec「仕様確定事項」の内容を正式化。指示書のUC-L01〜UC-L11、UC-I01〜UC-I05に相当)。
  ※既存の`docs/23`〜`25`は端末/認証キー/MCP関連のため、備品管理は新規に26番以降へ追記する
  (実装時に既存ファイル番号の欠番がないか`docs/README.md`で最終確認する)。
- `docs/16-database-schema.md`: `assets` / `asset_default_location_changes` /
  `asset_placements` / `asset_loan_requests` / `asset_loans` テーブル定義を追記。
- `docs/17-events.md`: `## Asset` / `## AssetLoanRequest` セクションを追加し、上記イベント
  一覧を記載。
- `docs/05-user-roles.md`(または権限一覧の該当章): `asset.manage`の1Permissionと、それ以外の
  基本操作(検索・セルフ貸出・セルフ返却・自分の貸与確認・貸出申請作成・自分の申請取下げ・
  貸出申請の承認自体)はPermission不要である旨を追記。
- `docs/10-usecases-workflow.md`: `request_types`に`asset_loan`を追加する旨、
  `subject_type=AssetLoanRequest`のポリモーフィック連携、および新規追加する汎用「却下」
  (`REJECTED`状態・`RejectWorkflowRequest`/`WorkflowRequestRejected`・
  `rejected_at`/`rejection_reason`カラム)を追記。
- `docs/17-events.md`: `## Workflow (汎用申請)`セクションに`workflow_request.rejected`を追加。
- `docs/03-architecture.md`: 変更なし(既存原則の範囲内のため)。

## モック・アセット

なし(UI詳細は実装フェーズでフロント側の`add-page`スキルに沿って別途ワイヤーフレーム化する)。

## 実装対象

- Backend:
  - `backend/app/Domain/Asset/{Aggregates,Commands,Events,Handlers,Projectors,Services,Guards}/`
    (`AssetAggregate`1つのみ。`AssetLoanRequestAggregate`等の申請専用Aggregateは作らない)
  - `backend/app/Domain/Asset/Reactors/`: `workflow_requests`側のイベント
    (`request_type=asset_loan`のもの)を購読して`asset_loan_requests`Projectionを更新する
    Reactor、および`asset.loaned`(`loanRequestId`あり)を購読して該当申請を`lent`表示に
    更新するReactor。
  - `backend/database/migrations/`: `assets`, `asset_default_location_changes`,
    `asset_placements`, `asset_loan_requests`, `asset_loans`, および`request_types`への
    `asset_loan`シードレコード追加。加えて`workflow_requests`へ`rejected_at`/
    `rejection_reason`カラムを追加するマイグレーション(汎用却下機能、論点2-2)。
  - `backend/app/Domain/Workflow/{Commands,Events,Handlers}/`: `RejectWorkflowRequest`
    Command・`WorkflowRequestRejected`Event・Handler・`WorkflowRequestStatus::REJECTED`
    追加(既存の`ReturnWorkflowRequest`/`CancelWorkflowRequest`と同様のパターンで実装)。
    追加前に、既存の`WorkflowRequestStatus`を参照している全箇所(他ドメインのReactor・
    一覧フィルタ・通知ロジック・フロントの表示分岐等)を洗い出し、新しい終端状態
    `REJECTED`の追加で意図しない挙動(想定漏れのswitch文等)が発生しないことを確認する。
  - `backend/app/Domain/AccessControl/AccessControlCatalog.php`: Permission1件
    (`asset.manage`)追加。
  - `backend/app/Http/Controllers/Api/Asset/`: 一覧/詳細/検索/各種アクション/一括操作
    コントローラ。
  - `backend/routes/api.php`: 上記エンドポイント追加。
  - `backend/config/domain.php`, `backend/config/event-sourcing.php`: Command/Event登録。
  - `backend/tests/Feature/Asset/`: 仕様確定事項・「テストで重点的に確認すること」に基づく
    Featureテスト一式。
- Frontend:
  - `frontend/src/api/asset.ts`, `frontend/src/hooks/useAsset.ts`
  - `frontend/src/pages/asset/`: 一覧・詳細・QR一括操作系ページ一式(`add-page`スキル使用)。
  - `frontend/src/components/asset/`: 備品カード・状態バッジ等(`add-frontend-component`使用)。
  - ナビゲーション(`AppLayout`/`AdminLayout`)への「備品管理」メニュー追加。
- Docs: 上記「ドキュメントへの影響」記載ファイル一式。

## 検証方法

- Backend: `cd backend && php artisan test --filter=Asset`
- Frontend: `cd frontend && npm run test -- asset` / Storybook該当ストーリーの目視確認。
- 手動確認: セルフ貸出→返却、バックオフィス一括貸与、申請→承認→貸与、修理→紛失→発見、
  削除ガード(貸出中は削除不可)、QR再発行後も履歴・管理番号が変わらないこと。

## レビュー履歴

- 初版。
- 2026-08-30: 却下(UC-L09)の実装場所についてユーザーに確認したところ「却下は申請業務
  (ワークフロー)の内数であり、貸出ドメインに却下の概念を持ち込まない」との回答を得た。
  これを受けて論点2-2を追加し、却下を`workflow_requests`側の汎用機能として実装する方針に
  変更(備品ドメイン側の`RejectAssetLoanRequest`/`asset_loan_request.rejected`は削除)。
- 2026-08-30: 削除可否ガードにおける「設置中」の扱いをユーザーに確認し、「設置中も
  削除禁止にする」との回答を得た(仕様確定事項「削除可否ガード」を修正)。
- 2026-08-30: 一般ユーザーの基本操作(検索・セルフ貸出/返却・自分の貸与確認・貸出申請
  作成/取下げ)にPermissionを必須にするかユーザーに確認し、「不要(認証済みなら誰でも可)」
  との回答を得た。これを受け`asset.view`/`asset.loan_request.create`を廃止し、新設
  Permissionを`asset.manage`/`asset.loan_request.approve`の2つに縮小(論点10を修正)。
- 2026-08-30: `AssetAggregate`と貸出申請用Aggregateを分けた設計についてユーザーから
  「まず申請対象エンティティ(Asset)の状態を、申請がない前提で設計し、申請があるならその
  上にどう乗せるかという順序で設計してほしい」との指摘を受けた。この観点で再検討した結果、
  `self_service`/`backoffice`は同一の`LendAsset`Commandに統合できること、`approval`方式も
  「`LendAsset`実行前に承認済み申請の存在を求める」という前提条件の追加でしかないことが
  判明し、`AssetLoanRequestAggregate`という専用Aggregate自体が不要と判断した(論点1・
  論点2を全面的に書き直し)。これに伴い、承認専用のPermission`asset.loan_request.approve`も
  不要となり、新設Permissionは`asset.manage`の1つのみに縮小した(論点10を再修正)。
- 2026-08-30: レビュー観点の洗い出しとして、(1)同一資産への複数承認済み申請の扱い、
  (2)`workflow_requests`への`REJECTED`追加の影響範囲精査、(3)`approval`貸与時の申請選定
  ロジック、(4)セルフ貸出における借用者の真正性、をこちらから提示した。このうち
  (1)/(3)についてユーザーに確認し、「重複申請の制約は設けず、貸与時にバックオフィスが
  承認済み申請一覧から手動選択する運用で対応する」との回答を得た。これを受け論点2-3を
  新設し、`approval`貸与時の申請選定ルールを明記した。(2)は実装フェーズで
  `WorkflowRequestStatus`の既存利用箇所(他ドメインのReactor等)を洗い出すタスクとして
  「実装対象」に残し、(4)は既存のセルフ貸出設計が前提とする性善説の範囲内として対象外の
  まま据え置く。

## 実装結果

未着手。
