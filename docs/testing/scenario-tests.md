# シナリオテスト計画

ローカル環境(docker compose / devcontainer + モックOIDC)が動く状態になったことを前提に、
実際の業務の流れに沿ってシステム全体を通しで確認するためのシナリオテスト計画。
`docs/06`〜`docs/15` の個別ユースケースを「一連の業務フロー」として繋げて検証する。

## 1. 目的・方針

- 個々のユースケース(UC-A001 等)は既に `backend/tests/Feature/*` の自動テストで検証されて
  いるが、それらは機能単体の正しさを保証するもので、**実際の運用フロー(マスタ設定→日々の
  利用→月締め→バックオフィス処理完了)を人がやる順番で通しても壊れないか**は別途確認が要る。
- このドキュメントは (1) シナリオの一覧と手順、(2) 各シナリオの実行方法、(3) 今後のツール
  導入・PHP実装作り直しを見据えた設計方針、をまとめる。
- シナリオは **すべてブラックボックス(HTTP API / 画面操作)で完結させる**。Laravel の内部
  クラスを直接呼び出したり、DBを手で書き換えて確認したりしない
  (`stored_events` が正、Projectionは再生成可能という設計原則にも合致する)。これにより、
  将来バックエンドをPHP以外で作り直した場合でも、同じシナリオ・同じツールでそのまま
  回帰テストとして使い続けられる。

## 2. 前提環境

- devcontainer または `docker compose up -d mock-oidc` でモックOIDC(`http://localhost:9000`)
  を起動しておく。
- backend: `cd backend && composer install && cp .env.example .env && php artisan key:generate
  && touch database/database.sqlite && php artisan migrate --seed && php artisan serve`
  - `migrate --seed` で `DatabaseSeeder`(ロール・申請種別マスタ・admin@example.com)が入る。
  - 追加で `php artisan db:seed --class=ScenarioSeeder` を実行すると、本ドキュメントの
    シナリオに必要な最小マスタデータ(カレンダー・勤務形態・有給付与ルール・登場人物の
    ユーザー・勤務予定・有給付与)が投入される(§4参照)。何度実行しても安全(冪等)。
- frontend: `cd frontend && npm install && cp .env.example .env && npm run dev`
- ログインは実際のMicrosoft Entra IDではなく `mock-oidc/` のログイン画面でダミーユーザーを
  選択する(`docs/06-usecases-auth.md` UC-001、README.md参照)。
- **`backend/.env` の `APP_URL` は `php artisan serve` の待受ポート(既定8000)まで含めて
  設定すること**(例: `http://localhost:8000`)。`MICROSOFT_REDIRECT_URI` が `APP_URL` を
  元に組み立てられるため、ポート番号が抜けているとモックOIDCからのコールバックが
  `http://localhost/api/auth/microsoft/callback`(80番ポート)に飛んでしまい、SSOログインが
  失敗する。本ドキュメント作成時に判明したため `.env.example` は修正済みだが、既存の
  `.env` を使い回している場合は要確認。
- `admin@example.com`(`DatabaseSeeder`が作成する管理者)はMS365由来ではなく
  `UserFactory`でランダムな`entra_user_id`を持つため、そのままではモックOIDCで
  ログインできない。`ScenarioSeeder`実行時に `entra_user_id` を
  `mock-oidc/server.js` の `mock-entra-admin` エントリに合わせて上書きするので、
  シナリオ実行時は必ず一度 `ScenarioSeeder` を実行してから管理者ログインを試すこと。

## 3. 登場人物

`ScenarioSeeder` が `mock-oidc/server.js` の追加ユーザー(mock-entra-user-004〜009)と
同じ `entra_user_id`/emailで事前にユーザーを作成しロール・入社日を設定する。モックOIDCの
ログイン画面で該当ユーザーを選ぶと、初回ログイン処理ではなくこの事前設定済みユーザーとして
ログインできる。

| 役割 | 氏名 | メール | ロール | 用途 |
|---|---|---|---|---|
| 打刻ユーザー | 高橋 健太 | kenta.takahashi@example.com | employee | 日次打刻で勤怠をつける社員 |
| 月次入力ユーザー | 伊藤 舞 | mai.ito@example.com | employee | 打刻せず月次でまとめて日次実績を入力する社員 |
| 承認者 | 渡辺 直樹 | naoki.watanabe@example.com | employee | 勤怠・有給・申請の承認者(都度指定) |
| 経理担当者 | 小林 誠 | makoto.kobayashi@example.com | accounting_staff | 交通費等のバックオフィス処理 |
| 総務担当者 | 中村 恵 | megumi.nakamura@example.com | general_affairs_staff | 名刺申請のバックオフィス処理 |
| 人事担当者 | 加藤 由美 | yumi.kato@example.com | hr_staff | 入社日設定・有給付与・月次締め |
| 管理者 | Test Admin | admin@example.com | admin | マスタ設定全般・監査ログ |

既存の `mock-entra-user-001〜003`(山田太郎・佐藤花子・鈴木一郎)は上記シナリオに含めず、
差し戻し・エラー系など追加のアドホックなテストに自由に使える枠として空けておく。

## 4. シナリオ一覧

各シナリオは「前提」「手順」「確認ポイント」で構成する。手順は画面操作を主体に書くが、
括弧内に対応するAPIエンドポイントも併記するので、API直叩きでの実行にも使える。

### シナリオ0: 初期マスタ設定

管理者・人事担当者が行う、他の全シナリオの前提となる設定。`ScenarioSeeder` で自動投入
できるが、**初回は必ず一度、画面から手作業でも実施して管理画面が壊れていないか確認する**。

1. 管理者でログインし、システム設定(タイムゾーン)を確認する(`GET/PUT /system-settings`)。
2. 年度カレンダーを作成→休日を設定→公開する(UC-C001、`POST /work-calendars` →
   `PUT /work-calendars/{id}/days` → `POST /work-calendars/{id}/publish`)。
3. 勤務形態を2種類作成する: 「標準勤務(打刻)」「標準勤務(月次入力)」
   (UC-C002、`POST /work-styles`)。この2つは打刻するかどうかで**運用を分けるだけ**で、
   スキーマ上の`attendance_mode`のようなフラグはない(打刻APIを使うか、日次編集APIを
   使うかという操作の違いのみ)ことに注意。
4. 対象の社員に入社日を設定する(UC-P002、`PUT /users/{id}/hire-date`)。
5. 有給付与ルールを作成する(UC-P001、`POST /paid-leave/grant-rules`)。
6. 対象社員に有給を付与する(UC-P002、`POST /paid-leave/grants`)。
7. 申請種別マスタ(交通費精算・名刺申請 等)を確認する(UC-W001、`GET /request-types`)。
   `RequestTypeSeeder` で初期投入済みのため、ここでは中身の確認と、必要なら
   `RequestTypeListPage`/`RequestTypeEditPage` からの追加・修正ができることを確認する。
8. 社員のシフト(勤務予定)を対象月分まとめて生成する(`POST /employee-shift-assignments/generate`)。

**確認ポイント**: カレンダー未公開のまま勤務形態が参照できないか、勤務予定生成が
カレンダーの休日設定を正しく反映しているか。

### シナリオ1: 打刻ユーザーの1か月勤怠

打刻ユーザー(高橋健太)が実際に毎日打刻し、月末に月次提出→承認→締めまで通す。

1. 平日ごとに出勤〜退勤を打刻する(UC-A001〜A004):
   `POST /attendance/clock-in` → (任意で) `POST /attendance/break/start` →
   `POST /attendance/break/end` → `POST /attendance/clock-out`。休憩を挟む日、
   残業になる日、遅刻になる日など複数パターンを混ぜる。
2. 週次画面で1週間分の実績を確認する(UC-A006、`GET /attendance/week`)。
3. 打刻を忘れた日を想定し、日次編集で後から補正する(UC-A005、`PUT /attendance/days/{id}`)。
4. スマホ等の別打刻端末を想定し、`POST /attendance-punches` でも打刻ログを送り、
   `attendance_days` に矛盾なく反映される(または反映されず要確認扱いになる)ことを
   確認する(UC-A012)。
5. 月末に月次実績を提出する(`POST /attendance/months/{yearMonth}/submit`)。
6. 承認者(渡辺直樹)で承認する(`POST /attendance-months/{id}/approve`)。
7. 人事担当者(加藤由美)で月を締める(`POST /attendance-months/{id}/close`)。

**確認ポイント**: 日次の実績→週次表示→月次集計の数値が一致しているか、締め後は
編集できなくなるか。

### シナリオ2: 月次入力ユーザーの1か月勤怠

月次入力ユーザー(伊藤舞)は打刻APIを一切使わず、月末にまとめて日次実績を入力する。

1. 打刻は行わず、対象月の営業日ぶん `PUT /attendance/days/{id}` で出勤時刻・退勤時刻・
   休憩時間を1日ずつ入力する(UC-A005)。
2. 週次画面(`GET /attendance/week`)で入力済みの日と未入力の日が分かることを確認する。
3. 月末に月次提出→承認者承認→人事締め、までシナリオ1と同じ流れで実施する。

**確認ポイント**: 打刻ユーザーと月次入力ユーザーが同じ `attendance_days`/月次集計の
仕組みに乗っており、UI上は入力方法が違うだけで結果整合していること
(`attendance_days.source` が `live`/`punch` ではなく `manual` になっていることも確認)。

**⚠️ E2Eテスト実装時に判明した既知の欠落機能(要対応判断)**: 手順1は現状のAPI/画面では
実施できない。`PUT /attendance/days/{id}` はLaravelのroute-model bindingで既存の
`attendance_days` 行のIDを要求するため
(`backend/app/Domain/Attendance/Handlers/EditAttendanceDayHandler.php`)、
まだ1度も打刻・編集していない日の行を新規作成する手段が存在しない。`attendance_days`
行は (1) 打刻(`ClockInHandler`)、(2) 有給申請の承認時の自動作成
(`ApprovePaidLeaveRequestHandler::reflectOnAttendanceDay`) の2経路でしか作られず、
フロントエンドの週次勤怠画面(`WeekAttendancePage`)もその行が無い日には「編集」ボタン
自体を出さない。**つまり打刻を一切しない社員は、有給を取った日以外は勤怠を入力できない**。
`frontend/e2e/scenario-02-monthly-attendance.spec.ts` にこの現状挙動を固定するテストを
用意した。月次のみ入力する運用を実際にサポートするなら、「日次実績を新規作成するAPI」
(例: `POST /attendance/days`)を追加する必要がある。

### シナリオ3: 勤怠管理中の有給消化

シナリオ1・2 実施中の月内で、有給を1日消化するケースと半日消化するケースを混ぜる。

1. 対象社員が有給残数を確認する(UC-A007、`GET /paid-leave/grants/mine`)。
2. 終日有給を申請する。承認者を指定する(UC-P003、`POST /paid-leave/requests`、
   `leave_type=full`)。
3. 承認者が承認する(UC-P004、`POST /paid-leave/requests/{id}/approve`)。
4. 承認後、対象日の `attendance_days.work_type` が `paid_leave_full` になり
   `status` が `clocked_out` 扱いになっていることを日次・週次画面で確認する
   (打刻していないのに「未退勤」警告が出ないこと)。
5. 別の日に半休(`am_half`または`pm_half`)を申請〜承認し、同様に確認する。
6. 有給残数(`paid_leave_grants`)が消化ぶん減り、`paid_leave_usages` に消化履歴が
   残ることを確認する。
7. 月次提出時、有給消化日を含んだ状態で月次実績が正しく集計されることを確認する
   (既知の注意点: `attendance_daily_calculations` は現状、有給時間を実労働時間には
   加算しない仕様。集計結果を見て「想定通りの挙動か」をこのシナリオで再確認する)。
8. 差し戻しパターンとして、承認者が有給申請を差し戻し(`POST .../return`)、
   本人が再申請または取消(`POST .../cancel`)する流れも1回試す。

### シナリオ4: 交通費申請

打刻ユーザーが月内の出張で発生した交通費を精算申請する。

1. 申請種別「交通費精算」(`commuting_expense`)を選び、金額・経路を入力して下書き作成する
   (UC-W002、`POST /workflow-requests`)。
2. 承認者を指定して提出する(`POST /workflow-requests/{id}/submit`)。
3. 承認者が承認する(UC-W003、`POST /workflow-requests/{id}/approve`)。
4. 承認により自動生成される経理向けバックオフィスタスク(`task_type=expense_reimbursement`)
   を確認する(UC-B001、`GET /backoffice-tasks/unassigned`)。
5. 経理担当者(小林誠)がタスクを自分に割り当て(UC-B002、`POST .../assign`)、
   処理ステータスを進めて完了にする(UC-B003、`POST .../status`、
   `processing`→`payment_scheduled`→`completed` など)。
6. 経理担当者が経費CSVを出力し、金額が含まれることを確認する(UC-E001、
   `GET /exports/expenses`)。**2026-07-10時点でこの出力用の画面はまだ実装されて
   おらず、APIを直接呼ぶ以外に確認方法がない**。フロントエンドにCSV出力画面
   (`AttendanceExportPage`相当のもの)を追加するかどうかは別途検討する。
7. 申請者が申請一覧・履歴でステータス変遷を確認する(`GET /workflow-requests/{id}/history`)。

**確認ポイント**: `workflow_requests.status` と `backoffice_tasks.status` が独立した
ステータス系列として管理され、どちらか一方だけを見て「完了」と誤認しないか。

### シナリオ5: 名刺の申請〜作成・発行

新入社員(想定として月次入力ユーザー・伊藤舞を使う)が名刺を申請し、総務が発注〜発送まで
処理する一連の流れ(UC-B005)。

1. 申請種別「名刺申請」(`business_card`)で枚数を指定し下書き作成〜提出する(UC-W002)。
2. 承認者が承認する(UC-W003)。承認により総務向けバックオフィスタスク
   (`task_type=business_card`)が自動生成されることを確認する(UC-B001)。
3. 総務担当者(中村恵)が未担当タスク一覧からタスクを確認し、氏名・部署・役職・メール・
   電話番号など名刺記載情報を確認する(UC-B005 手順1〜2)。
4. ステータスを `in_review`(担当) → `processing`(発注データ作成) → `ordered`(発注済み)
   → `shipped`(発送済み) → `completed`(完了)の順に進める(UC-B005 手順3〜6、
   `POST /backoffice-tasks/{id}/status`)。
5. 各ステータス変更でTeams通知ジョブがキューに積まれることを確認する
   (UC-N001、`jobs`テーブルまたは`schedule:work`実行後のログ)。
6. 申請者が自分の申請一覧で「完了」になっていることを確認する。

**確認ポイント**: ステータス遷移が `BackOfficeTaskStatus::all()` の定義通りに1段階ずつ
進められること、不正な遷移(例: `not_started`から`completed`へ一気に飛ばす)を弾くか
どうかは現状のバリデーションでは許容されている点にも注意(値が有効なステータス文字列で
あることしかチェックしていないため、業務手順としての遷移順はUI/運用でのみ担保している)。

**⚠️ E2Eテスト実装時に判明した既知の欠落機能**: UC-B005手順1〜2(氏名・部署・役職・
メール・電話番号、および申請内容(枚数)の確認)は、この画面だけでは行えない。
`BackOfficeTaskResource`(`backend/app/Http/Resources/BackOfficeTaskResource.php`)は
`title`/`task_type`/`assigned_department`/`assignee`/`due_on`/`completed_at` しか
返しておらず、申請者本人の情報や元の`workflow_requests.form_data`(枚数)を含まない。
`BackOfficeTaskDetailPage`も「元データ: workflow_request #16」という文字列表示のみで、
その申請の詳細へ遷移するリンクすら無い。総務担当者が実際に名刺を発注するには、現状
`workflow_requests`側の申請詳細を都度探して照合する必要があり、UC-B005の運用にそのまま
使える状態ではない。対応案: (1) `BackOfficeTaskResource`に申請者情報・form_dataを含める、
または (2) `BackOfficeTaskDetailPage`から元の申請詳細ページへのリンクを追加する。

## 5. その他、用意しておくべきシナリオ

上記4つに加えて、少なくとも以下は用意する(優先度高い順)。

1. **承認差し戻し→再申請**(勤怠月次・有給・汎用申請それぞれ): `return`後に本人が
   修正して再提出できるか。差し戻しコメントが履歴に残るか。
2. **申請取消**: 提出後・承認後それぞれのタイミングでの `cancel`。承認後キャンセルが
   バックオフィスタスク作成後にどう影響するか(現状は取消してもタスクは残るはずなので、
   総務・経理側の運用手順として問題ないか要確認)。
3. **月次締め後の扱い**: 締めた月の日次実績を編集しようとしたときにブロックされるか
   (UC-A011)。
4. **打刻ログと日次実績の不一致**: 複数打刻・打刻漏れなど `attendance_punches` と
   `attendance_days` が食い違うケースの確認(UC-A012)。
5. **有給の自動失効警告・年5日取得義務警告バッチ**: `schedule:run` 経由のバッチ
   (`WarnExpiringPaidLeave`/`WarnFiveDayObligation`)を手動実行し、対象者に警告が
   飛ぶことを確認する(常駐workerではなくcron前提であることの確認も兼ねる)。
6. **権限変更**: 一般社員から `hr_staff`/`admin` へロールを追加/剥奪した際、
   即座に権限反映される(古いSanctumトークンのままでも新しい権限で403/200が
   切り替わる)ことを確認する(UC-M001)。
7. **監査ログ**: 上記シナリオ全体を通した操作が `audit-log` 一覧・CSV出力に
   もれなく記録されているか(UC-M003)。
8. **勤怠CSV出力**: 締めた月の勤怠CSVが打刻ユーザー・月次入力ユーザー両方について
   正しく出力されるか(UC-E001)。
9. **Entra ID初回ログイン(新入社員のオンボーディング)**: モックOIDCの未使用ユーザーで
   初回ログインし、`employee`ロールが自動付与されること、その後管理者が入社日・
   ロールを設定する一連の流れ(UC-001〜UC-M001)。
10. **同一申請への複数バックオフィス担当部署の混在**: 交通費と名刺を同時期に多数
    申請し、経理・総務それぞれの未担当一覧が正しくフィルタされているか
    (シナリオ4・5を並行して回すことで自然にカバーできる)。

## 6. 実行方法の検討

### 6.1 手動確認チェックリスト(必須・最初にやる)

自動化する前に、上記シナリオを**一度は人がブラウザで実施する**。理由は、自動テストは
「決めた通りに動くか」しか見ないが、シナリオ検証の初回は「そもそもこの手順で運用可能か
(画面の分かりやすさ・入力の手間・エラーメッセージの妥当性含む)」を見る必要があるため。
本ドキュメントの各シナリオの「手順」をそのままチェックリストとして使う。

### 6.2 自動化: Playwright によるE2Eテスト(推奨)

- frontend には既に `playwright`(ブラウザバイナリ)が依存関係として入っており
  (`@vitest/browser-playwright` 経由)、Storybookのブラウザテストで使われている。
  これとは別に **`@playwright/test` を追加し、`frontend/e2e/` 配下に独立したE2E
  テストスイートを置く**のがおすすめ。
  - Vitestのbrowser modeはコンポーネント単位のテスト用で、複数画面をまたぐ・複数
    ユーザーでログインし直す、といったシナリオテストには向かない。
  - Playwrightならモックログイン画面(`http://localhost:9000`)でのユーザー選択→
    フロントエンドへのリダイレクト→各画面操作、まで人の操作を忠実に再現できる。
  - 複数ロールでの検証(申請者→承認者→バックオフィス担当、で別人としてログインし
    直す)も `browser.newContext()` を人数分作ることで自然に書ける。
- 本PRで雛形を追加し、実際にローカルで起動して動作確認済み(`frontend/e2e/README.md`
  参照)。構成:
  - `frontend/e2e/playwright.config.ts`: baseURL(frontend dev server)。
  - `frontend/e2e/support/auth.ts`: モックOIDCでの疑似ログインを1関数にまとめる
    (「氏名を指定してログインする」ヘルパー`loginAs`)。
  - シナリオ0〜5・その他(§5)におおよそ対応する `frontend/e2e/*.spec.ts` を
    1シナリオ1ファイルで用意し、本ドキュメントの章番号をコメントで参照させる。
  - 実装・グリーン確認済み: シナリオ0(マスタ画面遷移、カレンダー作成〜公開〜勤務
    形態作成〜シフト生成〜有給付与ルール作成〜手動付与)、シナリオ1(打刻ユーザーの
    出勤〜退勤1日ぶん)、シナリオ3(有給残数画面表示、終日/半休の申請〜承認〜勤怠反映)、
    シナリオ4(交通費申請〜承認〜経理タスク処理〜経費CSV確認)、シナリオ5(名刺申請〜
    承認〜総務タスク処理)、その他(§5-1差戻し→再申請、§5-2申請取消、§5-3月次締め後の
    編集制限、§5-4打刻ログ突合、§5-6+7ロール変更の即時反映と監査ログ記録、§5-8締めた
    月のCSV出力、§5-9新入社員の初回ログイン)。
  - シナリオ2(月次入力ユーザーの日次入力)は、実装の過程で「打刻していない日は
    そもそも編集手段が無い」という欠落機能が判明したため、その現状挙動を固定する
    テストに差し替えている(§4シナリオ2内の注記を参照)。
  - §5-3の実装過程で、`GET /attendance/months/to-approve`が「自分が承認者かつ
    submitted」のみを対象にしており、UC-A011が想定する「管理部(admin・hr_staff)が
    承認者を問わず全社員の承認済み月次を締める」という導線が実際には到達不能な
    バグだったため、backend側を修正した(admin・hr_staffにはapproved月次も追加で
    返す。回帰テストは`backend/tests/Feature/AttendanceFlowTest.php`に追加)。
  - 未実装(`test.skip`のTODO): §5-5(有給失効・年5日警告バッチ)のみ。artisanコマンド
    自体はあるが送信結果を確認できるAPI・画面が無く、ブラックボックスE2Eでは検証
    できない(通知履歴を返すAPIの追加が前提)。
- CIには繋がず、まずはローカルで `npm run test:e2e` として都度実行する運用から
  始め、安定してきたらGitHub Actionsに追加を検討する。

### 6.3 補助: バックエンドAPIのみを叩く確認(任意)

- 画面を介さずAPIの組み合わせだけを素早く確認したい場合向けに、Postman/Bruno等の
  HTTPクライアントで交換可能なコレクションを用意してもよい。ただしSSOの2段階
  (`/auth/microsoft/redirect` → モックOIDCでの選択 → `/auth/microsoft/callback` →
  `/auth/token`)をコレクション側で自動化するのはPlaywrightよりも面倒なため、
  優先度はPlaywrightより下げる。バックエンドは既に `l5-swagger` でOpenAPIドキュメントを
  自動生成しているので、疎通確認程度ならSwagger UI(`/api/documentation`)からの
  手動実行でも十分。

### 6.4 既存の自動テストとの役割分担

- `backend/tests/Feature/*`(PHPUnit): ユースケース単体の正しさを保証する回帰テスト。
  今後も機能追加のたびにここを拡充する。
- `frontend/src/**/*.test.tsx`(Vitest) / Storybook: コンポーネント単体の見た目・
  挙動を保証する。
- 本ドキュメントのシナリオテスト(Playwright): 上記2つでは拾えない「複数画面・
  複数ユーザー・複数日にまたがる業務フロー全体の整合性」を保証する。3層は互いに
  代替ではなく、テストピラミッドとして併存させる。

## 7. 今後のPHP実装作り直しを見据えて

「PHP部分は作り直すことも含めて検討する」という前提があるため、シナリオテスト自体を
実装の入れ替えに耐える資産にしておく。

- **本ドキュメントのシナリオ・Playwright E2Eテストは、HTTP API(またはブラウザ操作)
  だけに依存し、Laravelの内部実装(Eloquentモデル・CQRSのCommand/Event等)には
  一切依存しない。** バックエンドを別言語・別フレームワークで作り直しても、
  API契約(`docs/16-database-schema.md`のテーブル定義相当のレスポンス形状、
  `routes/api.php`のエンドポイント)さえ維持すれば、このシナリオテスト一式は
  そのまま回帰テストとして使い続けられる。
- 既存の `l5-swagger` によるOpenAPIドキュメントは、作り直し後の実装が同じ
  APIコントラクトを満たしているかを機械的に検証する契約テスト
  (例: Dredd、schemathesis等でOpenAPI定義に対するレスポンス検証)の元データとしても
  流用できる。作り直しプロジェクトの初期タスクとして「現行のOpenAPI定義をゴールデン
  マスターとして固定し、新実装がそれを満たすことを契約テストで確認する」進め方を
  推奨する。
- 逆に、**PHPUnitのFeatureテストはLaravelの実装に強く依存する**ため作り直し時に
  そのまま使い回せない。作り直しの際は「本ドキュメントのシナリオ + OpenAPI契約」を
  正として新実装のテストを書き直す前提で計画するとよい。
- `stored_events`(EventStore)を正とする設計のため、作り直し後もイベント一覧
  (`docs/17-events.md`)と集約単位のコマンド/イベント定義を維持すれば、
  Projection再生成や監査ログの考え方はそのまま移植しやすい。作り直しの際は
  「テーブルスキーマの移植」ではなく「イベントスキーマの移植」を優先すると
  この資産(シナリオテストや監査ログの過去データ)を失わずに済む。
