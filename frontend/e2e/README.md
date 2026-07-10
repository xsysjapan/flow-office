# シナリオE2Eテスト (Playwright)

`docs/testing/scenario-tests.md` のシナリオをブラウザ操作で自動実行するためのテスト。
frontend の Vitest browser mode(Storybookのコンポーネントテスト用)とは別の、
独立したPlaywright E2Eテストスイート。

## 実行方法

1. backend を起動する: `cd backend && php artisan serve`
2. モックOIDCを起動する: `docker compose up -d mock-oidc` (または devcontainer)
3. シナリオ用マスタデータを投入する:
   `cd backend && php artisan db:seed --class=ScenarioSeeder`
4. frontend を起動する: `cd frontend && npm run dev`
5. 別ターミナルでE2Eテストを実行する: `cd frontend && npm run test:e2e`

`npx playwright install chromium` 済みのバイナリと `@playwright/test` が期待する
リビジョンが一致しない環境では `browserType.launch: Executable doesn't exist` の
ようなエラーになる。その場合は `npx playwright install chromium` を実行するか、
既存のChromiumバイナリのパスを `E2E_CHROMIUM_EXECUTABLE_PATH` に設定して実行する
(例: `E2E_CHROMIUM_EXECUTABLE_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome npm run test:e2e`)。

## ファイル構成

- `playwright.config.ts` — 設定(baseURLはfrontend dev server)
- `support/auth.ts` — モックOIDCでのログインヘルパー(`loginAs`)
- `scenario-00-master-data.spec.ts` 〜 `scenario-05-business-card.spec.ts` —
  `docs/testing/scenario-tests.md` の各シナリオに対応
- `scenario-99-additional.spec.ts` — 同ドキュメント §5(その他のシナリオ)のプレースホルダ

`test.skip` になっているテストは未実装(TODO)。実装済みなのは以下のみ:

- シナリオ0: 管理画面への遷移確認
- シナリオ1: 打刻ユーザーの出勤・休憩・退勤(1日ぶん)
- シナリオ3: 有給残数画面の表示確認

残りは画面の詳細(フォーム項目・確認文言)を見ながら順次実装する。
