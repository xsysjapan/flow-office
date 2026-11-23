# boolean-query-param(フラグ(真偽値)の扱いの標準化)

ステータス: 検討中

## 変更要望(原文)
> 以前の変更セット...といったのは「前回お伝えした既存の不具合を別の変更セットで直すかどうか、ご判断をお待ちしています。社員一覧で「Microsoft未連携」にチェックを入れるとエラーになる件です。」のことです。
> (前の依頼)見つかった不具合は以前の変更セットが分かればその変更セットの不具合として修正してください
> 私はtrue/falseの方が好きですが、DB設計の兼ね合いですか？フラグ変数の扱いも標準化して、見直しをお願いします。
> Laravelで標準的な実装が0/1ならばそうしても良いです。ただし統一してください

## 背景・目的
社員一覧(`/admin/users`)で「Microsoft未連携」「外部HR管理」にチェックを入れると、一覧の代わりに
「The external unlinked field must be true or false.」(422)が出る。原因は、HTTPクエリでの真偽値の表し方が
frontend(`"true"`)と backend(`boolean` ルールは `"1"/"0"` のみ)で食い違っていること。同じ食い違いは他の真偽値クエリにもあり、
Controller ごとに読み方(`boolean` ルール/`$request->boolean()`/独自の正規化)がばらばらなため、真偽値の扱い全体を統一する。

原因を入れた変更セットの特定: `'external_unlinked' => ['sometimes', 'boolean']` は 2026-08-21 のコミット(728f7fd 付近)から存在し、
変更セット運用の開始前のため対応する変更セットが無い。そのため本変更セットを新設する。

## 現状(As-Is)
Laravel v13.19.0。`boolean` 検証ルール(`ValidatesAttributes::validateBoolean`)は `true/false/0/1/"0"/"1"` を受け、文字列 `"true"/"false"` は拒否する
(`boolean:strict` は `true/false` のみ。本リポジトリでは未使用)。`$request->boolean()` は `"1"/"true"/"on"/"yes"` を真とする。

### クエリの真偽値(6件)
| Controller:行 | パラメータ | backend の読み方 | frontend の送信 |
|---|---|---|---|
| UserController:102 | external_unlinked | validate `boolean` | `String(true)`→`"true"` → **422** |
| UserController:103 | external_hr | validate `boolean` | `"true"` → **422** |
| DeviceController:55 | with_trashed | `$request->boolean()`(検証なし) | `"true"` |
| ExpenseCategoryController:32 | include_inactive | `$request->boolean()`(検証なし) | `"true"` |
| RequestTypeController:31 | include_inactive | `$request->boolean()`(検証なし) | `"true"` |
| ExportController:645-652,663 | include_submitted | `"true"/"false"` を正規化してから validate `boolean`、判定は `$request->boolean()` | `api/exports.ts:65,101` が自前で `'1'` |

- frontend の `api/client.ts:44-58` `buildUrl` がクエリ値を `String(value)` にするため、真偽値は `"true"` になる。
- MCP サーバー(`mcp/`)はこれらのパラメータを使っていない。

### その他の真偽値
- JSON 本文: 約57項目 / 19 Controller が validate `boolean`。frontend は `JSON.stringify` で JSON の `true/false` を送っており一貫している。FormData で真偽値を送る箇所は無い。
- API 応答: Resource は model の casts により `true/false` で返している(0/1 のまま返す列は無い)。
- DB: `boolean` 型の列 48 種。全て model の casts が `boolean`。命名は `is_`(15)・`*_enabled`(7)+`enabled`(1)・`*_requires_approval`(6)・`requires_`(3)・`allow`系(3)・接頭辞なし(13)が混在。
  `require_location` と `requires_*` の綴りの揺れ、有効化フラグの `enabled` と `is_active` の併存、`paid_leave_usages` の `is_confirmed` と `confirmed` の併存がある。
- 画面の URL: `show_deleted`・`external_unlinked`・`external_hr` はいずれも `"1"`、オフはキーを消す(`external_*` は空文字を設定→`updateParam`でキー削除)。
- 既存ルール: docs・skills に真偽値の命名・表現のルールは無い。

## 設計前提の検証
- 正データと派生データ: 本変更は HTTP での表現と検証のみで、データの書き込み・イベントに影響しない。
- 定義と実態: API の型(真偽値)と backend の検証(`boolean`)は一致しているが、HTTP クエリでの表現が決まっていないため、送り手と受け手で食い違っている。
- 原因の分類: 実装のミス(表現のルールが無いことによる不統一)。1か所直すだけでは、次に真偽値クエリを足したときに同じ不具合が起きる。表現と読み方の両方を統一する。
- 横展開: クエリの真偽値6件すべてと、frontend の送信の共通処理を対象にする。JSON本文・応答は既に統一されているのでルールの明文化だけ行う。
- DB の命名の揺れ: 列名を変えると Projection・Resource・frontend の型、場合によりイベントの payload にも波及する(`data-correction`の作り直しが要る)。本変更では命名ルールを新しい列から適用し、既存の列は変えない(論点4)。

## 仕様検討

### 論点1: HTTP クエリでの真偽値の表現
- 選択肢:
  - A. `"1"`/`"0"`(Laravel の `boolean` ルールと `$request->boolean()` の両方がそのまま受ける)
  - B. `"true"`/`"false"`(読みやすいが、`boolean` ルールが受けないため全 Controller で正規化が要る)
- 決定: A
- 理由: ユーザー回答「Laravelで標準的な実装が0/1ならばそうしても良いです。ただし統一してください」。Laravel の `boolean` ルールで受けられるクエリの文字列表現は `"1"`/`"0"` だけで、
  B にすると全 Controller(または middleware)に独自の正規化が要る。
- 未確定・要確認事項: なし

### 論点2: backend でのクエリ真偽値の読み方
- 選択肢:
  - A. すべて `validate` で `['sometimes', 'boolean']`(必須なら `required`)を書き、検証済みの値を `(bool)` で使う
  - B. すべて `$request->boolean()` で読む(検証なし。`"true"` 等も受ける)
  - C. 現状のまま(混在)
- 決定: A
- 理由: 不正値を 422 で弾くのが他の入力と同じ扱いで、OpenAPI の記載(`type: boolean`)とも一致する。B は `"abc"` も false 扱いで黙って通る。
  `ExportController` の `"true"/"false"` 正規化は、A に統一すると不要になるため削除する(呼び出し元は frontend の `'1'` のみ。MCP は使っていない)。
  これにより `"true"` を送ると 422 になる(既存テスト `AttendanceExportTest.php:238` の `'true'` を受ける検証は、`'true'` が 422 になる検証に書き換える)。
- 未確定・要確認事項: なし

### 論点3: frontend での送り方
- 選択肢:
  - A. `api/client.ts` の `buildUrl` で真偽値を `"1"`/`"0"` に変換する(全 API 共通)
  - B. 各 `api/*.ts` で個別に `x ? 1 : undefined` と書く
- 決定: A
- 理由: 真偽値クエリを足すたびに同じ不具合が起きないよう、変換を1か所にする。`api/exports.ts` のダウンロードURLは `buildUrl` を通らない自前の組み立てで、既に `'1'` を送っているのでそのまま(論点1と一致)。
- 細則: `false` は `"0"` を送る。各 API 関数で「オフのときは送らない(`|| undefined`)」としている既存の書き方はそのまま残してよい(どちらも backend で false になる)。
- 未確定・要確認事項: なし

### 論点4: JSON 本文・応答・画面URL・DB の扱い(ルールの明文化)
- 決定(現状を追認し、ルールとして書く):
  - JSON の本文・応答: JSON の `true`/`false`。文字列や 0/1 にしない。FormData で送る必要が出た場合は `"1"`/`"0"`(クエリと同じ)。
  - 応答: model の casts で `boolean` にし、Resource は真偽値で返す。計算値は `(bool)` で明示する。
  - 画面の URL(useSearchParams): オンは `"1"`、オフ(既定)はキーを書かない。読み取りは `=== "1"`。
  - DB: `boolean` 型・`NOT NULL`・既定値を持たせる(三値が必要なら nullable にし、理由を docs/16 に書く)。model の casts に `boolean` を必ず書く。
  - 新しい列・項目の命名: 状態・属性は `is_*`(`is_active`、`is_default`)、機能の有効化は `*_enabled`、要否は `requires_*`、許可は `allow_*`。有効/無効のフラグは `is_active` を使う(`enabled` は新規に使わない)。
    API・frontend の型も同じ名前にする。frontend の API 関数の引数オブジェクトは camelCase(`includeInactive`)で受け、`api/*.ts` で snake_case のクエリ名に変換する。
- 対象外とするもの: 既存の列の改名(`require_location`→`requires_location`、`enabled`→`is_active` など)。列名の変更は Projection・Resource・frontend の型・イベントの payload に及び、
  イベントの作り直し(`data-correction`)が要るため、命名の揺れだけを理由に行わない。
- 別途調査とするもの: `paid_leave_usages` の `is_confirmed` と `confirmed` の併存。同じ事実を2列で持っている(設計原則の「同じ事実を複数の場所で持たない」に反する)可能性があり、
  意味の違いの調査が要るため、本変更セットでは扱わず、ユーザーに別の変更セットでの調査を提案する。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
1. frontend `api/client.ts` `buildUrl`: 値が真偽値なら `true`→`"1"`、`false`→`"0"`(配列の要素も同じ)。`undefined` は送らない。それ以外は従来どおり `String(value)`。
2. backend のクエリ真偽値6件を validate `['sometimes', 'boolean']` に統一し、検証済みの値で判定する:
   - `UserController` の `external_unlinked`・`external_hr`(既に validate。判定はそのまま)
   - `DeviceController` の `with_trashed`、`ExpenseCategoryController`・`RequestTypeController` の `include_inactive`: validate を追加し、`$request->boolean()` を検証済みの値に置き換える
   - `ExportController` の `include_submitted`: `"true"/"false"` の正規化(645-649行)を削除し、validate `['nullable', 'boolean']` の値で判定する
3. ルールの明文化: `docs/20-implementation-notes.md` に「真偽値(フラグ)の扱い」の節を追加し、論点1〜4の決定(クエリは`1/0`、JSONは`true/false`、画面URLは`1`かキー無し、DBの型と casts、新規の命名)を書く。
   `.claude/skills/add-api-hook/SKILL.md` と `add-projection`(列を足すスキル)から、その節を1行で参照する。
4. テスト:
   - frontend: `buildUrl`(または `apiFetch` 経由)で `true`→`1`、`false`→`0`、配列の真偽値、`undefined` は送らない、を確かめる単体テスト。
   - backend Feature: 6パラメータそれぞれで `=1` が真として効く、`=0` と未指定が偽、`=true` は 422(validate を追加した4件と、正規化を消した include_submitted)。
     `UserController` は `external_unlinked=1`・`external_hr=1` で絞り込まれる(既存テストが無ければ追加)。

## 受け入れ条件
- 社員一覧で「Microsoft未連携」「外部HR管理」にチェックを入れると、エラーにならず絞り込まれた一覧が表示される。
- 端末一覧の「削除済みの端末も表示」、経費区分・申請種別の「無効なものも表示」(include_inactive を使う画面)、勤怠出力の「提出済みも含める」が従来どおり動く。
- クエリの真偽値6件がすべて `1`/`0` で効き、`true` は 422 になる(Feature テストPASS)。
- `docs/20-implementation-notes.md` に真偽値の扱いのルールがある。
- backend の全件テスト、frontend の単体テスト全件・型チェック、関係する e2e(社員一覧・端末・経費区分・申請種別・勤怠出力を通るもの)がPASSする。

## 対象外
- 既存のDB列の改名(論点4)
- `paid_leave_usages` の `is_confirmed`/`confirmed` の整理(別途調査を提案)
- JSON 本文・応答の変更(既に統一されている)
- frontend の既存 API 関数の引数名の camelCase/snake_case の揃え直し(新規から適用)

## ドキュメントへの影響
- `docs/20-implementation-notes.md`: 「真偽値(フラグ)の扱い」の節を追加
- `.claude/skills/add-api-hook/SKILL.md`・`.claude/skills/add-projection/SKILL.md`: 上記の節への参照を1行
- `docs/16-database-schema.md`: 変更なし(列は変えない)
- `docs/14-usecases-export.md`: `include_submitted` の受け付ける値を書いていれば `1/0` に合わせる(書いていなければ変更なし)

## モック・アセット
なし

## 実装対象
- frontend: `src/api/client.ts`、そのテスト(無ければ `src/api/client.test.ts` を新規)
- backend: `app/Http/Controllers/Api/{UserController,DeviceController,ExpenseCategoryController,RequestTypeController,ExportController}.php`、対応する Feature テスト(`tests/Feature/` の既存ファイルに追加。`AttendanceExportTest.php:238` の書き換え)
- docs/skills: 上記「ドキュメントへの影響」

## 検証方法
- `cd backend && ./vendor/bin/phpunit --filter 'User|Device|ExpenseCategory|RequestType|Export'`、CI と同じ条件で全件(`cp .env.example .env && php artisan key:generate && php artisan test`、後で `.env`・sqlite を削除)
- `cd frontend && npx vitest run --project unit`、`npx tsc -p tsconfig.app.json --noEmit`
- e2e: 社員一覧・端末・経費区分・申請種別・勤怠出力を通る spec(実装時に grep で特定)
- 実画面で社員一覧のチェックボックスを確認

## レビュー履歴
- 初版(2026-11-23): 社員一覧の422の不具合修正として作成(クエリを`1/0`で送る)
- 2026-11-23: ユーザーの依頼「フラグ変数の扱いも標準化して、見直し」「Laravelで標準的な実装が0/1ならば…ただし統一」を受け、真偽値の扱い全体の標準化に作り直した
  (クエリ6件の読み方の統一、ExportControllerの個別正規化の削除、JSON・画面URL・DB・命名のルール化)。

## 実装結果
未着手
