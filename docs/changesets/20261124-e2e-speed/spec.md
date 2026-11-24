# e2e-speed(E2Eの短縮と、失敗をすぐ検知できるようにする)

ステータス: レビュー中

## 変更要望(原文)
> E2Eテストにかかる時間が増えています。できる限り単体テストで確認ができるような実装を心がけてください。
> 特に確認していただきたいのはドメインのテストで多様なシナリオの確認は単体テストで済ませてほしいです。E2Eテストでも大きなシナリオは確認したいですが、できる限り素早くエラーを検知し、改修の素早さを落とさないようにしてください。

あわせて、変更候補 #1(`docs/changesets/candidates-20261124.md`。`scenario-10:195` が全件実行で毎回落ちる件、日本時間の0時をまたぐと `scenario-99` の打刻2件が落ちる件)をここで扱う。

## 現状(調査、2026-11-24)
- 全件で約16分(70件)。`workers: 1`。テストの時間の合計は977秒で、うち失敗した1件(`scenario-10:195`)が300秒。
- 失敗に気づくまでが長い。
  - `actionTimeout`・`navigationTimeout` が未設定のため、`click`・`fill` などは、テストの上限(120〜600秒)まで待つ。
  - `expect` は30秒待つ。
  - `scenario-10:195` は、「新規作成」を押した後に画面の移動を待たずに入力し、300秒で落ちる。単独で実行すると21秒で通る。移動を待たないため、一覧の絞り込みの「グループ種別」に当たっている。
- ログインが多い。1回の実行で、UI からのログインが約135回ある(`support/auth.ts:24`)。
- 業務ルールのバリエーションの確認が、E2E に多く含まれている。対応する backend のテストが既にあるものが多い(下の表)。
  - 例: 1か月単位の変形労働、裁量労働、法定休日、半休、経費の4区分、付与の取消、削除のガード、自己承認のスキップ、12か月の連続(66秒)。
- 不安定な要因:
  - ランダムな日付と、重なったときの再試行(`scenario-03:66`、`scenario-99:569`)。
  - 当月の状態への依存(`scenario-99` §5-3・§5-8。0時をまたぐと422で落ちる)。
- 複数のワーカーでの実行は、今はできない。DB を共有しており(SQLite)、同じ社員の当月の状態をファイルをまたいで使っているため。

## 仕様検討

### 論点1: 失敗をすぐ検知する
- 決定:
  1. 待つ上限を短くする。
     - 操作の待ちの上限を10秒にする: `page.setDefaultTimeout(10000)`。`loginAs` で作るページ、`newContext` のページの両方に効くよう、共通の補助関数で設定する。
     - 画面移動の待ちの上限を15秒にする。
     - テストの上限の既定を120秒から60秒にする。長い上書き(`scenario-08` の600秒など)は、論点2で削るものを除いて見直す。
     - `expect` は30秒のままにする(更新直後の再取得を待つため)。計測して短くできれば、実装結果に書く。
  2. 開発用の実行を足す。
     - `npm run test:e2e:quick`: `--max-failures=1` で、最初の失敗で止まる。
     - 実行の前に接続先(backend `/up`、mock-oidc、vite)を数秒で確かめ、起動していなければすぐに止める(global-setup)。
  3. `scenario-10:195` を直す。「新規作成」を押した後に、URL と見出しを待ってから入力する。
     - 製品側の不具合かどうかは、直した後に全件実行で3回通るかで確かめる。通らなければ原因を調べる。
- 理由: 失敗が数十秒で分かれば、直す・流し直すの往復が速くなる。
- 未確定・要確認事項: なし

### 論点2: 業務ルールのバリエーションは backend のテストへ移す
- 決定: 次の E2E を削り、確認を backend のテスト(Unit・Feature のシナリオテスト)に任せる。
  - 移す前に、対応する backend のテストが同じことを確かめているかを照合する。足りなければ backend のテストを足してから、E2E を削る。
  - 主要導線のスモークは残す。

| E2E | 内容 | 対応する backend のテスト(照合する) | 残す E2E |
|---|---|---|---|
| scenario-07 全件 | 1か月単位の変形、裁量・管理監督者、法定休日 | MonthlyVariableWorkTimeTest、DiscretionaryAndManagerSupervisorWorkTimeTest、LegalHolidayUndeterminedTest | なし |
| scenario-08:162 | 12か月の連続(66秒) | 分散している。12か月の残高計算の業務シナリオを backend に新しく作る | なし(月次の提出〜締めは scenario-02) |
| scenario-08:396/421/452 | 警告・付与予定のバッチ | PaidLeaveWarningBatchTest、RollPaidLeaveSchedulesCommandTest、AssessPaidLeaveSchedulesCommandTest | なし |
| scenario-09 §5-14〜§5-17 | 60時間、混在制度の締め、締め後の独立性 | MonthlyOvertimeCalculationTest、AttendanceMonthLockAndShareTest、AttendanceMonthApprovalBackOfficeTaskTest ほか | なし |
| scenario-03:158 | 半休 | HalfDayLeavePrescribedMinutesTest、PaidLeaveRequestScenarioTest | scenario-03:102(全日の休暇) |
| scenario-04:243 | 経費の4区分 | ExpenseClaimFlowTest、ExpenseItemCommonFormatTest | scenario-04:77(交通費の全フロー) |
| scenario-16:45 | 付与の取消 | PaidLeaveGrantRevocationTest ほか | なし |
| scenario-15:84/142/222 | 付与予定の編集・失敗の行 | ManuallyEditScheduleEntryScenarioTest、PaidLeaveScheduleControllerTest | :122(一括付与)、:246(判定の受付) |
| scenario-11:350/587/693/790 | Role・Feature の合成、原子性、外部HRの拒否 | PermissionCatalogIntegrationTest、RoleFeatureAutoApplyTest、UserManagementAccessIntegrationTest | :248 の画面と URL だけ |
| scenario-10:386/495 | Role→Group の同期、外部HRのCSV取込 | RoleFeatureAutoApplyTest。CSV取込は backend のテストを足す | :195(グループの作成)のスモーク |
| scenario-10:110/178/561 | 画面の操作性・表示 | frontend の画面のテスト(Vitest)へ移す | なし |
| scenario-12:20 | 会社カレンダーの一連 | CompanyCalendarYearControllerTest、DuplicateCompanyCalendarYearTest、CalendarBulkOperationControllerTest | 公開〜一括適用のスモーク |
| scenario-14:145/244/273 | 備品の貸与・設置・削除のガード | AssetLendingTest、AssetInstallationTest、AssetDeletionTest | :81(セルフ貸出)、:173(申請制) |
| scenario-99 自己承認・§5-2〜§5-8 | 自己承認のスキップ、締めの確認ほか | WorkflowRequestFlowTest、AttendanceMonthApprovalNotRequiredTest、AttendanceMonthLockAndShareTest ほか | §5-1(差戻し→再申請)、§5-9(初回ログイン) |
| scenario-06 | 打刻の訂正、日次の削除 | AttendancePunchCorrectionTest、AttendanceDayDeletionTest | なし |
| scenario-00:35 | マスタの連鎖 | 前提データは API で作る | :10(マスタ画面のスモーク) |

- 残す主要導線: scenario-01(打刻)、02(月次の提出〜締め)、03:102、04:77、05(名刺・総務のタスク)、13(外部連携のスモーク)、14:81/173、15:122/246、99 §5-1・§5-9、と上の表の「残す」。
- 削った E2E は、変更セットの実装結果に「どの backend テストで確かめているか」の対応表を残す。
- 理由: 業務ルールの組み合わせは backend のテストの方が速く、失敗の場所も分かりやすい(原則16、ユーザーの指示)。
- 未確定・要確認事項: なし

### 論点3: 不安定な要因を除く
- 決定:
  - ランダムな日付と再試行(`scenario-03:66`、`scenario-99:569`): spec ごとに決めた日付(重ならない範囲)にし、再試行をやめる。
  - 当月の状態への依存(`scenario-99` §5-3・§5-8): 対象を当月ではなく固定の過去の月にするか、0時をまたいでも結果が変わらない書き方にする。論点2で削る場合は不要。
- 未確定・要確認事項: なし

### 論点4: ログインの回数を減らす
- 決定: 役割ごとのログイン状態を、最初に1回作って使い回す(Playwright の setup プロジェクトと storageState。トークンは localStorage の `flow-office.token`)。
  - DB のリセット(global-setup)の後に作る。
  - ログインそのものを確かめるテストは、今のまま UI からログインする。
- 理由: 1回の実行で約135回の UI ログインがある。推定で2〜4分かかっているが、未計測。
- 未確定・要確認事項: なし

### 論点5: 対象外(後で検討)
- 複数のワーカーでの実行: ワーカーごとに backend・DB・vite が要るため、工数・リスクが大きい。論点1〜4の後で、まだ遅ければ検討する。
- `PHP_CLI_SERVER_WORKERS` の実験: 効果は未計測で、SQLite のロックが起きるおそれがある。論点1〜4の後に計測する。

## 受け入れ条件
- E2E の全件が通る。
  - 全件実行を3回続けて行い、すべて通る(不安定な失敗が無い)。これは本変更セットの検証として、ユーザーの指示どおり1回の作業で行う。
  - 日本時間の0時をまたいでも通る。
- 全件の時間が、今(約16分)の半分以下になる。実測を実装結果に書く。
- 要素が無いときに、10〜15秒程度で落ちる。わざと失敗させて確かめる。
- 削った E2E の確認が、backend・frontend の単体テストで担保されている(対応表)。足した backend のテストが通る。
- `test:e2e:quick` と接続先の確認がある。
- `frontend/CLAUDE.md`・`domain-test` スキルに、開発中の実行方法(quick、変えた画面の spec だけ)を書く。

## 対象外
- 論点5。

## ドキュメントへの影響
- `frontend/CLAUDE.md`(E2E の実行方法)、`docs/testing/scenario-tests.md`(残したシナリオと、移した確認の対応)、`domain-test` スキル。

## 実装対象
1. 論点1(設定・quick・接続先の確認・scenario-10 の修正)。
2. 論点3(決まった日付)。
3. 論点2(照合 → backend・frontend のテストを足す → E2E を削る)。
4. 論点4(ログインの使い回し)。
5. 計測(全件3回)とドキュメント。

## 検証方法
- 単体テスト: backend の全件、frontend の全件。
- E2E: 全件3回(計測)、わざと失敗させる確認。

## レビュー履歴

## 実装結果
未着手
