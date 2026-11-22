# `php artisan test` でテストが APP_ENV=local のまま動く不具合の修正

- ステータス: 実装中
- 種別: 不具合修正(backend テスト設定)

## 変更要望(原文)

> テストエラーがあるとCIが通りません。修正してください。

## 現状(As-Is)・原因

- backend全件をCIと同じ手順(`cp .env.example .env && php artisan key:generate && php artisan test`)で流すと、
  `Tests\Feature\EventSourcing\ReadModelFingerprintCommandTest::test_fingerprintは…` が失敗する(単体では成功)。
- 原因: `backend/phpunit.xml` の `<env name="APP_ENV" value="testing"/>` に `force="true"` が無い。`php artisan test` は
  `.env`(`APP_ENV=local`)を読み込んだ状態でテストの子プロセスを起動するため、既存の値が優先され、**テストが `local` 環境で動く**
  (`app()->environment()` = local、`runningUnitTests()` = false。`./vendor/bin/phpunit` では testing)。
- その結果、AIエージェント環境(Claude Code等)では `laravel/pao`(エージェント向けに出力を圧縮するパッケージ)が
  `runningUnitTests()` = false を見て有効になり、コマンド出力のタブ文字を取り除く。タブ区切りの出力を検査する上記テストが失敗する。
  エージェントでないGitHub Actionsでは pao が無効なため、mainのCIは成功している(2026-11-21の Backend Tests は成功)。
- 原因の分類: 設定のミス。テストが testing 以外の環境で動くこと自体が、環境で分岐する処理の検証漏れにつながる。

## 仕様確定事項

1. `backend/phpunit.xml` の `APP_ENV` を `<env name="APP_ENV" value="testing" force="true"/>` にする。
2. 他の `<env>` は変えない(DB・キュー等はCIで `.env` の値を使う前提が `delegate-implementer` に記録されているため)。
3. 全件をCIと同じ手順で流し、エージェント環境(pao有効)・`PAO_DISABLE=1`(CI相当)の両方で全件成功を確認する。

## 対象外

- `laravel/pao` の設定変更・削除。

## ドキュメントへの影響

- `.claude/skills/delegate-implementer/SKILL.md` の「CIと同じ条件」の記述に、APP_ENVがtestingに固定される旨を追記。

## 実装対象

- `backend/phpunit.xml`

## 検証方法

- `cp .env.example .env && php artisan key:generate && php artisan test`(エージェント環境のまま)と、同じく `PAO_DISABLE=1 php artisan test`。

## レビュー履歴

- 2026-11-22: ユーザーの修正依頼に基づき作成・実装。

## 実装結果

(実装後に追記)
