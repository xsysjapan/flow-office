# php84-cloud-setup

- ステータス: 完了

## 変更要望(原文)

> PHP8.4の環境をセットアップして初期スクリプト化してください。
> (以下のスクリプトを提示)apt で ondrej/php PPA から php8.4-cli 等をインストールし、
> `update-alternatives --set php /usr/bin/php8.4` で既定にしたうえで `backend` で `composer install` する。

## 背景・目的

CI(`.github/workflows/tests.yml`)・本番デプロイ(`deploy.yml`)は PHP 8.4 だが、Claude Code
クラウドセッションのコンテナは PHP 8.3 のため、ローカル検証とCIの実行環境がずれる。
セッション開始時に自動で PHP 8.4 + backend依存を揃え、テスト・Lintを CI と同じ条件で回せるようにする。

## 現状(As-Is)

- クラウドコンテナ: PHP 8.3.6、composer は `/usr/local/bin/composer` に導入済み。
- `.claude/settings.json` には `UserPromptSubmit` フック(codegraph)のみ。SessionStart フックなし。
- `scripts/` には ngrok 起動スクリプトのみ。

## 設計前提の検証

- 正データと派生データ: 業務データには触れない(開発環境のみの変更)。
- 定義と実態: CI・本番とも `php-version: 8.4`。`composer.json` は `^8.3` なので 8.4 で問題ない。

## 仕様検討

### 論点1: スクリプトの置き場所
- 選択肢: A) `.claude/hooks/session-start.sh` に全処理を書く / B) 本体を `scripts/setup-php84.sh` に置き、フックはそれを呼ぶだけにする
- 決定: B
- 理由: クラウド環境の「セットアップスクリプト」設定や手動実行でも同じスクリプトを使い回せる。フックは起動条件の判定だけに保つ。

### 論点2: 実行条件
- 選択肢: A) 常に実行 / B) `CLAUDE_CODE_REMOTE=true` のときのみ
- 決定: B
- 理由: ローカル(Mac/Windows/devcontainer)で apt や PPA 追加を走らせない。

### 論点3: 冪等性
- 決定: `php8.4` と必要な拡張がすでに揃っていれば apt 処理をスキップし、`update-alternatives` と `composer install` のみ実行する。`cd backend` はスクリプト位置基準の絶対パスで行う。`root` でない場合は `sudo` を付ける。
- 理由: セッション再開(resume/compact)ごとに apt-get update が走るのを避ける。

### 論点3-2: PPAの登録方法
- 選択肢: A) 提示どおり `add-apt-repository` / B) 鍵(keyserver.ubuntu.com)と sources.list を手動で登録
- 決定: B
- 理由: クラウドコンテナでは python3 が 3.13 なのに apt_pkg が 3.12 向けで、`add-apt-repository` が `No module named 'apt_pkg'` で落ちるため。

### 論点4: 同期/非同期
- 決定: 同期実行
- 理由: PHPが切り替わる前にテストが走る競合を避ける(必要なら後から async に変更)。

## 仕様確定事項(まとめ)

1. `scripts/setup-php84.sh`: 提示スクリプトをベースに、冪等化(導入済みならapt省略)・リポジトリルート基準のパス解決・非root時sudoを追加。
2. `.claude/hooks/session-start.sh`: `CLAUDE_CODE_REMOTE` が `true` のときだけ 1 を実行。
3. `.claude/settings.json`: 既存設定を保ったまま `SessionStart` フックを追加。

## 受け入れ条件

- フック実行後 `php -v` が 8.4 系、`backend/vendor` が揃う。
- 2回目の実行で apt 処理がスキップされる。
- backend の Pint(1ファイル)と PHPUnit(1テスト)が PHP 8.4 で通る。

## 対象外

- `mcp/`・`frontend/` の依存インストール、devcontainer・CI・本番環境の変更。

## ドキュメントへの影響

- なし(`backend/CLAUDE.md` のセットアップ手順はローカル向けで変更不要)。

## 実装対象

- `scripts/setup-php84.sh`(新規)、`.claude/hooks/session-start.sh`(新規)、`.claude/settings.json`(追記)

## 検証方法

- `CLAUDE_CODE_REMOTE=true .claude/hooks/session-start.sh` を2回実行、`php -v`、Pint・PHPUnit を1件ずつ実行。

## レビュー履歴

- 2026-10-15: スクリプト内容はユーザー提示のものを正とし、冪等化等の補足のみのため即実装。

## 実装結果

- 3ファイルを実装。フックは CLAUDE_CODE_REMOTE=true でのみ起動し、鍵取得・PPA登録までは動作確認済み。
- 当初はネットワークポリシーが `ppa.launchpadcontent.net` を拒否していたが、ユーザーがネットワーク設定をFullに変更して解消。
- `backend` の `composer install` が `ext-gd` を要求したため `php8.4-gd` をパッケージに追加(本番 deploy.yml でも gd を有効化している)。
- 検証: フック実行 exit 0(PHP 8.4.26)、2回目はapt処理をスキップ、Pint(`app/Http/Controllers/Controller.php`)passed、
  PHPUnit(`tests/Unit/PaidLeaveRequest/PaidLeaveRequestAggregateTest.php`)28件passed。
