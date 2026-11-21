# 有給・特別休暇履歴でイベントコードがそのまま表示される不具合の修正

- ステータス: 実装中
- 種別: 不具合修正(frontend)

## 変更要望(原文)

> 有給休暇の履歴についてコードで出ている場所があります。対応をお願いします

(履歴の例) `取消 / paid_leave_account.grant_revoked`、`充当 / paid_leave_account.usage_allocated`、
`充当取消 / paid_leave_account.usage_allocation_released`、`連携 / paid_leave_request.shared`、
`消化指定 / paid_leave_account.usage_designated`

## 背景・目的

履歴の詳細欄に内部のイベント種別(`ドメイン.種別`)が表示され、利用者に意味が伝わらない。
全ての履歴イベントを人が読める文に整形する。

## 現状(As-Is)

- `frontend/src/utils/statusLabels.ts` の `leaveEventDetail()`(有給・特別休暇の共通)が、
  末尾の種別ごとに`switch`で詳細文を作り、該当しない種別は `default: return event.event_type` で
  イベントコードをそのまま返す。
- 次の種別に`case`が無い(バックエンドの`config/event-sourcing.php`で履歴に出るイベント):
  - `grant_revoked`(有給・特別休暇の付与取消。payload: `reason`)
  - `grant_date_changed`(有給の付与日の変更。payload: `new_granted_on`, `reason`)
  - `grant_expiry_changed`(有給の有効期限の変更。payload: `new_expires_on`, `reason`)
  - `usage_designated`(消化指定。payload: `used_on`, `used_days`, 有給の時間単位は`hours`、特別休暇の時間単位は`used_minutes`)
  - `usage_allocated`(充当。payload: `allocated_days`)
  - `usage_allocation_released`(充当の解除。payload: `released_days`)
  - `shared` / `request_shared`(申請のワークフロー連携。payload: `workflow_request_id`)
- また `leaveEventSuffixMeta` に `grant_date_changed` / `grant_expiry_changed` のラベルが無く、
  ラベル欄にもイベントコードが出る。

## 設計前提の検証

- 原因の分類: 実装のミス(イベント追加時にフロントの表示整形の追加が漏れた)。データ・設計の誤りではない。
- 表示のみの変更で、イベント・ReadModelには影響しない。

## 仕様検討 / 仕様確定事項

| 種別 | ラベル | 詳細文 |
|---|---|---|
| `grant_revoked` | 取消(既存) | `付与を取り消しました` + reasonがあれば`(理由)` |
| `grant_date_changed` | **付与日の変更**(新規, info) | `付与日を{new_granted_on}に変更` + `(理由)` |
| `grant_expiry_changed` | **有効期限の変更**(新規, info) | `有効期限を{new_expires_on}に変更` + `(理由)` |
| `usage_designated` | 消化指定(既存) | `対象日 {used_on} に{量}の消化を指定`。量は`hours`があれば`{hours}時間`、`used_minutes`があれば`{h}時間{m}分`(0の部分は省略)、それ以外は`{used_days}日` |
| `usage_allocated` | 充当(既存) | `付与へ{allocated_days}日を充当` |
| `usage_allocation_released` | 充当取消(既存) | `付与への充当{released_days}日を解除` |
| `shared` / `request_shared` | 連携(既存) | `申請がワークフローに連携されました`(代休履歴と同じ文) |
| 上記以外の未知の種別 | (現状どおり) | **空文字**(イベントコードを利用者に見せない) |

理由の書式は既存の`grant_amount_changed`と同じ`(理由)`。

## 受け入れ条件

- 有給・特別休暇の履歴で、上表の種別の詳細欄にイベントコードが出ない。
- 未知の種別でも詳細欄にイベントコードが出ない。

## 対象外

- 代休履歴(`compensatoryLeaveEventDetail`)は専用関数で全種別対応済みのため変更しない。
- 充当先の付与(付与日など)の表示。payloadには付与IDしかなく、他イベントからの引き当ては行わない。

## ドキュメントへの影響

なし(表示文言のみ)。

## 実装対象

- `frontend/src/utils/statusLabels.ts`
- `frontend/src/utils/statusLabels.test.ts`

## 検証方法

`cd frontend && npx vitest run src/utils/statusLabels.test.ts src/components/LeaveHistoryList` と
`npx tsc -p tsconfig.app.json --noEmit`。

## レビュー履歴

- 2026-11-21: 表示文言のみの不具合修正のため、ユーザー依頼に基づき即実装。

## 実装結果

(実装後に追記)
