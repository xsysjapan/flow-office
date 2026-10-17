# attendance-export-group-scope

- ステータス: 完了(2026-10-17 ユーザーレビュー済み。論点1の本人の扱いもユーザー決定)

## 変更要望(原文)

> `attendance.export`はスコープ`global`・`group`を持つが、勤怠CSV・Excel・外部連携の出力APIは
> `permission:attendance.export,any`で通すだけで、`user_id`を呼び出し元のスコープで絞っていない。
> グループスコープだけを持つ利用者が全社員を出力できる。他のエンドポイントのグループスコープの扱いに
> そろえ、グループスコープの利用者は自分のグループのメンバーだけを出力できるようにする。
> 範囲外の`user_id`を明示したら403、`user_id`を省略したらグループの範囲に絞る。

## 現状(As-Is)

- `backend/routes/api.php:263-268`: `GET /exports/attendance`・`GET /exports/attendance.xlsx`・
  `POST /exports/attendance/external-publish`はいずれも`permission:attendance.export,any`。
  `any`は`EffectiveAccessResolver::permissions($user)`に含まれるかだけを見る(`EnsureEffectivePermission.php:30`)ため、
  スコープは問わない。
- `ExportController::resolveAttendanceMonths`(:720-731)は`user_id`があればそれで絞り、無ければ全社員の月次を返す。
  3つのエンドポイントが共通で使う。スコープの絞り込みは無い。

## 既存のグループスコープの扱い(前例)

- `UserController::index`(:118-125): `hasGlobalPermission`なら絞らない。そうでなければ
  `permittedGroupIds($user, 'user.view')`を取り、`memberships.group_id`がそのいずれかのユーザーに絞る。
- `UserManagementController`(:80・:351)・`AccessControlController`(:182-201): `permittedGroupIds`で絞り、範囲外の指定は`abort_unless(..., 403)`。
- `EffectiveAccessResolver::permittedGroupIds`(:235-262)は、グループスコープの割当の`scope_group_id`と、
  `include_descendants`のときはその子孫グループを返す。`hasPermission`(:170-227)の判定(対象ユーザーの
  所属グループが割当のグループかその子孫)と同じ範囲になる。
- 参考: `AttendanceController::resolveViewableUserId`は本人・管理者(global)・承認者だけを見るもので、グループスコープは扱っていない(前例にしない)。

## 設計前提の検証

- 読み取りのみの変更(出力対象の絞り込み)。イベント・ReadModelへの書き込みは増えない。出力履歴(`export.created`)の記録は既存のまま。
- 原則15: Export文脈がアクセス制御の`memberships`を読むのは、他のControllerと同じ`EffectiveAccessResolver`経由の権限判定に限る。

## 仕様検討

### 論点1: 出力できる社員の範囲(グループスコープ)
- 決定: `attendance.export`を`global`スコープで持つ利用者は従来どおり制限なし。持たない利用者は、
  `permittedGroupIds($user, 'attendance.export')`のいずれかのグループに所属(`memberships`)する社員だけ。
- 本人: 自分がそのグループに所属していなければ、自分も範囲外とする。
- 理由: `attendance.export`のスコープは`global`・`group`だけで`self`が無い。`UserController`は本人を足しているが、
  それは`user.view`の画面上の都合(自分の情報を見る)で、出力の権限には当てはまらない。

### 論点2: `user_id`を明示したとき
- 決定: 指定した`user_id`のうち1件でも範囲外があれば、出力せず403(エラーメッセージ「出力する権限のない社員が含まれています。」)。
- 選択肢: A) 403 / B) 範囲外だけ黙って除く
- 理由: B は利用者が出力漏れに気づけない。前例(`UserManagementController:351`等)も範囲外の指定は403。

### 論点3: `user_id`を省略したとき
- 決定: 範囲内の社員の月次だけを出力する(CSV・Excel・外部連携とも)。範囲が0人(許可グループが無い・メンバーがいない)のときは、
  対象月次0件と同じ扱い(CSVはヘッダのみ、Excelは既存の0件時の挙動、外部連携は0件の結果)で、403にはしない。
- 理由: 要望どおり。0件時の挙動は既存のままにし、新しい分岐を作らない。

### 論点4: 実装の置き場所
- 決定: `ExportController`に、許可された社員IDの一覧(制限なしはnull)を返すprivateメソッドを1つ置き、
  3つのエンドポイントで共通に使う。`resolveAttendanceMonths`に引数を足して、nullでなければ`whereIn('user_id', ...)`を加える。
  `user_id`の明示が範囲外なら`resolveAttendanceMonths`の前に403にする。
- 理由: 3エンドポイントが既に`resolveAttendanceMonths`を共有しており、同じ場所で絞れば漏れない。
  ルートのミドルウェアは`any`のまま(入口の判定は「権限を持つか」、範囲の絞り込みはControllerという前例どおり)。

## 対象外

- 経費の出力(`expense.export`)は`global`スコープだけのため対象外。
- フロントエンド: 月次勤怠の「出力」カードは`attendance.export`を持てば表示される(`AttendanceReferencePage.tsx`)。
  グループ外の社員では403のエラーメッセージが表示される。ボタンの出し分けは今回は変えない。
- 出力履歴の`filters`の記録内容は変えない(要求された条件をそのまま残す)。

## ドキュメントへの影響

- docs/14-usecases-export.md: 勤怠のCSV・Excel・外部連携の権限の記述に「グループスコープの場合は所属グループ(子孫を含む割当なら子孫も)のメンバーだけ。
  範囲外の`user_id`は403、省略時は範囲内に絞る」を追記する。

## テスト(`backend/tests/Feature/Attendance/AttendanceExportTest.php`に追加)

グループ・所属・ロール割当は、既存の`tests/Feature/UserManagement/UserManagementAccessIntegrationTest.php`(:386-400・:476-494)と
同じくテーブルへ直接用意する(アクセス制御の設定はイベント経由のドメインではないため)。月次勤怠は既存の出力テストと同じ作り方。
社員A(グループG所属)・社員B(グループ外)の2026-06を承認済みで用意する。

1. グローバルスコープ: `user_id`省略のCSVにA・Bの両方が出る。
2. グループスコープ・省略: CSVにAだけが出て、Bは出ない。
3. グループスコープ・範囲外を明示: `user_id[]=B`のCSVが403。`user_id[]=A&user_id[]=B`も403。
4. グループスコープ・範囲内を明示: `user_id[]=A`のCSVが200でAが出る。
5. グループスコープのExcel: `user_id[]=B`が403、省略時はAだけの1ブック(ZIPではなく単一`.xlsx`)。
6. グループスコープの外部連携: `user_id[]=B`が403(連携先の設定に依存しない入口の検証)。
7. 子孫を含む割当: 割当がGの親グループ・`include_descendants=true`のとき、子グループGのAを出力できる。

受け入れ条件: 追加テストが通り、`cd backend && php artisan test --filter Export`が全件通る。

## 結果

- `php artisan test --filter Export`: 75件PASS(既存68件+追加7件)。
- 本体の変更だけを外した状態では、グループスコープの4件(省略時の絞り込み・範囲外のCSV/Excel/外部連携の403)が失敗し、修正で直ることを確認
  (グローバル・範囲内の明示・子孫を含む割当の3件は修正前から通る)。
- `ExportController.php`はpintで整形した(既存の違反を含む。`use`の並び、`\Illuminate\Http\JsonResponse`→`JsonResponse`の表記で、
  経費の外部連携のメソッドも型名の表記だけ変わった。挙動の変更なし)。
