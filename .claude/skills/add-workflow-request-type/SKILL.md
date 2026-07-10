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
   必要なら `add-teams-notification` スキルを使う。

7. **コードは変更しない前提で確認する**: フォーム項目・添付必須・バックオフィス生成
   有無は全てマスタ(`request_types.form_schema` 等)で表現し、`request_types` に
   行を追加するだけで新しい申請が動くことを確認する。もしコード分岐が必要になった
   場合、それは汎用化の余地がある(UC-W001の設計原則に反する)サインなので設計を見直す。

## チェックリスト (実装後)

- [ ] コード分岐を追加せず `request_types` の設定だけで動く
- [ ] `requires_backoffice_task` に応じたタスク生成 (`docs/11-usecases-backoffice.md`)
      が正しく発火する
- [ ] 承認者は任意の社員から選択できる
- [ ] 添付ファイルの許可設定を確認した
- [ ] `docs/20-implementation-notes.md` のチェックリストに抵触していない
