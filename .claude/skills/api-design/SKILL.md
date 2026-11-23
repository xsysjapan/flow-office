---
name: api-design
description: >-
  flow-officeのbackend APIの仕様(エンドポイント・クエリ・JSON本文・レスポンス・バリデーション・
  OpenAPI・命名)を決めるときに使う。新しいエンドポイントを作る、または既存APIの入出力を変える
  ときに、リクエスト・レスポンス・OpenAPIの書き方(真偽値の表現を含む)をここで揃える。
---

# APIの仕様の決め方

根拠の変更セット: `20261123-boolean-query-param`(真偽値の表現)、`20261122-list-search-form-standard`(一覧の`meta`)。
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

変更セット `20261123-boolean-column-naming` で追記する。
