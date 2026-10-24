# 有給付与の取消の不具合の修正(取り消した付与に残日数が残る・消化済みの最新付与を取り消せない)

ステータス: レビュー中

## 変更要望(原文)

```
* 2026-06-01 〜 2027-05-31 / 残16日取消
* 2026-06-02 〜 2028-05-31 / 残20日

付与済みの有給付与を取り消した
```

(追加の連絡)

```
もともと上記の状態から2026-06-02 〜 2028-05-31を削除して取り消しをして、
さらに2026-06-01 〜 2027-05-31を取り消したいのですが取り消しができません。
依頼していた仕様的には取り消しができるはずだと思います。
確認をお願いします。
```

```
そういう意味では画面上では20日側が取り消しされています。
```

## 背景・目的

有給付与を取り消すと、管理画面(有給付与ポリシー画面の「対象社員の有給付与状況」)に
「残16日 取消済み」と表示される。取り消した付与は使えないので残日数は0日であるべき。
表示だけでなく、残日数の列を読む処理(自分の有給画面の合計残日数、消滅警告バッチ)も
取り消した付与の日数を数えてしまう。

あわせて、最新の付与(2026-06-02分)を取り消したあと、次に最新となった2026-06-01分(20日付与・4日消化・残16日)を
取り消そうとしても、画面の「取消」ボタンが押せない。UC-P008では「最新の非取消Grantであれば消化済みでも取消可能・
消化分は自動で他の付与へ付け替える」仕様であり、画面の判定が古い仕様のまま残っている。

## 現状(As-Is)

- `backend/app/Domain/PaidLeaveAccount/Projectors/PaidLeaveUsageAllocationProjector.php`
  - `onPaidLeaveGrantRevoked()`は`status`/`revoked_at`/`revoked_by_user_id`/`revoke_reason`だけを更新し、
    `remaining_days`を変えない。
  - `recalculateGrantCache()`は`remaining_days = granted_days - 充当の合計`で、取消を考慮しない。
    集約の`revokeGrant()`は取消の前に充当を解除する(`PaidLeaveUsageAllocationReleased`)ため、
    解除時の再計算で`remaining_days`は付与日数そのもの(例: 16日)に戻り、そのまま残る。
- `backend/app/Domain/SpecialLeaveAccount/Projectors/SpecialLeaveAccountProjector.php`
  - `onSpecialLeaveAccountGrantRevoked()`・`recalculateGrant()`も同じ作りで、取り消した特別休暇の付与にも
    `remaining_days = granted_days`が残る(特別休暇は消化済みの付与は取り消せないので、取消時の残日数は常に付与日数)。
- 代休(`CompensatoryLeaveAccountProjector::onCompensatoryLeaveAccountGrantCancelled`)は取消時に
  `remaining_days = 0`にしており、正しい。
- `remaining_days`を読む箇所:
  - `frontend/src/pages/paidLeave/PaidLeavePolicyPage.tsx`(付与一覧の「残N日」。今回の報告)
  - `frontend/src/pages/paidLeave/MyPaidLeavePage.tsx`(付与一覧と合計残日数。取消分が合計に入る)
  - `frontend/src/pages/specialLeave/SpecialLeaveAdminPage.tsx`・`MySpecialLeavePage.tsx`(同上)
  - `backend/app/Domain/PaidLeave/Handlers/WarnExpiringPaidLeaveHandler.php`
    (`remaining_days > 0`で消滅警告の対象を選ぶ。`status`で絞っていないため取消分も警告対象になる)
- 残高の`paid_leave_balances`(`PaidLeaveBalanceProjector`)と勤怠Excelの残日数は取消分を除いており、正しい。
- `docs/16-database-schema.md`:`remaining_days`は「`granted_days - allocated_days`の非正規化キャッシュ」とだけ書かれ、
  取消時の値が定義されていない。

- 画面の取消ボタン:`frontend/src/pages/paidLeave/PaidLeavePolicyPage.tsx`の`RevokeGrantButton`は
  `disabled={grant.used_days > 0}`・`disabledReason="既に消化された分は取り消せません。"`。これは旧ドメインのガード
  (コミット`231a3c7`で導入)。新ドメインの集約`PaidLeaveAccountAggregate::revokeGrant()`は
  `assertGrantIsLatestAndActive()`(最新の非取消Grantのみ)だけを条件とし、充当を解除して他の付与へ再配分する
  (`docs/09-usecases-paid-leave.md` UC-P008、`docs/changesets/20260906-paid-leave-domain-redesign`)。
  APIは消化済みでも取り消せるため、画面だけが仕様とずれている。
  一方で画面は最新でない付与にも押せる取消ボタンを出しており、押すとAPIが「最新のGrantのみ変更・取消できます。」で拒否する。
- 特別休暇・代休の画面の同じ判定(`used_days > 0`で無効)は、それぞれの集約が「消化済みは取消不可」なので正しい。

## 設計前提の検証

- 正データと派生データ: 付与・取消・充当の正は`stored_events`(`paid_leave_account.*`/`special_leave_account.*`)。
  `paid_leave_grants.remaining_days`・`special_leave_grants.remaining_days`はProjectorが作る派生データ(表示用キャッシュ)。
  書き込み経路はProjectorのみで、設計原則1・2に沿っている。
- 定義と実態: docs上は「付与日数 − 充当の合計」だが、読み手(画面・警告バッチ)は「使える残日数」として読んでいる。
  代休は取消時0で「使える残日数」の意味で作られている。読み手の意味(使える残日数)に定義を揃えるのが正しい。
- 原因の分類: どちらも実装のミス。残日数はProjectorが取消をキャッシュ計算に反映していない。取消ボタンは
  新ドメインへの移行時に画面の判定を更新し忘れた(業務ルールの正は集約で、画面は表示の補助にすぎない)。正データの二重化などの設計の誤りではない。
  イベント履歴は正しいので、データ補正はProjectorの修正後にリビルド(replay)するだけで足りる。

## 仕様検討

### 論点1: どこで直すか
- 選択肢:
  - A. Projectorで、取り消した付与の`remaining_days`を0にする(取消イベント時と、充当の再計算時の両方)。
    派生データの定義が「使える残日数」に揃い、画面・警告バッチ・今後の読み手がすべて直る。
  - B. 読み手(画面・警告バッチ)で`status`を見て取消分を除く。読み手が増えるたびに同じ考慮が必要で漏れやすい。
- 決定: A
- 理由: 代休と同じ定義に揃い、1か所の修正で全ての読み手が直る。イベントから再生成しても同じ結果になる(冪等)。
- 未確定・要確認事項: なし

### 論点2: 特別休暇も直すか
- 選択肢:
  - A. 有給と同じく直す。
  - B. 報告された有給だけ直す。
- 決定: A
- 理由: 同じ原因・同じ症状(特別休暇の管理画面・自分の画面の合計)で、修正も同じ形で数行。
- 未確定・要確認事項: なし

### 論点3: 既存データの直し方
- 選択肢:
  - A. Projectorを直したうえで、該当Projectorをリビルド(`php artisan event-sourcing:replay`)する。
  - B. 補正用のイベントを追記する。
- 決定: A
- 理由: イベント履歴は正しく、派生データの計算だけが誤っている(`data-correction`スキル: 履歴は正しい → リビルドで反映)。
  リビルド対象は`PaidLeaveUsageAllocationProjector`と`SpecialLeaveAccountProjector`。
- 未確定・要確認事項: なし

### 論点4: 画面の表示
- 選択肢:
  - A. 画面は変えない(バックエンドの修正で「残0日 取消済み」と表示される)。
  - B. 取消済みの付与は「残N日」を出さない。
- 決定: A
- 理由: 値が正しくなれば表示も正しい。表示の変更は頼まれていない。
- 未確定・要確認事項: なし

### 論点5: 有給の取消ボタンを押せる条件
- 選択肢:
  - A. 消化の有無では無効にせず、「最新の非取消付与」以外を無効にする(理由「最新の付与のみ取り消せます。」)。
    集約の不変条件と同じ条件を画面にも出す。
  - B. 常に押せるようにし、最新でなければAPIのエラーを表示する。
- 決定: A
- 理由: 押せるのに必ず失敗するボタンを出さない。判定の正はあくまで集約で、画面は同じ条件を表示に使うだけ。
  「最新」の判定は集約と同じく、取り消されていない付与のうち付与日(`granted_on`)が最も遅いもの。
  同じ付与日の付与が複数ある場合は画面では区別できない(記録の順を返していない)ため、その付与日の付与はどれも押せるようにし、
  最新でなければAPIのエラーを表示する(同日付与はまれで、APIが正しく拒否する)。
- 未確定・要確認事項: なし

### 論点6: 消化済みの付与を取り消すときの確認ダイアログ
- 選択肢:
  - A. 消化済み(`used_days > 0`)の場合、確認文に「消化済みのN日分は他の付与へ付け替えます。付け替え先が足りない分は残高不足(未充当)になります。」を加える。
  - B. 文言は変えない。
- 決定: A
- 理由: 取り消すと消化分の扱いが変わることを、操作前に管理者が分かるようにする(UC-P008の動作どおりの説明)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

- `PaidLeaveUsageAllocationProjector`
  - `onPaidLeaveGrantRevoked()`で`remaining_days = 0`も更新する(`allocated_days`/`used_days`は変えない。
    取消時は充当解除済みなので0になっている)。
  - `recalculateGrantCache()`で、付与の`status`が`revoked`なら`remaining_days = 0`とする(それ以外は従来どおり
    `granted_days - 充当の合計`)。`onPaidLeaveGrantAmountChanged()`の`remaining_days`計算も同じ規則にする
    (取消済みの付与は集約が額変更を拒否するが、Projectorとしても0を保つ)。
- `SpecialLeaveAccountProjector`
  - `onSpecialLeaveAccountGrantRevoked()`で`remaining_days = 0`も更新する。
  - `recalculateGrant()`で`status`が`revoked`なら`remaining_days = 0`とする。
- `frontend/src/pages/paidLeave/PaidLeavePolicyPage.tsx`(対象社員の有給付与状況):
  - `RevokeGrantButton`の`disabled`を`grant.used_days > 0`から「最新の非取消付与でない」に変える。
    最新の判定:`status === 'active'`の付与のうち`granted_on`の最大値を求め、`grant.granted_on`がそれより前なら無効。
  - `disabledReason`は「最新の付与のみ取り消せます。」。
  - 確認文(`description`)は、`used_days > 0`のとき末尾に
    「消化済みの{used_days}日分は他の付与へ付け替えます。付け替え先が足りない分は残高不足(未充当)になります。」を加える
    (既存の「…を取り消します。この操作は元に戻せません。」の後ろ)。
  - 特別休暇・代休の画面は変えない。
- テスト(既存の取消テストに追記する):
  - `tests/Feature/PaidLeaveAccount/PaidLeaveGrantRevocationTest.php`:付与を取り消すと、
    `GET /paid-leave/grants/user/{userId}`の該当付与が`status=revoked`・`remaining_days=0`になる。
    充当のあった付与(消化が別の付与へ付け替えられるケース)を取り消した場合も`remaining_days=0`。
    消滅警告バッチの対象にならない(既存の警告テストの書き方に合わせる。書きにくければ`remaining_days=0`の確認で足りる)。
  - `tests/Feature/SpecialLeave/SpecialLeaveGrantRevocationTest.php`:取り消すと`remaining_days=0`。
  - リビルド(Projectorのreplay)後も`remaining_days=0`のままであること(既存のreplayテストの書き方があれば合わせる)。
  - バックエンド:付与A(先)・付与B(後)があり、Aに消化が充当されている状態で、Bを取り消したあとAを取り消せる
    (API 200、Aの`status=revoked`・`remaining_days=0`、消化は未充当になり`paid_leave_balances.unallocated_days`に計上)。
    既存テストで同等のケースがあれば追記不要。
  - `frontend/src/pages/paidLeave/PaidLeavePolicyPage.test.tsx`:
    - 消化済み(`used_days > 0`)でも最新の付与の取消ボタンが押せる。
    - 最新でない付与の取消ボタンは無効で、`title`が「最新の付与のみ取り消せます。」。
    - 取り消した付与より前の付与は、取り消した付与を除いて最新なら押せる(報告のケース:06-02取消済み・06-01が押せる)。

## 受け入れ条件

- 有給付与を取り消すと、管理画面・自分の有給画面のAPIで該当付与の残日数が0日になる。
- 取り消した有給付与が、自分の有給画面の合計残日数・消滅警告の対象に含まれない。
- 特別休暇の付与を取り消した場合も残日数が0日になる。
- 報告のケース(06-02取消済み、06-01に消化あり)で、06-01の取消ボタンが押せ、取り消せる。
- 最新でない有給付与の取消ボタンは押せず、理由が表示される。
- Projectorをリビルドしても同じ値になる。
- 追加・既存のテスト(`php artisan test`)が全てPASSする。

## 対象外

- 代休(既に正しい)。
- 「残N日」の表示文言・レイアウトの変更。
- 特別休暇・代休の取消ボタンの条件(それぞれの集約の仕様どおり)。
- `paid_leave_balances`・勤怠Excel(既に取消分を除いている)。
- 本番環境でのリビルドの実施そのもの(デプロイ時に`event-sourcing:replay`の実行が必要な旨を実装結果に書く)。

## ドキュメントへの影響

- `docs/16-database-schema.md`:`paid_leave_grants.remaining_days`の説明に「取り消した付与(`status=revoked`)は0」を追記する。
  `special_leave_grants`の説明(「同じ`status`/`revoked_at`…を持つ」)にも同じ旨を追記する。

## モック・アセット

なし

## 実装対象

- `backend/app/Domain/PaidLeaveAccount/Projectors/PaidLeaveUsageAllocationProjector.php`
- `backend/app/Domain/SpecialLeaveAccount/Projectors/SpecialLeaveAccountProjector.php`
- `backend/tests/Feature/PaidLeaveAccount/PaidLeaveGrantRevocationTest.php`
- `backend/tests/Feature/SpecialLeave/SpecialLeaveGrantRevocationTest.php`
- `frontend/src/pages/paidLeave/PaidLeavePolicyPage.tsx`
- `frontend/src/pages/paidLeave/PaidLeavePolicyPage.test.tsx`
- `docs/16-database-schema.md`

## 検証方法

- `cd backend && php artisan test --filter='GrantRevocation'`
- `cd backend && php artisan test`
- `cd frontend && npx vitest run src/pages/paidLeave/PaidLeavePolicyPage.test.tsx`
- `cd frontend && npm run lint && npx tsc -b`(または`npm run build`)

## レビュー履歴

- 初版(残日数の不具合)
- 2026-10-24: ユーザーの追加連絡(06-02取消後に06-01が取り消せない)を受け、取消ボタンの条件(論点5・6)を追加

## 実装結果

未着手
