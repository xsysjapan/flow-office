# 6. 認証・ユーザー連携ユースケース

## UC-000: 初回オンボーディングを実行する

認証は原則Microsoft Entra ID SSOだが、初回オンボーディングでSSOを設定しなかった場合に
限り`users.password`によるローカルパスワードログインも許可する。いずれの認証方式も
未設定の間は誰もログインできず、管理画面(UC-003のシステム設定含む)にも到達できない。
この「鶏と卵」を解消するため、未認証で呼び出せる初回オンボーディングを用意する。
SSOモードとローカルパスワードモードの2つから選べる。

`GET /api/onboarding/status`は`needs_onboarding`(=`system_settings.onboarding_completed_at`
が未設定か)と`sso_configured`(=Entra ID資格情報が設定済みか)を返す。`needs_onboarding`が
trueならフロントエンドはログイン画面の代わりにオンボーディング画面を表示し、完了後は
`sso_configured`を見てログイン画面がMicrosoftボタン(true)かローカルパスワードフォーム
(false)のどちらを出すか決める。

### SSOモード

管理者になるユーザーを事前入力しない。実際にEntra IDへログインした結果(OIDCの認証済み
ユーザーID・メール・表示名)だけを使って管理者を作成・リンクする。メールアドレスの
文字列一致には一切依存しない。

1. Entra IDアプリ登録の資格情報(テナントID・クライアントID・クライアントシークレット・
   リダイレクトURI、必要ならローカル開発用モックOIDC切替)を入力し、
   `POST /api/onboarding/sso`を呼ぶ(`StartOnboardingSso`)。
   - `system_settings`にEntra ID資格情報を保存し、`onboarding_started_at`を設定する
     (`SystemSetting::claimOnboarding()`による単一UPDATE文での原子的クレーム。
     `onboarding_completed_at`が未設定かつ`onboarding_started_at`が未設定または開始から
     10分以上経過している場合のみ成功する。この10分の猶予は、SSOログインを最後まで
     やり切らずに離脱した場合に永久ロックしないためのセーフティネット)。
   - まだユーザーは作成しない。管理者になる人はまだ確定していないため。
   - レスポンスとしてMicrosoftログイン画面へのリダイレクトURLを返す
     (`Socialite::stateless()->with(['state' => 'onboarding-sso-link'])`で、通常のSSO
     ログイン(UC-001)と区別するための目印を付ける)。
2. ブラウザがそのままMicrosoftのログイン画面へ遷移する。
3. ログイン成功後、`GET /api/auth/microsoft/callback`(UC-001と共通のコールバック)が
   `state`パラメータでオンボーディングのSSOリンクだと判定し、`CompleteOnboardingSsoLink`を
   発行する。
   - `onboarding_started_at`が未設定、または既に`onboarding_completed_at`が設定済みなら
     422で拒否する。
   - 同じ`email`または`entra_user_id`のユーザーが既に存在する場合も422で拒否する
     (既存アカウントの乗っ取り・意図しない上書きを防ぐ)。
   - Socialiteが返した`entra_user_id`/`name`/`email`をそのまま使って`users`に管理者
     ユーザーを作成し、`admin`ロールを付与する。
   - `system_settings.onboarding_completed_at`を設定する(こちらも原子的UPDATE)。
   - 通常のSSOログイン(UC-001)と同じ交換コード方式でフロントエンドへリダイレクトし、
     そのままログイン済みにする。

### ローカルパスワードモード

Microsoft 365を設定しない場合、その場でパスワード付きの管理者ユーザーを作成する。

1. 氏名・メールアドレス・パスワードを入力し、`POST /api/onboarding/local`を呼ぶ
   (`CompleteOnboardingWithLocalPassword`)。
2. 同じ`email`のユーザーが既に存在する場合は422で拒否する。
3. `onboarding_started_at`と`onboarding_completed_at`を同時に原子的コミットし(1リクエストで
   完結するモードのため)、パスワード(`users.password`、`hashed`キャストで自動ハッシュ化)を
   持つ管理者ユーザーを作成、`admin`ロールを付与する。
4. その場でSanctumトークンを発行しログイン済みにする。
5. 以後は`POST /api/auth/local-login`(email + password、ブルートフォース対策として
   1分あたり5回に制限)でログインできる。

関連イベント: `user.onboarded_as_admin`(`auth_method`が`sso`または`local`)
関連テーブル: `system_settings`, `users`

**注意**: 未認証で呼び出せるため、`onboarding_completed_at`が未設定の間は誰でも初回
オンボーディングを実行できてしまう(いわゆる「先に完了させたもの勝ち」)。セットアップ
トークン等の追加保護は設けていないため、デプロイ直後にオンボーディングを完了させる運用を
前提とする。このリスクは、`onboarding_completed_at`が設定された瞬間に
`POST /api/onboarding/sso`・`POST /api/onboarding/local`のいずれも422で拒否されるように
なるため、デプロイ直後の一時的な窓のみに限定される。

一方、`m365_mock_enabled`(ローカル開発用モックOIDC切替)はSSOモードのボディで送信できる
DB値のため、この一時的な窓を過ぎた後も値が残り続ける(通常のシステム設定と同様、
システム設定画面から誤って有効化される可能性もある)。この値は開発専用の危険な
エンドポイント(`DevDatabaseResetController`、DB全体を初期化する)のゲートも兼ねるため、
DBの値だけに依存せず`Ms365ConfigResolver::mockEnabled()`が`APP_ENV`が`local`/`testing`の
場合のみtrueを返すようにしている。本番・検証環境ではDBの値に関わらず常にfalseになり、
これらのエンドポイントは恒久的に到達不能。

## UC-001: Microsoft SSOでログインする

1. ユーザーがアプリにアクセスする
2. Microsoft Entra ID のログイン画面へ遷移する
3. 認証成功後、アプリに戻る(`GET /api/auth/microsoft/callback`)
4. Entra ID のユーザーID、メール、表示名を取得する
5. 初回ログインならアプリ側ユーザーを作成する(`employee`ロール)
6. 既存ユーザーなら最終ログイン日時を更新する

コールバックはUC-000のSSOモードのオンボーディングリンク完了とも共通で、`state`
パラメータで判定する(`AuthController::callback()`)。通常ログインの`RecordSsoLoginHandler`は
`entra_user_id`一致のみで判定するシンプルなロジックで、オンボーディング固有の特別扱いは
持たない(UC-000のSSOモードは別のCommand/Handlerが担当するため)。

関連イベント: `user.logged_in`
関連テーブル: `users`

初回オンボーディング(UC-000)でSSOを設定しなかった場合は、代わりに`POST /auth/local-login`
(メールアドレス + パスワード)でログインする。

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
