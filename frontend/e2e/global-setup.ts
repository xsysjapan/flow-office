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

  const result = (await response.json()) as {
    product_initial_access?: {
      all_users_feature_assignments: number
      all_users_role_assignments: number
    }
  }

  // AccessControlSeederが移行済み環境の現行運用として付与する標準初期値
  // (docs/31-user-group-access-foundation.md 31.1節: 打刻・勤怠入力・勤務表提出、汎用申請、
  // 休暇申請、経費入力の9Feature + EMPLOYEE RoleAssignment 1件)からズレていないかを検証する。
  // ズレている場合、新Featureが意図せずALL_USERSへ自動開放された可能性がある。
  const EXPECTED_ALL_USERS_FEATURE_ASSIGNMENTS = 9
  const EXPECTED_ALL_USERS_ROLE_ASSIGNMENTS = 1

  if (
    result.product_initial_access?.all_users_feature_assignments !== EXPECTED_ALL_USERS_FEATURE_ASSIGNMENTS ||
    result.product_initial_access?.all_users_role_assignments !== EXPECTED_ALL_USERS_ROLE_ASSIGNMENTS
  ) {
    throw new Error(
      `E2E globalSetup: ALL_USERS の標準初期値(Feature ${EXPECTED_ALL_USERS_FEATURE_ASSIGNMENTS}件・Role ${EXPECTED_ALL_USERS_ROLE_ASSIGNMENTS}件)からズレています: ${JSON.stringify(result.product_initial_access)}\n` +
        'AccessControlSeederのinitialFeaturesが変更された場合は、この期待値とdocs/31-user-group-access-foundation.md 31.1節/31.17節も合わせて更新してください。',
    )
  }
}
