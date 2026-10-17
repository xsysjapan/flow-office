# excel-paid-leave-balance-allocations

- ステータス: 承認済み(2026-10-17 ユーザーレビュー)

## 変更要望(原文)

> 勤怠管理表(Excel)の有給残日数を、充当(`paid_leave_usage_allocations`)から月末時点で算出するよう直す。
> 取消済みの消化を除き、確定済みかつ`used_on <= 月末`の消化だけを数える。対象月末までに付与され、
> 同日時点で有効な付与だけを数える既存の規則(docs/14「月次集計の有給残日数」)は維持する。
> Featureテスト(取消した消化・2つの付与にまたがる消化・月末より後の消化)を追加する。

## 現状(As-Is)

`AttendanceExcelBuilder.php:120-134`:

```
残日数 = Σ_付与 max(0, granted_days − Σ usages.used_days)
  付与: user_id一致 ∧ granted_on <= 月末 ∧ expires_on >= 月末
  消化: PaidLeaveGrant::usages()(paid_leave_usages.paid_leave_grant_id)∧ is_confirmed ∧ used_on <= 月末
```

## 原因

1. 取消: `PaidLeaveUsageAllocationProjector::onPaidLeaveUsageCancelled`(:123-125)は`cancelled=true`にするだけで
   `is_confirmed`はtrueのまま。Excelの式は`cancelled`を見ないため、取消した消化も差し引く(残が少なく出る)。
2. 複数付与・未充当: `paid_leave_grant_id`は充当先が1件のときだけ入る(`syncUsageGrantReference` :206-215)。
   2件以上にまたがる消化は`null`になり、どの付与からも差し引かれない(残が多く出る)。
   docs/16のとおり、この列はcutover後は表示用の互換列で、充当の正は`paid_leave_usage_allocations`。
- 原因の分類: 実装のミス(正ではない互換列を集計に使った)。

## 設計前提の検証

- 正データと派生データ: 充当の正は`paid_leave_usage_allocations`(Projection。イベントから再生成可能)。
  `paid_leave_grants.allocated_days`・`remaining_days`は「現在」の非正規化キャッシュで月末時点の値を持たないため使わない。
- 取消時の充当: `PaidLeaveAccountAggregate::cancelUsage`は`PaidLeaveUsageCancelled`の前に
  `PaidLeaveUsageAllocationReleased`を記録するため、取消済みの消化の充当行は通常は残らない。
  それでも要望どおり`cancelled = false`の条件を明示的に入れる(月末時点の判定を消化の状態でも担保する)。
- 書き込み: 読み取り専用の計算のみ。ReadModel・イベントへの書き込みは無い。

## 仕様検討

### 論点1: 消化日数の出どころ
- 選択肢: A) 充当行(`paid_leave_usage_allocations.allocated_days`)を付与ごとに合計する / B) 消化行の`used_days`を使い続け、付与への紐づけだけ充当行から取る
- 決定: A
- 理由: 1件の消化が2つの付与にまたがるとき、各付与から差し引くべき日数は充当行にしか無い。

### 論点2: 月末時点の条件
- 決定: 次の条件で充当行を付与ごとに合計し、`max(0, granted_days − 合計)`を付与ごとに出して足す(付与ごとの0下限は既存どおり)。
  - 付与: `user_id`一致 ∧ `granted_on <= 月末` ∧ `expires_on >= 月末`(既存の規則のまま)
  - 充当行: `grant_id`がその付与 ∧ 対応する消化(`paid_leave_usages.usage_id = allocations.usage_id`)が
    `is_confirmed = true` ∧ `cancelled = false` ∧ `used_on <= 月末`
- 実装: 付与ごとに`withSum`(またはサブクエリ)で、`paid_leave_usage_allocations`を`paid_leave_usages`にjoinして合計する。
  `PaidLeaveGrant::usages()`は使わない(他の利用箇所があるため関係自体は残す)。
- 注記(既知の近似): 充当行は「現在」の充当関係。月末より後に起きた充当の付け替え(付与日数の変更・付与の取消による再充当)は
  過去の月の残にも反映される。月末時点の充当を正確に出すにはイベントの再生が必要で、帳票の用途に対して過大なため今回は扱わない。
- 注記: どの付与にも充当されていない確定済みの消化(有効な付与の残が尽きている場合に生じる)は差し引かない。
  その時点で有効な付与の残はすでに0のため、残日数の表示は変わらない。

### 論点3: 取り消された付与(`status = 'revoked'`)【要確認】
- 現状: 付与の取消(UC-P008)は充当を解除して`status='revoked'`にするだけで、`granted_days`・`expires_on`は変わらない。
  既存の式は`status`を見ないため、取り消した付与の日数が丸ごと残日数に入る(残が多く出る)。今回の変更で充当行から数えると、
  取消時に充当が解除されるためこの付与は「満額残っている」扱いになり、症状は変わらない。
- 選択肢: A) 今回あわせて`status = 'active'`の付与だけを数える(docs/14の文言に「取り消した付与を除く」を足す) / B) 今回は対象外とし別の変更セットにする
- 決定: A(2026-10-17 ユーザー決定)
- 理由: 同じ式の同じ種類の不具合で、条件1つの追加で済む。

## 対象外

- Export文脈が有給口座のReadModelを直接読んでいる点(原則15)は既存の構成のまま。今回の変更で新たに読むテーブル
  (`paid_leave_usage_allocations`)も同じ有給口座のReadModelで、読み取り専用。
- `paid_leave_usages.paid_leave_grant_id`列・`PaidLeaveGrant::usages()`の扱いは変えない。

## ドキュメントへの影響

- docs/14「月次集計の有給残日数」: 「確定した消化日数」を「確定済みで取り消していない消化の、各付与への充当日数」と明記
  と「取り消した付与を除く」を追記。

## テスト(`backend/tests/Feature/Attendance/AttendanceExportTest.php`に追加)

いずれも既存テストと同じく、付与は`GrantPaidLeave`コマンド、消化は申請・承認APIで作る(ReadModelを直接作らない)。
Excelの`H10`(有給残日数)を検証する。

1. 取消した消化: 付与10日、月内に全休を2日承認し、うち1日を取り消す → 9.0日(修正前は8.0日)。
2. 2つの付与にまたがる消化: 付与A(0.5日)と付与B(10日)、月内に全休1日を承認 → 充当がA 0.5・B 0.5に分かれ 9.5日(修正前は10.5日)。
   テストの中で充当行が2行あることも確認する(前提の確認)。
3. 月末より後の消化: 付与10日、月内に全休1日・翌月に全休1日を承認 → 9.0日(翌月分は差し引かない)。
4. 取り消した付与: 付与2件のうち1件を取り消す → 取り消した付与の日数を含まない。

受け入れ条件: `cd backend && php artisan test --filter Export`が全件通る。

## 結果

- `php artisan test --filter Export`: 68件PASS(既存64件+追加4件)。
- 修正前のコード(テストだけ追加)での結果: 2つの付与にまたがる消化(10.5日)・取り消した付与(15日)が失敗し、修正で直ることを確認。
- 取消した消化のテストは修正前のコードでも通った。通常の取消の流れでは、`cancelUsage`が充当を解除し、
  `syncUsageGrantReference`が`paid_leave_grant_id`をnullに戻すため、旧式の`usages()`経由でも差し引かれなかった。
  原因1は通常の取消の流れでは表面化しない(列の値に依存した偶然)。新しい式では`cancelled = false`を明示しているため、
  回帰防止のテストとして残す。月末より後の消化のテストも修正前から通る(旧式にも`used_on`の条件があった)。
- 表示は既存の`formatDays`に合わせ、整数は「9日」「10日」となる(上のテスト節の「9.0日」等は値の表記)。
