# boolean-query-param

ステータス: 検討中

## 変更要望(原文)
> 以前の変更セット...といったのは「前回お伝えした既存の不具合を別の変更セットで直すかどうか、ご判断をお待ちしています。社員一覧で「Microsoft未連携」にチェックを入れるとエラーになる件です。」のことです。
> (前の依頼)見つかった不具合は以前の変更セットが分かればその変更セットの不具合として修正してください

## 背景・目的
社員一覧(`/admin/users`)で「Microsoft未連携」「外部HR管理」にチェックを入れると、一覧の代わりに
「The external unlinked field must be true or false.」(422)が出て絞り込めない。変更セット`20261122-list-search-form-standard`の
動作確認中に見つかった既存不具合。

原因を入れた変更セットの特定: `'external_unlinked' => ['sometimes', 'boolean']` は 2026-08-21 のコミット(728f7fd 付近、変更セット運用の開始前)から
存在し、対応する変更セットは無い(`docs/changesets/`に該当なし)。そのため本変更セットを新設して直す。

## 現状(As-Is)
- `frontend/src/api/client.ts:44-58` `buildUrl` はクエリ値を `String(value)` で文字列にするため、真偽値 `true` は `"true"` で送られる。
- `frontend/src/api/users.ts:8-9`・`pages/admin/UserListPage.tsx` は `external_unlinked: true`・`external_hr: true` を渡す。
- `backend/app/Http/Controllers/Api/UserController.php:102-103` は `'boolean'` ルールで検証する。Laravel の `boolean` ルールは `true/false/1/0/"1"/"0"` を受け、文字列 `"true"`/`"false"` は受けない → 422。
- 同じ問題を、勤怠出力は backend 側で個別に回避している(`ExportController.php:645-648` で `"true"/"false"` を真偽値に正規化)。フロントの勤怠出力(`api/exports.ts:65,101`)は自前で `'1'` を送っている。
- 他の真偽値クエリ: 端末一覧の `with_trashed`(`api/devices.ts:11`)は backend が `$request->boolean()` で読むため `"true"` でも通る。

## 設計前提の検証
- 正データと派生データ: 読み取りの絞り込みのみ。データの書き込みなし。
- 定義と実態: フロントの型は真偽値、backend の検証は `boolean` ルールで一致しているが、HTTPクエリでの表現(`"true"` vs `"1"`)が食い違う。
- 原因の分類: 実装のミス(クエリへの真偽値の符号化が backend の検証と合っていない)。1か所だけの問題ではなく、`buildUrl` が真偽値を `"true"` にする限り、`boolean` ルールで検証する真偽値クエリを新設するたびに同じ不具合が起きる。
- 横展開: 真偽値をクエリで送る API は `users`(external_unlinked・external_hr)、`devices`(with_trashed)。勤怠出力は自前で `'1'`。

## 仕様検討

### 論点1: どこで直すか
- 選択肢:
  - A. `buildUrl` で真偽値を `"1"`/`"0"` に変換する(全APIで共通)
  - B. `api/users.ts` で `external_unlinked: x ? 1 : undefined` のように個別に送る
  - C. `UserController` で `ExportController` と同じく `"true"/"false"` を正規化する
- 決定: A
- 理由: 真偽値クエリを足すたびに同じ不具合が起きないよう、符号化を1か所で backend の `boolean` ルール・`$request->boolean()` の両方が受ける形にそろえる。
  `"1"`/`"0"` は両方で真偽値として読める(`with_trashed` も影響なし)。B は次の真偽値クエリで再発し、C は Controller ごとに正規化を書くことになる。
- 未確定・要確認事項: なし

### 論点2: 既存の個別回避をどうするか
- 選択肢:
  - A. `ExportController` の正規化と `api/exports.ts` の自前の `'1'` は残す
  - B. 共通化に合わせて削除する
- 決定: A
- 理由: 勤怠出力のURLはダウンロード用に自前で組み立てており `buildUrl` を通らない。backend の正規化は外部から `"true"` で呼ばれても動くための後方互換として害がない。今回は不具合の修正に範囲を限る。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `frontend/src/api/client.ts` の `buildUrl`: 値が真偽値なら `true`→`"1"`、`false`→`"0"` を設定する(配列の要素が真偽値の場合も同じ)。`undefined` は従来どおり送らない。それ以外の型は従来どおり `String(value)`。
- backend は変更しない。

## 受け入れ条件
- 社員一覧で「Microsoft未連携」「外部HR管理」にチェックを入れると、エラーにならず絞り込まれた一覧が表示される。
- 端末一覧の「削除済みの端末も表示」が従来どおり動く。
- `client.ts` の単体テストで、真偽値が `1`/`0` で送られることを確認するテストがPASSする。frontend の単体テスト全件・型チェックがPASSする。

## 対象外
- `ExportController` の正規化・`api/exports.ts` の自前組み立ての整理
- backend の検証ルールの変更

## ドキュメントへの影響
変更なし(API仕様は真偽値のまま。HTTPでの符号化の不具合修正のため)。

## モック・アセット
なし

## 実装対象
- `frontend/src/api/client.ts`、`frontend/src/api/client.test.ts`(無ければ新規)
- 必要なら `frontend/src/pages/admin/UserListPage.test.tsx`(fetch に渡るURLを確かめているテストがあれば追随)

## 検証方法
- `cd frontend && npx vitest run --project unit`、`npx tsc -p tsconfig.app.json --noEmit`
- backend: `UserController` の index に `external_unlinked=1` で絞り込める Feature テストが無ければ追加し、`./vendor/bin/phpunit --filter User` を実行
- 実画面で社員一覧のチェックボックスを確認

## レビュー履歴
初版

## 実装結果
未着手
