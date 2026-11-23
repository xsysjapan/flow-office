---
name: api-design
description: >-
  flow-officeのbackend APIの仕様(エンドポイント・クエリ・JSON本文・レスポンス・バリデーション・
  OpenAPI・命名)を決めるときに使う。新しいエンドポイントを作る、または既存APIの入出力を変える
  ときに、リクエスト・レスポンス・OpenAPIの書き方(真偽値の表現を含む)をここで揃える。
---

# APIの仕様の決め方

根拠の変更セット: `20261123-boolean-query-param`(真偽値の表現)、`20261123-boolean-column-naming`(真偽値の命名)、`20261122-list-search-form-standard`(一覧の`meta`)。
今回は下記の節だけを書き、他の節は将来足す。

## リクエスト

- [ ] クエリの真偽値は `1`/`0`。frontendは `api/client.ts` の `buildUrl` が真偽値を自動で `1`/`0` にするので、
      `api/*.ts` では `boolean` をそのまま渡す
- [ ] backendは `validate` で `['sometimes', 'boolean']`(必須なら `required`)を書き、検証済みの値で判定する。
      `$request->boolean()` だけで読まない(`"abc"` が黙って偽になる)。`"true"`/`"false"` は422にし、独自の正規化をしない
- [ ] JSON本文の真偽値は `true`/`false`(文字列や0/1にしない)。FormDataで送る場合は `"1"`/`"0"`

## レスポンス

- [ ] 真偽値は modelの casts を `boolean` にして `true`/`false` で返す。計算値は `(bool)` で明示する
- [ ] 一覧の付加情報は `meta` に載せる(例: `meta.status_counts`、`meta.assignee_counts`。変更セット `20261122-list-search-form-standard`)

## OpenAPI

- [ ] クエリの真偽値は `type: integer, enum: [0, 1]`、説明に「1=はい、0=いいえ」(Swagger UIの試し実行が422にならないように)

## 命名

真偽値の名前は、読んだときに「何が真なのか」が分かる述語にする。列・イベント項目・API・frontendの型で同じ語を使う。
根拠の変更セット: `20261123-boolean-column-naming`(論点3の命名ルール・論点4の改名表)。

真偽値の意味ごとの形:

| 意味 | 形 | 例 |
|---|---|---|
| 状態・属性(〜である) | `is_*` | `is_active`、`is_default`、`is_offline`、`is_cancelled` |
| 持っている・経験した(〜がある/した) | `has_*` | `has_attended`、`has_clocked_out`、`has_core_time_violation` |
| 機能・設定の有効化 | `*_enabled` | `notification_mail_enabled`、`punch_type_auto_detection_enabled` |
| 要否(〜を必要とする) | `requires_*` または `<対象>_requires_*` | `requires_attachment`、`paid_leave_requires_approval` |
| 許可・禁止(〜を許す/禁じる) | `allows_*` / `prohibits_*`(三人称単数の動詞) | `allows_offline`、`prohibits_self_privileged_role_assignment` |
| その他の性質(〜する) | 三人称単数の動詞で始める | `crosses_midnight`、`includes_descendants` |
| イベントの中だけにある「その時点の事実」 | `was*`(過去形)も可 | `wasReissued`、`wasFirstLogin`(既存。変えない) |

- [ ] レコード自体の有効/無効は `is_active` にする。`enabled` は使わない(`*_enabled` は機能・設定のオン/オフに使う)
- [ ] 否定形の名前(`is_not_*`・`disabled`)は使わない
- [ ] 列名は snake_case。イベントのプロパティ・集約の状態は同じ語のcamelCase(`is_offline` → `isOffline`)。
      イベントの配列(`attributes`・`calculation`・`before`・`after`)の中のキーは列名と同じ snake_case
- [ ] APIキーは列名と同じ。frontendの型はAPIキーと同じ
- [ ] 上の形に外れる名前(接頭辞なしの名詞・過去分詞・命令形 `allow`・`require_*`・`prohibit_*`)を新しく作らない
