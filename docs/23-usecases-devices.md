# 23. 端末管理ユースケース

打刻専用の端末エンティティを作らず、共有Android打刻リーダー・ユーザー個人端末・NFC/生体認証
端末等の外部端末を共通の`devices`モデルで扱う(docs/16-database-schema.md「端末・認証キー・
アプリ連携」、CLAUDE.mdの設計原則12)。端末には所有区分(`owner_type`)と役割(`device_roles`、
複数可)を設定し、役割によって利用可能な操作を制限する。

## UC-D001: 共有端末を登録する

1. 管理者が端末管理画面を開く
2. 「共有端末を追加」を選択する
3. 端末名、端末種別(`device_type`)、端末役割(`device_roles`、複数可)を設定する
4. 所属事業所(`site_id`)、設置場所(`location_name`)を設定する
5. 利用可能な打刻種別(`allowed_punch_types`)、利用可能なユーザー範囲を設定する
6. この端末で打刻した場合に自動反映する勤務形態区分(`default_work_location_type`)を
   任意で設定する(docs/07-usecases-attendance.md「勤務形態区分」参照)
7. オフライン利用可否(`allow_offline`)、位置情報必須可否(`require_location`)、
   自動判定可否(`auto_detect_punch_type`)を設定する
8. `device.registered`イベントを記録する
9. `is_active`(内部的には`status=pending_pairing`)で保存し、UC-D002のペアリングへ進む

関連イベント: `device.registered`
関連テーブル: `devices`, `device_roles`

`devices`は`request_types`/`work_styles`と同じ「管理者が設定する参照データ」に分類し
(`owner_type=organization_shared`の場合)、Command/EventStoreを経由せずEloquentで直接更新する
運用でもよいが、紛失・盗難時の追跡可能性を重視し、本ドキュメントでは登録・ペアリング・停止・
失効の主要な状態遷移だけは`device.*`イベントとして`stored_events`に残す方針とする(UC-M003の
監査ログ画面でそのまま参照できるようにするため)。

## UC-D002: 共有端末をペアリングする

匿名の「ペアリングコード交換」API(誰でも呼び出せる、`device_id`+コードだけで認可する方式)は
持たない。管理者の認証済みSanctumトークン(`role:admin`)だけを認可根拠にし、その場で
短命な一時トークン(claim token)を発行してQRコードとして端末アプリへ渡す
「2段階トークン交換」方式を採用する。非対称鍵(端末が鍵ペアを生成しサーバーへ公開鍵を
登録する方式)は採用せず、既存のSanctum Bearerトークン基盤(トークンの`expires_at`・
`tokens()->delete()`)に寄せることで新しい署名検証基盤を増やさない。

1. 管理者がUC-D001で登録した端末に対し、一時ペアリングトークン(claim token)を発行する
   (`POST /devices/{device}/pairing`。`role:admin`ミドルウェアで保護。管理者本人の
   トークンをそのまま端末へ渡すのではなく、`device:claim-pairing`abilityのみを持つ
   5分間有効なSanctumトークンを新たに発行する。`IssueDevicePairingClaimHandler`)
2. 管理者の画面がclaim tokenをQRコード(`{url, claim_token}`等)として表示する。端末IDは
   QRに含めない(サーバー発行のdevice_id(内部PK)をQR/人手で運ばせない。claim token自体が
   Sanctumの`personal_access_tokens`に紐づく識別子を兼ねる)
3. Androidアプリ(または対象端末)がQRを読み取り、`Authorization: Bearer <claim_token>`を
   付けて`POST /devices/pairing/claim`を呼ぶ(`auth:sanctum`+
   `ability:device:claim-pairing`で保護。呼び出し元が有効なclaim tokenの持ち主で
   あることが、この時点で認証済み)
4. サーバーは対象`devices`行の`device_roles`に応じたability(例: `ATTENDANCE_READER`役割
   なら`recorder:punch`)を持つ本トークンを発行する(`$device->createToken('device',
   $abilities)`。`devices`モデルにもSanctumの`HasApiTokens`を付与し、User以外の任意の
   Eloquentモデルにもトークンを発行できるSanctumの仕組みをそのまま使う)。claim token
   自体も含め、この端末の既存トークンはすべて入れ替える(`ClaimDevicePairingHandler`)
5. 平文の本トークンは端末アプリへの応答として一度だけ返す(以降、端末はこのトークンを
   `Authorization: Bearer`として全リクエストに付与する)。この時点で`status=active`にする
6. `device.paired`イベントを記録する
7. 以降、端末アプリは`POST /devices/heartbeat`で定期的に疎通確認(`last_seen_at`/
   `app_version`の更新)を送る。これは高頻度な運用テレメトリでありCommand/EventStoreを
   経由しない(業務上の事実ではないための意図的な例外。system_settings/request_typesと
   同じ「マスタ的な設定の直接更新」の扱いに準ずる)
8. 管理者が端末管理画面で最終通信日時が更新されたことを確認する

端末の停止・失効(UC-D005)時は、この端末のSanctumトークンを`tokens()->delete()`で
すべて削除する。未使用のclaim tokenが残っていても、これにより自動的に無効化される。

関連イベント: `device.paired`
関連テーブル: `devices`

### カメラのない端末でのペアリング

claim tokenはQR専用の値ではなく、管理画面上でテキストとしてもコピーできる(一度だけ表示)。
カメラを持たない端末は種類によって次のように使い分ける。

- キーボード入力・コピー&ペーストができる端末(Windows/macOS/Linux・Webブラウザ端末等):
  管理画面の「コピー」ボタンでclaim tokenをコピーし、端末側のセットアップ画面(ローカルの
  管理Web UI等)に貼り付ける。バックエンドの扱いはQRと完全に同じ(同じclaim tokenを使う)
- カメラもキーボードもない組込み機器(NFCリーダー・指紋/顔認証端末・入退室管理端末等):
  端末自身がAPIを直接呼べないことが多いため、設置作業者のノートPC等が代理でclaim tokenを
  使って`POST /devices/pairing/claim`を呼び、得られた本トークンをローカル接続
  (USB・シリアル・ローカルWiFi等、端末メーカー固有のプロビジョニング手段)で端末へ
  書き込む運用とする。この手順自体はUC-D004の外部端末登録と同様、端末メーカー固有の
  詳細設計であり本ドキュメントのスコープ外とする(匿名のコード交換APIを別途用意する
  ことはしない。認可根拠を常に管理者の認証済みトークンに一本化するため)

このトークンには、その端末の`device_roles`に対応するability以外を持たせない(最小権限。
`ATTENDANCE_READER`役割の端末は打刻のみ、`ADMIN_OPERATION`役割を持たない端末は管理系API
(端末管理・認証キー管理・月次締め等)を呼び出せない)。既存のリポジトリはSanctumの
ability(`tokenCan()`)を検証していなかったため、`App\Http\Middleware\EnsureFullAccessOrExplicitAbility`
をAPI全体のグローバルミドルウェアとして追加した。ability`*`を持たない(=限定スコープの)
トークンは、ルートに`ability:`/`abilities:`ミドルウェアが明示的に付与されている場合のみ
アクセスできる(デフォルト拒否)。既存の人間向けトークン(`createToken('api')`、ability`*`)
には影響しない(実装済み。Phase 1)。

## UC-D003: 個人端末を登録する

1. ユーザーが勤怠管理アプリへログインする(既存の個人Sanctumトークンで認証済み)
2. 個人設定から「端末を登録」を選択する
3. 端末名を設定する
4. `POST /users/me/devices`を呼び出し、`owner_type=personal`・`owner_user_id=自分`の
   `devices`行を作成する
5. サーバーが、本人の打刻専用ability(`punch:self`)のみを持つSanctumトークンを発行する
   (UC-D002のclaim token交換は不要。既にログイン済みの本人操作のため)
6. 利用規約と、この端末で可能な操作(下記)を画面に表示し、ユーザーが確認する
7. `device.registered`イベントを記録し、`status=active`にする

関連イベント: `device.registered`
関連テーブル: `devices`

### 個人端末で可能な操作

本人の情報だけを対象とする。

- 自分の出勤・休憩開始・休憩終了・退勤(打刻)
- 自分の勤怠閲覧
- 自分の勤怠修正申請・休暇申請
- 自分の月次勤怠下書き確認・月次勤怠申請

他人の打刻や勤怠編集は許可しない(`punch:self`abilityはトークンの`tokenable`(自分自身)
以外のユーザーを対象にできない)。

## UC-D004: 外部端末を登録する

NFCリーダー・指紋認証端末・顔認証端末・入退室管理端末・他社勤怠システム・Webhook送信元等、
Android以外の機器も共通の`devices`モデル(`device_type`の該当する値、`owner_type=organization_shared`)
で登録する。

1. 管理者が端末管理画面で「外部端末を追加」を選択する
2. 端末種別・端末役割(`EXTERNAL_EVENT_SOURCE`等)・設置場所を設定する
3. 認証方式を選択する(下記のいずれか)
4. 選択した認証方式に応じた認証情報を発行する
5. 付与するAPIスコープ(`device_scopes`)を設定する(`attendance:clock` /
   `attendance:read_current_state` / `attendance:read_result` / `identity:resolve` /
   `device:heartbeat` 等)
6. `device.registered`イベントを記録する
7. 疎通確認を行い、管理者が有効化する

関連イベント: `device.registered`, `device.scope_granted`
関連テーブル: `devices`, `device_scopes`

### 認証方式

外部端末ごとに次のいずれかを選べるようにする(固定のAPIキーだけに依存しない)。

- Sanctumトークン(UC-D002と同じ発行方式。既定の推奨方式)
- APIキー(ヘッダー送信。ハッシュ化して保存し、生値は発行時のみ表示する。
  `authentication_keys`の`key_hash`と同じ考え方を流用する)
- 署名付きリクエスト(将来拡張。本ドキュメントでは詳細設計をスコープ外とする)

外部端末には`device_scopes`で限定的なスコープのみを付与し、月次締めや管理者補正等の
管理系APIは呼び出せないようにする(UC-D002の「最小権限」原則と同じ)。

## UC-D005: 端末を停止・失効する

1. 管理者(個人端末の場合は本人でも可)が対象端末を選ぶ
2. 「停止」または「失効」を選ぶ(停止=一時的、失効=紛失・盗難等で恒久的に無効化)
3. 対応するSanctumトークンを削除する(`$device->tokens()->delete()`)
4. `device.disabled`または`device.revoked`イベントを記録する
5. 失効した端末を再度使う場合は、UC-D001/UC-D002から登録し直す

関連イベント: `device.disabled`, `device.revoked`
関連テーブル: `devices`

## 端末管理画面(UI)

### 管理者向け

- 端末一覧(端末名・所有区分・端末種別・役割・事業所・状態・最終通信・アプリバージョン)
- 端末詳細、共有端末追加、ペアリング用QR(claim token)表示
- 端末権限(役割・スコープ)設定、設置場所設定
- 端末停止・失効、監査履歴(UC-M003の監査ログ画面から`aggregate_type=device`で参照)

### ユーザー向け(個人端末)

- 自分の端末一覧、個人端末登録、端末名変更
- 最終利用日時、ログアウト(=UC-D005の停止)、端末失効

## 打刻フローとの関係

実際の打刻操作(出勤・休憩開始・休憩終了・退勤、自動判定/手動選択、オフライン対応)は
`docs/07-usecases-attendance.md`のUC-A012「打刻ログを記録する」を参照。端末認証(本ドキュメント)
と打刻の身元解決(`docs/24-usecases-authentication-keys.md`)は別の関心事として分離している
(docs/03-architecture.md 3.5)。
