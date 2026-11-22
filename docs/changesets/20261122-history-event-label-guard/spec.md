# 履歴画面のイベント表示名の漏れを機械的に検出する

- ステータス: 実装中
- 種別: プロセス改善(backend・frontend・CI・スキル)

## 変更要望(原文)

> 他にもイベントの名前の解決が必要な場合に漏れないようにして欲しいのですが、プロセスの改善はできますか？

## 背景・目的

変更セット`20261121-leave-history-event-detail`で、有給履歴にイベントコードがそのまま表示されていた。
原因は、backendにイベントを追加したときにfrontendの表示名・詳細文の追加が漏れたこと。
漏れを人の注意やチェックリストに頼らず、**CIが落ちる仕組み**で防ぐ。

## 現状(As-Is)

- イベント種別の正は`backend/config/event-sourcing.php`の`event_class_map`(エイリアス `ドメイン.種別`)。
- 種別をそのまま画面に出すのは次の4画面で、表示名はfrontendの`frontend/src/utils/statusLabels.ts`にある手書きの表で引き当てる。
  未登録の種別は**エラーにならずイベントコードを返す**(漏れが静かに表示へ出る)。
  | 画面 | backendの取得範囲 | 表示名 / 詳細文 |
  |---|---|---|
  | 有給履歴 | `LeaveHistoryQuery`(付与・申請・口座の集約)→ `paid_leave_account.*` / `paid_leave_request.*` | `leaveEventSuffixMeta` / `leaveEventDetail()` |
  | 特別休暇履歴 | 同 → `special_leave.*` / `special_leave_account.*` | 同上 |
  | 代休履歴 | 同 → `compensatory_leave.*` / `compensatory_leave_account.*` | 同上 / `compensatoryLeaveEventDetail()` |
  | 備品の履歴(備品詳細) | 備品の集約 → `asset.*` | `assetHistoryEventTypeLabels` |
- 管理者の監査ログ画面(`AuditLogPage`)は、検索条件と同じ値として**イベントコードを意図的にそのまま表示**している。
- frontendのCI(`.github/workflows/frontend.yml`)は lint・`npm run build`(`tsc -b`を含む)・Storybookのビルドのみで、
  **ユニットテスト(vitest)は流れていない**。そのためfrontendのテストで検出する方式はCIでは効かない。backendのCI(`tests.yml`)はPHPUnitを流す。

## 設計前提の検証

- 原因の分類: プロセスの欠陥(backendとfrontendで種別の一覧が二重管理で、突き合わせる仕組みが無い)。
- 表示名はfrontendに持つ方針(文言・書式は画面の責務)を変えない。

## 仕様検討

### 論点1: 漏れの検出方法

- 案A(推奨): **backendから種別の一覧をTypeScriptに書き出し、frontendの表を型で網羅させる**。
  1. backendのartisanコマンドが、`event_class_map`から上の4画面が扱う接頭辞の種別を抜き出し、
     `frontend/src/generated/historyEventTypes.ts`(`as const`の配列と型)へ書き出す(生成物もコミットする)。
  2. backendのPHPUnitテストが、コミット済みの生成ファイルとコマンドの出力が一致することを確かめる
     → イベントを足して書き出しを忘れると**backendのCIが落ちる**。
  3. frontendの表を`Record<生成された型, ...>`にし、詳細文の`switch`に網羅チェック(`never`)を入れる
     → 書き出し後に表示名・詳細文が足りないと**`tsc`(=`npm run build`)が落ちる**。エディタ上でもすぐ赤くなる。
- 案B: frontendのユニットテストで生成した一覧を全件回して確かめる。テストをCIで流す追加作業が要り、型ほど早く気づけない。
- 案C: 表示名をbackendのAPIが返す。文言が2か所に分かれ、frontendの責務を崩すため採らない。
- 案D: スキル(`add-domain-event`)のチェックリストに追記するだけ。今回もチェックリストがあっても漏れたため、単独では不十分。
- **決定: 案A。あわせて案Dのチェックリスト追記も行う**(機械検出の手順=「書き出しコマンドを流す」を案内するため)。

### 論点2: 対象画面

- **決定: 有給・特別休暇・代休の履歴と、備品の履歴の4画面**。
- 監査ログ画面は対象外(イベントコードを検索・照合に使う画面のため、そのまま表示する現仕様を維持)。
- ワークフローの履歴(`workflowRequestHistoryActionLabels`)はイベント種別ではなく履歴テーブルの`action`を引き当てるため対象外。

### 論点3: 未登録時のフォールバック

- 型で網羅させた後も、生成前の古いデータや想定外の値に備えて実行時のフォールバックは残す。
  ただし表示名はイベントコードではなく、**詳細文は空文字・表示名は「その他」**とする(利用者にコードを見せない)。

### 論点4: frontendのユニットテストをCIで流すか

- 本件の検出は型(`tsc`)で完結するため必須ではないが、テストが流れていないこと自体が品質上の穴。
- **決定: 本変更セットに含める**(`frontend.yml`に`npm test`(`vitest run --project=unit`)を追加)。
  既存テストが全件通ることは2026-11-21に確認済み(68ファイル/688件)。

## 仕様確定事項(まとめ)

1. artisanコマンド `event-types:export-history`(名前は実装時に衝突確認)を追加し、対象の接頭辞
   (`paid_leave_account.` `paid_leave_request.` `special_leave.` `special_leave_account.`
   `compensatory_leave.` `compensatory_leave_account.` `asset.`)の種別を画面ごとの配列として
   `frontend/src/generated/historyEventTypes.ts` に書き出す。
2. PHPUnitで「生成ファイルが最新であること」を検証する(差分があれば再生成のコマンドを失敗メッセージに出す)。
3. `statusLabels.ts`の表示名の表・詳細文を生成された型で網羅させ、漏れを`tsc`のエラーにする。
4. 実行時フォールバックは表示名「その他」・詳細文は空文字。
5. `frontend.yml`にユニットテストの実行を追加する。
6. `.claude/skills/add-domain-event/SKILL.md` に「履歴に出るイベントを足したら書き出しコマンドを流し、`statusLabels.ts`の表示名・詳細文を足す」を追記する。

## 受け入れ条件

- 対象の接頭辞にイベントを1件足して書き出しを忘れると、backendのテストが失敗する。
- 書き出した後、表示名または詳細文を足さないと `npm run build` が失敗する。
- 現在の全種別で表示名・詳細文が揃っている(足りない種別があれば本件で追加する)。

## 対象外

- 監査ログ画面・ワークフロー履歴(論点2)。
- 表示文言の見直し(既存の文言は変えない)。

## ドキュメントへの影響

- `frontend/CLAUDE.md`・`backend/CLAUDE.md` に書き出しコマンドを1行追記。

## 実装対象

- backend: artisanコマンド、PHPUnitテスト
- frontend: `src/generated/historyEventTypes.ts`(生成物)、`src/utils/statusLabels.ts`・テスト
- CI: `.github/workflows/frontend.yml`
- スキル: `.claude/skills/add-domain-event/SKILL.md`

## 検証方法

- backend: `./vendor/bin/phpunit` で新テスト、試しに種別を足して失敗することを確認。
- frontend: `npx tsc -p tsconfig.app.json --noEmit`、`npm test`、試しに表から1件消して`tsc`が失敗することを確認。

## レビュー履歴

- 2026-11-22: 初版。ユーザー承認(監査ログ除外・frontend CIへのテスト追加を含め推奨案どおり)

## 実装結果

(実装後に追記)
