/**
 * Playwright E2Eテスト(`npm run test:e2e`)の実行開始時に必ず1回だけ呼ばれる
 * グローバルセットアップ。開発DBを`backend/app/Http/Controllers/Api/DevDatabaseResetController.php`
 * (`POST /dev/reset-database`)経由で既知の初期状態(migrate:fresh --seed +
 * ScenarioSeeder)にリセットする。
 *
 * これにより、永続的な開発DBに対して何度テストを実行しても常に同じ状態から始まる
 * (前回実行分の日次実績・有給付与・承認済み/締め済みステータスなどが残らない)。
 * 本エンドポイントは`MICROSOFT_MOCK_ENABLED=true`の時のみ有効で、本番・検証環境では
 * 404になり到達不能(`MockOidcUserController`と同じ考え方)。
 */
const API_BASE_URL = process.env.E2E_API_BASE_URL ?? 'http://localhost:8000/api'

export default async function globalSetup(): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/dev/reset-database`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    const text = await response.text()
    throw new Error(
      `E2E globalSetup: POST ${API_BASE_URL}/dev/reset-database failed (${response.status}): ${text}\n` +
        'backend (php artisan serve) が起動していること、.envで MICROSOFT_MOCK_ENABLED=true になっていることを確認してください。',
    )
  }
}
