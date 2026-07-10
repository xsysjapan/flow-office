import { test } from '@playwright/test'

/**
 * docs/testing/scenario-tests.md シナリオ4(交通費申請)。
 * 申請(request_type_code=commuting_expense)〜承認〜経理バックオフィスタスク処理〜
 * 経費CSV出力までの一連の流れ。
 */
test.skip('交通費申請〜承認〜経理タスク処理〜CSV出力 (TODO)', async () => {
  // 1. 高橋健太で /requests/new から「交通費精算」を選び金額・経路を入力し提出
  //    (承認者=渡辺直樹)
  // 2. 渡辺直樹で /approvals から承認
  // 3. 小林誠で /backoffice-tasks から未担当タスクを自分に割り当て、
  //    processing → payment_scheduled → completed の順にステータス変更
  // 4. 経費CSV (GET /api/exports/expenses) をAPI直叩きで取得し金額が含まれることを確認
  //    (2026-07-10時点、経費CSV出力の画面はまだ未実装。UI追加時にここもUI操作へ差し替える)
  // 5. 高橋健太で /requests/:id の履歴からステータス変遷を確認
})
