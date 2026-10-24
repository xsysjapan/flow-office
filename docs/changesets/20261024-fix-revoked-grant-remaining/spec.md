# 有給付与の取消の不具合の修正(取り消した付与に残日数が残る・消化済みの最新付与を取り消せない)

ステータス: 完了

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
- 決定: A。手順は既存の全体リビルドの手順(`docs/32-stored-event-history-normalization.md`・
  `docs/changesets/20261024-paid-leave-release-rehearsal/assets/rehearsal-checklist.md`)と同じにする:
  `php artisan leave:rebuild-event-store --list-readmodels`で出る全テーブルを空にしてから
  `php artisan event-sourcing:replay --force`。
- 理由: イベント履歴は正しく、派生データの計算だけが誤っている(`data-correction`スキル: 履歴は正しい → リビルドで反映)。
  対象Projectorだけを表を空にせずにreplayするのは不可。`PaidLeaveUsageAllocationProjector::onPaidLeaveUsageAllocated`は
  既存行に`allocated_days`を足し込み、`resetState`も持たないため、充当が2重になる。対象テーブルだけを空にするのも避ける。
  `paid_leave_schedule_entries.granted_paid_leave_grant_id`が`paid_leave_grants`を外部キーで参照しているため。
  PR#121のリリースでも同じ全体リビルドを行う予定で、この修正がそれより前に入れば、そこで既存データも直る。
- 未確定・要確認事項: なし

### 論点4: 画面の表示
- 選択肢:
  - A. 画面は変えない(バックエンドの修正で「残0日 取消済み」と表示される)。
  - B. 取消済みの付与は「残N日」を出さない。
- 決定: A
- 理由: 値が正しくなれば表示も正しい。表示の変更は頼まれていない。
- 未確定・要確認事項: なし

### 論点4-2: 取消後に画面で再読込するデータ
- 選択肢:
  - A. 有給付与の取消の成功時に、付与一覧に加えて、その社員の消化明細(`PaidLeaveUsageCard`が読むもの)と残高も再読込させる。
  - B. 付与一覧だけを再読込する(現状)。
- 決定: A
- 理由: 消化済みの付与を取り消せるようになると、消化明細の充当先(`paid_leave_grant_id`)と残高が変わる。同じ画面の
  消化明細が古いまま残るのを防ぐ。
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

### 論点7: e2eテスト(ユーザーの依頼「e2eテストも実施をお願いします。」)
- 選択肢:
  - A. 報告のケースを画面操作で再現するe2eを新しいファイルに足し、既存の有給系e2eと一緒に流す。
  - B. 既存のe2eを流すだけにする。
- 決定: A
- 理由: 報告された操作(新しい付与を取り消す → 消化のある古い付与を取り消す)が、画面で通しでできることを確かめる。
  ほかのテストと付与日・勤務予定が重なると前提が崩れるため、ほかのシナリオと重ならない専用の年の範囲を使う。
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
- `frontend/src/hooks/usePaidLeave.ts`の`useRevokePaidLeaveGrant`:成功時に`['paid-leave', 'grants', 'user', userId]`に加えて、
  その社員の消化明細(`usePaidLeaveUsagesForUser`のqueryKey)と、残高を読むqueryがあればそのqueryKeyも無効化する
  (queryKeyは同ファイルの既存hookの定義に合わせる)。
- `backend/app/Http/Controllers/Api/PaidLeaveController.php`の`revoke`のdocコメント(「既に消化された分がある場合は…422」)を、
  「最新の非取消付与のみ取り消せる(それ以外は422)。消化済みの分は充当を解除して他の付与へ付け替え、足りない分は未充当になる」に直す。
- `PaidLeaveUsageAllocationProjector`のクラスdocコメントの「冪等な加減算・上書きであり、`event-sourcing:replay`による
  Projection再生成にそのまま対応する」を、実態(足し込みがあるため、書き込み先テーブルを空にしてからreplayする)に合わせて直す。
- テスト(既存の取消テストに追記する):
  - `tests/Feature/PaidLeaveAccount/PaidLeaveGrantRevocationTest.php`:付与を取り消すと、
    `GET /paid-leave/grants/user/{userId}`の該当付与が`status=revoked`・`remaining_days=0`になる。
    充当のあった付与(消化が別の付与へ付け替えられるケース)を取り消した場合も`remaining_days=0`。
    消滅警告バッチの対象にならない(既存の警告テストの書き方に合わせる。書きにくければ`remaining_days=0`の確認で足りる)。
  - `tests/Feature/SpecialLeave/SpecialLeaveGrantRevocationTest.php`:取り消すと`remaining_days=0`。
  - リビルド後も`remaining_days=0`のままであること。`tests/Feature/EventSourcing/LeaveEventStoreRebuildScenarioTest.php`の
    `replayReadModels()`と同じく、ReadModelのテーブルを空にしてから`event-sourcing:replay --force`する書き方にする
    (このファイルにケースを足すか、同じ手順のprivateメソッドを取消テスト側に置く)。
  - バックエンド:付与A(先)・付与B(後)があり、Aに消化が充当されている状態で、Bを取り消したあとAを取り消せる
    (API 200、Aの`status=revoked`・`remaining_days=0`、消化は未充当になり`paid_leave_balances.unallocated_days`に計上)。
    既存テストで同等のケースがあれば追記不要。
  - `frontend/src/pages/paidLeave/PaidLeavePolicyPage.test.tsx`:
    - 消化済み(`used_days > 0`)でも最新の付与の取消ボタンが押せる。
    - 最新でない付与の取消ボタンは無効で、`title`が「最新の付与のみ取り消せます。」。
    - 取り消した付与より前の付与は、取り消した付与を除いて最新なら押せる(報告のケース:06-02取消済み・06-01が押せる)。

- e2e:`frontend/e2e/scenario-16-paid-leave-grant-revocation.spec.ts`(新規)
  - 対象社員:小林 誠(`makoto.kobayashi@example.com`、`SCENARIO_USERS.accountingStaff`)。ScenarioSeederで有給・勤務予定を
    持たない。ただし`scenario-09-cross-domain.spec.ts` §5-14が9000〜9399年の範囲のランダムな年に、この社員の付与(`{年}-04-01`)・
    勤務予定・消化を作る。付与は既存の付与日より後にしか作れないため、scenario-16は専用の年の範囲9500〜9899年から
    ランダムに年(`year`)を選ぶ(scenario-09より後の付与日になり、他のシナリオの年の範囲とも重ならない)。
  - 前提データ(API、`support/api.ts`の既存ヘルパー。scenario-09 §5-14と同じ作り方):
    - 管理者で固定時間制の勤務形態を作り(`createWorkStyleViaApi`、コードは`rv16_{year}`)、`{year}-06-01`〜`{year}-06-30`の勤務予定を作る(`generateShiftAssignments`)。
    - 付与A:付与日`{year}-04-01`、失効日`{year+1}-03-31`、10日。付与B:付与日`{year}-04-02`、失効日`{year+2}-04-01`、10日。
    - 小林 誠で`{year}-06`の最初の平日に終日有給を申請し、渡辺 直樹で承認する。充当は「失効日の近い順」なので付与Aに入る
      (`AllocationPlanner`)。承認後、APIで付与Aの`used_days`が1であることを確かめる。
  - 画面操作(管理者):有給付与ポリシー画面の付与フォームで個別に小林 誠を1人選び、「対象社員の有給付与状況」を表示する。
    1. 付与Aの取消ボタンは無効で、`title`が「最新の付与のみ取り消せます。」。付与Bは押せる。
    2. 付与Bを取り消す(確認ダイアログで「取消する」)。APIの完了を待ち、付与Bの行が「残0日」と「取消済み」になる。
    3. 付与Aの取消ボタンが押せる(消化済みでも押せる)。確認文に「消化済みの1日分は他の付与へ付け替えます。」が出る。取り消す。
    4. 付与Aの行が「残0日」と「取消済み」になる。
  - 操作の後は、APIの完了(`waitForResponse`)または画面の結果を待ってから次の操作に進む。

## 受け入れ条件

- 有給付与を取り消すと、管理画面・自分の有給画面のAPIで該当付与の残日数が0日になる。
- 取り消した有給付与が、自分の有給画面の合計残日数・消滅警告の対象に含まれない。
- 特別休暇の付与を取り消した場合も残日数が0日になる。
- 報告のケース(06-02取消済み、06-01に消化あり)で、06-01の取消ボタンが押せ、取り消せる。
- 最新でない有給付与の取消ボタンは押せず、理由が表示される。
- 新しいe2e(scenario-16)と、既存の有給系e2e(scenario-03・scenario-15)がPASSする。
- Projectorをリビルドしても同じ値になる。
- 追加・既存のテスト(`php artisan test`)が全てPASSする。

## 対象外

- 代休(既に正しい)。
- 「残N日」の表示文言・レイアウトの変更。
- 特別休暇・代休の取消ボタンの条件(それぞれの集約の仕様どおり)。
- `paid_leave_balances`・勤怠Excel(既に取消分を除いている)。
- 本番環境でのリビルドの実施そのもの(論点3の手順でのリビルドが必要な旨を実装結果に書く)。

## ドキュメントへの影響

- `docs/16-database-schema.md`:`paid_leave_grants.remaining_days`の説明に「取り消した付与(`status=revoked`)は0」を追記する。
  `special_leave_grants`の説明(「同じ`status`/`revoked_at`…を持つ」)にも同じ旨を追記する。
- `docs/09-usecases-paid-leave.md` UC-P008の手順4:「`paid_leave_grants.remaining_days`を0にする」を追記する。
  画面では最新の非取消付与のみ取消ボタンを押せる旨も追記する。

## モック・アセット

なし

## 実装対象

- `backend/app/Domain/PaidLeaveAccount/Projectors/PaidLeaveUsageAllocationProjector.php`
- `backend/app/Domain/SpecialLeaveAccount/Projectors/SpecialLeaveAccountProjector.php`
- `backend/tests/Feature/PaidLeaveAccount/PaidLeaveGrantRevocationTest.php`
- `backend/tests/Feature/SpecialLeave/SpecialLeaveGrantRevocationTest.php`
- `frontend/src/pages/paidLeave/PaidLeavePolicyPage.tsx`
- `frontend/src/pages/paidLeave/PaidLeavePolicyPage.test.tsx`
- `frontend/src/hooks/usePaidLeave.ts`
- `frontend/e2e/scenario-16-paid-leave-grant-revocation.spec.ts`(新規)
- `frontend/e2e/README.md`(ファイル構成に1行追加)
- `backend/app/Http/Controllers/Api/PaidLeaveController.php`(docコメントのみ)
- `docs/09-usecases-paid-leave.md`
- `docs/16-database-schema.md`

## 検証方法

- `cd backend && php artisan test --filter='GrantRevocation'`
- `cd backend && php artisan test`
- `cd frontend && npx vitest run src/pages/paidLeave/PaidLeavePolicyPage.test.tsx`
- `cd frontend && npm run lint && npx tsc -p tsconfig.app.json --noEmit`
- e2e:backend(`php artisan serve`、`MICROSOFT_MOCK_ENABLED=true`)・mock-oidc・frontend(`npm run dev`)を起動し、
  `npx playwright test --config e2e/playwright.config.ts scenario-16 scenario-03 scenario-15`

## レビュー履歴

- 初版(残日数の不具合)
- 2026-10-24: ユーザーの追加連絡(06-02取消後に06-01が取り消せない)を受け、取消ボタンの条件(論点5・6)を追加
- 2026-10-24: 独立設計レビューの指摘を反映。
  (1)対象Projectorだけのreplayは充当が2重になる → 論点3の手順を全体リビルドに変更。
  (2)APIのdocコメントが古い → 実装対象に追加。
  (3)取消後に消化明細が古いまま → 論点4-2を追加。
  (4)docs/09 UC-P008の追記を追加。
  前提(原因・他の書き込み経路・「最新」の定義・他の付与が無いときも取消可)に誤りはないことを確認済み。
- 2026-10-24: ユーザーの依頼(e2eテストも実施)を受け、論点7とe2eの仕様を追加。ユーザーの依頼をもって実装の了承とし、ステータスを実装中にした
- 2026-10-24: e2eの前提の誤りを修正。「小林 誠は有給系のe2eで使われていない」は誤りで、scenario-09が9000〜9399年に付与・消化を作る。
  さらに勤務予定が無く、今日付けの付与・翌月の申請は実行時に失敗した(「勤務予定日ではないため有給を申請できません。」)。
  scenario-09と同じく、専用の未来の年の範囲(9500〜9899年)・API作成の勤務形態と勤務予定を使う方式に変えた

## 実装結果

### コミット
- `e7575d1` fix(frontend): 取消ボタンを最新の付与で判定し、消化済みでも取り消せるようにする(画面・hook・画面テスト・e2e新規・e2e README)
- `4c63983` fix(backend): 取り消した有給・特別休暇の付与の残日数を0にする(Projector 2本・取消テスト・APIのdocコメント・docs/09・docs/16)
- `ab135f9` test(e2e): scenario-16を専用の年の範囲・勤務予定で作るよう直す

### 検証結果
- `php artisan test --filter=GrantRevocation`:9件PASS。Projectorの修正を外すと4件FAIL(取消後もremaining_days=10)になることも確認。
- `php artisan test`(CIと同じ:`.env`+`key:generate`+ファイルのsqlite):2123件中2122件PASS、1件FAIL
  (`ReadModelFingerprintCommandTest::test_fingerprintは1行1テーブル…`)。`origin/main`でも同じ1件が失敗する(2119件中2118件PASS)。
  単体で流すと通るため、実行順に依存する既存の不安定さで、本変更とは無関係。
- `npx vitest run src/pages/paidLeave/PaidLeavePolicyPage.test.tsx`:19件PASS(追加3件)。`npm run lint` error無し。
  `npx tsc -p tsconfig.app.json --noEmit`・`npx tsc -p e2e/tsconfig.json --noEmit` エラー無し。
- e2e:
  - scenario-16:単独で複数回PASS。scenario-09と続けて流してもPASS。全件実行の中でもPASS。
  - scenario-03・scenario-15:PASS(03/09/15/16をまとめて流した回、15単独の回)。
  - 全件実行(69件):43 passed / 18 failed / 8 did not run。失敗はすべて本変更の範囲外:
    - scenario-09 §5-17:テストが待つ文言「締め処理済みのため修正できません。…」がアプリに存在しない(既存)。
    - scenario-13:この環境でmock-freee(:9001)を起動していない。
    - scenario-14(備品管理):単独実行でも5件失敗(「作成」ボタン待ちのタイムアウト等)。本変更は備品管理に触れていない。
    - scenario-15の1件・scenario-99の多数:全件実行の後半のタイムアウトの連鎖。単独で流し直すと15はPASS、99は8件PASS・2件FAIL
      (§5-6+7はテストが待つナビの「付与ポリシー」がアプリに存在しない。§5-8は月次締め後のCSV出力でタイムアウト)。いずれも有給付与の取消と無関係。

### 受け入れ条件の確認
- 取消後の残日数0(有給・特別休暇):backendの取消テストで確認。
- 合計残日数・消滅警告の対象外:どちらも`remaining_days`を読むため、上の確認で担保(警告バッチのテストは追加していない)。
- 報告のケースで06-01が取り消せる・最新でない付与は押せず理由が出る:画面テストとe2e scenario-16で確認。
- リビルド後も同じ値:backendのテストで、ReadModelを空にしてreplayして確認。
- 既存テストが全件PASS:上記の1件(`origin/main`でも失敗する既存の失敗)を除きPASS。

### 本番データへの反映(デプロイ時に必要)
- 取消済みの付与の既存行は、論点3の手順でリビルドするまで残日数が付与日数のまま。
  `php artisan leave:rebuild-event-store --list-readmodels`で出る全テーブルを空にしてから`php artisan event-sourcing:replay --force`
  (PR#121のリリースで行う全体リビルドに含めてよい)。

### 補足
- e2eの社員の選択で、付与対象の複数選択の候補が「ビューポート外」と判定されてクリックできず、`dispatchEvent('click')`で回避した。
  アプリ側のポップオーバーの位置の問題の可能性があり、別タスクで確認する。
