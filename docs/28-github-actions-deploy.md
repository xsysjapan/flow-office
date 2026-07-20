# 28. GitHub Actions によるXSERVERへの自動デプロイ

`docs/27-release-runbook.md` の考え方(3アプリを単一ドメインの `/flow-office` 配下に配置)を、
実際のXSERVERアカウント構成に合わせて自動化したもの。ワークフロー本体は
`.github/workflows/deploy.yml`、補助ファイルは `deploy/` 配下。

対象サーバー:

```
ssh xsys@sv8141.xserver.jp -p 10022
/home/xsys/xsys.co.jp/public_html/   … 既存ホームページのドキュメントルート(触らない)
```

## 1. 配置構成

`docs/27-release-runbook.md` のApache Alias方式ではなく、共有ホスティングでも設定ファイルの
編集権限なしで完結する**シンボリックリンクのCapistrano風リリース方式**を採る。

```
/home/xsys/xsys.co.jp/
├── public_html/
│   └── flow-office -> ../../flow-office/current/frontend/dist   … 初回デプロイ時に1度だけ作成、以後不変
└── flow-office/                                                  … ドキュメントルート外(Web非公開)
    ├── releases/
    │   ├── 20260720120500-abc1234/
    │   │   ├── backend/          Laravel(vendor込み)。storage/はshared/へのシンボリックリンク
    │   │   ├── mcp/               同上
    │   │   └── frontend/dist/
    │   │       ├── index.html, assets/...
    │   │       ├── api -> ../../backend/public   (相対シンボリックリンク)
    │   │       └── mcp -> ../../mcp/public       (相対シンボリックリンク)
    │   └── (直近5世代を保持し、古いものは自動削除)
    ├── current -> releases/20260720120500-abc1234   … デプロイのたびにアトミックに張替え
    └── shared/
        ├── backend/storage/    セッション・ログ・添付ファイル(storage/app)を永続化
        └── mcp/storage/        同上 + OAuth署名鍵(oauth-*.key)
```

- `public_html/flow-office` のシンボリックリンクは**初回デプロイ時に1度だけ**作られ、以後は
  向き先(`current`)の中身が変わるだけなので触らない。
- `.env` と `storage/` はどちらも`shared/`に永続化し、リリースディレクトリからシンボリックリンク
  する。`.env`はGitHub Actionsからは一切生成・上書きしない。初回セットアップ時に
  `deploy/env/*.env.production.example` を元に**サーバー上で手動作成**し(2.3節)、以後の
  デプロイはそれをそのまま使い回す。`storage/`はセッション・ログ・添付ファイル・mcpのOAuth鍵を
  デプロイのたびに失わないための永続化。
- 3アプリの`migrate`・`*:cache`がすべて成功してから最後に`current`を張り替えるため、
  途中で失敗した場合は本番に影響が出ない(旧リリースが動き続ける)。
- ロールバックは `ln -sfn releases/<過去のタイムスタンプ> current` を実行するだけでよい
  (直近5世代は自動的に残してある)。

## 2. 一度だけ行う手動セットアップ

GitHub Actionsからは行えない(あるいは行うべきではない)作業。

### 2.1 デプロイ用SSH鍵

ローカルまたは信頼できる端末で鍵ペアを作成し、公開鍵をサーバーの
`~/.ssh/authorized_keys` に追加する。秘密鍵はGitHub Secretsにのみ登録し、他に保存しない。

```
ssh-keygen -t ed25519 -f deploy_key -N "" -C "github-actions-flow-office"
ssh-copy-id -i deploy_key.pub -p 10022 xsys@sv8141.xserver.jp
ssh-keyscan -p 10022 sv8141.xserver.jp   # → DEPLOY_SSH_KNOWN_HOSTS に登録する出力
```

### 2.2 PHPバイナリの実パスを確認

XSERVERは複数PHPバージョンが共存するため、`composer.lock`が要求するPHP 8.4系の実パスを
SSHで確認しておく(`docs/27-release-runbook.md` 8節の既知課題参照)。

```
ssh xsys@sv8141.xserver.jp -p 10022 'which php8.4 || ls /usr/bin/php*'
```

### 2.3 .env の作成(サーバー上、手動、初回のみ)

`.env`はGitHub Actionsからは生成しない。`deploy/env/backend.env.production.example`・
`deploy/env/mcp.env.production.example` を元に、サーバー上で直接作成する。

```
ssh xsys@sv8141.xserver.jp -p 10022
mkdir -p /home/xsys/xsys.co.jp/flow-office/shared/backend
mkdir -p /home/xsys/xsys.co.jp/flow-office/shared/mcp
vi /home/xsys/xsys.co.jp/flow-office/shared/backend/.env   # backend.env.production.exampleを元に作成
vi /home/xsys/xsys.co.jp/flow-office/shared/mcp/.env       # mcp.env.production.exampleを元に作成
```

`APP_KEY`は暗号化データ(セッション等)の整合性のため、`.env`作成時に一度生成したら
以後は変更しない。

```
php artisan key:generate --show   # backend/mcp それぞれのディレクトリで実行し、.envのAPP_KEYに設定
```

### 2.4 データベース

- backend用: 既存の `mysql8015.xserver.jp` / `xsys_db` / `xsys_user` を利用する。
- mcp用: backend/とは別データベースが必要(`mcp/README.md`参照)。XSERVERのサーバーパネル
  (MySQL管理)で新規データベース・ユーザーを作成する(例: `xsys_db_mcp`)。GitHub Actionsから
  DB作成は行わない(XSERVERのMySQL管理はSSHではなくサーバーパネル経由のため)。
  作成したホスト・DB名・ユーザー・パスワードは2.3節の`shared/mcp/.env`に直接書く
  (GitHub Secretsには含めない)。

### 2.5 cron設定

3アプリとも常駐ワーカーを前提にしないため、`current`シンボリックリンク経由でcronに登録する
(デプロイのたびにパスを変更しなくてよい)。XSERVERのサーバーパネルのcron設定、または
契約プランで許可されていればcrontabに以下を登録する。

```cron
* * * * * cd /home/xsys/xsys.co.jp/flow-office/current/backend && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/xsys/xsys.co.jp/flow-office/current/mcp     && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1
```

## 3. GitHub Secrets 一覧

リポジトリの Settings → Secrets and variables → Actions に登録する。

| Secret | 例 | 用途 |
|---|---|---|
| `DEPLOY_SSH_HOST` | `sv8141.xserver.jp` | 接続先ホスト |
| `DEPLOY_SSH_PORT` | `10022` | SSHポート |
| `DEPLOY_SSH_USER` | `xsys` | SSHユーザー |
| `DEPLOY_SSH_KEY` | (秘密鍵本文) | 2.1で作成した秘密鍵 |
| `DEPLOY_SSH_KNOWN_HOSTS` | (`ssh-keyscan`の出力) | known_hosts検証用 |
| `DEPLOY_BASE_DIR` | `/home/xsys/xsys.co.jp/flow-office` | Web非公開のリリース領域 |
| `DEPLOY_PUBLIC_HTML_LINK` | `/home/xsys/xsys.co.jp/public_html/flow-office` | 公開ドキュメントルートのリンク先パス |
| `DEPLOY_PHP_BIN` | `/usr/bin/php8.4` | 2.2で確認した実パス |
| `APP_PUBLIC_URL` | `https://xsys.co.jp/flow-office` | 公開URL(末尾スラッシュなし)。frontendのビルド(`VITE_API_BASE_URL`)と疎通確認のcurlにのみ使う |

`APP_KEY`・DB接続情報・Entra ID資格情報はいずれもGitHub Secretsには置かない。前者2つは
2.3節でサーバー上の`.env`に直接書き、Entra ID(Microsoft Client ID/Secret等)は
`system_settings`テーブルで管理する(初回オンボーディング画面 `POST /api/onboarding` から
登録する。`docs/06-usecases-auth.md`参照)。

## 4. デプロイの流れ

`main`ブランチへのpush、または手動実行(workflow_dispatch)で `.github/workflows/deploy.yml` が
起動する。

1. backend(`composer install --no-dev`)・mcp(同左 + `npm run build`)・frontend
   (`npm run build`、`VITE_BASE_PATH=/flow-office/`)をGitHub Actionsランナー上でビルドする
   (`.env`はここでは一切生成しない)。
2. `releases/<タイムスタンプ>-<sha>/` へrsyncで転送する(`.env`は含まれない)。
3. SSHでサーバーに入り、`deploy/scripts/activate-release.sh` を実行する。このスクリプトが
   `shared/backend/.env`・`shared/mcp/.env`(2.3節で作成済みのもの)をリリースディレクトリへ
   シンボリックリンクし、**backend・mcp両方について**`migrate --force`と各種`*:cache`を実行、
   最後に`current`を一括切替、古いリリースを整理する。
4. `docs/27-release-runbook.md` 7節と同じ疎通確認(frontend/backend/mcpのcurl)を行う。

## 5. ロールバック

直近5世代のリリースが`releases/`配下に残っているので、問題が起きたら該当タイムスタンプへ
`current`を戻すだけでよい(DBスキーマを戻す変更を含む場合は別途マイグレーションのrollback判断が必要)。

```
ssh xsys@sv8141.xserver.jp -p 10022
ln -sfn /home/xsys/xsys.co.jp/flow-office/releases/<戻したいタイムスタンプ> \
        /home/xsys/xsys.co.jp/flow-office/current
```

## 6. 既知の注意点

`docs/27-release-runbook.md` 8節の注意点(`route:cache`とサブパス配置の組み合わせでの
405、Apacheの`Alias`宣言順、MySQL識別子長制限、外部キー参照順序、composer.jsonの
PHPバージョン要件、OAuth鍵ファイルの権限)はこの自動デプロイでも同様に該当する。
特にOAuth鍵の600権限は`activate-release.sh`が毎回`chmod 600`で強制しているが、
`shared/mcp/storage`ディレクトリ自体のパーミッションがWebサーバー実行ユーザーから
読めることも確認しておくこと。
