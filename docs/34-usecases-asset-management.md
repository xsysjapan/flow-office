# 34. 備品管理ユースケース

会社が保有する備品(PC・スマートフォン等の貸出品、会議室モニター等の設置品)の登録・貸出・
返却・貸出申請/承認・設置/移設/撤去・修理・紛失・廃棄・QRコードを利用した操作・検索/参照を
扱う。購入・調達申請は対象外で、備品管理は「購入後、会社の備品として登録するところ」から
開始する(`docs/changesets/20260830-equipment-management/spec.md`が仕様検討の正)。

## ドメイン構成

- `App\Domain\Asset\Aggregates\AssetAggregate`(UUID主キー)**のみ**。貸出品・設置品は
  `assets.management_type`(`lending`/`installation`)で区別し、別Aggregateには分けない。
  貸出申請専用のAggregateも作らない。
- 貸出申請(`request_types.code=asset_loan`)は`workflow_requests`(汎用申請ワークフロー、
  [10章](./10-usecases-workflow.md))の既存Command/Eventのみで完結し、備品ドメイン固有の
  申請用Command/Eventは持たない。`asset_loan_requests`は`workflow_requests`側イベントと
  `asset.loaned`を購読するReactorが更新する読み取り専用Projection。

## 貸出方式(`lending_method`)

貸出品(`management_type=lending`)は3つの貸出方式のいずれかを持つ。`LendAsset`/
`ReturnAsset`コマンド自体はどの方式でも共通の1つであり、違いは呼び出し側
(Controller/Guard)の前提条件としてのみ表現する。

- `self_service`(セルフサービス): `default_location_text`が設定済みの備品のみ選択できる
  (`AssetLendingMethodGuard`)。
- `backoffice`(バックオフィス貸与): `asset.manage`権限保有者が任意の借用者を指定して貸与する。
- `approval`(承認制): `asset.manage`権限保有者のみ貸与でき、対象借用者に対する承認済み・
  未貸与の貸出申請(`asset_loan_requests`)の存在を前提条件とする。

## UC-L01: 備品をセルフ貸出する

**アクター**: 一般社員(認証済みであれば誰でもよい。Permission不要)

**事前条件**: 対象備品が`management_type=lending`かつ`lending_method=self_service`かつ
`lending_status=available`であること。

1. 社員が備品検索・QR読み取りで対象備品を開く
2. 「借りる」を押す
3. `POST /assets/{asset}/lend`を`borrower_user_id = lent_by_user_id = 本人`で呼ぶ
   (`AssetController::lend`が`isSelfServiceForSelf`を検証しPermissionチェックを免除する)
4. `LendAsset`コマンド → `asset.loaned`イベント(`loanRequestId`はnull)
5. `asset_loans`に新規貸出行が作成され、`assets.lending_status`が`loaned`、
   `assets.current_loan_id`が更新される

## UC-L02: 備品をセルフ返却する

**アクター**: 一般社員(Permission不要。他人による返却も許容する)

1. 社員が「返す」を押す
2. `POST /assets/{asset}/return`を呼ぶ(`loan_id`省略時は`assets.current_loan_id`を使う)
3. `ReturnAsset`コマンド → `asset.returned`イベント
4. `asset_loans.returned_at`/`returned_by_user_id`/`return_note`が記録され、
   `assets.lending_status`が`available`、`current_loan_id`が`null`に戻る

## UC-L03: バックオフィスが備品を貸与する

**アクター**: `asset.manage`権限保有者

**事前条件**: 対象備品が`lending_status=available`であること。`lending_method`は
`backoffice`/`approval`のどちらでもよい(`approval`はUC-L07参照)。

1. バックオフィス担当者が備品詳細画面で借用者・返却予定日を指定する
2. `POST /assets/{asset}/lend`を呼ぶ(`asset.manage`をController内で検証)
3. `LendAsset`コマンド → `asset.loaned`イベント

## UC-L04: バックオフィスが返却を受け付ける

**アクター**: `asset.manage`権限保有者、または借用者本人(UC-L02と同一操作)

UC-L02と同じ`POST /assets/{asset}/return`を使う。Permission不要のため、バックオフィスが
借用者に代わって返却処理することもできる。

## UC-L05: QRコードで一括セルフ貸出する

**アクター**: 一般社員(Permission不要)

**画面**: `SelfBulkLoanPage`(`frontend/src/pages/asset/bulk/`)

1. 社員が備品ピッカー(`AssetPicker`、`frontend/src/components/AssetPicker/AssetPicker.tsx`)
   でテキスト検索(管理番号の部分一致)またはカメラでのQRスキャン(`@zxing/browser`を
   使用)により対象を次々に追加し、フロント側のローカル状態に対象リストを積み上げる
   (サーバーには何も保存しない。追加都度`GET /assets/{asset}/loan-eligibility`等で
   適格性を検証できる)。この画面は複数選択できるため、QRリーダーの「連続読み取り」
   トグルが常に表示・有効になっており、オンにすると1件読み取ってもリーダーを開いたまま
   次のスキャンを続けられる(オフの場合は1件読み取るとリーダー・ポップアップが閉じる)
2. 「確定」を押すと`POST /assets/bulk`を`operation=self_loan`・`asset_ids`配列で1回呼ぶ
3. `AssetBulkOperationController`がループで各`asset_id`に`LendAsset`を発行する
   (`borrower_user_id`は常に呼び出し本人。`lending_method`が`self_service`でない備品は
   その備品だけ失敗として扱う)
4. 成功/失敗を備品ごとの配列で返す(部分成功を許容)

## UC-L06: QRコードで一括返却する

**アクター**: 一般社員(Permission不要)

**画面**: `SelfBulkReturnPage`

UC-L05と同じ確定パターンで、`POST /assets/bulk`を`operation=self_return`で呼ぶ。
`AssetBulkOperationController`は「呼び出し本人が現在借用中の備品」であることを
備品ごとに検証し、`ReturnAsset`を発行する。

## UC-L07: 備品貸出を申請する(承認制)

**アクター**: 一般社員(Permission不要)

**事前条件**: 対象備品が`lending_method=approval`であること。

1. 社員が対象備品を選び、利用目的(`purpose`)を入力する
2. 承認者を`asset.manage`権限保有者から選択する
3. `request_types.code=asset_loan`の`workflow_requests`を作成・提出する
   (`form_data = {asset_id, purpose}`。既存の`DraftWorkflowRequest`/`SubmitWorkflowRequest`
   をそのまま使う)
4. `App\Domain\Asset\Reactors\AssetLoanRequestOnWorkflowRequestReactor`が
   `asset_loan_requests`に`status=pending`の行を作成する
5. 承認されただけでは貸出中にはならない(UC-L08参照)

## UC-L08: 承認制の貸出申請を承認する

**アクター**: 申請時に指定された承認者(`approval.execute` Permission)

1. 承認者が申請詳細を開く(既存の`workflow_requests`承認フロー、[10章](./10-usecases-workflow.md) UC-W003)
2. 承認する(`ApproveWorkflowRequest`コマンド → `workflow_request.approved`イベント)
3. `AssetLoanRequestOnWorkflowRequestReactor`が`asset_loan_requests.status`を`approved`に更新する
4. バックオフィス(`asset.manage`権限保有者)が備品詳細画面の承認済み申請一覧
   (`GET /assets/{asset}/loan-requests`)から対象申請を選び、`POST /assets/{asset}/lend`を
   `loan_request_id`付きで呼ぶ
5. `LendAssetHandler`が対象`loan_request_id`が承認済み・対象備品・対象借用者と一致することを
   再検証してから`LendAsset`を実行する
6. `asset.loaned`イベント(`loanRequestId`あり)を`AssetLoanRequestOnAssetLoanedReactor`が
   購読し、`asset_loan_requests.status`を`lent`に更新する

同一資産に対して承認済み・未貸与の申請が複数存在する場合、システムは自動選択せず、
バックオフィスが一覧から手動で1件選ぶ(重複申請自体は許容し、貸与されなかった側は
必要に応じてUC-L10(取消)で手動整理する運用とする)。

## UC-L09: 承認者が貸出申請を却下する

**アクター**: 申請時に指定された承認者(`approval.execute` Permission)

却下は`workflow_requests`本体が持つ全申請種別共通の汎用機能であり、備品ドメイン側は
却下の概念を一切持たない([10章 UC-W003-2](./10-usecases-workflow.md#uc-w003-2-承認者が申請を却下する)参照)。

1. 承認者が申請詳細画面(`WorkflowRequestDetailPage`)で却下理由を入力する
   (却下ボタンは`request_type.code=asset_loan`の申請にのみ表示される)
2. `POST /workflow-requests/{id}/reject`を呼ぶ
3. `RejectWorkflowRequest`コマンド → `workflow_request.rejected`イベント
   (`workflow_requests.rejected_at`/`rejection_reason`に反映、終端状態`REJECTED`)
4. `AssetLoanRequestOnWorkflowRequestReactor`が`asset_loan_requests.status`を`rejected`に
   更新する

## UC-L10: 申請者が貸出申請を取り下げる/バックオフィスが承認済み申請を取り消す

**アクター**: 申請者本人(取下げ)、または承認者・バックオフィス(承認済み取消)

既存の`workflow_requests`の汎用操作(`WithdrawWorkflowRequest`/`CancelWorkflowRequest`)を
そのまま使う。`asset_loan_requests.status`はそれぞれ`withdrawn`/`cancelled`に更新される。

## UC-L11: 自分の貸与状況を確認する

**アクター**: 一般社員(Permission不要)

`GET /users/{user}/asset-loans`で自分が現在借用中/過去に借用した備品の一覧を確認する。

## UC-I01: 備品を設置する

**アクター**: `asset.manage`権限保有者

**事前条件**: 対象備品が`management_type=installation`かつ`installation_status=stored`。

1. バックオフィス担当者が設置場所(`location_text`)を入力する
2. `POST /assets/{asset}/install`を呼ぶ
3. `InstallAsset`コマンド → `asset.installed`イベント
4. `asset_placements`に現在有効な設置行(`ended_at=null`)が作成され、
   `assets.installation_status`が`installed`になる

## UC-I02: 備品を移設する

**アクター**: `asset.manage`権限保有者

**事前条件**: 対象備品が`installation_status=installed`。

1. 新しい設置場所を入力する
2. `POST /assets/{asset}/relocate`を呼ぶ
3. `RelocateAsset`コマンド → `asset.relocated`イベント
4. 従来の`asset_placements`行を`ended_at`で終了させ、新しい設置行を作成する

## UC-I03: 備品を撤去する(設置→保管)

**アクター**: `asset.manage`権限保有者

1. `POST /assets/{asset}/remove-from-installation`を呼ぶ
2. `RemoveAssetFromInstallation`コマンド → `asset.removed_from_installation`イベント
3. `asset_placements`の現在有効な行を`ended_at`で終了させ、
   `assets.installation_status`が`stored`に戻る

## UC-I04: 一括移設する(QR一括操作)

**アクター**: `asset.manage`権限保有者

**画面**: `BulkRelocatePage`

UC-L05と同じ確定パターンで、`POST /assets/bulk`を`operation=relocate`・`location_text`で
呼ぶ。`AssetBulkOperationController`内で`asset.manage`を検証し、対象備品ごとに
`RelocateAsset`を発行する。

## UC-I05: バックオフィスが一括貸与する(QR一括操作)

**アクター**: `asset.manage`権限保有者

**画面**: `BackofficeBulkLendPage`

`POST /assets/bulk`を`operation=backoffice_lend`・`borrower_user_id`で呼ぶ。対象備品が
`lending_method=approval`の場合、`AssetBulkOperationController`が対象備品・対象借用者に
一致する承認済み(`AssetLoanRequestStatus::APPROVED`)の`asset_loan_requests`を
`approved_at`降順で1件自動選択し、`loan_request_id`として`LendAsset`に渡す
(複数一致時にどれを使うかを手動選択させるUIはUC-L08の単体貸与画面のみで提供し、
一括操作では最新の承認済み申請を機械的に選ぶ)。

`BackofficeBulkReturnPage`(`operation=return`)も同じ確定パターンで一括返却を行う。

## 備品の登録・編集・削除・状態変更(バックオフィス操作)

いずれも`asset.manage`権限が必須。

- **登録**(`AssetRegisterPage`): `POST /assets`→`RegisterAsset`コマンド→`asset.registered`
  イベント。`asset_no`(管理番号)・`qr_token`はこの時点で確定し、以後不変
  (`qr_token`のみ後述の再発行で差し替え可能)。
- **編集**(`AssetEditPage`): `PATCH /assets/{asset}`→`UpdateAssetDetails`→
  `asset.details_updated`(名称・カテゴリ・シリアル番号・備考)。
- **削除**: `DELETE /assets/{asset}`→`DeleteAsset`→`asset.deleted`。`lending_status=loaned`/
  `repair`、`installation_status=installed`/`repair`、承認待ち(`pending`)または承認済み
  未貸与(`approved`)の貸出申請が存在する場合は`AssetActiveBusinessGuard`が拒否する
  (設置中の備品は先に撤去(UC-I03)してから削除する)。Projectionからは物理削除するが、
  `stored_events`は変更しない。
- **管理区分変更**(`lending`⇄`installation`): `POST /assets/{asset}/management-type`→
  `ChangeAssetManagementType`→`asset.management_type_changed`。削除と同じ
  `AssetActiveBusinessGuard`の検証を通す。
- **貸出方式変更**: `POST /assets/{asset}/lending-method`→`ChangeAssetLendingMethod`→
  `asset.lending_method_changed`。`self_service`への変更は`default_location_text`が
  設定済みの場合のみ許可し、貸出中・修理中の備品は変更できない。
- **通常配置場所の設定**(貸出品のみ): `POST /assets/{asset}/default-location`→
  `SetAssetDefaultLocation`→`asset.default_location_set`。変更履歴は
  `asset_default_location_changes`に追記される(現在値は貸出中でも不変)。
- **修理開始/完了**: `POST /assets/{asset}/repair/start` / `.../repair/complete`→
  `StartAssetRepair`/`CompleteAssetRepair`→`asset.repair_started`/`asset.repair_completed`。
  貸出品・設置品共通。
- **紛失報告/発見**: `POST /assets/{asset}/lost` / `.../recover`→`ReportAssetLost`/
  `RecoverAssetFromLost`→`asset.reported_lost`/`asset.recovered_from_lost`。貸出中に紛失した
  場合も借用者情報(`current_loan_id`/`asset_loans`)は保持される。発見時は
  `wasLoanedBeforeLoss`により`lending_status`が`loaned`/`available`のどちらへ戻るかを決める。
- **廃棄**: `POST /assets/{asset}/dispose`→`DisposeAsset`→`asset.disposed`。`loaned`/`repair`
  中は先に返却/修理完了させる必要があり、直接廃棄はできない。Projection上は`status=disposed`
  のまま行を残し、検索・一覧対象にも表示する(削除とは異なる)。
- **QRコード再発行**: `POST /assets/{asset}/qr-code/reissue`→`ReissueAssetQrCode`→
  `asset.qr_code_reissued`。`qr_token`のみ新しいランダム値に差し替え、`asset_no`・履歴は
  変更しない。QRの中身は`qr_token`そのものではなく、`App\Support\FrontendUrl::path()`で
  組み立てた完全なURL(`AssetResource.qr_url`、`/assets/qr/{qr_token}`形式。既存の
  デバイスペアリングQRの`claim_url`と同じ組み立て方)であり、名称・状態等の可変情報は
  含まない識別URLとする。スマホのカメラでQRラベルを直接読み取って開くと、フロントエンドの
  `/assets/qr/:token`ルート(`AssetQrRedirectPage`)が`GET /assets/by-qr/{token}`で
  トークンから資産を解決し、備品詳細画面(`/assets/{id}`)へリダイレクトする(未ログイン時は
  既存の保護ルートの仕組みによりログイン後に同URLへ戻る)。QR画像自体はフロントエンドで
  レンダリングする(サーバーはトークン・URLのみ払い出す)。

## 検索・参照(Permission不要)

- `GET /assets`: カテゴリ・管理区分・状態・貸出方式・申請状況等で絞り込んで検索する
  (`AssetListPage`)。
- `GET /assets/{asset}`: 備品詳細(`AssetDetailPage`)。QR画像
  (`frontend/src/components/AssetQrLabel/AssetQrLabel.tsx`、`qrcode.react`の
  `QRCodeSVG`を使用)を表示する画面はこの備品詳細画面のみで、`qr_url`をQRの値として
  描画する。物理的な備品ラベルとしてコンビニのネットプリント等でそのまま出力できる
  ことを想定し、QR+管理番号+名称の大きめ表示で`@media print`に対応した印刷向け
  レイアウトになっている(端末ペアリングQR(`DevicePairingQr`)のような画面間連携用途
  ではなく、印刷してモノに貼るためのものである点が異なる)。
- `GET /assets/by-qr/{qrToken}`: QRコードから備品詳細へ遷移する(`AssetQrRedirectPage`が
  使う)。レスポンスの`AssetResource`は`qr_token`に加え、`qr_url`(`/assets/qr/{qr_token}`
  形式の完全なURL)フィールドも返す。
- `GET /assets/{asset}/history`: `stored_events`から再構成した操作履歴。
- `GET /assets/{asset}/loan-eligibility`: QR一括操作のスキャン時点で、対象備品が指定操作の
  対象になり得るかを軽量に検証する(サーバー側にセッション状態は持たない)。
- `GET /assets/{asset}/loan-requests`: 対象備品に紐づく貸出申請一覧
  (UC-L08の承認済み申請選択用。取得自体は`asset.manage`検証をController内で行う)。

## 状態遷移まとめ

- 貸出品`lending_status`: `available → loaned → available`(返却)。
  `available ⇄ repair`(修理開始/完了)。`available/loaned → lost`(紛失、貸出中なら借用者
  情報を保持)。`lost → available/loaned`(発見。発見時点の貸出有無で分岐)。
  `available → disposed`(廃棄。`loaned`/`repair`中は直接廃棄不可)。
- 設置品`installation_status`: `stored → installed → stored`(撤去)。
  `stored/installed ⇄ repair`。`stored/installed → lost → stored`。`stored → disposed`。
- 貸出申請`asset_loan_requests.status`(すべて`workflow_requests`側イベントの反映結果で、
  備品ドメイン自身のCommandでは発生しない): `pending → approved → lent`、
  `pending → withdrawn`、`approved → cancelled`、`pending → rejected`。

## 対象外

- 購入申請・調達管理・発注管理・仕入先管理・在庫管理・消耗品管理
- 貸出予約
- 拠点/部屋/棚/座席マスタ(`locations`テーブル)。場所は`location_text`の自由記述
- 備品セット管理・棚卸機能
- 返却期限超過の督促通知(`expected_return_at`は保持するが通知は未実装)
