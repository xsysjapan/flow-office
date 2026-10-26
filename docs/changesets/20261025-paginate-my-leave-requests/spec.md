# 自分の休暇申請の一覧(有給・特別休暇・代休)を対象日の降順でページングする

ステータス: 完了

## 変更要望(原文)

```
また、自分の有給申請については、対象日の昇順でページングされることを確認してください。
有給・代休・特別休暇すべてで同様に対応をお願いします。
```

```
すみません。昇順ではなく降順で正解でした。
```

## 背景・目的

本人の休暇申請の一覧は全件を返して全件を表示しており、件数が増えると重くなる。対象日の降順で20件ずつページングする。

## 現状(As-Is)

- API:`GET /paid-leave/requests/mine`(`PaidLeaveController::myRequests`)・`GET /special-leave/requests/mine`(`SpecialLeaveController::myRequests`)・
  `GET /compensatory-leave/requests/mine`(`CompensatoryLeaveController::myRequests`)。3つとも本人の申請を`target_date`の降順で全件返す(`->get()`)。
- 使っている箇所:
  - 本人の画面の申請一覧(`MyPaidLeavePage.tsx`・`MySpecialLeavePage.tsx`・`MyCompensatoryLeavePage.tsx`。`useMy*LeaveRequests`)。
  - 日次勤怠の画面(`pages/attendance/AttendanceDayPage.tsx` 1244行付近):3種の申請を全件読み、表示している日(`date`)の申請を探す
    (`find((r) => r.target_date === date ...)`、`LeaveDesignationLists`)。
  - MCPのツール`mcp/app/Mcp/Tools/Attendance/GetMyLeaveRequestsTool.php`(`/paid-leave/requests/mine`をそのまま返す)。
- ページングの既存の型:API側`->paginate(20)`(応答`{ data, links, meta: { current_page, last_page, total, ... } }`)、画面`components/Pagination`、
  ページ番号はURL(`ApprovalsPage.tsx`)。

## 設計前提の検証

- 申請一覧は申請の状態(`*_requests`のReadModel)の読み出しだけで、正データ・業務ルールに関わらない。
- 全件を前提にしている読み手(日次勤怠の画面)があり、ページングすると最初の20件しか届かなくなる。読み手ごとに必要な範囲で読む形に直す。
- 原因の分類:要望による変更(一覧のページングの検討。`ui-interaction-patterns` §2.20)。

## 仕様検討

### 論点1: APIの形
- 選択肢:
  - A. 3つの`requests/mine`を常にページングにし、対象日で絞る指定(`target_date`)を足す。日次勤怠の画面はその日で絞って読む。
  - B. `page`があるときだけページングにし、無ければ全件(応答の形が2通りになる)。
- 決定: A
- 理由: 応答の形を1つにする。日次勤怠の画面はその日の申請だけを使っている。
- 細部:並び順は`target_date`の降順、同じ日は`id`の降順(ページをまたいで順が揺れないように)。1ページ20件。`page`(1以上の整数、既定1)。
  `target_date`(任意、`Y-m-d`)を指定するとその日の申請だけを返す(ページングの形は同じ)。応答は既存の申請のResourceのページング(`{ data, links, meta }`)。
- 未確定・要確認事項: なし

### 論点2: 画面
- 決定:
  - 本人の画面の申請一覧の下に`components/Pagination`を置く。ページ番号はURLのクエリ(有給・特別休暇・代休とも`requestPage`)に載せる
    (既存の`date`/`dates`のクエリはそのまま残す)。申請の作成・取消の後は一覧を再取得する(今の無効化のまま。ページは変えない)。
  - 日次勤怠の画面は、3種とも`target_date=表示している日`で読む(ページングの1ページ目で足りる)。
  - 3種で同じ作りにする(`ui-interaction-patterns` §2.25)。
- 未確定・要確認事項: なし

### 論点3: MCP
- 決定:`GetMyLeaveRequestsTool`は変えない(応答の`data`に最初の20件、`meta`にページ情報が入る)。ツールの説明に「新しい順に20件」を追記する。
- 理由:MCPは別アプリで、AIの読解用。最新の20件で足りる。全件が要る用途は今は無い。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

- backend:3つの`myRequests`を`->orderByDesc('target_date')->orderByDesc('id')->paginate(20)`にし、`target_date`(任意・`date_format:Y-m-d`)で絞れるようにする。
  OAに`page`・`target_date`のパラメータと応答の形を書く。
- frontend:
  - `api/paidLeave.ts`・`api/specialLeave.ts`・`api/compensatoryLeave.ts`の`fetchMy*LeaveRequests`を`(params: { page?: number; targetDate?: string })`にし、
    応答型をページング(`{ data: T[]; meta: { current_page: number; last_page: number; total: number } }`)にする。
  - hook:queryKeyにparamsを含める(`[...MY_REQUESTS_KEY, params]`)。作成・取消の後の無効化は`MY_REQUESTS_KEY`の前方一致のまま。
  - 本人の3画面:`requestPage`をURLから読み、一覧の下に`Pagination`。
  - `AttendanceDayPage`:3種とも`{ targetDate: date }`で読み、`.data`を使う。
- mcp:`GetMyLeaveRequestsTool`の説明の追記のみ。
- テスト:
  - backend(3種):並び順(対象日の降順・同じ日はidの降順)、21件で2ページ(`meta.last_page = 2`、2ページ目に1件)、`target_date`で絞れる、他人の申請は出ない。
    既存の`requests/mine`のテストの期待値(配列のまま読んでいる箇所)を新しい形に直す。
  - frontend:本人の3画面のテスト(ページングの表示、`requestPage`で2ページ目を読む)、`AttendanceDayPage`のテスト(その日で読む)。既存のテストのモックを新しい形に直す。

## 受け入れ条件

- 本人の有給・特別休暇・代休の画面で、申請が対象日の降順に20件ずつ表示され、ページを移動できる(URLにページ番号が残る)。
- 日次勤怠の画面で、その日の休暇申請がこれまでどおり表示される。
- 追加・既存のテスト(backendはCIと同じ条件の全件、frontendはvitest・lint・tsc)がPASSする。

## 対象外

- 管理者向けの申請一覧。
- 申請一覧の絞り込み(状態など)。
- e2e(既存のe2eが壊れないことは、申請一覧を使うシナリオ(scenario-03)で確かめる)。

## ドキュメントへの影響

- `docs/09-usecases-paid-leave.md` UC-P003付近の「自分の申請一覧」:対象日の降順・20件ずつのページング。特別休暇・代休のユースケースの同じ節にも追記(`grep`で特定)。

## モック・アセット

なし

## 実装対象

上記「仕様確定事項」のbackend・frontend・mcp・テスト・docs。

## 検証方法

- `cd backend && cp .env.example .env && php artisan key:generate && rm -f database/database.sqlite && touch database/database.sqlite && php artisan test`
- `cd frontend && npx vitest run --project=unit && npm run lint && npx tsc -p tsconfig.app.json --noEmit && npx tsc -p e2e/tsconfig.json --noEmit`
- e2e:scenario-03(有給の申請)

## レビュー履歴

- 初版。ユーザーの依頼をもって実装中にした。

## 実装結果

- コミット`66d72d8`。3つの`myRequests`を対象日の降順・同日はidの降順で20件ずつのページングにし、`target_date`で絞れるようにした。
  本人の3画面はURLの`requestPage`でページを読み`Pagination`を表示。`AttendanceDayPage`は3種ともその日で読む。MCPのツールの説明を追記。docs/09に追記。
- テスト:3種それぞれ5ケース(並び順・21件で2ページ・target_dateで絞る・不正なtarget_dateは422・他人の申請は出ない)、本人の3画面と日次勤怠の画面のテスト。
- 検証:backend(CIと同じ条件の全件)2219件中2218件PASS(失敗は既存の`ReadModelFingerprintCommandTest`の1件)。
  frontend:`vitest --project=unit` 1096件PASS、lint error無し、tsc(app・e2e)エラー無し。
  e2e:scenario-01・02・03・16 の6件PASS。
