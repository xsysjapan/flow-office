# 6. 認証・ユーザー連携ユースケース

## UC-000: 初回オンボーディングを実行する

認証はMicrosoft Entra ID SSOのみ(`users`テーブルはローカルパスワードを持たない)。
Microsoft 365連携設定(下記UC-001・UC-002・UC-N001が共有するEntra ID資格情報)が
未設定の間は誰もSSOログインできず、管理画面(UC-003のシステム設定含む)にも到達できない。
この「鶏と卵」を解消するため、未認証で呼び出せる初回オンボーディングを用意する。

1. `GET /api/onboarding/status` で`needs_onboarding`(=`system_settings.onboarding_completed_at`
   が未設定か)を確認する。trueならフロントエンドはログイン画面の代わりに
   オンボーディング画面を表示する。
2. 管理者になる人が、氏名・メールアドレスと、Entra IDアプリ登録の資格情報
   (テナントID・クライアントID・クライアントシークレット・リダイレクトURI、必要なら
   ローカル開発用モックOIDC切替)を入力する。
3. `POST /api/onboarding`でCommandBus経由の`CompleteOnboarding`を発行する。
   - `system_settings`にEntra ID資格情報を保存する(`onboarding_completed_at`も設定)。
   - 入力されたメールアドレスで`users`に管理者ユーザーを作成し、`admin`ロールを付与する。
     この時点では`entra_user_id`は未設定のまま(実際のSSOでの初回ログインを経ていないため)。
   - 作成した管理者をそのままSanctumトークンでログイン済みにする(実際のSSO往復を待たない)。
4. 既に完了済み(`onboarding_completed_at`設定済み)の状態で`POST /api/onboarding`を
   呼ぶと422を返し、二度目以降の実行は拒否する。
5. その後、この管理者が実際にEntra IDでSSOログインすると(UC-001)、`entra_user_id`が
   未設定かつメールが一致するこの行にバックフィルされ、新規ユーザーとして重複作成されない
   (付与済みの`admin`ロールも維持される)。

関連イベント: `user.onboarded_as_admin`
関連テーブル: `system_settings`, `users`

**注意**: 未認証で呼び出せるため、`onboarding_completed_at`が未設定の間は誰でも自分を
管理者として登録できてしまう(いわゆる「先に完了させたもの勝ち」)。セットアップトークン等の
追加保護は設けていないため、デプロイ直後にオンボーディングを完了させる運用を前提とする。

## UC-001: Microsoft SSOでログインする

1. ユーザーがアプリにアクセスする
2. Microsoft Entra ID のログイン画面へ遷移する
3. 認証成功後、アプリに戻る
4. Entra ID のユーザーID、メール、表示名を取得する
5. 初回ログインならアプリ側ユーザーを作成する
6. 既存ユーザーなら最終ログイン日時を更新する

関連イベント: `user.logged_in`
関連テーブル: `users`

## UC-002: MS365ユーザーを同期する

1. 管理者またはバッチが同期を実行する
2. Microsoft Graph からユーザー一覧を取得する
3. メール、表示名、部署、役職、在籍状態を更新する
4. アプリ独自の権限は上書きしない
5. 同期結果をログに残す

関連イベント: `user.synced_from_ms365`
関連テーブル: `users`

**注意**: アプリ独自の権限(ロール)は Microsoft Graph 側の属性で上書きしない。同期は
氏名・メール・部署・役職・在籍状態のみを対象とし、権限管理は [UC-M001](./15-usecases-admin.md#uc-m001-権限を設定する)
で別管理する。タイムゾーン(`timezone`)も同期対象外で、既存ユーザーの値は上書きしない。

## UC-003: システム設定(default_timezone等)を管理する

1. 管理者がシステム設定画面を開く
2. `GET /api/system-settings` で現在のデフォルトタイムゾーンを取得する
3. 管理者がデフォルトタイムゾーンを変更し、`PUT /api/system-settings` で更新する
4. 変更後に新規作成されるユーザーは新しいデフォルトタイムゾーンで作成される
   (既存ユーザーの `timezone` は変更されない)

同じ画面で、UC-000で登録したMicrosoft 365連携設定(SSOログイン・MS365ユーザー同期・
メール通知が共有するEntra ID資格情報: `m365_tenant_id`/`m365_client_id`/
`m365_client_secret`/`m365_redirect_uri`/`m365_mock_enabled`)も後から変更できる。
`m365_client_secret`は`encrypted`キャストでDBに暗号化して保存し、画面には平文を返さず
設定済みかどうか(`m365_client_secret_configured`)のみ返す。空欄のまま保存すると
既存のシークレットは変更されない。

関連テーブル: `system_settings`

**注意**: `system_settings` はマスタ的なシングルトン設定であり、Command/EventStoreを経由せず
Eloquentで直接更新する(他のマスタ管理APIと同じ方針)。参照・更新はいずれも管理者ロール限定
(`role:admin` ミドルウェア)。

新規ユーザーがどのタイムゾーンで作成されるかは以下の通り(docs/03-architecture.md 3.4):

- SSO初回ログイン([UC-001](#uc-001-microsoft-ssoでログインする))・MS365同期による新規作成
  ([UC-002](#uc-002-ms365ユーザーを同期する))のいずれも、作成時点の `system_settings.default_timezone`
  を初期値として使う
- 既存ユーザーの `timezone` はSSOログインやMS365同期で上書きされない
