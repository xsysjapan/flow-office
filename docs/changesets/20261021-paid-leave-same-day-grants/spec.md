# 同じ日に複数の有給を付与できるようにする

ステータス: レビュー中

## 変更要望(原文)
> 現在は付与を同一日に行うことはできない認識ですが、付与は同一日にできるように変更してください。

> 目的は1/1入社で4/1に付与される際、半年の付与と1年の付与を合わせて付与できるようにするためです。

確認への回答(2026-10-21)
- 2026-01-01入社の社員: 「4/1に10日+11日の2件」(2026-04-01に半年分10日と1年半分11日。その後は2027-04-01に12日、
  以後毎年4/1)
- まとめる条件: 「初回が前倒しの時だけです。4/1に被らない入社日の場合(例えば4/1入社の場合)は半年後に付与でお願いします。」

## 背景・目的
年度はじめの一斉付与で初回が前倒しになる社員は、その年度開始日に、半年分と1年半分の2件を同じ日にまとめて付与する。
そのためには、同じ社員に同じ付与日の付与を複数持てる必要がある。今は、有給口座も付与予定も、これを許していない。

本変更では、同じ日の付与を持てる土台を作る。
- 有給口座に、同じ日の付与を記録できるようにする。
- 付与予定が、同じ日に複数の行を持てるようにする。

いつ2件にまとめるかという日付の規則は、`20261021-paid-leave-fiscal-mass-grant-and-auto-assessment`に足す。

## 現状(As-Is)
- 有給口座(`PaidLeaveAccountAggregate`)
  - `grant()`(71-77)は、非取消の最新の付与(`latestActiveGrant`)の付与日と比べ、`grantedOn <= 最新`を
    `DomainRuleException`にする(「同日・過去日付は不可」)。
  - `changeGrantDate()`(160-164)も、ひとつ前の付与と同じ日付を拒否する。
  - 「最新」の判定(`latestActiveGrantExcluding`:482-497)は付与日の`>`比較で、同じ日の付与があると決まらない。
    変更・取消は、最新の付与にしかできない(`assertGrantIsLatestAndActive`:499-512)。
  - 消化の充当(`AllocationPlanner:30-73`)
    - Step 1: 有効期限の古い順。
    - Step 2: 付与日の最小。
    - 同じ値のときの順は、配列の挿入順まかせ(仕様に書かれていない)。
    - 作り直しの`UnallocatedUsageAllocator`(365, 459-468)にも同じ論理がある。
  - 制約の出典は`docs/changesets/20260906-paid-leave-domain-redesign/spec.md`の論点4・不変条件1。同じ日を禁止する
    理由は書かれていない。`docs/09:289-290`にも「同日付与は拒否」とある。
  - DBのunique制約は無い。手動付与のAPI(`PaidLeaveController:471-477`)・画面(`ManualGrantCard`)にも、同じ日の検証は無い。
- 5日取得義務(`WarnFiveDayObligationHandler:40-75`)
  - 付与ごとに判定する(`granted_days >= 10`、期限は付与日+1年)。
  - 同じ日の付与が2件あると、警告が2件出る。消化の充当が片方に偏ると、片方だけ未達と判定されうる。
  - 使用日数を、`PaidLeaveGrant::usages()`(旧テーブル`paid_leave_usages`)から取っている。今の充当
    (`paid_leave_usage_allocations`)を反映しているかは、調査では確かめていない。
- 付与予定(`PaidLeaveScheduleAggregate`)
  - 行を付与予定日で見分けている。
    - `ensureFutureScheduleGenerated`: `findEntryIdByScheduledOn`。
    - `recalculateFutureSchedule`: `$desiredByScheduledOn`。付与済みと同じ日付の候補の消費もここ。
  - 同じ日に2行を持てない。
  - 候補とイベントに、名目の継続勤務月数が無い。
  - 一括付与(`ApplyScheduledGrantsHandler`)は、行ごとに`GrantPaidLeave`(付与日=付与予定日)を出す。

## 設計前提の検証
- 正データと派生データ: 付与の正は有給口座のイベント、付与予定の正は付与予定のイベント。どちらも、Projectionは
  イベントから作り直せる。本変更は、両方の集約の規則と、イベントの項目を変える。
- 定義と実態
  - 「最新の付与」は付与日だけで定義されており、同じ日の付与があると決まらない。記録の順(集約の中の順番)を
    第2のキーにする。
  - 付与予定の行の見分けを、付与予定日だけで行っている。名目の継続勤務月数(何回目の付与か)と組にする。
  - 5日取得義務は、法令上は基準日(付与日)ごとの義務。付与ごとに判定すると、同じ日の2件で二重になる。
- 原因の分類: 業務の要件の追加(同じ日の付与)。今の規則は、同じ日を想定していなかった。

## 仕様検討

### 論点1: 有給口座で同じ日の付与を許す
- 選択肢:
  - A. 付与日が最新の付与と同じ日なら許す。過去の日付(より前)は、今どおり拒否する。
  - B. 過去の日付も許す。
- 決定: A。`grant()`の`<=`を`<`にする。`changeGrantDate()`も、ひとつ前の付与と同じ日付を許す。
- 理由: 要件は同じ日のまとめ付与だけ。過去への挿入は、充当の作り直しが要るので広げない。
- 未確定・要確認事項: なし

### 論点2: 同じ日の付与があるときの「最新」と並び順
- 決定
  - 有給口座の付与に、記録の順`order`(集約の中の連番。消化の`order`と同じ作り)を持たせる。新しいイベントは
    要らない(applyの順で決まる)。
  - 最新は「付与日が最も遅く、そのうち`order`が最も大きい付与」にする。
    - 変更・取消は、今どおり最新の付与にだけできる。
    - 同じ日の2件のうち先の付与を取り消すには、後の付与を先に取り消す(docs/09に書く)。
  - 充当の順
    - Step 1: 有効期限→付与日→`order`の順に古いものから。
    - Step 2: 付与日→`order`の順に古いものから。
    - 作り直しの`UnallocatedUsageAllocator`も同じ順にする。
- 理由: 同じ日の付与でも、結果が決まる。先に記録した付与(半年分)から使われるのが自然。
- 未確定・要確認事項: なし

### 論点3: 5日取得義務を、基準日ごとにまとめる
- 選択肢:
  - A. 社員と付与日の組でまとめる。日数の合計が10日以上なら義務の対象にし、取得日数も合計で見て、警告は1件だけにする。
  - B. 付与ごとのまま(同じ日の2件で警告が二重になる)。
- 決定: A。
  - 警告済みの印(`five_day_obligation_warned_at`)は、組のすべての付与に付ける。
  - 実装の最初に、取得日数の取り方(`PaidLeaveGrant::usages()`が旧テーブルを読んでいるか)を確かめる。
    今の充当を反映していなければ、そのこと自体が別の不具合なので、直さずに報告して止める。
- 理由: 法令上の義務は、基準日ごとに年5日。前倒しでの重なりなど細かい扱いは、社労士確認の対象として対象外に書く。
- 未確定・要確認事項: なし

### 論点4: 付与予定が、同じ日に複数の行を持てるようにする
- 決定
  - 候補とイベント(`PaidLeaveScheduleEntryCreated`)に、`nominalServiceMonths`(int。名目の継続勤務月数)を足す。
    Projection(`paid_leave_schedule_entries`)に`nominal_service_months`列を足す。
  - 行の見分けは(付与予定日, 名目の継続勤務月数)の組にする。
    - 対象は`findEntryIdByScheduledOn`、`$desiredByScheduledOn`、付与済みの消費。
    - 名目の月数が無い古いイベントの行(本番には無い。AggregateIdの分離で付与予定のイベントは取り除く)は、
      付与予定日だけで見分ける。
  - `ScheduleCandidateGenerator`は、候補に`nominalServiceMonths`を入れる(今の計算でも名目の月数は出ている)。
  - 一括付与(`ApplyScheduledGrantsHandler`)は、同じ社員・同じ日の行を、名目の月数の小さい順に付与する。
  - 付与予定の画面は、同じ日の行を名目の月数の順に並べ、「継続◯か月分」を表示する。
- 理由: 2件まとめの付与(論点の外: 年度はじめ一斉付与の変更セット)の土台になる。年度開始日を変えたときの
  「付与済みを起点にした名目の月数」にも使う。
- 未確定・要確認事項: なし

### 論点5: 手動付与の画面・API
- 決定: 同じ日の手動付与を受け付ける(論点1で集約が許すため)。画面の文言・検証は変えない。
  - 年度はじめの一括付与と同じ日に手動付与をすると、両方が付与される。二重の付与かは、管理者が付与の一覧で
    確かめる(今回は防がない)。
- 理由: 要件は同じ日のまとめ付与で、手動との重複を防ぐ規則は求められていない。
- 未確定・要確認事項: なし

### 論点6: 設計レビューで決めた点(論点1〜5を補う。食い違うときはこちらを正とする)
- 5日取得義務の数え方(論点3を置き換える)
  - 今の実装は、取得日数を`paid_leave_usages.paid_leave_grant_id`(充当先が1件のときだけ入る、表示用の列)で数えている。
    そのため、複数の付与にまたがる消化が数えられない。繰越分に充当された消化も、新しい付与に数えられない。
    法令(39条7・8項)とも`docs/09:410-411`とも合わない、今ある不具合なので、ここで直す。
  - 規則
    - 社員と付与日(基準日)の組ごとに判定する。取消済みの付与は除く。
    - 対象は、組の付与日数の合計が10日以上の組(比例の7+8日なども対象になる)。
    - 取得日数は、`usedOn`が[基準日, 基準日+1年)にある消化(確定済み・未取消・時間単位以外)の日数の合計。どの付与から
      充当したかは問わない。
    - 警告は組に1件。警告済みの印は、組のすべての付与に付ける。
  - テスト: 2つの付与にまたがる消化が数えられる/繰越分に充当された消化も数えられる/7+8日の組が対象になる/取消済みの付与は除く。
- 消滅の警告(`WarnExpiringPaidLeaveHandler`)も、同じ日・同じ有効期限の付与をまとめて1件にする。
- 二重付与の防止
  - 一括付与の付与IDを、付与予定の行のIDから決めて作る(UUIDv5)。
  - 有給口座の集約は、すでにある付与IDの付与を`DomainRuleException`で拒否する。
  - これで「付与は成功したが予定の行の更新に失敗した」行を再実行しても、二重には付与されない。
  - 手動付与の二度押しの防止は、今回は扱わない(対象外)。
- 充当のStep 2: 「最も近い将来の付与日」の付与を、`order`の順に見る。1件目の残りが足りなければ、同じ日の次の付与に続けて充当する。
  作り直しの`UnallocatedUsageAllocator`も同じ。`grantsSnapshot`に`order`を渡す。作り直し側にもテストを足す。
- 一括付与の順と組: `ApplyScheduledGrantsHandler`の中で、(付与予定日, 名目の月数)の順に並べ替える。
- 同じ日の付与の直し方: 半年分の日数を直すには、1年半分を取り消してから手動で付与し直す。このとき予定の行は、取り消した付与を
  指したまま付与済みで残る。この手順をdocs/09に書く。
- 細部
  - `nominal_service_months`列はnullableにする。
  - 付与予定の一覧APIの並びに、名目の月数を第2キーとして足す。
  - Supersededのイベントに、置き換える前の名目の月数(`previousNominalServiceMonths`)を足す。
  - `findEntryIdByScheduledOn`は`ensureFutureScheduleGenerated`専用。割当の再計算の変更セットでは`Ensure`を消さずに残した
    (テストのデータの用意に使う)。そのため、この関数も(付与予定日, 名目の月数)の組で見分けるように直す。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- 有給口座
  - `grant()`と`changeGrantDate()`で同じ日を許す。
  - 付与の`order`を持つ。
  - 最新の判定と充当の順は論点2のとおり。`AllocationPlanner`と`UnallocatedUsageAllocator`も合わせる。
- 5日取得義務: 社員と付与日の組でまとめる(論点3)。
- 付与予定: `nominalServiceMonths`を持たせ、行の見分けを(付与予定日, 名目の月数)にする(論点4)。一括付与の順。
  画面の表示。
- テスト
  - Unit(有給口座): 同じ日の2件目が付与できる。過去の日付は拒否される。最新は後に記録した方。同じ日の先の付与は、
    後の付与を取り消すまで取り消せない。消化は先に記録した付与から充当される。
  - Unit(付与予定): 同じ日で名目の月数が違う2つの候補から2行ができる。再計算で冪等。付与済みの消費も組で行う。
  - Feature
    - 手動付与のAPIで同じ日の2件目が201になる。
    - 一括付与で同じ日の2行が両方付与される(順は名目の月数の小さい順)。
    - 5日取得義務で、同じ日の2件(10日+11日)の警告が1件だけ出る。取得が合計5日なら警告されない。
  - 既存のテストの変更: `PaidLeaveAccountAggregateTest::test_same_day_grant_is_rejected`は、同じ日を許すテストに直す
    (過去の日付の拒否は残す)。

## 受け入れ条件
- 同じ社員に、同じ付与日の手動付与・一括付与を2件以上記録できる。過去の日付の付与は、今どおり拒否される。
- 同じ日の付与があっても、最新の付与・充当の順・取消の可否が決まった順で動く(テスト)。
- 5日取得義務の警告が、基準日ごとに1件になる。
- 付与予定が、同じ日に名目の月数の違う複数の行を持て、一括付与でその順に付与される。
- backendのテスト一式がPASSする(既知の失敗を除く)。e2eの`scenario-03`・`scenario-15`がPASSする。
- docs(下記)が実装と一致する。

## 対象外
- 2件をまとめて付与する日付の規則(年度はじめ一斉付与の変更セットに足す)。
- 年度はじめの一括付与と、同じ日の手動付与の重複の防止。
- 前倒しで基準日が重なるときの、5日取得義務の期間の扱い(比例按分など。社労士確認の対象)。
- 過去の日付への付与の挿入。

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`
  - 289-290行(同日付与の拒否)を「同じ日は可、過去の日付は不可」に直す。
  - 最新の判定・取消の順・充当の順を書く。5日取得義務の基準日ごとのまとめを書く。付与予定の名目の月数を書く。
- `docs/16-database-schema.md`: `paid_leave_schedule_entries.nominal_service_months`。
- `docs/17-events.md`: `paid_leave_schedule.entry_created`の`nominalServiceMonths`。
- `docs/changesets/20260906-paid-leave-domain-redesign/spec.md`は過去の記録なので書き換えない。
- `docs/changesets/README.md`

## モック・アセット
なし

## 実装対象
- `backend/app/Domain/PaidLeaveAccount/`(Aggregate、Support/AllocationPlanner)
- `backend/app/Domain/EventSourcing/Rebuild/UnallocatedUsageAllocator.php`
- `backend/app/Domain/PaidLeave/Handlers/WarnFiveDayObligationHandler.php`
- `backend/app/Domain/PaidLeaveSchedule/`(Aggregate、Events、Projectors、Support/ScheduleCandidateGenerator、
  Handlers/ApplyScheduledGrantsHandler)、migration(`nominal_service_months`)
- `backend/app/Http/Controllers/Api/PaidLeaveScheduleController.php`(summarizeに名目の月数)
- `frontend/src/pages/paidLeave/PaidLeaveSchedulePage.tsx`、`src/api/types.ts`
- `backend/tests/`、`frontend`のテスト
- docs: 上記

## 検証方法
- `cd backend && php artisan test`(全件)
- frontend: `npx tsc -p tsconfig.app.json --noEmit`、`npx vitest run --project=unit`
- e2e: `npm run test:e2e -- scenario-03-paid-leave scenario-15-paid-leave-schedule`

## レビュー履歴
初版(2026-10-21)

- 2026-10-21 独立設計レビュー(同等モデル)の指摘を、論点6として反映した。
  - 要修正
    - 5日取得義務の数え方は、今ある不具合なので直す規則を決めた。
    - 同じ日を禁止していたことが二重付与を防いでいた → 付与IDを決めて作り、同じIDを拒否する。
  - 推奨
    - Step 2で同じ日の2件目を使う。
    - 一括付与の並べ替え。
    - 消滅の警告もまとめる。
  - 軽微: 列をnullableにする、一覧の並び、Supersededの項目、Ensureの消去との関係、直し方の手順。

## 実装結果
未着手
