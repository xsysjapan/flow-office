# シナリオE2Eテスト (Playwright)

`docs/testing/scenario-tests.md` のシナリオをブラウザ操作で自動実行するためのテスト。
frontend の Vitest browser mode(Storybookのコンポーネントテスト用)とは別の、
独立したPlaywright E2Eテストスイート。

## 実行方法

1. `docker compose up` (または devcontainer) で backend・frontend・mock-oidc を起動する。
2. シナリオ用マスタデータを投入する:
   `docker compose exec app sh -c "cd backend && php artisan db:seed --class=ScenarioSeeder"`
   (devcontainer内のターミナルで実行する場合は `cd backend && php artisan db:seed --class=ScenarioSeeder`)
3. 別ターミナルでE2Eテストを実行する: `cd frontend && npm run test:e2e`

devcontainerを使わずbackend/frontendをホストで直接動かしたい場合は、従来どおり
`cd backend && php artisan serve` / `cd frontend && npm run dev` をそれぞれ起動し、
`docker compose up -d mock-oidc` でモックOIDCだけ起動してもよい。

Dockerを一切使わずホストのPHP/Node/Composerだけで動かすこともできる(確認済み):

1. backend: `composer install` → `cp .env.example .env && php artisan key:generate` →
   `touch database/database.sqlite` → `.env`の`MICROSOFT_MOCK_ENABLED`を`true`に変更
   (devcontainer/docker-compose以外ではここが自動設定されないため必須) →
   `php artisan migrate --seed` → `php artisan db:seed --class=ScenarioSeeder` →
   `php artisan serve`(ポート8000)
2. mock-oidc: `cd mock-oidc && node server.js`(ポート9000。`npm install`不要、
   Node標準ライブラリのみで動く)
3. frontend: `cp .env.example .env` → `npm run dev`(ポート5173)
4. E2E: `npx playwright install chromium`済みのバイナリと`@playwright/test`が期待する
   リビジョンが合わない場合は、下記のとおり`E2E_CHROMIUM_EXECUTABLE_PATH`を指定する。

`npx playwright install chromium` 済みのバイナリと `@playwright/test` が期待する
リビジョンが一致しない環境では `browserType.launch: Executable doesn't exist` の
ようなエラーになる。その場合は `npx playwright install chromium` を実行するか、
既存のChromiumバイナリのパスを `E2E_CHROMIUM_EXECUTABLE_PATH` に設定して実行する
(例: `E2E_CHROMIUM_EXECUTABLE_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome npm run test:e2e`)。

## ファイル構成

- `playwright.config.ts` — 設定(baseURLはfrontend dev server)
- `support/auth.ts` — モックOIDCでのログインヘルパー(`loginAs`)
- `support/api.ts` — テスト前提を整えるためのAPI直叩きヘルパー(有給の追加付与、
  経費CSVの取得など。画面がまだ無い/繰り返し実行のための前提データ調整に使う)
- `scenario-00-master-data.spec.ts` 〜 `scenario-05-business-card.spec.ts` —
  `docs/testing/scenario-tests.md` の各シナリオに対応
- `scenario-06-attendance-corrections.spec.ts` — 同ドキュメント §5-11(打刻ログの訂正・削除)・
  §5-12(日次勤怠の削除)に対応
- `scenario-07-new-work-time-systems.spec.ts` — 1か月単位変形労働時間制・裁量労働制・
  管理監督者・法定休日「決めない方式」に対応(同ドキュメント §5に追加分として記載)
- `scenario-99-additional.spec.ts` — 同ドキュメント §5(その他のシナリオ)に対応

## 実装状況

実装・グリーン確認済み:

- シナリオ0: 管理画面への遷移確認、カレンダー作成〜公開〜勤務形態作成〜シフト生成〜
  有給付与ルール作成〜手動付与
- シナリオ1: 打刻ユーザーの出勤・休憩・退勤(1日ぶん)
- シナリオ2: 月次入力ユーザーが打刻せず日次実績を新規作成(UC-A016)し、月次提出〜承認〜
  締めまで進める
- シナリオ3: 有給残数画面の表示確認、終日有給・半休それぞれの申請〜承認〜勤怠反映
- シナリオ4: 交通費申請〜承認〜経理タスク処理(発注〜支払予定〜完了)〜経費CSV確認
- シナリオ5: 名刺申請〜承認〜総務タスク処理(発注〜発送〜完了)
- その他(§5-1): 承認差し戻し→再申請
- その他(§5-2): 申請取消(提出後)
- その他(§5-3): 月次締め後は日次実績が編集できない(月次を提出〜承認〜締めまで進め、
  締め済みの日を編集しようとするとブロックされることを確認)
- その他(§5-4): 打刻ログと日次実績の不一致確認(矛盾する打刻を記録しても
  `attendance_days`には反映されないことを確認。専用の「要確認」UIはまだ無いため、
  APIでの前提投入+週次画面での確認のハイブリッド)
- その他(§5-6+7): ロール変更が同じSanctumトークンのまま即座に反映されること、
  および監査ログに記録されること
- その他(§5-8): 締めた月の勤怠CSV出力(管理画面からダウンロードし内容を確認)
- その他(§5-9): Entra ID初回ログイン(新入社員オンボーディング)。mock-oidcの
  未使用ユーザーで初回ログインし、employeeロールの自動付与・その後の入社日設定と
  hr_staffロール付与までを確認
- その他(§5-11): 打刻ログの訂正・削除。誤った出勤打刻を訂正すると日次勤怠が
  組み立て直され、元の打刻は「訂正済み」として理由付きで参照できること、重複打刻を
  削除すると「削除済み」として残ることを確認
- その他(§5-12): 日次勤怠の削除。承認前は削除できるが、月次が承認済みになった時点で
  (締めの有無によらず)削除・編集ともにできなくなることを確認
- 追加分(1か月単位変形労働時間制): あらかじめ8時間を超える所定労働時間を設定した日
  (UC-C006)は、その所定を超えた分のみが法定時間外になること、実績が既にある日は
  事後に予定を変更できないこと(振替防止ガード)を確認
- 追加分(裁量労働制): 雇用区分「正社員」と組み合わせ、打刻せずに出勤日を作成しても
  みなし時間(deemed_daily_minutes)が給与計算上の労働時間になることを確認
- 追加分(管理監督者): 長時間勤務でも法定内・法定外残業として計上されないことを確認
- 追加分(法定休日「決めない方式」、UC-C007): 週内で自動推定された休日への出勤が
  法定休日労働として計上されること、管理者が別の日を指定し直すと既存の実績の計算が
  再実行され、指定した日の出勤が法定休日労働として計上されることを確認

§5-3の実装にあたり、`GET /attendance/months/to-approve`が「自分が承認者かつ
`submitted`」のみを対象にしており、UC-A011が想定する「管理部(admin・hr_staff)が
承認者を問わず全社員の承認済み(締め処理待ち)月次を締める」導線が実際には
到達不能なバグだったため、`backend/app/Http/Controllers/Api/AttendanceController.php`
の`monthsToApprove`を修正した(admin・hr_staffには`approved`月次も追加で返す)。
`backend/tests/Feature/AttendanceFlowTest.php`に回帰テストを追加済み。

シナリオ2(月次入力ユーザーの日次入力)は、日次実績の新規作成画面(UC-A016に対応する
画面)がまだ無いため、日次実績の新規作成→週次画面での確認→月次提出→承認→締め、
という流れのうち新規作成部分のみAPIを直接叩く。

シナリオ5の注記(バックオフィスタスク画面に申請者情報が出ない件)は
`docs/testing/scenario-tests.md`のシナリオ5を参照。

追加分(1か月単位変形労働時間制・裁量労働制・管理監督者・法定休日「決めない方式」)は
`scenario-07-new-work-time-systems.spec.ts`のファイル冒頭コメントに記載のとおり、
以下の管理画面未対応事項がある(いずれもAPIを直接叩いて確認):

- 勤務形態作成フォーム(`WorkStylesAndShiftsPage`)に、雇用区分・みなし時間
  (deemed_daily_minutes)・変形期間の起算日(variable_period_start_day)・法定休日
  「決めない方式」の入力欄がまだ無く、`calendar_id`も必須のままになっている
  (バックエンドは2026-07-12時点でnullable)
- 週次勤怠画面(`WeekAttendancePage`)は実働時間(actual_work_minutes)しか表示せず、
  給与計算上の労働時間(payroll_work_minutes)・みなし時間(deemed_work_minutes)・
  法定内/法定時間外・法定休日労働などの内訳を表示しない
- 「打刻漏れ」警告が`day.status !== 'clocked_out'`のみで判定されており、裁量労働制の
  ように打刻自体が不要な勤務形態を考慮していない(実際には出退勤していないのに
  警告が出てしまう)
- 1か月単位変形労働時間制の所定編集(UC-C006)、法定休日「決めない方式」の指定操作
  (UC-C007)にも専用画面がまだ無い

未実装(`test.skip`のTODO): §5-5(有給失効・年5日警告バッチ)のみ。artisanコマンド
自体は存在するが、送信結果を確認できるAPI・画面が無く(Teams通知ジョブをキューに
積むのみ)、本スイートが前提とするブラックボックスE2E(HTTP/画面操作のみで完結)では
検証できない。実装するなら通知履歴を返すAPIの追加が前提になる。
