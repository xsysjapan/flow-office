# 24. 認証キー管理ユースケース

ユーザー本人を識別するための外部識別情報(NFCカードのUID、生体認証端末が発行する外部利用者ID、
QRコード、FIDOクレデンシャル等)を、NFCカード専用ではない汎用の`authentication_keys`として
管理する(docs/16-database-schema.md、CLAUDE.mdの設計原則12)。

## UC-K001: 認証キーを本人が登録する

1. ユーザーが「認証キー管理」画面を開く(既存の個人Sanctumトークンで認証済み)
2. 「認証キーを追加」を選択する
3. キー種別(`key_type`)を選択する(`nfc_uid` / `qr_code` / `fingerprint_external_id` 等)
4. 対応端末でキーを読み取る、またはキーの値を入力する
5. キーの表示名(`display_name`)を設定する
6. 利用可能な端末・事業所(`authentication_key_device_rules`)を任意で設定する
7. 有効期間(`valid_from`/`valid_until`)を任意で設定する
8. サーバーが `key_hash = HMAC-SHA256(app_secret, normalize(input))` を計算し、
   生値を保存せずハッシュ値のみを保存する(下記「セキュリティ要件」参照)
9. サーバーが、同じ`key_type`+`key_hash`の組で、有効な(`status=active`かつ有効期間内の)
   行が他ユーザーに存在しないことを検証する(`AUTHENTICATION_KEY_DUPLICATED`エラー)
10. `authentication_key.issued`イベントを記録し、`status=active`にする

関連イベント: `authentication_key.issued`
関連テーブル: `authentication_keys`, `authentication_key_device_rules`

## UC-K002: 管理者が代理登録する

管理者は、本人確認済みであることを前提に代理登録できる(UC-K001と同じ手順を管理者が
対象社員を指定して実行する)。登録主体がユーザー本人か管理者かを、`authentication_key.issued`
イベントの`registeredByUserId`として監査ログへ残す。

## UC-K003: 認証キーを無効化する

1. 本人または管理者が対象の認証キーを選ぶ(紛失・退職・交換等を想定)
2. 「無効化」を実行する
3. `authentication_key.disabled`イベントを記録し、`status=disabled`にする
4. 無効化以降、このキーでは打刻(docs/07-usecases-attendance.md UC-A012の身元解決)が
   一切成立しなくなる

関連イベント: `authentication_key.disabled`
関連テーブル: `authentication_keys`

退職者の認証キーは、退職処理(`termination_date`の設定)のオフボーディング手順に本UCの
無効化を含める運用を推奨する。

## セキュリティ要件

- **認証キーの生値をそのまま保存しない**。`normalize(input)`(全角/半角統一、大文字小文字統一等
  キー種別ごとの正規化)を行った上で`HMAC-SHA256(app_secret, normalized_key)`を計算し、
  ハッシュ値(`key_hash`)のみを保存する。検索・照合は常にハッシュ値で行う。
- 画面には`display_name`と、必要な場合のみキーの末尾数文字を表示する。生値は表示しない。
- **生体情報そのもの(指紋画像・顔画像・特徴量データ)を保存しない**。生体認証端末
  (docs/23-usecases-devices.md UC-D004の外部端末)側でテンプレート照合を完結させ、
  その端末が発行した「認証済み外部利用者ID」(`fingerprint_external_id`/
  `face_recognition_external_id`)と認証成功結果だけを受け取り、それを`authentication_keys`の
  識別値としてハッシュ化・登録する。個人情報保護法上の要配慮個人情報に準ずる機微情報の
  取り扱いを避けるための必須要件とする。
- 有効期限切れ・無効化済みのキーでは打刻できない(docs/07-usecases-attendance.md UC-A012の
  身元解決で`status=active`かつ有効期間内であることを検証する)。

## 認証キー管理画面(UI)

- 自分の認証キー一覧(表示名・種別・状態・有効期間・利用可能端末)
- 認証キー追加、表示名変更、利用可能端末・有効期間の確認
- 一時停止(`status=suspended`)、無効化(`status=disabled`)

キーの生値は表示しない(上記セキュリティ要件参照)。

## 打刻時の身元解決

docs/07-usecases-attendance.md UC-A012「打刻ログを記録する」の手順4(サーバーが打刻元から
受け取った値でユーザーを特定する)は、本ドキュメントの`authentication_keys`を次の順序で
参照する。

1. 端末(`devices`)から送信された認証キーの値を正規化し、`HMAC-SHA256`でハッシュ化する
2. `authentication_keys`で`key_hash`が一致し、`status=active`かつ有効期間内の行を検索する
3. `authentication_key_device_rules`が設定されている場合、打刻に使われた端末・事業所が
   許可されているか確認する(`USER_NOT_ALLOWED_ON_DEVICE`エラー)
4. 見つからない・条件を満たさない場合は`AUTHENTICATION_KEY_NOT_FOUND`
   /`AUTHENTICATION_KEY_DISABLED`エラーを返し、打刻ログには記録しない(`user_id`が
   解決できないため記録しようがなく、UC-A012の「矛盾があっても必ず記録する」原則の
   適用対象外であることを明記する)
5. 解決できた場合、`authentication_key_id`と対象`user_id`を伴って
   `RecordAttendancePunch`コマンドを呼び出す
