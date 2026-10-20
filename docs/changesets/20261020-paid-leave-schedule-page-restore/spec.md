# 有給休暇の付与予定画面の復元

ステータス: レビュー中

## 変更要望(原文)
> 以前に実装として有給の付与計画を一覧で見られる機能があったと思うのですが、現状では見られません。
> どうなっているのか調べてください。最終的な要件に合わせて復元を試みたいです

> 有給休暇サブメニューの中で、付与予定ボタンから遷移する画面が良いです。ボタンは適切な場所を
> 考えてください。改めて仕様をまとめてください

## 背景・目的
付与予定(`paid_leave_schedule_entries`)を確認・判定・一括付与する管理画面は、`3a4187d`
(2026-09-19、コミットメッセージ「付与予定画面を削除」)でフロントエンドだけが意図的に削除された。
削除理由は記録されていない(`docs/changesets/20260919-paid-leave-schedule-rebuild/spec.md:45-47`に
「別対応で削除済み」とあるのみ)。バックエンドAPI・ドメイン・仕様書は残っている。そのため現状は、
cronが作る付与予定を人事が事前に確認できず、UC-P013〜P015(`docs/09-usecases-paid-leave.md`)を
画面から実行できない。ユーザーの依頼に基づき画面を復元し、最終要件(UC-P013〜P015、
`docs/testing/scenario-tests.md` §18)に合わせる。

## 現状(As-Is)
- 経緯
  - `11be259`/`408fa12`(2026-09-09): 画面を追加(独立ナビ「付与予定」)。
  - `46c32d6`(2026-09-13): ナビ項目を外し、`PaidLeavePolicyPage`の最上部に右寄せの
    「付与予定を確認」ボタンを置いた。
  - `3a4187d`(2026-09-19): 削除。対象はページ、コンポーネント2種、API関数5、フック5、型7、
    ラベル2、e2e(`scenario-15`)、ボタン、PolicyPageのリンクテスト、`scenario-00`の到達確認行。
    あわせて「付与ポリシー」を「有給休暇」に改称した。
- フロント(現在)
  - `adminNavGroups.ts:93-97`の「有給休暇」(`/admin/paid-leave`、`leave.manage`)は単独の項目。
  - `AdminLayout.tsx:40-43`の`NavLink`は`end`付き(完全一致)なので、下位パス(既存の
    `paid-leave/rules/:ruleId/edit`を含む)ではナビが点灯しない。
  - `App.tsx:238-241`に`paid-leave`と`paid-leave/rules/:ruleId/edit`のルートがある。
  - `PaidLeavePolicyPage.tsx:846-854`はページ見出しを持たず、Cardを3枚並べる
    (「付与ルール」:452、「手動付与」:675、「使用状況」:796)。「付与ルール」Cardは
    `actions`を使っていない。
  - docコメント`:837-845`は、存在しないドリルダウン導線を記述している。
- 旧画面(`3a4187d^:frontend/src/pages/paidLeave/PaidLeaveSchedulePage.tsx`)
  - 状態フィルタ5種(トグルボタン、URLクエリ`filter`)と一覧Tableを持つ。
  - 行クリックで詳細Sheet(`PaidLeaveScheduleAssessmentPanel`: 内訳・再判定・Override)を開く。
  - Eligible行を選ぶと`PaidLeaveScheduleBulkGrantBar`が出て、一括付与と成否の集計を表示する。
  - 社員名・期間の絞り込み、一括付与の確認ダイアログは無い。
  - `isLoading`中は画面全体を`LoadingState`に置き換える(`:121`)。
- バックエンド(現在)
  - `backend/routes/api.php:441-445`(`permission:leave.manage,any`): index / show / bulk-grant /
    reassess / override。
  - `index`(`PaidLeaveScheduleController.php:66-83`)が受け付けるのは`filter`だけ。
    `with('user')`で社員名を読む。ページングは無い。
  - reassess / overrideは`summarize()`の形を返す。
  - `ManuallyEditScheduleEntry` CommandとHandlerはテスト済みだが、ルートもUIも無い。
    - Aggregate(`PaidLeaveScheduleAggregate.php:230-240`)は、確定・取消済み(`FINALIZED_STATUSES`)の
      行を`DomainRuleException`で拒否する(422、`bootstrap/app.php:71-75`)。
    - apply(`:390-397`)は`changes`の中で行に存在するキーを、何であれ上書きする(許可キーの
      制限が無い)。
    - 修正済みの行は、通常の`RecalculateFutureSchedule`から保護される。ただし付与ルール・
      法定ポリシーの再適用(UC-P011b、`overrideManualEdits=true`)では上書きされる
      (`PaidLeaveScheduleAggregateTest.php:149,170`)。
  - 区分値の定数は`GrantCategoryClassifier.php:21-27`にある(`normal`/`proportional`/`shift`/
    `needs_review`)。
  - `PaidLeaveScheduleEntryResource`は未使用。
- 仕様書と実装のずれ
  - `docs/09`
    - UC-P013: 社員名・期間の絞り込みは未実装。区分値を`regular`/`NeedsReview`と書いている。
      `changed`を合成フィルタと書いている。
    - UC-P014: PATCHは未実装。
    - UC-P015: `apply-grants`/`entry_ids`/`granted_paid_leave_grant_id`/「エントリごとにCommand」と
      書いているが、実装は`bulk-grant`/`schedule_entry_ids`/`grant_id`/社員ごと。
    - UC-P012: `assessment_*`を書いているが、実体は`paid_leave_schedule_assessments`の列。
    - `:42-43`: 旧名称「付与ポリシー」のまま。
  - `docs/16`: `paid_leave_schedule_entries`の節が`:1053`と`:1255`に重複している。後者は実在しない
    列・誤ったProjector名・`regular`を含む。
  - `docs/17:417-419`(`entry_manually_edited`): 上書きの例外と`changes`のキーの記述が無い。
  - `docs/testing/scenario-tests.md` §18: 実在しない`GrantDaysResolver`を記述している。

## 設計前提の検証
- 正データと派生データ
  - 付与予定の正は`PaidLeaveScheduleAggregate`(AggregateId=userId)のイベントで、
    `paid_leave_schedule_entries`/`paid_leave_schedule_assessments`はそのProjection。
  - 画面は読み取りAPIとCommand発行API(reassess / override / bulk-grant / 新設のPATCH)だけを使い、
    Projectionを直接書く経路は作らない(原則1・2)。
  - 一括付与は既存経路`ApplyScheduledGrants`→`PaidLeaveAccountAggregate::grant()`を使い、
    付与の正は`paid_leave_grants`(原則14・15、変更しない)。
- 定義と実態
  - 区分値・`changed`の定義・一括付与のパス名のずれは、ドキュメント側の誤りと判断する
    (実装・テスト・stored_eventsのpayloadが一貫しているため)。
  - `is_manually_overridden`をProjectionで立てるのは`ManuallyEdited`イベントだけ
    (`PaidLeaveScheduleProjector.php:97`)で、Overrideでは立たない。過去の移行
    (`PaidLeaveScheduleRebuilder.php:167-176`)の分を除き、修正APIが無い現状では`changed`の行は
    新たに増えない。
  - `users.name`による絞り込みは、既存の`with('user')`と同じく表示・検索のための読み取りだけ。
    書き込みも業務判定もしないので、原則15の「他文脈の集約の操作」には当たらない。
- 原因の分類: 機能の消失は、記録の無い仕様変更(画面の削除)による。バックエンド・仕様は画面が
  ある前提のまま残っているので、設計の誤りではない。ただしPATCHを開ける際、Aggregateが
  `changes`のキーを制限していない穴は塞ぐ(論点3)。

## 仕様検討

### 論点1: 画面への入り方とボタンの位置
- 選択肢:
  - A. ページ最上部に右寄せの単独ボタン(`46c32d6`の形)。見出しの無い画面にボタンが浮き、
    どのカードに関係するかが分からない。
  - B. 「付与ルール」Cardヘッダーの`actions`に置く。付与予定は付与ルールと勤務形態から生成される
    ので、関係が読み取れる。既存の`Card` APIで実現でき、`actions`は未使用なので衝突しない。
  - C. 「手動付与」Cardに置く。手動付与は付与予定とは別の経路(UC-P002)で、混同を招く。
  - D. 管理ナビをサブメニュー化する。ユーザー指定(ボタンから遷移)に反する。
- 決定: B
- 理由: ユーザー指定を満たし、付与予定の発生源である付与ルールの近くで意味が通る。
- 未確定・要確認事項: なし

### 論点2: 付与予定画面の構成・戻り導線・ナビの点灯
- 選択肢:
  - A. 旧画面をそのまま復元する。
  - B. 旧画面を土台にして、論点3〜6と、ui-interaction-patternsに足りない点を補う。
- 決定: B。
  - 画面上部に`← 有給休暇`(`/admin/paid-leave`)を置く。
  - ナビ項目は足さない。
  - ナビの点灯: `AdminNavItem`に任意の`matchPrefix?: boolean`を足す。`true`の項目は`NavLink`の
    `end`を外し、前方一致で点灯させる。設定するのは「有給休暇」だけにする。これで付与予定画面と
    既存のルール編集画面でも「有給休暇」が点灯する。
- 理由: 全項目から`end`を外すと、`/admin`のような短いパスの項目が常に点灯する恐れがある。
  項目ごとの指定なら影響を「有給休暇」に限定できる。
- 未確定・要確認事項: なし

### 論点3: 区分・候補日数の個別修正(UC-P014後半、PATCH)
- 選択肢:
  - A. 区分・候補日数・付与予定日を修正可能にする(初版の案)。
  - B. 区分・候補日数だけを修正可能にする(仕様UC-P014の文言どおり)。
  - C. 今回は入れない。
- 決定: B。今回の範囲に含めることはユーザー確認済み(2026-10-20)。
  - 付与予定日は変更対象にしない。
  - Aggregateの`manuallyEditScheduleEntry`で許可するキーを`category`/`candidateGrantDays`に
    限定し、それ以外のキーは`DomainRuleException`で拒否する。
- 理由: 付与予定日を動かすと次の問題が起きるため、仕様の範囲(B)に留める。
  - 元の日付に予定が無くなり、翌日のcronやRecalculateが元の日付の予定を作り直して二重付与に
    なる恐れがある(`Aggregate.php:59-70,112,320-329`)。
  - 判定期間と付与日・有効期限が食い違う。
  - 日付を前にずらすと、口座の「新しい付与は最新の付与より後」という不変条件で一括付与が失敗する。
  - キーを制限するのは、`status`などが混入すると集約の状態とProjectionが食い違うため。
- 未確定・要確認事項: なし

### 論点4: 一覧の絞り込みの形とページング
- 選択肢:
  - A. 状態フィルタだけにする(旧画面どおり)。UC-P013-3と§18-4を満たさない。
  - B. 状態・社員名・付与予定日の期間を1行の絞り込み欄にまとめる(参照実装`ApprovalsPage.tsx:240-`
    の形)。ページングは無し。
  - C. Bにページングを加える。
- 決定: B。
  - 状態は`NativeSelect`(5種)、社員名は`Input`、期間は`DateRangePicker`にする。
  - 値はすべてURLクエリ(`filter`/`user_name`/`scheduled_on_from`/`scheduled_on_to`)で保持し、
    ANDで組み合わせる。
  - 社員名はEnterまたはblurで反映する。
  - 再取得中は前の結果を表示したままにする(`placeholderData: keepPreviousData`)。画面全体を
    読み込み表示にするのは初回だけ。
- 理由:
  - 最終要件に含まれる。
  - 参照実装と揃えると、画面間の挙動が一致する(ui-interaction-patterns)。
  - 件数は社員数×(1年先+履歴)程度で、全件返却で足りる。
- 未確定・要確認事項: なし

### 論点5: 一括付与の確認
- 選択肢:
  - A. 確認せず即時に実行する(旧画面・`ManualGrantCard`と同じ)。
  - B. `ConfirmActionDialog`で確認してから実行する。
- 決定: B。
  - 本文には件数・合計付与日数・付与予定日の範囲(最早〜最遅)を出す。
  - 「付与後は付与予定画面から取り消せません(取消は使用状況から付与ごとに行います)」と書く。
- 理由: Grantedへの遷移は戻せず、ui-interaction-patterns §2.12の「戻せない操作は確認する」に
  当たる。複数社員にまとめて効くので、単発の手動付与より影響が大きい。
- 未確定・要確認事項: なし

### 論点6: 仕様書と実装の名称のずれ
- 選択肢:
  - A. 実装を仕様書に合わせる(ルート改名・区分値のデータ移行)。
  - B. 仕様書を実装に合わせる。
- 決定: B(直す内容は「ドキュメントへの影響」に列挙)。
- 理由: 実装・テスト・stored_eventsのpayloadが揃っていて、改名すると移行が要るのに価値が無い。
- 未確定・要確認事項: なし

### 論点7: e2e(scenario-15)
- 選択肢:
  - A. 書かずにunit/storyテストだけにする。
  - B. 現行の導線・API・画面の文言で新しく書き直す。
- 決定: B。
  - ファイル名は`scenario-15-paid-leave-schedule.spec.ts`。
  - 旧ファイルは当時からAPIや画面と合っていなかったので、復元ではなく書き直しとする
    (`status=`/`per_page`/`apply-grants`/`role=tab`、パネルに無い文言を使っていた)。
  - 部分失敗(Eligible以外を含む一括付与)は、UIでは選べないのでAPIを直接叩いて確認する。
  - `scenario-00-master-data.spec.ts`の到達確認に`/admin/paid-leave/schedule`を戻す。
- 理由: §18が主要導線そのもの。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

### 画面と導線(frontend)
- `PaidLeavePolicyPage`
  - 「付与ルール」Cardの`actions`に`Button asChild variant="secondary" size="sm"`の
    `Link to="/admin/paid-leave/schedule"`「付与予定を確認」を置く。
  - docコメント`:837-845`を現状に合わせる。
  - リンクのテストを`PaidLeavePolicyPage.test.tsx`に戻す。
- `App.tsx`: `RequireAdminRoute`配下に`paid-leave/schedule`→`PaidLeaveSchedulePage`を登録する。
- ナビ: `adminNavGroups.ts`の型に`matchPrefix?: boolean`を足し、「有給休暇」に`true`を付ける。
  `AdminLayout.tsx`では`end={!item.matchPrefix}`にする。`AdminLayout.test.tsx`に「下位パスで
  有給休暇が点灯し、他の項目は完全一致のまま」のテストを足す。

### 一覧画面 `PaidLeaveSchedulePage`
`3a4187d^`の版を土台にする。
- 上部
  - `← 有給休暇`リンク、`Card title="付与予定"`。
  - 絞り込み欄1行: 状態(`NativeSelect`)、社員名(`Input`、Enter/blurで反映)、付与予定日の期間
    (`DateRangePicker`)。
  - 絞り込みを変えたら、選択と一括付与の結果をリセットする。
- 一覧
  - 列は旧画面どおり: 選択 / 社員 / 付与予定日 / 区分 / 付与候補日数 / 出勤率 / 判定状態。
  - チェックボックスはEligible行だけ。
  - 「変更あり」バッジ(`is_manually_overridden`)を出す。Granted・取消の行でも出してよい。
    「変更あり」の絞り込みにはこれらの状態も含む。
  - NeedsReview行には「勤務形態を確認」リンクを出す。
- 読み込み: 初回だけ全体を`LoadingState`にする。再取得中は前の結果を表示したまま。
- 空表示
  - 絞り込み無し:「付与予定はまだありません。」
  - 絞り込みあり:「条件に一致する付与予定はありません。」と`[検索条件をクリア]`ボタン
    (URLクエリを消す)。
- 一括付与
  - `PaidLeaveScheduleBulkGrantBar`の「一括付与する」を押したら`ConfirmActionDialog`を開く。
  - タイトルは「付与予定を一括付与しますか?」。
  - 本文: 「N件(合計X日)」、付与予定日の範囲、「付与後は付与予定画面から取り消せません。
    取消は使用状況から付与ごとに行います。」
  - 確定ボタンは「一括付与する」。
  - 実行後は`BulkGrantResultSummary`(N件成功 / M件失敗と理由)を出す。
- キャッシュ
  - 一括付与の成功後は、付与予定のキー(`['paid-leave','schedule-entries']`)に加えて、
    有給休暇画面の使用状況・付与・残高のクエリキー(`hooks/usePaidLeave.ts`の既存キー)も無効化する。
  - reassess / override / PATCHの後は、一覧と詳細を無効化する。
- API・フック
  - 関数`fetchPaidLeaveScheduleEntries(params: { filter, user_name?, scheduled_on_from?,
    scheduled_on_to? })`。クエリキーには4つすべてを含める。
  - `reassess`/`override`/`edit`の戻り値の型は`PaidLeaveScheduleEntry`(summarizeの形)。
  - 新規に`editPaidLeaveScheduleEntry(id, { category?, candidate_grant_days?, reason })`と
    `useEditPaidLeaveScheduleEntry()`を足す。
  - そのほかの型・ラベルは`3a4187d^`の名前で戻す。

### 詳細パネル `PaidLeaveScheduleAssessmentPanel`
- `PaidLeaveSchedulePage`では`key={entry.id}`で描画する(行を切り替えたら入力を初期化するため)。
- 既存の3ステップ(内訳 / 再判定 / 判定結果の上書き)の後に、「4. 区分・日数を修正」を足す。
  - Granted・取消の行ではこのステップを出さない。
  - 入力は2つ。初期値は現在の値。
    - 区分: `NativeSelect`、`normal`=通常 / `proportional`=比例 / `shift`=シフト。
    - 付与候補日数: `Input type=number step=0.5 min=0.5`。
  - 修正理由: `Textarea`、必須。空なら「修正理由を入力してください。」。
  - 送信するのは現在値から変わった項目だけ。変わった項目が無いときはボタンを無効にする。
  - 注意書き:「付与ルール・法定付与日数を変更すると、この修正は再計算で上書きされます。」
  - 区分を`needs_review`から修正しても、判定状態は自動では変わらない。状態がNeedsReviewの行では
    「区分を修正した後、再判定または判定結果の上書きを行ってください。」と表示する。
  - 修正済みの行では`manual_override_reason`を表示する(詳細APIで既に返っている)。
- 未保存の変更(上書きフォームまたは修正フォームの入力)があるままSheetを閉じようとしたら、
  既存の未保存警告パターンで確認する(ui-interaction-patterns §2.9)。閉じたときには
  override/editのmutationの状態もリセットする。

### バックエンド
- `PaidLeaveScheduleController::index`
  - 追加するパラメータ: `user_name`(string)、`scheduled_on_from`/`scheduled_on_to`(`date`)。
    `scheduled_on_to`には`after_or_equal:scheduled_on_from`をかけ、違反は422。
  - `user_name`は`whereHas('user', fn ($q) => $q->where('name', 'like', ...))`で絞り込む。
    `%`・`_`・`\`はエスケープする。期間は両端を含む。
- PATCH
  - ルート: `PATCH /paid-leave/schedule-entries/{scheduleEntry}` → `PaidLeaveScheduleController::update`
    (既存の`permission:leave.manage,any`グループ内)。
  - 入力
    - `category`: `in:normal,proportional,shift`。
    - `candidate_grant_days`: `numeric|min:0.5|multiple_of:0.5`。
    - 上の2つのうち少なくとも1つが必須(`required_without`)。
    - `reason`: `required|string|max:1000`。
  - 処理
    - snake_caseのキーをcamelCase(`category`/`candidateGrantDays`)に変換し、
      `ManuallyEditScheduleEntry`(operatorはログインユーザー)を発行する。
    - 返すのは`summarize()`の形。
    - 確定・取消済みの行は既存の`DomainRuleException`で422になる。
  - Aggregateの`manuallyEditScheduleEntry`: `changes`のキーが`category`/`candidateGrantDays`
    以外を含む場合、または空の場合は`DomainRuleException`を投げる。Handlerを経由する既存の
    呼び出し元(テスト)はこの2キーだけを使っていることを確認する。
- テスト
  - Aggregateの単体テスト(`PaidLeaveScheduleAggregateTest`): 許可外のキーで例外、空の`changes`で
    例外、Grantedの行で例外。
  - Controller(`PaidLeaveScheduleControllerTest`)
    - index: `user_name`の部分一致(`%`を含む名前のエスケープを含む)、期間の両端、状態フィルタとの
      AND、from>toで422。
    - PATCH: 正常系(イベントが記録され、Projectionの`category`/`candidate_grant_days`/
      `is_manually_overridden=true`/`manual_override_reason`が更新され、`filter=changed`でヒットする)、
      理由なしで422、項目なしで422、`scheduled_on`を送っても無視されて変わらない、
      `candidate_grant_days=0.3`で422、Granted済みで422、`leave.manage`なしで403。
  - シナリオテスト(`domain-test`)
    - PATCHの後に勤務形態の変更でRecalculateが走っても、修正が保持される。
    - 付与ルールの再適用(UC-P011b)では、修正が上書きされる。
    - 既存テストで同じ連鎖を確認できるなら、それで代えてよい。

## 受け入れ条件
- 管理画面「有給休暇」の「付与ルール」カードに「付与予定を確認」ボタンがあり、押すと
  `/admin/paid-leave/schedule`が開く。`← 有給休暇`で戻れる。付与予定画面とルール編集画面でも、
  ナビの「有給休暇」が点灯する。
- 状態・社員名・付与予定日の期間で絞り込める。条件はURLに残り、再読込しても保たれる。0件のときは
  「検索条件をクリア」で戻せる。
- 行をクリックすると詳細パネルが開き、内訳の確認・再判定・Override(理由必須)ができる。
- 詳細パネルで区分・候補日数を理由付きで修正できる。修正した行には「変更あり」が付き、
  「変更あり」の絞り込みに出る。付与予定日は修正できない。確定済みの行は修正できない。
- Eligible行を複数選んで一括付与すると確認ダイアログが出て、確定すると成功・失敗の件数と理由が
  表示される。その後、有給休暇画面の使用状況にも反映されている。
- `leave.manage`の無い利用者は、画面にもAPIにも到達できない。
- backendの追加テスト(Aggregate・Controller・シナリオ)、frontendのunit/story(新規・既存)、
  e2eの`scenario-15`と`scenario-00`がPASSし、既存のテスト一式にも退行が無い。
- 「ドキュメントへの影響」に挙げた記述が実装と一致している。

## 対象外
- 管理ナビへの「付与予定」項目の追加。
- 付与予定日の個別変更(論点3)。
- 一覧のページング・並べ替えの変更(`scheduled_on`昇順のまま)。
- 付与予定日より前に一括付与できてしまうこと(Handlerに日付の確認が無い)への対策。確認ダイアログに
  付与予定日の範囲を出すだけに留める。
- 社員本人向けの「次回付与予定」表示。
- 未使用の`PaidLeaveScheduleEntryResource`の整理。
- `paid-leave:schedule:rebuild`など運用コマンドのUI化。
- 付与予定の生成・判定ロジック(`ScheduleCandidateGenerator`・`AttendanceRateAssessor`)の変更。

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`
  - `:42-43`: 「有給休暇」画面(`/admin/paid-leave`)の「付与ルール」カードにある「付与予定を確認」
    ボタンから遷移する、と書く。
  - UC-P012: `assessment_final_result`等を`paid_leave_schedule_assessments`の列として書き直す。
  - UC-P013
    - パラメータ`filter`と、社員名・期間のパラメータ名を書く。
    - 区分値を`normal`/`proportional`/`shift`/`needs_review`にする。
    - `changed`は「個別修正済み(`is_manually_overridden=true`)の行。状態を問わない」とする。
  - UC-P014(PATCH)
    - 修正できる項目は区分・候補日数に限り、付与予定日は対象外。
    - 理由は必須。確定・取消済みの行は修正不可。
    - 通常の再計算からは保護されるが、付与ルール・ポリシーの再適用では上書きされる。
  - UC-P015
    - `POST /paid-leave/schedule-entries/bulk-grant`(`schedule_entry_ids`)。
    - Commandは社員ごとに発行する。付与IDは`grant_id`。
    - 実行前に確認ダイアログを出す。
- `docs/16-database-schema.md`
  - 重複している`:1255`の節を削除し、`:1053`の節に一本化する。
  - `:1069`の「管理者による手動判定上書き」を「区分・候補日数の個別修正(`ManuallyEditScheduleEntry`)」
    に直す。
  - 区分値を実装に合わせる。
- `docs/17-events.md:417-419`(`entry_manually_edited`): `changes`のキー(`category`/
  `candidateGrantDays`)と、付与ルール・ポリシーの再適用で上書きされる例外を追記する。
- `docs/testing/scenario-tests.md` §18
  - 手順3を「有給休暇画面の『付与予定を確認』から開く」にする。
  - 区分・日数の修正の手順を足す。
  - 部分失敗はAPI経由で確認すると書く。
  - `GrantDaysResolver`の記述を実在するクラス名に直す。
- `docs/changesets/README.md`: 本変更セットの行(作成済み)。

## モック・アセット
なし(旧画面`3a4187d^`の構成を土台にし、追加分は本文で定める)

### 実装前メモ(ui-interaction-patterns §3.3)
- 画面の種類: 一覧+詳細Sheet(閲覧・判定操作)、一括操作あり。
- 参照実装: 絞り込み欄は`ApprovalsPage`、行クリックで詳細Sheetを開く形は旧`PaidLeaveSchedulePage`、
  確認は`ConfirmActionDialog`。
- 状態: 初回読み込み / 再取得(前の結果を表示) / 0件(絞り込み無し・あり) / エラー
  (`ErrorState`) / 権限なし(ルートで遮断)。
- 戻せない操作: 一括付与(確認する)。判定の上書きと区分の修正は、再判定・再修正で戻せるので
  確認しない。

## 実装対象
- frontend
  - `src/pages/paidLeave/`(`PaidLeavePolicyPage`の変更、`PaidLeaveSchedulePage`の
    page / stories / test)
  - `src/components/PaidLeaveScheduleAssessmentPanel/`、`src/components/PaidLeaveScheduleBulkGrantBar/`
  - `src/components/AdminLayout/`(`adminNavGroups.ts`、`AdminLayout.tsx`とテスト)
  - `src/api/paidLeave.ts`、`src/api/types.ts`、`src/hooks/usePaidLeave.ts`、
    `src/utils/statusLabels.ts`、`src/App.tsx`
  - `e2e/scenario-15-paid-leave-schedule.spec.ts`、`e2e/scenario-00-master-data.spec.ts`
- backend
  - `app/Http/Controllers/Api/PaidLeaveScheduleController.php`、`routes/api.php`
  - `app/Domain/PaidLeaveSchedule/Aggregates/PaidLeaveScheduleAggregate.php`(許可キーの規則)
  - `tests/Unit`または`tests/Feature/PaidLeaveSchedule/`
- docs: 上記「ドキュメントへの影響」

## 検証方法
- backend: `php artisan test --filter=PaidLeaveSchedule`と、テスト一式
- frontend: `npx vitest run`、storybookテスト、`npx tsc --noEmit`、`npm run lint`
- e2e: `npx playwright test e2e/scenario-15-paid-leave-schedule.spec.ts e2e/scenario-00-master-data.spec.ts`

## レビュー履歴
- 初版(2026-10-20)
- 2026-10-20 ユーザー確認: 論点3の個別修正(PATCH)を今回の範囲に含める。
- 2026-10-20 独立設計レビュー(同等モデル)の指摘を反映した。設計前提の大枠は変わらないので、
  作り直さず改訂とした。
  - 要修正
    - PATCHから`scheduled_on`を外した(二重付与・判定期間の不整合・口座の不変条件)→論点3をBに変更。
    - Granted/Cancelledを拒否する規則が既存だったので条件分岐を削除し、Aggregateに許可キーの規則を
      追加した。
    - 一括付与の確認ダイアログが無かった→論点5を新設。
    - ナビの点灯の前提が誤り(`end`付きの完全一致)だった→論点2に`matchPrefix`を追加。
    - `docs/16`の重複節が漏れていた→ドキュメントへの影響に追加。
  - 推奨
    - 絞り込み欄を`ApprovalsPage`の形にし、`keepPreviousData`と「検索条件をクリア」を追加。
    - 未保存の警告と`key`による初期化を追加。
    - ラベルを「修正理由」にした。
    - 一括付与の後に有給休暇画面のキャッシュも無効化する。
    - リンクのテストと`scenario-00`の行を戻す。
    - e2eは書き直しと明記。
    - ポリシーの再適用で修正が上書きされることの注意書きとテストを追加。
    - `docs/09`(UC-P012/P015)・`docs/17`・§18の修正漏れを追加。
    - 削除理由の記述を正確にした。
    - 実装前メモを追加。
  - 軽微
    - 区分値の定数の場所を訂正した。
    - `changed`が新たに増えない、という表現を正確にした。
    - `multiple_of:0.5`を追加した。
    - 「変更項目なし」を、UIでは差分なし、APIではキーなし、と定義した。

## 実装結果
未着手
