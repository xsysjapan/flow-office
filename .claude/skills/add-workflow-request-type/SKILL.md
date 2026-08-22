---
name: add-workflow-request-type
description: Use when adding a new general request type (申請種別) to flow-office's workflow module — e.g. 経費精算, 名刺申請, 証明書発行, or any new form-based request. Guides configuring request_types (form_schema, backoffice task generation) instead of hardcoding a new request flow in code, per docs/10-usecases-workflow.md and docs/11-usecases-backoffice.md.
---

# 新しい申請種別を追加する

flow-office の汎用申請は「申請種別マスタ (`request_types`)」で駆動する設計
(`docs/10-usecases-workflow.md`)。新しい申請(経費精算、名刺申請、備品申請、住所変更、
証明書発行など)を追加するときは、まず**新しいコードパスを作らずマスタで表現できないか**
を検討する。

## 手順

1. **既存の申請種別例と比較する**: `docs/10-usecases-workflow.md` の申請種別例
   (経費精算/交通費精算/名刺申請/備品申請/グッズ申請/住所変更/通勤経路変更/証明書発行/
   アカウント発行/一般申請) に近いものがあれば、それをテンプレートにする。

2. **`request_types` にレコードを追加する**:
   - `code` / `name` / `description`
   - `form_schema` — フォーム項目定義 (JSON)。バリデーションルールも含める。
   - `requires_backoffice_task` — 最終承認後にバックオフィスタスクを自動生成するか。
   - `backoffice_task_type` — 生成する場合のタスク種別 (`docs/11-usecases-backoffice.md`
     のステータス例・処理フローに合わせる)。
   - 申請可能な対象者 (全員 / 部署限定など)。

3. **`requires_backoffice_task = true` の場合**: 対応する `backoffice_tasks.task_type` の
   処理フロー・ステータス遷移を `docs/11-usecases-backoffice.md` の UC-B004〜UC-B006 を
   参考に定義する (経費精算なら経理担当者向け、名刺・備品なら総務担当者向けなど)。
   処理部署・初期ステータス・期限のデフォルト値も決める。

4. **添付ファイル要否を決める**: `docs/12-usecases-attachment.md` の共通添付の仕組みを
   使う。申請種別ごとに許可する拡張子・サイズ上限を上書きできるようにする。

5. **承認者選択の確認**: 承認者は固定ルートではなく申請時に任意の社員から選べる
   (`docs/05-user-roles.md`, UC-W002 手順4)。この申請種別で承認者選択に制約が必要か
   確認する (例: 特定ロールのみ承認可能、など)。

6. **通知**: 承認依頼・差戻し・承認完了の通知が飛ぶか確認する。新しい通知文言が
   必要なら `add-notification` スキルを使う。

7. **コードは変更しない前提で確認する**: フォーム項目・添付必須・バックオフィス生成
   有無は全てマスタ(`request_types.form_schema` 等)で表現し、`request_types` に
   行を追加するだけで新しい申請が動くことを確認する。もしコード分岐が必要になった
   場合、それは汎用化の余地がある(UC-W001の設計原則に反する)サインなので設計を見直す。

## ワークフローと業務ドメインを分離する

新しい申請種別を設計するときは、「申請」(誰が・何を・いつ申請し誰が承認するかという
進行状況、`workflow_requests`)と「業務」(その申請種別固有の未確定ステート・金額計算・
残高計算・確定処理などのドメインロジック)を分けて考える。

- 単純なフォーム入力・承認・バックオフィスタスク生成だけで完結する申請(名刺申請、
  備品申請、住所変更など)は`request_types`の設定だけで表現し、`workflow_requests`側で
  完結してよい。
- 有給休暇・振替休日・代休消化・経費精算のように、金額計算・残高計算・確定処理などの
  ドメイン固有ロジックを伴う申請は、`workflow_requests`に計算結果を持たせず専用ドメイン
  (`App\Domain\PaidLeave`/`ShiftSwap`/`CompensatoryLeave`/`Expense`等)を新設し、
  `workflow_requests.subject_type`/`subject_id`で連携するに留める
  (`docs/03-architecture.md` 3.9節、本章の代休申請・振替休日申請の実装例を参照)。
  この場合、承認・差戻し・取消のCommand/Handlerは汎用ワークフローのものを使い回せるか、
  専用ドメイン側に同等のCommand/Handlerを用意するかを設計時に決める。
- 「申請不要」のケースを考慮する: 業務によっては、承認ワークフローを経由せず勤怠実績等
  から自動導出・直接処理されるケースがありうる(例: 代休の「付与」は自動導出のため
  申請不要だが「消化」は申請制)。このような申請不要の業務データを、無理に
  `workflow_requests`側に取り込まない。新しい申請種別を検討する際は、まずその業務が
  本当に「申請」(承認を要する人の意思決定)を必要とするのか、それとも自動導出・自動確定
  で足りるのかを先に切り分ける。

## 承認取消・差戻しを設計するときの原則

承認(`workflow_request.approved`等)は状態遷移の橋渡しに過ぎず、承認という行為自体を
取り消す操作(承認イベントの削除・書き換え)は設けない。承認後に状態を巻き戻したい
場合は、新しい申請種別・処理フローであっても「承認取消」ではなく、差戻し・取消・
巻戻しといった別の専用Command/Eventとして新しい状態遷移を積む設計にする
(`CancelWorkflowRequestHandler`/`ReturnWorkflowRequestHandler`参照)。締め・確定後
(バックオフィス処理完了後等)の巻戻しを許可する場合は、管理者・バックオフィス担当者
など権限を限定し、申請者自身の通常操作には含めない。

## チェックリスト (実装後)

- [ ] コード分岐を追加せず `request_types` の設定だけで動く
- [ ] `requires_backoffice_task` に応じたタスク生成 (`docs/11-usecases-backoffice.md`)
      が正しく発火する
- [ ] 承認者は任意の社員から選択できる
- [ ] 添付ファイルの許可設定を確認した
- [ ] `docs/20-implementation-notes.md` のチェックリストに抵触していない
