# save-all-user-and-role(利用者詳細とロール詳細の「まとめて保存」)

ステータス: レビュー中

## 変更要望(原文)
変更候補の判断 `docs/changesets/candidates-20261124.md` の #13: 利用者詳細とロール詳細の、項目ごとの保存を「まとめて保存」にする(ユーザー判断 2026-11-24)。変更セット`20261123-form-layout-standard`の論点8(項目・セクションごとの保存)の解決にあたる。

## 現状(調査 2026-11-25)

利用者詳細(`UserRoleEditPage`)には、保存のボタンが6つある。

| ボタン | API | イベント | 拒否の条件 |
|---|---|---|---|
| 基本情報を保存 | `PATCH /users/{id}`(`user.update`+`feature:administration.users`) | `UserProfileUpdated` | 一意性・列挙値、外部人事の項目、最後の管理者の無効化 |
| 入社日を保存 | `PUT /users/{id}/hire-date`(`user.update`) | `UserHireDateSet` | 外部人事、退社日より後 |
| 退社日を保存 | `PUT .../termination-date` | `UserTerminationDateSet` | 外部人事、入社日より前 |
| 利用開始日を保存 | `PUT .../usage-start-date` | `UserUsageStartDateSet` | 外部人事 |
| 自動付与設定を保存 | `PUT .../paid-leave-auto-grant-enabled`・`special-...`(2本を並列) | `PaidLeaveAutoGrantEnabledSet`・`SpecialLeaveAutoGrantEnabledSet` | なし |
| 働き方を保存 | `POST/DELETE /user-work-style-monthly-assignments` | 月ごとの割当の追加・削除 | — |

ロール詳細(`RoleDefinitionPage`)には、保存が3つある。

| ボタン | API | イベント |
|---|---|---|
| 基本情報を保存 | `PATCH /admin/access-control/roles/{role}` | `RoleUpdated` |
| Permissionを保存 | `PUT .../roles/{role}/permissions` | `RolePermissionsChanged` |
| Feature構成を保存 | `PUT .../roles/{role}/features` | `RoleFeaturesChanged`。グループへの Feature の同期も同じトランザクションで行う |

どのコマンドも同期で実行され、1コマンドが1トランザクションになる。入社日・利用開始日・自動付与(有給)は、付与予定の再計算の Reactor を同じトランザクションで動かす。

- 入社日と退社日の検証は、現在の値(Projection)と比べて行う。そのため、2つを同時に変えると結果が順序に左右される。
- 基本情報は、変更が無くても全項目を送るので、変えていなくてもイベントが記録される。
- 一括の前例: `ExpenseClaimController::bulkAddItems`、`BackOfficeTaskController::bulkComplete`。どちらも1つの `DB::transaction` の中で既存のコマンドを順に dispatch する。

## 仕様検討

### 論点1: 一部だけ失敗したときの扱い
- 選択肢:
  - A. まとめて保存のエンドポイントを新しく作る。すべて成功するか、すべて戻すか。
  - B. 画面から既存の API を順に呼ぶ。項目ごとに成功・失敗する。
- 決定: A。
- 理由: B は途中で失敗すると前の保存が確定して残り、利用者には何が保存されたか分かりにくい。A なら、1回の保存は全部成功するか全部戻るかのどちらかになる。
- イベント: 既存のコマンドをそのまま使い、変更した項目ごとに今と同じイベントを記録する。「まとめて保存」という新しいイベントは作らない(原則1・15)。
- 未確定・要確認事項: なし

### 論点2: 利用者詳細のまとめて保存
- 新しいエンドポイント: `PUT /users/{id}/details`(権限 `user.update`+`feature:administration.users`。基本情報の PATCH と同じ)。
- 本文に入るのは変更した項目だけ:
  - `profile`(氏名・メール・社員番号・部署・役職・在籍状況・アカウント状態のうち、変えたもの)
  - `hire_date`・`termination_date`・`usage_start_date`
  - `paid_leave_auto_grant_enabled`・`special_leave_auto_grant_enabled`
- 処理:
  1. 全体を検証する。最後の状態で入社日 ≤ 退社日になっているか。外部人事が管理する項目を含んでいないか(`FieldAuthorityService::assertLocallyEditable` に変更した項目をまとめて渡す)。形式の検証。
  2. 1つの `DB::transaction` の中で、変えた項目だけ既存のコマンドを dispatch する。
  3. 順序: 入社日と退社日を両方変える場合は、途中の状態が成り立つ順にする(新しい入社日が今の退社日より後なら、退社日を先に変える。それ以外は入社日を先に変える)。
  4. コマンドが業務の規則で拒否したら、全て戻し、該当する項目のキーを付けた 422 を返す(`errors.{項目キー}`)。
- 働き方(月ごとの割当の追加・削除)は、まとめて保存に含めない。項目の値ではなく、行を追加・削除する別の操作のため。今のボタンのまま残し、セクションを分けて表示する。
- 既存の項目ごとの API は残す(API・MCP の互換)。画面はまとめての API だけを使う。
- 画面:
  - 保存のボタンは画面の末尾に1つ(「保存」。FormActions。変更が無いときは無効)。
  - 変更の有無は、読み込んだ値と比べて判定する。
  - 外部人事が管理する項目(入社日・退社日・利用開始日を含む)は、入力を無効にして理由を出す(今は押せてしまい、保存で拒否される)。
  - 項目のエラーは、その項目の下に出す(`FormField` の `error`)。
  - 離脱警告(`useUnsavedChangesGuard`)を付ける。
- 未確定・要確認事項: なし

### 論点3: ロール詳細のまとめて保存
- 新しいエンドポイント: `PUT /admin/access-control/roles/{role}/definition`(権限は今の3本と同じ)。
- 本文: `name`・`description`・`status`・`permission_ids`・`feature_ids` のうち、変えたもの。
- 処理:
  - 1つのトランザクションで、基本情報 → Permission → Feature の順に、既存のコマンドを dispatch する。Feature の変更に伴うグループの同期も、同じトランザクションで行う。
  - 拒否されたら全て戻し、項目のキーを付けた 422 を返す。拒否の例: システムのロールの廃止、無効のロールの変更、システム管理者から `user.manage` を外す。
- 画面: 保存は末尾に1つ。変更の判定と離脱警告は、利用者詳細と同じ。
- 既存の3本の API は残す。
- 未確定・要確認事項: なし

### 論点4: テスト(単体)
- backend の Feature テスト(2つのエンドポイントについて):
  - 変えた項目だけのイベントが記録される。変えていない項目は記録されない。
  - 一部の拒否で全て戻る。例: 退社日の規則で拒否されたとき、氏名の変更も残らない。
  - 入社日と退社日を両方動かす例が、順序の決め方で通る。最後の状態が不正なら 422。
  - 外部人事の項目を含むと 422。
  - 権限が無いと 403。
  - 付与予定の再計算の Reactor が、同じトランザクションで動く。
  - ロールについて: Feature の変更でグループの同期が起きる。システムのロールの廃止で全て戻る。
- frontend の画面のテスト:
  - 保存ボタンが1つで、変更が無いと無効になる。
  - 変えた項目だけを送る。
  - 422 の項目のエラーが、その項目の下に出る。
  - 外部人事の項目が無効になる。
- E2E: 利用者・ロールを操作する既存の spec で文言を追随させるだけ。新しい E2E は足さない。

## 対象外
- 働き方の月ごとの割当の操作
- 所属変更のダイアログ
- 既存の項目ごとの API の廃止

## ドキュメントへの影響
- `docs/15-usecases-admin.md`(利用者・ロールの編集)
- `.claude/skills/ui-interaction-patterns` §2.8: まとめて保存の形(変更した項目だけ送る、全て成功か全て戻す、項目ごとのエラー、離脱警告)を書く。
- `.claude/skills/api-design`: まとめて保存のエンドポイントの書き方を書く。既存のコマンドを1トランザクションで dispatch し、項目のキー付きの 422 を返す。
- 変更セット`20261123-form-layout-standard`の論点8: 解決済みと追記する。

## 実装結果
未着手
