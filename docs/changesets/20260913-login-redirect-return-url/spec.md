# ログイン時のリダイレクト元URL復帰対応

ステータス: レビュー中

## 変更要望(原文)
URLを指定してログインする際に、リダイレクト元のURLに戻るように修正してください。
一般的なサイトの考慮事項が実装されているかを確認してください。
※特にLaravelで最初に有効化するところなど。

## 背景・目的
未認証ユーザーが保護ルート(例: `/attendance/today`)へ直接URLアクセスした場合、現状は
ログイン後に必ずトップページ(`/`)へ遷移してしまい、本来アクセスしたかった画面に戻れない。
ブックマーク・共有リンク・通知メール経由のディープリンクの利便性を損なっているため、
ログイン完了後に元のURLへ戻す。あわせて、この種の実装で一般的に必要となる
Open Redirect対策が漏れなく入っているかを確認する。

## 現状(As-Is)
- `frontend/src/auth/RequireAuth.tsx:16-18` — 未認証時は`<Navigate to="/login" replace />`
  固定。元の`location`(pathname+search)を保持していない。
- `frontend/src/pages/auth/LoginPage.tsx:47` — ローカルログイン成功時
  `navigate('/', { replace: true })`固定。
- `frontend/src/pages/auth/AuthCallbackPage.tsx:30` — SSOコールバック成功時
  `navigate('/', { replace: true })`固定。
- `frontend/src/auth/AuthContext.tsx:52-55` — `login()`は`fetchMicrosoftRedirectUrl()`で
  取得したURLへ`window.location.href`で離脱する(Microsoft Entra IDへの外部遷移を挟む)。
- `backend/app/Http/Controllers/Api/AuthController.php`
  - `redirect()`(55-62行目): `Socialite::driver('azure')->stateless()->redirect()`。
    `state`パラメータは未指定。
  - `linkRedirect()`(71-86行目): UC-004用に`state`へユーザーIDの暗号化文字列を載せている
    (`SSO_LINK_STATE_PREFIX`)。
  - `callback()`(95-124行目): `state`の値で「通常ログイン」「オンボーディングSSOリンク
    (`OnboardingController::SSO_LINK_STATE`)」「アカウント紐づけ(`link-sso:`prefix)」の
    3経路を判定し、最後にフロントエンドの`/auth/callback?code=...`へ`redirect()->away()`する。
    `state`は既に多重の意味を持たせて使い切っている。
- `redirect`/`returnTo`等のクエリパラメータはコード上どこにも存在しない(新規実装)。
- 関連ユースケース: `docs/06-usecases-auth.md` UC-001(Microsoft SSOログイン)。ログイン後の
  遷移先についての記載はなし。

## 仕様検討

### 論点1: ログイン後の戻り先をどう受け渡すか(ローカルログイン/SSOログイン共通)
- 選択肢:
  - A. `RequireAuth`が`/login?redirect=<元のpath+search>`のクエリパラメータで渡し、
    `LoginPage`がそれを読んでローカルログイン後にそのまま`navigate`する。SSOログインの
    場合はMicrosoft往復を挟むため、`redirect`先を`sessionStorage`に一時保存しておき、
    `AuthCallbackPage`で読み出して`navigate`する(バックエンドは無改修)。
  - B. `redirect`先をバックエンドのOAuth `state`パラメータに載せ、Microsoft往復後の
    `callback()`から最終リダイレクトURLのクエリに含めて返す(バックエンドを改修)。
- 決定: A
- 理由: `state`パラメータは`AuthController::callback()`側で既に
    「通常ログイン/オンボーディングSSOリンク/アカウント紐づけ」の3経路判定に使われており、
    ここへ任意のリダイレクト先文字列を混在させるとstateのパース・改ざん検証がさらに複雑になり
    バグ・脆弱性混入のリスクが上がる。`sessionStorage`はタブ単位でオリジン間遷移
    (自サイト→Microsoft→自サイトのcallback)をまたいで残るため、バックエンドを一切変更せずに
    同じ目的を達成できる。ルートCLAUDE.mdの原則9(操作経路とロジックの分離)にも、
    UI側の遷移復帰はドメインロジックではないため抵触しない。
- 未確定・要確認事項: なし

### 論点2: Open Redirect対策(一般的なサイトの考慮事項)
- 選択肢:
  - A. リダイレクト先文字列をそのまま`navigate()`/`window.location`に渡す(対策なし)。
  - B. 許可される形式を「`/`で始まり、かつ`//`・`/\`で始まらない相対パスのみ」に限定する
    共通バリデーション関数を新設し、`LoginPage`・`AuthCallbackPage`双方の遷移前に必ず通す。
    不正な値・欠落時は`/`にフォールバックする。
- 決定: B
- 理由: `redirect`パラメータ(およびsessionStorageに保存する値)は外部から改ざん可能な
    入力(URL共有・ブックマーク経由)であるため、絶対URL(`https://evil.example/...`)や
    プロトコル相対URL(`//evil.example/...`、`/\evil.example`)を許可すると、正規のログイン
    フローを経由した外部サイトへの誘導(オープンリダイレクト)に悪用されうる。共通関数化して
    ログイン画面・コールバック画面の両方で同じ検証を通すことで、片方だけ対策漏れになることを防ぐ。
- 未確定・要確認事項: なし

### 論点3: リダイレクト先として許可しないパスの扱い
- 選択肢:
  - A. `/login`・`/auth/callback`自身も許可対象に含める(理論上ループの可能性)。
  - B. `redirect`先が`/login`または`/auth/callback`(あるいはその配下)の場合は無効値として
    扱い`/`にフォールバックする。
- 決定: B
- 理由: ログイン画面自体やコールバック画面への回帰を許可する意味はなく、実装ミスや
    細工されたURLでのリダイレクトループを未然に防ぐ。
- 未確定・要確認事項: なし

### 論点4: 「Laravelで最初に有効化するところ」の考慮(React側での実装)
- 内容: Laravel標準の認証ミドルウェア(`Illuminate\Auth\Middleware\Authenticate`)は、
  未認証アクセス時に元のURLを`session()->put('url.intended', ...)`へ保存し、ログイン成功後に
  `redirect()->intended('/')`でそこへ戻す「intended URL」という一般的な仕組みを標準で持つ
  (Laravel初期化直後から有効な既定動作)。本システムはCookieベースのstateful Sanctum認証を
  使わずBearerトークン認証のSPA構成のため、この仕組みはLaravel側には存在しない。今回の
  依頼は、この`intended URL`パターンと同等の考慮(未認証時に元URLを保存し、ログイン後に
  そこへ戻す)を**React側(SPAのルーティング層)に実装してほしい**という意味だった
  (2026-09-13ユーザー確認済み)。
- 決定: 論点1〜3で確定した`RequireAuth`(元URL保存)→`LoginPage`/`AuthCallbackPage`
  (戻り先への遷移、`sessionStorage`でSSO往復をまたぐ)の一連の実装が、この
  `intended URL`パターンのReact側での実装そのものにあたる。バックエンド(`backend/`)は
  引き続き変更しない(Laravel側に該当ミドルウェアの出番がないため)。
- 理由: Bearerトークン認証ではLaravel側にセッション(Cookie)が存在せず`session()`を
  使えないため、同等の状態保持はSPA側(`sessionStorage`・URLクエリ)で行うのが自然な対応
  であり、論点1で決定した設計と矛盾なく両立する。

## 仕様確定事項(まとめ)
- `frontend/src/auth/redirectTarget.ts`(新規)に以下を実装する:
  - `getSafeRedirectTarget(value: string | null | undefined): string | null` —
    値が`/`で始まり、`//`・`/\`で始まらず、`/login`・`/auth/callback`(またはその配下
    `/login/...`・`/auth/callback/...`)でない場合のみその値を返す。それ以外は`null`を返す。
  - `POST_LOGIN_REDIRECT_STORAGE_KEY`定数(`sessionStorage`用キー名)をあわせてexportする。
- `frontend/src/auth/RequireAuth.tsx`:
  - `useLocation()`で現在の`pathname + search`を取得し、未認証時は
    `<Navigate to={`/login?redirect=${encodeURIComponent(pathname + search)}`} replace />`
    とする。
- `frontend/src/pages/auth/LoginPage.tsx`:
  - `useSearchParams()`で`redirect`クエリを取得し、`getSafeRedirectTarget()`で検証した値を
    `safeRedirect`として保持する(無効なら`null`)。
  - ローカルログイン成功時: `navigate(safeRedirect ?? '/', { replace: true })`。
  - Microsoftログインボタン押下(`handleLogin`)時: `login()`を呼ぶ前に、`safeRedirect`が
    あれば`sessionStorage.setItem(POST_LOGIN_REDIRECT_STORAGE_KEY, safeRedirect)`で保存し、
    なければ既存キーを`removeItem`しておく(前回の値が残らないようにする)。
  - `useEffect`でオンボーディング要の場合に`/onboarding`へ飛ばす既存分岐は変更しない
    (オンボーディング未完了ユーザーには今回のredirect復帰は適用しない)。
- `frontend/src/pages/auth/AuthCallbackPage.tsx`:
  - `completeLogin(code)`成功後、`sessionStorage.getItem(POST_LOGIN_REDIRECT_STORAGE_KEY)`を
    読み出し、`removeItem`で消費した上で`getSafeRedirectTarget()`に再度通す(defense in
    depth)。有効なら`navigate(target, { replace: true })`、無効/未設定なら
    `navigate('/', { replace: true })`。
- `frontend/src/auth/AuthContext.tsx`・`frontend/src/api/auth.ts`・バックエンドは変更しない。
- `RequireAdminRoute`(権限不足時の`/`への遷移)は認証ではなく認可の分岐であり対象外。

## 対象外
- バックエンド(`backend/`)の変更(論点4の通り不要と判断)。
- 権限不足(`RequireAdminRoute`)によるリダイレクトへの同様の対応。
- ログアウト時の遷移先制御。
- `redirect`パラメータをsessionStorage以外(例: DBやCookie)へ永続化すること。

## ドキュメントへの影響
`docs/06-usecases-auth.md` UC-001にログイン後の遷移先(リダイレクト元URLへの復帰)に関する
記載がないため、UC-001の末尾に1行、以下を追記する:
「ログイン開始時に`/login?redirect=<元のURL>`が指定されている場合、ログイン成功後は
そのURL(自サイト内の相対パスに限る)へ遷移する。」

## モック・アセット
なし

## 実装対象
- `frontend/src/auth/redirectTarget.ts`(新規、要: story/test不要な純粋関数ユーティリティ。
  `frontend/src/auth/redirectTarget.test.ts`をあわせて追加しValidationルールを検証する)
- `frontend/src/auth/RequireAuth.tsx`(修正)
- `frontend/src/pages/auth/LoginPage.tsx`(修正)
- `frontend/src/pages/auth/AuthCallbackPage.tsx`(修正)
- `docs/06-usecases-auth.md`(UC-001に1行追記)

## 検証方法
- `cd frontend && npm run test`(新設する`redirectTarget.test.ts`を含む既存Vitestスイート)
- `cd frontend && npm run build`(型チェック)
- 手動確認(可能であれば): 未ログイン状態で`/attendance/today`等の保護URLへ直接アクセス→
  `/login?redirect=...`へ遷移することを確認→ローカルログイン(SSO未設定環境)で
  ログイン後に`/attendance/today`へ戻ることを確認。SSO往復は環境上再現困難な場合、
  `sessionStorage`書き込み/読み出しロジックの単体テストで代替する。

## レビュー履歴
- 初版。
- 2026-09-13: 論点4を修正。「Laravelで最初に有効化するところ」の意図はバックエンド不要の
  結論ではなく、Laravel標準の`intended URL`パターン相当の考慮をReact側に実装してほしいと
  いう意味だったとユーザーより確認。実装方針(論点1〜3)自体はこれと合致しているため、
  技術的な実装対象・結論に変更なし。

## 実装結果
未着手。
