import { test } from '@playwright/test'

/**
 * docs/testing/scenario-tests.md シナリオ5(名刺の申請〜作成・発行)。
 * 申請(request_type_code=business_card)〜承認〜総務バックオフィスタスクの
 * ステータス遷移(in_review→processing→ordered→shipped→completed)までの流れ。
 */
test.skip('名刺申請〜承認〜総務タスク処理(発注〜発送〜完了) (TODO)', async () => {
  // 1. 伊藤舞で /requests/new から「名刺申請」を選び枚数を入力し提出
  //    (承認者=渡辺直樹)
  // 2. 渡辺直樹で /approvals から承認 (自動生成されるbackoffice_tasksを確認)
  // 3. 中村恵で /backoffice-tasks から未担当タスクを自分に割り当て、
  //    processing → ordered → shipped → completed の順にステータス変更
  // 4. 伊藤舞で /requests/:id が完了表示になっていることを確認
})
