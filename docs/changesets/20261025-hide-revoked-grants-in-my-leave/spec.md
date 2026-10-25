# 自分の有給・特別休暇・代休の画面に、取り消した付与が残る不具合の修正

ステータス: 実装中

## 変更要望(原文)

```
自分の有給で、取り消したはずの有給付与が消えていません。確認をお願いします
```

```
他の休暇も確認し、必要ならば横展開をお願いします。履歴表示は不要です。
```

(「履歴表示は不要」の確認への回答:「履歴画面は今のままでよい」=取り消した付与を一覧に「取消済み」として残す必要はなく、履歴の画面は変えない。)

## 背景・目的

社員本人の「自分の有給」画面に、管理者が取り消した有給付与が通常の付与と同じように表示され、合計の残日数にも含まれている。
取り消した付与は使えないので、本人の画面には出さない。

## 現状(As-Is)

- `GET /paid-leave/grants/mine`(`backend/app/Http/Controllers/Api/PaidLeaveController.php::myGrants`)は、本人の`paid_leave_grants`を`status`で絞らずに全件返す。
- `GET /special-leave/grants/mine`(`SpecialLeaveController::myGrants`)も同じ。
- 画面:`frontend/src/pages/paidLeave/MyPaidLeavePage.tsx`は返ってきた付与を全件表に出し、`remaining_days`を合計する。取消の区別の表示は無い。
  `frontend/src/pages/specialLeave/MySpecialLeavePage.tsx`も同じ(種別ごとの残日数の合計と表)。
- 変更セット`20261024-fix-revoked-grant-remaining`で、取り消した付与の`remaining_days`を0にするProjectorの修正をした。既存のデータに反映されるのは
  ReadModelの作り直しの後で、作り直すまでは取り消した付与の残日数が元の値のまま合計に入る。作り直した後も、取り消した付与は残日数0の行として表に残る。
- 管理者向けの`GET /paid-leave/grants/user/{userId}`・特別休暇の管理画面は、取り消した付与を「取消済み」のバッジ付きで出す(これは正しい)。
- 取消の記録は有給の履歴(`/paid-leave/history/mine`)に出る。
- 代休:`GET /compensatory-leave/grants/mine`(`CompensatoryLeaveController::myGrants`)も`status`で絞らない。代休の付与の状態は
  `draft`(月次提出前の仮)・`confirmed`(確定)・`cancelled`(未使用のまま取消申請が承認された)(`App\Models\CompensatoryLeaveGrantStatus`)。
  取消時の`remaining_days`は0にしている(`CompensatoryLeaveAccountProjector::onCompensatoryLeaveAccountGrantCancelled`)が、
  `frontend/src/pages/compensatoryLeave/MyCompensatoryLeavePage.tsx`の一覧には残日数0の行として残る。

## 設計前提の検証

- 正データと派生データ:付与・取消の正は`stored_events`。`paid_leave_grants.status`はProjectorが作る派生データで、`revoked`は取り消し済みを正しく表している。
  データは正しく、本人向けの読み出しが`status`を見ていないことが原因。
- 原因の分類:実装のミス(本人向けのAPIが取り消した付与を除いていない)。

## 仕様検討

### 論点0: 対象の休暇(横展開)
- 決定: 本人向けの付与一覧APIがある3種(有給・特別休暇・代休)すべて。代休は取り消した付与(`cancelled`)だけを除き、`draft`(仮)は今のまま出す
  (仮の付与は取り消しではなく、月次提出で確定する予定の付与のため。今回の対象外)。
- 理由: ユーザーの依頼「他の休暇も確認し、必要ならば横展開」。他に本人向けの付与一覧は無い(`routes/api.php`の`grants/mine`は3本)。
- 未確定・要確認事項: なし

### 論点1: どこで除くか
- 選択肢:
  - A. 本人向けのAPI(`/paid-leave/grants/mine`・`/special-leave/grants/mine`)で`status = 'active'`に絞る。
  - B. 画面で`status`を見て除く。
  - C. 画面で「取消済み」のバッジ付きで残す(合計からは除く)。
- 決定: A(有給・特別休暇は`status = 'active'`、代休は`status != 'cancelled'`)
- 理由: 本人向けのAPIの用途は「自分が使える付与の一覧」で、使っているのは本人の画面だけ。取り消した付与を返す必要がない。
  APIで絞れば、作り直す前のデータ(取消済みでも残日数が元の値)でも合計に入らない。管理者向けのAPIは変えない(取消済みの確認に使う)。
  取消の事実は履歴の画面で確認できる。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

- `PaidLeaveController::myGrants`・`SpecialLeaveController::myGrants`:`where('status', 'active')`を追加する(特別休暇は`SpecialLeaveGrantStatus::ACTIVE`等の既存の定数があれば使う)。
  並び順・応答の形は変えない。Swagger(OA)の説明に「取り消した付与は含めない」を追記する。
- `CompensatoryLeaveController::myGrants`:`where('status', '!=', CompensatoryLeaveGrantStatus::CANCELLED)`を追加し、OAの説明に同じ旨を追記する。
- 画面は変えない。履歴の画面(`/*/history/mine`)も変えない(ユーザーの回答)。
- テスト:
  - `tests/Feature/PaidLeaveAccount/PaidLeaveTest.php`(または取消のテスト):付与を2件作り1件を取り消すと、`/paid-leave/grants/mine`は取り消していない1件だけを返す。
    管理者向けの`/paid-leave/grants/user/{userId}`は2件(取消済みを含む)を返す。
  - `tests/Feature/SpecialLeave/SpecialLeaveTest.php`(または取消のテスト):同じことを`/special-leave/grants/mine`で確かめる。
  - 代休(`tests/Feature/CompensatoryLeave/`の既存の取消のテストの作り方に合わせる):取消(`cancelled`)の付与は`/compensatory-leave/grants/mine`に出ず、
    確定(`confirmed`)の付与は出る。管理者向けの`/compensatory-leave/grants/user/{userId}`は取消の付与を含めて返す。

## 受け入れ条件

- 自分の有給・特別休暇・代休の画面に、取り消した付与が表示されず、合計の残日数にも含まれない(作り直す前のデータでも)。
- 管理者の画面では、これまでどおり「取消済み」として確認できる。
- 追加したテストと、backend(CIと同じ条件の全件)がPASSする。

## 対象外

- 画面の変更。
- 履歴の画面(取消の記録はそのまま出る。ユーザーの回答「履歴画面は今のままでよい」)。
- 代休の仮の付与(`draft`)の表示。
- 失効した付与の扱い(今回は変えない)。

## ドキュメントへの影響

- `docs/09-usecases-paid-leave.md` UC-P001(自分の有給付与状況を確認する):取り消した付与は表示しない旨を追記。
- 特別休暇・代休のユースケースの「自分の付与の確認」の節(実装時に`grep`で特定)に同じ旨を追記。

## モック・アセット

なし

## 実装対象

- `backend/app/Http/Controllers/Api/PaidLeaveController.php`、`SpecialLeaveController.php`、`CompensatoryLeaveController.php`、テスト、上記docs。

## 検証方法

- `cd backend && cp .env.example .env && php artisan key:generate && rm -f database/database.sqlite && touch database/database.sqlite && php artisan test`

## レビュー履歴

- 初版。ユーザーの依頼(確認と修正)をもって実装中にした。
- 2026-10-25: ユーザーの依頼「他の休暇も確認し、必要ならば横展開」を受け、代休を追加(論点0)。履歴の画面は今のままとする回答を反映。

## 実装結果

未着手
