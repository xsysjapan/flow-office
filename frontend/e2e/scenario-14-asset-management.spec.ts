import { expect, test, type Page } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import { apiFetch } from './support/api'
import { pickUser } from './support/ui'

/**
 * docs/changesets/20260830-equipment-management/spec.md に基づく備品管理機能のE2E。
 * バックエンド・フロントエンドとも実装済み(request_types=asset_loan、asset.manage権限、
 * AssetAggregate等)であることを前提に、代表的なシナリオを画面操作で確認する。
 *
 * - asset.manage権限は`AccessControlSeeder`上ADMIN(Test Admin/admin@example.com)のみが
 *   保有する(spec 論点10)。バックオフィス操作・承認者選択はすべてTest Adminで行う。
 * - `useUserAssetLoans`(`GET /users/{user}/asset-loans`)フックはまだどの画面からも
 *   呼び出されていない(専用の「自分の貸与一覧」画面が無い)ため、セルフ貸出後の確認は
 *   詳細画面の表示 + このAPIへの直接アクセスの両方で行う。
 */

function randomAssetNo(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.floor(Math.random() * 100000)}`
}

/** UC-A系: 備品を登録し、`/assets/{id}`に遷移した状態で返す。 */
async function registerAsset(
  page: Page,
  input: {
    assetNo: string
    name: string
    category: string
    managementType?: 'lending' | 'installation'
    lendingMethod?: 'self_service' | 'backoffice' | 'approval'
    defaultLocationText?: string
  },
): Promise<string> {
  await page.goto('/assets/new')
  await page.getByLabel('管理番号').fill(input.assetNo)
  await page.getByLabel('名称').fill(input.name)
  await page.getByLabel('カテゴリ').fill(input.category)
  if (input.managementType) {
    await page.getByLabel('管理区分').selectOption(input.managementType)
  }
  if ((input.managementType ?? 'lending') === 'lending' && input.lendingMethod) {
    await page.getByLabel('貸出方式').selectOption(input.lendingMethod)
  }
  if (input.defaultLocationText) {
    await page.getByLabel('通常配置場所').fill(input.defaultLocationText)
  }
  await page.getByRole('button', { name: '作成' }).click()
  await page.waitForURL(/\/assets\/[0-9a-f-]+$/)
  return page.url()
}

test.describe('備品管理', () => {
  test('備品登録〜検索一覧で見つかる〜詳細画面で内容確認', async ({ page }) => {
    test.setTimeout(120000)
    const assetNo = randomAssetNo('EQ-REG')
    const name = `E2Eテスト用ノートPC ${assetNo}`

    await loginAs(page, SCENARIO_USERS.admin)
    const detailUrl = await registerAsset(page, { assetNo, name, category: 'ノートPC', lendingMethod: 'backoffice' })

    // 詳細画面の内容確認。
    await expect(page.getByRole('heading', { name: `${name} / ${assetNo}` })).toBeVisible()
    await expect(page.getByText('カテゴリ')).toBeVisible()
    await expect(page.getByText('ノートPC').first()).toBeVisible()

    // 検索一覧で見つかること。
    await page.goto('/assets')
    await page.getByLabel('管理番号').fill(assetNo)
    const row = page.getByRole('row', { name: new RegExp(name) })
    await expect(row).toBeVisible()
    await expect(row.getByRole('cell', { name: assetNo, exact: true })).toBeVisible()

    // 一覧から詳細に戻れること。
    await row.getByRole('link', { name }).click()
    await expect(page).toHaveURL(detailUrl)
    await expect(page.getByRole('heading', { name: `${name} / ${assetNo}` })).toBeVisible()
  })

  test('セルフ貸出(self_service)〜借りる〜自分の貸与一覧〜返却〜通常配置場所に戻る', async ({ browser }) => {
    test.setTimeout(120000)
    const assetNo = randomAssetNo('EQ-SELF')
    const name = `E2Eテスト用セルフ貸出備品 ${assetNo}`
    const location = '本社4F 備品庫'

    const adminContext = await browser.newContext()
    const employeeContext = await browser.newContext()
    try {
      const adminPage = await adminContext.newPage()
      const employeePage = await employeeContext.newPage()

      await loginAs(adminPage, SCENARIO_USERS.admin)
      const detailUrl = await registerAsset(adminPage, {
        assetNo,
        name,
        category: 'その他',
        lendingMethod: 'self_service',
        defaultLocationText: location,
      })

      await loginAs(employeePage, SCENARIO_USERS.punchEmployee)
      await employeePage.goto(detailUrl)
      await expect(employeePage.getByRole('status', { name: '利用可能' })).toBeVisible()

      // セルフ貸出: 「借りる」で自分自身へ貸与する。
      await employeePage.getByRole('button', { name: '借りる' }).click()
      await employeePage.getByRole('button', { name: '借りる' }).click()
      await expect(employeePage.getByRole('status', { name: '貸出中' })).toBeVisible()
      // ヘッダーのログインユーザー名表示と重複するため、詳細本体(main)側に絞って確認する。
      await expect(employeePage.getByRole('main').getByText(SCENARIO_USERS.punchEmployee)).toBeVisible()

      // 一覧の「現在の状況」にも借用者が表示される。
      await employeePage.goto(`/assets?asset_no=${assetNo}`)
      await expect(employeePage.getByRole('row', { name: new RegExp(name) })).toContainText(
        `貸出中: ${SCENARIO_USERS.punchEmployee}`,
      )

      // 「自分の貸与一覧」に相当する専用画面はまだ無いため、APIで直接確認する。
      const myId = await apiFetch<{ id: string }>(employeePage, '/auth/me').then((me) => me.id)
      const loans = await apiFetch<Array<{ asset: { asset_no: string }; returned_at: string | null }>>(
        employeePage,
        `/users/${myId}/asset-loans`,
      )
      expect(loans.some((l) => l.asset.asset_no === assetNo && l.returned_at === null)).toBe(true)

      // 返却〜通常配置場所に戻る。
      await employeePage.goto(detailUrl)
      await employeePage.getByRole('button', { name: '返却' }).click()
      await employeePage.getByRole('button', { name: '返却する' }).click()
      await expect(employeePage.getByRole('status', { name: '利用可能' })).toBeVisible()
      await expect(employeePage.getByText(location)).toBeVisible()

      const loansAfterReturn = await apiFetch<Array<{ asset: { asset_no: string }; returned_at: string | null }>>(
        employeePage,
        `/users/${myId}/asset-loans`,
      )
      expect(loansAfterReturn.find((l) => l.asset.asset_no === assetNo)?.returned_at).not.toBeNull()
    } finally {
      await adminContext.close()
      await employeeContext.close()
    }
  })

  test('バックオフィス貸与(backoffice)〜貸与〜返却', async ({ browser }) => {
    test.setTimeout(120000)
    const assetNo = randomAssetNo('EQ-BO')
    const name = `E2Eテスト用バックオフィス貸与備品 ${assetNo}`

    const adminContext = await browser.newContext()
    try {
      const adminPage = await adminContext.newPage()
      await loginAs(adminPage, SCENARIO_USERS.admin)
      const detailUrl = await registerAsset(adminPage, { assetNo, name, category: 'スマートフォン', lendingMethod: 'backoffice' })

      await adminPage.goto(detailUrl)
      await adminPage.getByRole('button', { name: '貸与する' }).click()
      await pickUser(adminPage, '借用者', SCENARIO_USERS.monthlyEmployee, 'mai.ito@example.com')
      await adminPage.getByRole('dialog').getByRole('button', { name: '貸与する' }).click()

      await expect(adminPage.getByRole('status', { name: '貸出中' })).toBeVisible()
      await expect(adminPage.getByText(SCENARIO_USERS.monthlyEmployee)).toBeVisible()

      await adminPage.getByRole('button', { name: '返却' }).click()
      await adminPage.getByRole('button', { name: '返却する' }).click()
      await expect(adminPage.getByRole('status', { name: '利用可能' })).toBeVisible()
    } finally {
      await adminContext.close()
    }
  })

  test('申請制貸出(approval)フルフロー: 貸出申請〜承認〜貸与(1件のみなら自動選択)〜返却', async ({ browser }) => {
    test.setTimeout(150000)
    const assetNo = randomAssetNo('EQ-APP')
    const name = `E2Eテスト用申請制備品 ${assetNo}`

    const adminContext = await browser.newContext()
    const employeeContext = await browser.newContext()
    try {
      const adminPage = await adminContext.newPage()
      const employeePage = await employeeContext.newPage()

      await loginAs(adminPage, SCENARIO_USERS.admin)
      const detailUrl = await registerAsset(adminPage, { assetNo, name, category: 'プロジェクター', lendingMethod: 'approval' })

      // 1. 一般ユーザーが貸出申請する(承認者はasset.manage保有者に絞られるのでTest Adminを選ぶ)。
      await loginAs(employeePage, SCENARIO_USERS.punchEmployee)
      await employeePage.goto(detailUrl)
      await employeePage.getByRole('button', { name: '貸出申請' }).click()
      await employeePage.getByLabel('利用目的').fill('E2Eテスト用の申請')
      await pickUser(employeePage, '承認者', SCENARIO_USERS.admin, 'admin@example.com')
      await employeePage.getByRole('dialog').getByRole('button', { name: '申請する' }).click()
      await expect(employeePage.getByRole('dialog')).toHaveCount(0)

      // 申請の一覧画面(専用の承認画面遷移はUIから直接辿れないため、自分の申請一覧APIでidを特定する)。
      // indexMine はページネーション(paginate())を使うため、レスポンスは
      // `{ data: [...], links, meta }`の形になる(withoutWrapping()が効くのはResourceの
      // 外側ラップのみで、Paginatorのdata/links/meta構造自体は変わらない)。
      const myRequestsResponse = await apiFetch<{ data: Array<{ id: string; title: string; status: string }> }>(
        employeePage,
        '/workflow-requests/mine',
      )
      const requestTitle = `${name}(${assetNo})の貸出申請`
      const myRequest = myRequestsResponse.data.find((r) => r.title === requestTitle)
      expect(myRequest).toBeTruthy()
      expect(myRequest?.status).toBe('submitted')

      // 2. asset.manage権限ユーザー(Test Admin)が承認する。
      await adminPage.goto(`/requests/${myRequest!.id}`)
      await expect(adminPage.getByRole('heading', { name: requestTitle })).toBeVisible()
      await adminPage.getByRole('button', { name: '承認する' }).click()
      await expect(adminPage.getByRole('status', { name: '承認済み' })).toBeVisible()

      // 3. 貸与操作: 承認済み申請が1件のみなので自動選択されること。
      await adminPage.goto(detailUrl)
      await adminPage.getByRole('button', { name: '貸与する' }).click()
      await pickUser(adminPage, '借用者', SCENARIO_USERS.punchEmployee, 'kenta.takahashi@example.com')
      await expect(adminPage.getByText('E2Eテスト用の申請')).toBeVisible()
      await adminPage.getByRole('dialog').getByRole('button', { name: '貸与する' }).click()
      await expect(adminPage.getByRole('status', { name: '貸出中' })).toBeVisible()
      await expect(adminPage.getByText(SCENARIO_USERS.punchEmployee)).toBeVisible()

      // 申請の表示ステータスも「貸与済み」に反映される。
      const loanRequests = await apiFetch<Array<{ id: string; status: string }>>(
        adminPage,
        `/assets/${detailUrl.split('/').pop()}/loan-requests`,
      )
      expect(loanRequests.find((r) => r.id === myRequest!.id)?.status).toBe('lent')

      // 4. 返却(借用者本人から)。
      await employeePage.goto(detailUrl)
      await employeePage.getByRole('button', { name: '返却' }).click()
      await employeePage.getByRole('button', { name: '返却する' }).click()
      await expect(employeePage.getByRole('status', { name: '利用可能' })).toBeVisible()
    } finally {
      await adminContext.close()
      await employeeContext.close()
    }
  })

  test('設置備品: 設置〜移設〜撤去', async ({ page }) => {
    test.setTimeout(120000)
    const assetNo = randomAssetNo('EQ-INST')
    const name = `E2Eテスト用設置備品 ${assetNo}`

    await loginAs(page, SCENARIO_USERS.admin)
    const detailUrl = await registerAsset(page, { assetNo, name, category: 'モニター', managementType: 'installation' })

    // 設置。
    await page.getByRole('button', { name: '設置' }).click()
    await page.getByLabel('場所').fill('3階会議室A')
    await page.getByRole('dialog').getByRole('button', { name: '設置する' }).click()
    await expect(page.getByRole('status', { name: '設置中' })).toBeVisible()
    await expect(page.getByText('3階会議室A')).toBeVisible()

    // 移設。
    await page.getByRole('button', { name: '移設' }).click()
    await page.getByLabel('場所').fill('3階会議室B')
    await page.getByRole('dialog').getByRole('button', { name: '移設する' }).click()
    await expect(page.getByText('3階会議室B')).toBeVisible()
    await expect(page.getByText('3階会議室A')).not.toBeVisible()

    // 撤去。
    await page.getByRole('button', { name: '撤去' }).click()
    await page.getByRole('dialog').getByRole('button', { name: '撤去する' }).click()
    await expect(page.getByRole('status', { name: '保管中' })).toBeVisible()
    await expect(detailUrl).toContain('/assets/')
  })

  test('削除ガード: 進行中の申請/貸出中は削除できず、そうでない備品は削除できる', async ({ browser }) => {
    test.setTimeout(150000)
    const blockedAssetNo = randomAssetNo('EQ-DEL-BLOCK')
    const blockedName = `E2Eテスト用削除ガード確認備品 ${blockedAssetNo}`
    const deletableAssetNo = randomAssetNo('EQ-DEL-OK')
    const deletableName = `E2Eテスト用削除可能備品 ${deletableAssetNo}`

    const adminContext = await browser.newContext()
    const employeeContext = await browser.newContext()
    try {
      const adminPage = await adminContext.newPage()
      const employeePage = await employeeContext.newPage()

      await loginAs(adminPage, SCENARIO_USERS.admin)

      // 承認待ちの貸出申請が残っている(承認制)備品は、lending_status自体はavailableのままだが
      // バックエンドの削除ガード(spec「削除可否ガード」)が拒否し、ダイアログにエラーが表示される。
      const blockedDetailUrl = await registerAsset(adminPage, {
        assetNo: blockedAssetNo,
        name: blockedName,
        category: 'その他',
        lendingMethod: 'approval',
      })

      await loginAs(employeePage, SCENARIO_USERS.punchEmployee)
      await employeePage.goto(blockedDetailUrl)
      await employeePage.getByRole('button', { name: '貸出申請' }).click()
      await employeePage.getByLabel('利用目的').fill('E2Eテスト用の申請(削除ガード確認)')
      await pickUser(employeePage, '承認者', SCENARIO_USERS.admin, 'admin@example.com')
      await employeePage.getByRole('dialog').getByRole('button', { name: '申請する' }).click()
      await expect(employeePage.getByRole('dialog')).toHaveCount(0)

      await adminPage.goto(blockedDetailUrl)
      await expect(adminPage.getByRole('status', { name: '利用可能' })).toBeVisible()
      await adminPage.getByRole('button', { name: '削除' }).click()
      await adminPage.getByRole('dialog').getByRole('button', { name: '削除する' }).click()
      // 削除は拒否され、ダイアログが開いたままエラーが表示される。
      await expect(adminPage.getByRole('dialog')).toBeVisible()
      await expect(adminPage.getByRole('alert')).toBeVisible()

      // 貸出中でない備品は削除できる。
      const deletableDetailUrl = await registerAsset(adminPage, {
        assetNo: deletableAssetNo,
        name: deletableName,
        category: 'その他',
        lendingMethod: 'backoffice',
      })
      await adminPage.goto(deletableDetailUrl)
      await adminPage.getByRole('button', { name: '削除' }).click()
      await adminPage.getByRole('dialog').getByRole('button', { name: '削除する' }).click()
      await adminPage.waitForURL('**/assets')
      await adminPage.getByLabel('管理番号').fill(deletableAssetNo)
      // 管理番号で絞り込んだ結果0件のため、検索条件向けの空状態文言が表示される
      // (未フィルタ時の「登録されている備品がまだありません。」とは別の文言)。
      await expect(adminPage.getByText('条件に一致する備品がありません。')).toBeVisible()
    } finally {
      await adminContext.close()
      await employeeContext.close()
    }
  })

  test('権限: asset.manage権限を持たない一般ユーザーには登録・削除等のボタンが表示されない', async ({ browser }) => {
    test.setTimeout(120000)
    const assetNo = randomAssetNo('EQ-PERM')
    const name = `E2Eテスト用権限確認備品 ${assetNo}`

    const adminContext = await browser.newContext()
    const employeeContext = await browser.newContext()
    try {
      const adminPage = await adminContext.newPage()
      const employeePage = await employeeContext.newPage()

      await loginAs(adminPage, SCENARIO_USERS.admin)
      const detailUrl = await registerAsset(adminPage, { assetNo, name, category: 'その他', lendingMethod: 'backoffice' })
      // 以降はemployeePageしか使わない。adminPageを開いたままにしておくと、`php artisan serve`
      // (開発用の単一スレッドサーバー)への同時リクエストが増え、バックグラウンドの
      // ポーリング(通知取得等)がemployeePage側のページ遷移時のAPI呼び出しと競合して
      // まれにタイムアウトすることがあるため、早めに閉じておく。
      await adminContext.close()

      await loginAs(employeePage, SCENARIO_USERS.punchEmployee)

      // 一覧画面: 新規登録・バックオフィス系一括操作リンクが表示されない。
      await employeePage.goto('/assets')
      await expect(employeePage.getByRole('button', { name: '新規登録' })).toHaveCount(0)
      // getByRole の name はデフォルトで部分一致するため、「一括返却」だけで指定すると
      // 「セルフ一括返却」ともマッチしてしまう。exact指定でバックオフィス専用リンクだけを見る。
      await expect(employeePage.getByRole('link', { name: 'セルフ一括貸出', exact: true })).toBeVisible()
      await expect(employeePage.getByRole('link', { name: '一括貸与', exact: true })).toHaveCount(0)
      await expect(employeePage.getByRole('link', { name: '一括返却', exact: true })).toHaveCount(0)
      await expect(employeePage.getByRole('link', { name: '一括移設', exact: true })).toHaveCount(0)

      // 詳細画面: 編集リンク・貸与/削除等の管理操作ボタンが表示されない
      // (backoffice方式・available状態のため一般ユーザーが実行できる操作は無い)。
      await employeePage.goto(detailUrl)
      await expect(employeePage.getByRole('link', { name: '編集' })).toHaveCount(0)
      await expect(employeePage.getByRole('button', { name: '貸与する' })).toHaveCount(0)
      await expect(employeePage.getByRole('button', { name: '削除' })).toHaveCount(0)
      await expect(employeePage.getByRole('button', { name: 'QR再発行' })).toHaveCount(0)
      await expect(employeePage.getByRole('button', { name: '通常配置場所を設定' })).toHaveCount(0)

      // 一括操作画面への直URLアクセスも権限で拒否される。
      await employeePage.goto('/assets/bulk/lend')
      await expect(employeePage.getByText('バックオフィス一括貸与を行う権限がありません。')).toBeVisible()
      await employeePage.goto('/assets/bulk/relocate')
      await expect(employeePage.getByText('備品の一括移設を行う権限がありません。')).toBeVisible()

      // 新規登録画面への直URLアクセス自体は開けるが、実行(APIコール)はバックエンド側
      // (`permission:asset.manage`)で拒否される(CLAUDE.md 9番: 操作経路によらず業務ロジックは
      // バックエンドAPI側に集約する)。
      await employeePage.goto('/assets/new')
      await employeePage.getByLabel('管理番号').fill(randomAssetNo('EQ-DENIED'))
      await employeePage.getByLabel('名称').fill('権限確認用')
      await employeePage.getByLabel('カテゴリ').fill('その他')
      await employeePage.getByRole('button', { name: '作成' }).click()
      await expect(employeePage.getByRole('alert')).toBeVisible()
      await expect(employeePage).toHaveURL(/\/assets\/new$/)
    } finally {
      await adminContext.close()
      await employeeContext.close()
    }
  })
})
