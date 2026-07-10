import { test } from '@playwright/test'

/**
 * docs/testing/scenario-tests.md シナリオ2(月次入力ユーザーの1か月勤怠)。
 * 伊藤舞が打刻APIを使わず、日次編集(PUT /attendance/days/{id})だけで
 * 1か月分の実績を入力し、月次提出〜承認〜締めまで通す。
 *
 * 週次勤怠画面 (WeekAttendancePage) から各日をクリックして開く日次編集フォームの
 * 項目が固まった時点で実装する。
 */
test.skip('月次入力ユーザーが日次編集のみで1か月の勤怠を入力できる (TODO)', async () => {
  // 1. /attendance/week で対象日をクリック
  // 2. 出勤・退勤・休憩時刻を入力して保存 (source が manual になることを確認)
  // 3. 月末に /attendance/months から月次提出
  // 4. 承認者(渡辺直樹)で承認、人事担当者(加藤由美)で締め
})
