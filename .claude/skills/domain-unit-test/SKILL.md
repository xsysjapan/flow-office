---
name: domain-unit-test
description: >-
  flow-officeのbackendで業務ルール(残数・日数・時間の計算、状態遷移の可否、重複・衝突判定、充当順序、
  有効期限など)を実装・変更するときに使う。業務ルールをDB・Eloquent・Laravelに依存しない純粋なクラスに
  置き、PHPUnitの単体テスト(tests/Unit、PHPUnit\Framework\TestCase)で検証する形を定める。
  Handler・Projector・Controllerに業務ルールを書かないための判断基準と、既存の参考実装を示す。
---

# ドメインロジックの単体テスト

ルート`CLAUDE.md`設計原則16の具体的な進め方。業務ルールは「単体テストできる形で書き、実際に単体テストする」。

## どこに書くか

| 置き場所 | 書いてよいもの | 書かないもの |
|---|---|---|
| Aggregate(`Domain/<X>/Aggregates`) | 状態遷移の可否・不変条件(残数不足・二重承認の禁止など)、イベントの記録と適用 | Eloquent・DB・`SystemSetting::current()`・Facade |
| ドメインサービス/ポリシー/計算(`Domain/<X>/Support`等) | 計算・判定・計画(日数換算、充当順序、衝突判定、期限計算など) | 同上。入力は値(int/float/string/日付/配列/値オブジェクト)で受け取る |
| CommandHandler | 入力の読み込み(Eloquent・設定)→純粋なクラスの呼び出し→Aggregateの永続化、の組み立て | 業務ルールそのもの(if分岐による可否判定・計算) |
| Projector | イベントの内容をそのままテーブルへ反映 | 残数計算などの業務ルール(Aggregateかドメインサービスで計算し、イベントに結果を載せる) |
| Controller / Reactor | 入口の認可・Commandの組み立て/発行 | 業務ルール |

- 設定値(`system_settings`・マスタ)はHandlerで読み、値として純粋なクラスへ渡す。純粋なクラスの中で
  `SystemSetting::current()`やモデルを読まない。
- privateメソッドに業務ルールを閉じ込めない(Handlerのprivateにあるとテストできない)。独立したクラスの
  publicメソッドにする。
- 日付は`CarbonImmutable`等の値で渡し、「今日」も引数で受け取る(`now()`を内部で呼ばない)。

## テストの書き方

- 業務ルールのテストは`backend/tests/Unit/<DomainName>/`に置き、`PHPUnit\Framework\TestCase`を継承する
  (`Tests\TestCase`を継承しない=Laravelアプリ・DBを起動しない)。DBやアプリが必要になるなら、それは業務ルールが
  純粋になっていないサイン。
- Aggregateは`::fake()`等で永続化なしに生成し、Commandメソッド呼び出し→記録されたイベント/例外を検証する
  (参考: `tests/Unit/PaidLeaveAccount/PaidLeaveAccountAggregateTest.php`)。
- 1つの業務ルールについて、正常・境界値(しきい値ちょうど、0、上限)・異常(例外)を書く。
- Feature テスト(`tests/Feature`)は、HTTP・認可・Command→Event→Projectorの配線・文脈間のReactor連鎖の確認に
  使い、業務ルールの網羅はUnitで行う。

## 参考にする既存実装
- `backend/app/Domain/PaidLeaveAccount/Aggregates/PaidLeaveAccountAggregate.php` と
  `backend/tests/Unit/PaidLeaveAccount/PaidLeaveAccountAggregateTest.php`(Eloquent非依存の不変条件)
- `backend/app/Domain/PaidLeaveAccount/Support/AllocationPlanner.php`(充当計画を純粋化)
- `backend/app/Domain/PaidLeaveSchedule/Aggregates/PaidLeaveScheduleAggregate.php` と Unitテスト

## 既存コードを触るとき
- 業務ルールを変更・追加する箇所がHandler/Projector/Eloquent依存のServiceにある場合は、その変更の中で
  ルール部分を純粋なクラスへ切り出し、Unitテストを追加する(触った範囲から順に改善する)。
- 切り出しが変更の規模に対して大きすぎる場合は、変更セットの「対象外」に残課題として明記する。
