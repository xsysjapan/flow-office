# 有給・特別休暇の付与日数を管理画面から変更する(代休は同じ位置に変更できない理由を出す)

ステータス: 検討中

## 変更要望(原文)

```
有給の付与日数を画面から修正したいです。修正できるようにしてください。他の休暇も付与日数を修正できるように検討してください。現状はすべて同じ捜査官で設計をお願いします。
```

(「同じ捜査官」は「同じ操作感」と解釈した。)

仕様の確認への回答:
- 有給で付与日数を直せる付与:「最新の付与だけ」
- 代休の付与日数:「画面では直せないようにする」(他の休暇と同じ位置にボタンを出し、押せない理由を表示)

## 背景・目的

付与の日数を誤って付与した場合、今は取り消して付与し直すしかない(消化済みの場合は取り消し自体も制約がある)。
管理画面の付与一覧から、取消と同じ操作感で付与日数を変更できるようにする。

## 現状(As-Is)

- 有給(`App\Domain\PaidLeaveAccount`):
  - 集約`PaidLeaveAccountAggregate::changeGrantAmount`(197行)・Command`ChangePaidLeaveGrantAmount`・Handler・イベント
    `PaidLeaveGrantAmountChanged`(`paid_leave_account.grant_amount_changed`)は実装済み。HTTPのルートとUIが無い
    (変更セット20260906-paid-leave-domain-redesignで「Grant管理UI」はスコープ外にした)。
  - 不変条件:最新の非取消Grantのみ(`assertGrantIsLatestAndActive`)、新しい日数は充当済みの合計を下回れない。増やすと未充当の消化を充当し直す
    (`allocateUnallocatedUsages`)。理由は任意。
  - Projector:`PaidLeaveUsageAllocationProjector::onPaidLeaveGrantAmountChanged`が`granted_days`・`remaining_days`を更新、`PaidLeaveBalanceProjector`が再集計。
  - 画面:`frontend/src/pages/paidLeave/PaidLeavePolicyPage.tsx`の「対象社員の有給付与状況」の行に取消ボタン(`RevokeGrantButton`、最新の非取消付与だけ押せる)。
- 特別休暇(`App\Domain\SpecialLeaveAccount`):
  - 付与日数を変える集約のメソッド・イベント・Commandは無い。集約のメソッドは`registerGrant`・`revokeGrant`(取消済み・消化済みは拒否。最新の制約は無い)等。
  - 消化の確定時に、足りない分は未充当日数(`unallocatedDays`)として記録する。付与を増やしても、確定済みの消化の未充当を充当し直す仕組みは無い。
  - Projector:`SpecialLeaveAccountProjector`(残数は充当の表から再計算)。
  - 画面:`frontend/src/pages/specialLeave/SpecialLeaveAdminPage.tsx`の付与一覧(対象者1名選択時)に取消ボタン(`RevokeGrantButton`、`used_days > 0`で押せない)。
- 代休(`App\Domain\CompensatoryLeaveAccount`):日数は休日出勤の勤怠から決まる(自動付与は勤怠の再計算で下書きを上書き、手動付与も日数は勤怠から算出)。
  日数を変える操作は無い。画面`frontend/src/pages/compensatoryLeave/CompensatoryLeaveAdminPage.tsx`の付与一覧に取消ボタン。
  - 既存の不整合:取消ボタンを`status !== 'cancelled'`の行に出しているため、下書き(`draft`)の行にも押せるが、APIは「確定済みのみ取消可」で422になる。
- 共通部品:`frontend/src/components/RevokeGrantButton/RevokeGrantButton.tsx`(`ConfirmActionDialog`+任意の理由。`disabled`+`disabledReason`)。
- 権限:3種とも付与・取消は`permission:leave.manage,any`のグループ。
- 履歴:口座のイベントは履歴(`/*/history/user/{id}`・`/*/history/mine`)にそのまま出る。表示名は`frontend/src/utils/statusLabels.ts`の`leaveEventSuffixMeta`
  (末尾の種別で引く)。`grant_amount_changed`は未登録。

## 設計前提の検証

- 正データと派生データ:付与日数の正はイベント(`*_grant_registered`/`grant_created`と新しい変更イベント)。`*_grants.granted_days`・`remaining_days`は
  Projectorの派生データ。変更はCommand→イベント→Projectorで行い、ReadModelを直接書かない(原則1・2)。
- 有給は既存の集約の不変条件をそのまま使う(新しい業務ルールを作らない)。特別休暇は集約に新しいメソッドとイベントを足す。
- 代休は日数の正が勤怠(休日出勤の実績)にあり、付与日数だけを変えると勤怠側(`CompensatoryGrantDayViewProjector`等)と食い違うため、変更しない。
- 原因の分類:新機能(既存の不整合1件の修正を含む)。

## 仕様検討

### 論点1: 操作感(3種で揃える)
- 決定:
  - 共通部品`ChangeGrantDaysButton`(新規。`RevokeGrantButton`と同じ作り)を、各付与一覧の行の取消ボタンの左に置く。
    - トリガー「日数を変更」(secondary)。押すとダイアログ:タイトル「付与日数を変更しますか?」、説明(付与日・現在の日数)、
      入力「新しい付与日数」(数値、0.5刻み、必須、初期値=現在の付与日数、最小値=使用済みの日数と0.5の大きい方)、「変更理由(任意)」、確定ボタン「変更する」。
    - `disabled`+`disabledReason`で、押せない理由をボタンの`title`に出す(取消ボタンと同じ)。
    - 確定でAPIを呼び、成功で閉じる。エラーはダイアログ内に出す(422の業務ルールの文言をそのまま)。
  - 押せる条件・理由:
    - 有給:取消ボタンと同じ「最新の非取消付与」だけ押せる。押せない理由「最新の付与のみ変更できます。」。取り消した付与には出さない。
    - 特別休暇:取り消していない付与なら押せる。取り消した付与には出さない。
    - 代休:取り消していない付与の行に出すが常に押せない。理由「代休の日数は休日出勤の勤怠から決まるため、ここでは変更できません。勤怠を修正してください。」。
- 未確定・要確認事項: なし

### 論点2: 有給のAPI
- 決定:`PUT /paid-leave/grants/{grant}/granted-days`(`leave.manage`)。body `{ "granted_days": number, "reason": string|null }`。
  検証:`granted_days`は必須・数値・0.5以上・0.5刻み、`reason`は任意・255文字以内。`ChangePaidLeaveGrantAmount`を発行。
  集約が拒否した場合(最新でない・充当済みを下回る)は422(既存の`DomainRuleException`の扱い)。応答は付与1件(`PaidLeaveGrantResource`)。
  日数が現在と同じ場合は何もせず200で返す(イベントを記録しない)。
- 未確定・要確認事項: なし

### 論点3: 特別休暇の集約・イベント・API
- 決定:
  - 集約`SpecialLeaveAccountAggregate::changeGrantAmount(string $grantId, float $newGrantedDays, string $changedByUserId, ?string $reason)`:
    付与が存在しない・取り消し済みなら拒否。`$newGrantedDays < usedDaysOf($grantId)`なら拒否(「使用済みの日数より少なくはできません。」)。
    同じ日数なら何もしない。イベント`SpecialLeaveAccountGrantAmountChanged`(別名`special_leave_account.grant_amount_changed`。
    grantId・previousGrantedDays・newGrantedDays・changedByUserId・reason)を記録。
  - 増やしても、確定済みの消化の未充当(`unallocatedDays`)は充当し直さない(今の特別休暇の集約に充当し直す仕組みが無いため。対象外)。
  - Projector:`SpecialLeaveAccountProjector`に`onSpecialLeaveAccountGrantAmountChanged`(`granted_days`を更新し、残数を充当の表から再計算)。
  - Command`ChangeSpecialLeaveGrantAmount`・Handler(`config/domain.php`に登録)。
  - API:`PUT /special-leave/grants/{grant}/granted-days`(`leave.manage`)。body・検証・応答・同じ日数の扱いは有給と同じ。
- 未確定・要確認事項: なし

### 論点4: 代休
- 決定:日数の変更APIは作らない。画面に押せない「日数を変更」ボタンを出す(論点1)。
  あわせて既存の不整合を直す:取消ボタンは確定済み(`confirmed`)の行だけ押せるようにし、下書きの行は押せず理由「月次の提出で確定する前の代休は取り消せません。」を出す
  (APIの「確定済みのみ取消可」と揃える)。
- 未確定・要確認事項: なし

### 論点5: 履歴の表示名
- 決定:`leaveEventSuffixMeta`に`grant_amount_changed: { label: '付与日数の変更', tone: 'info' }`を追加する(有給・特別休暇の両方の履歴に出る)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

- backend
  - 有給:API(論点2)。`PaidLeaveController`にアクション、`routes/api.php`の`leave.manage`グループに追加。
  - 特別休暇:集約のメソッド・イベント(event_class_mapに登録)・Projector・Command/Handler・API(論点3)。
  - テスト(domain-test):
    - 有給:最新の付与の増額・減額が反映(`granted_days`・`remaining_days`・`paid_leave_balances`)、最新でない付与は422、充当済みを下回る減額は422、
      増額で未充当の消化が充当される、同じ日数は何もしない、権限の無い利用者は403、0.5刻みでない値は422。
    - 特別休暇:集約の単体テスト(増額・減額・使用済みを下回る拒否・取消済みの拒否・同じ日数)、APIのテスト(反映・422・403)、
      作り直し(ReadModelを空にしてreplay)後も同じ値。
- frontend
  - `components/ChangeGrantDaysButton/`(.tsx・.stories.tsx・.test.tsx。`add-frontend-component`)。
  - `api/paidLeave.ts`・`hooks/usePaidLeave.ts`、`api/specialLeave.ts`・`hooks/useSpecialLeave.ts`に関数とmutation(成功で付与一覧・消化明細・残高を無効化。取消のmutationと同じ範囲)。
  - 有給・特別休暇・代休の管理画面の付与一覧に`ChangeGrantDaysButton`を置く(論点1・4)。代休の取消ボタンの条件を直す(論点4)。
  - `utils/statusLabels.ts`に履歴の表示名(論点5)。
  - テスト:各画面のテストに、ボタンの表示・押せる条件・API呼び出しの値を追加。代休の下書きの行の取消ボタンが押せないこと。
- docs
  - `docs/09-usecases-paid-leave.md`:UC-P010「有給の付与日数を変更する」を追加(最新の非取消付与のみ、充当済みを下回れない、増やすと未充当を充当)。
  - 特別休暇のユースケースの文書(`grep`で特定):付与日数の変更を追加(使用済みを下回れない、未充当は充当し直さない)。
  - 代休のユースケースの文書:日数は勤怠から決まり画面では変更しない旨と、取消は確定済みのみである旨。
  - `docs/17-events.md`:`special_leave_account.grant_amount_changed`を追加。

## 受け入れ条件

- 有給の管理画面で、最新の付与の付与日数を変更でき、一覧の付与日数・残日数と、本人の画面の残日数に反映される。最新でない付与のボタンは押せず理由が出る。
- 特別休暇の管理画面で、取り消していない付与の付与日数を変更でき、反映される。使用済みより少なくはできない(ダイアログにエラー)。
- 代休の管理画面では、同じ位置のボタンが押せず理由が出る。下書きの代休の取消ボタンは押せず理由が出る。
- 3画面のボタンとダイアログの見た目・操作が同じ。
- 履歴に「付与日数の変更」と表示される。
- 追加したテストと、backend(CIと同じ条件の全件)・frontend(vitest・lint・tsc)がPASSする。

## 対象外

- 付与日・失効日の変更(有給の集約には操作があるが、今回は日数だけ)。
- 特別休暇で増やしたときの、確定済みの消化の未充当の充当し直し。
- 代休の日数の変更。
- 有給の古い付与の変更(最新のみ。ユーザーの回答)。
- e2e(画面のテストで確かめる)。

## ドキュメントへの影響

上記「docs」の4件。

## モック・アセット

なし

## 実装対象

上記「仕様確定事項」のbackend・frontend・docs。

## 検証方法

- `cd backend && cp .env.example .env && php artisan key:generate && rm -f database/database.sqlite && touch database/database.sqlite && php artisan test`
- `cd frontend && npx vitest run --project=unit && npm run lint && npx tsc -p tsconfig.app.json --noEmit`、新しいstoryのstorybookテスト
- 実機:開発環境で3画面を開き、有給・特別休暇の日数を変更して一覧と本人の画面の残日数を確かめ、代休のボタンが押せないことを確かめる。

## レビュー履歴

初版

## 実装結果

未着手
