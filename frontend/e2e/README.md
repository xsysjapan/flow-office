# シナリオE2Eテスト (Playwright)

`docs/testing/scenario-tests.md` のシナリオをブラウザ操作で自動実行するためのテスト。
frontend の Vitest browser mode(Storybookのコンポーネントテスト用)とは別の、
独立したPlaywright E2Eテストスイート。

## 実行方法

1. `docker compose up app` (または devcontainer) で backend・frontend・mock-oidc を起動する。
   `app` コンテナは起動時に自動で `php artisan serve` と `npm run dev` を立ち上げる
   (`.devcontainer/start.sh`)。
2. シナリオ用マスタデータを投入する:
   `docker compose exec app sh -c "cd backend && php artisan db:seed --class=ScenarioSeeder"`
   (devcontainer内のターミナルで実行する場合は `cd backend && php artisan db:seed --class=ScenarioSeeder`)
3. 別ターミナルでE2Eテストを実行する: `cd frontend && npm run test:e2e`

devcontainerを使わずbackend/frontendをホストで直接動かしたい場合は、従来どおり
`cd backend && php artisan serve` / `cd frontend && npm run dev` をそれぞれ起動し、
`docker compose up -d mock-oidc` でモックOIDCだけ起動してもよい。

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
- `scenario-99-additional.spec.ts` — 同ドキュメント §5(その他のシナリオ)に対応

## 実装状況

実装・グリーン確認済み:

- シナリオ0: 管理画面への遷移確認、カレンダー作成〜公開〜勤務形態作成〜シフト生成〜
  有給付与ルール作成〜手動付与
- シナリオ1: 打刻ユーザーの出勤・休憩・退勤(1日ぶん)
- シナリオ3: 有給残数画面の表示確認、終日有給・半休それぞれの申請〜承認〜勤怠反映
- シナリオ4: 交通費申請〜承認〜経理タスク処理(発注〜支払予定〜完了)〜経費CSV確認
- シナリオ5: 名刺申請〜承認〜総務タスク処理(発注〜発送〜完了)
- その他(§5-1): 承認差し戻し→再申請
- その他(§5-2): 申請取消(提出後)
- その他(§5-6+7): ロール変更が同じSanctumトークンのまま即座に反映されること、
  および監査ログに記録されること

シナリオ2(月次入力ユーザーの日次入力)は、実装しようとした結果**現状のAPI/画面では
実現できないことが判明した**(打刻していない日は`attendance_days`行が存在せず、
週次画面に「編集」ボタン自体が出ない)。そのため、この既知の制限を固定して記録する
テストに差し替えている。詳細は`docs/testing/scenario-tests.md`のシナリオ2の注記、
および同ファイル内シナリオ5の注記(バックオフィスタスク画面に申請者情報が出ない件)
を参照。

未実装(`test.skip`のTODO): §5-3(月次締め後の編集制限)、§5-4(打刻ログ突合)、
§5-5(有給失効・年5日警告バッチ)、§5-8(締めた月のCSV出力、画面が未実装のため要検討)、
§5-9(新入社員の初回ログイン)。
