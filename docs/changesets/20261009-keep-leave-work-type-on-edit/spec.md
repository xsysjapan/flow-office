# 日次勤怠の編集で休暇設定(work_type)が消えない・書き換えられないようにする

ステータス: レビュー中

## 変更要望(原文)
> 代休バッジは表示されましたが、おそらく代休が設定されていた日の労働時間を更新した際に
> ラベルが取れてしまったような気がします。代休だけでなく有給や、特別休暇もですが、
> 当該日の労働時間を編集しても休暇設定が無効にならないように対応をお願いします。

(レビュー時の追加要望)
> 勤怠入力でwork_typeを設定できるのであれば申請する意味が消えてしまいます。
> また、work_typeを変更することで各休暇のusageが作成されるのか確認してください。
> もし矛盾を発生させるのであれば、画面からの書き換えは無効です。
> 申請不要の場合は登録できても良いかもしれませんが、usageも作成されるようにしてください。

## 背景・目的
休暇の設定(`attendance_days.work_type`の休暇値)は、休暇申請・取消によってのみ変化し、
かつ常に休暇の消化記録(usage)と対応しているべきである。現状は日次勤怠の編集で休暇値が
消えたり、逆に申請を経ずに休暇値を書き込めたりし、勤怠集計と休暇残高が食い違う。
勤怠編集からは休暇値を一切変更できないようにする。

## 現状(As-Is)
- 休暇申請時に各Handlerが`work_type`を休暇値(`paid_leave_*`/`special_leave_*`/
  `compensatory_leave_*`)に設定し、同時にusage(消化記録)を作る
  (例: `backend/app/Domain/PaidLeave/Handlers/RequestPaidLeaveHandler.php:126`でDesignatePaidLeaveUsage、
  `:188`でwork_type設定。特別休暇・代休も同様)。取消でwork_typeをnullに戻す。
- **`work_type`を変更してもusageは作られない**。残高はusageイベントのみから算出される
  (`PaidLeaveBalanceProjector.php:72-92`)一方、勤怠集計の休暇日数は`work_type`から算出される
  (`AttendanceCalculator.php:125-145`)。
- **矛盾1(休暇が消える)**: 日次画面は休暇日の保存で`work_type: null`を送り
  (`frontend/src/pages/attendance/AttendanceDayPage.tsx:729`)、バックエンドは無条件に上書きする
  (`EditAttendanceDayHandler.php:83`→`AttendanceDayProjector.php:56`)。usageは残るため、
  残高は減ったまま勤怠集計上は休暇0日になる。一括パターン入力
  (`GeneratePatternAttendanceDaysHandler.php:114`)、MCPの月次一括取込
  (`mcp/app/Mcp/Support/AttendanceDraftDayApplier.php`、work_typeを送らない)も同じ経路で消す。
- **矛盾2(申請なしで休暇になる)**: 日次編集・作成API(`AttendanceController.php:387`/`:435`)は
  `work_type`を任意文字列で受け付ける。日次画面の「作業内容」欄(`AttendanceDayPage.tsx:780`)や
  APIから`paid_leave_full`等を送ると、usageも残高消化も伴わずに勤怠集計上だけ休暇日になる。
- 日次画面の休暇指定UI(`AttendanceDayPage.tsx:557-680`)自体は`work_type`を直接書かず、
  休暇の申請API・取消APIを呼んでいる(=usageが作られる正しい経路)。
- 承認不要設定(`system_settings.paid_leave_requires_approval`/`special_leave_requires_approval`/
  `compensatory_leave_requires_approval`)がfalseの場合、休暇の申請APIはワークフローを作らず
  同一トランザクションで申請→承認を行い、usageも作成・確定する
  (`PaidLeaveController.php:583-`、`CompensatoryLeaveController.php:156-`)。

## 仕様検討

### 論点1: 休暇値が設定されている日を勤怠編集したときの`work_type`
- 選択肢:
  - A. フロントのみ修正(既存値を送り直す)。一括パターン入力・API・MCPでは引き続き消える。
  - B. 「送信されたか」フラグを導入し未送信なら維持。nullを明示送信するクライアントでは消える。
  - C. `EditAttendanceDayHandler`で、編集前の`work_type`が休暇値なら、送られた値に関わらず
    編集前の値をそのまま引き継いでイベントに記録する。
- 決定: C
- 理由: 休暇値は休暇ドメインの責務であり勤怠編集から変えるべきではない(原則9・14)。
  Handlerで守れば日次画面・一括パターン入力・API・MCPのすべての入口で効き、イベントに
  引き継いだ値が残るので編集後のProjection再生成でも消えない。
- 未確定・要確認事項: なし

### 論点2: 休暇値が設定されている日に、異なる`work_type`が送られた場合
- 選択肢:
  - A. エラーにせず既存の休暇値を維持する(送られた値は無効)
  - B. 422エラーで拒否する
- 決定: A
- 理由: 現行の日次画面・MCP取込は休暇日にnull/未送信で保存しており、Bでは労働時間の編集
  自体ができなくなる。休暇を外す・変える操作は休暇申請の取消・再申請で行う(要望どおり
  「画面からの書き換えは無効」)。
- 未確定・要確認事項: なし

### 論点3: 休暇値でない日に、休暇値の`work_type`が送られた場合(申請なしでの休暇化)
- 選択肢:
  - A. 422エラーで拒否する(「休暇は休暇申請から設定してください」)
  - B. 黙って無視する(`work_type`はnullとして保存)
  - C. 勤怠編集から休暇申請(usage作成)を内部的に起動する
- 決定: A
- 理由: usageを伴わない休暇値は残高と勤怠集計の矛盾を生むため受け付けない。Bは入力が
  消えたことに利用者が気づけない。Cは勤怠編集に休暇ドメインの業務(残高・承認要否判定)を
  持ち込むことになり原則14に反するうえ、同じことは既存の休暇申請APIで実現できる。
- 未確定・要確認事項: なし

### 論点4: 申請不要(承認不要設定)の場合の登録経路
- 選択肢:
  - A. 既存の休暇申請API(承認不要時は申請と同時に承認・usage確定まで行う)を唯一の経路とする
  - B. 承認不要時に限り勤怠編集で休暇値を書けるようにし、そこからusageを作る
- 決定: A
- 理由: 承認不要時も既存の休暇申請APIが申請→承認→usage作成を1トランザクションで行って
  おり、日次画面の休暇指定UIもこのAPIを呼んでいる。要望の「申請不要なら登録できる・
  usageも作成される」はAで既に満たされるため、勤怠編集側に別経路を作らない。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- 「休暇値」: `work_type`が`paid_leave_`/`special_leave_`/`compensatory_leave_`のいずれかで
  始まる値。判定は既存の`SpecialLeaveWorkType::isSpecialLeaveWorkType`・
  `CompensatoryLeaveWorkType::isCompensatoryLeaveWorkType`に加え、有給側にも
  `App\Models\PaidLeaveType::isPaidLeaveWorkType`を追加し、3つを組み合わせる
  (組み合わせ判定は1か所の静的メソッドにまとめ、Handler間で複製しない)。
- `EditAttendanceDayHandler`:
  - 編集前の`work_type`が休暇値 → `AttendanceDayAggregate::edit()`へ渡す`workType`は編集前の値
    (コマンドの値は無視。エラーにしない)。
  - 編集前の`work_type`が休暇値でなく、コマンドの`workType`が休暇値 → ドメイン例外で拒否し、
    APIは422(メッセージ「休暇は休暇申請から設定してください」、対象フィールド`work_type`)。
  - それ以外 → 従来どおりコマンドの`workType`を使う。
- `CreateAttendanceDayHandler`: コマンドの`workType`が休暇値なら同じく422で拒否する。
- 上記により日次編集API・作成API・一括パターン入力・MCP取込のすべてで、休暇値は休暇申請・
  取消でのみ変化する。
- `status`・出退勤時刻・休憩・備考・`leave_segments`等、`work_type`以外の扱いは変えない。
- フロントの送信値・休暇指定UIは変更しない。

## 受け入れ条件
- 有給・特別休暇・代休(全休・半休)を申請した日に、日次編集APIで`work_type`をnull/未送信/
  休暇以外の値にして出退勤時刻を変更すると、時刻は更新され`work_type`は申請時の休暇値のまま。
  usage・残高も変化しない。
- 休暇値でない日に、日次編集API・作成APIで`work_type=paid_leave_full`等(3種いずれも)を送ると
  422になり、`work_type`・usageは変化しない。
- 休暇値でない日に休暇以外の`work_type`を送ると従来どおり反映される。
- 一括パターン入力を休暇日に適用しても休暇値が維持される。
- 承認不要設定で日次画面の休暇指定から休暇を設定すると、従来どおりusageが作成される
  (既存テストで担保されていることを確認する)。
- 既存のbackend/frontendテストが全てPASSする。

## 対象外
- 休暇申請・取消が`attendance_days`をイベントを経由せず直接更新している問題
  (Projection再生成時に休暇値が失われる。原則1・2に関わる既存の別問題)
- 代休の`work_type`が`AttendanceCalculator`の休暇日数集計に含まれていない件
  (代休日数を月次集計でどう扱うかは仕様判断が必要なため別途検討)
- 日次画面の休暇指定UI・「作業内容」欄・送信値の変更、`AttendanceDayPage.test.tsx`の既存`it.skip`
- MCP側(`mcp/`)の変更(バックエンドで守られるため不要)

## ドキュメントへの影響
- `docs/07-usecases-attendance.md`: 日次勤怠の作成・編集のユースケースに「休暇値の`work_type`は
  勤怠の作成・編集では設定・変更できず(休暇日の編集では維持され、休暇値の新規指定は422)、
  休暇申請・取消でのみ変化する」旨を追記する(該当UCは実装時に特定)。
- その他は変更なし(テーブル定義・イベントのpayload構造は変わらない)。

## モック・アセット
なし

## 実装対象
- `backend/app/Models/PaidLeaveType.php`(`isPaidLeaveWorkType`追加)
- 休暇値の組み合わせ判定メソッド(`backend/app/Domain/Attendance/`配下に1つ)
- `backend/app/Domain/Attendance/Handlers/EditAttendanceDayHandler.php`(維持・拒否)
- `backend/app/Domain/Attendance/Handlers/CreateAttendanceDayHandler.php`(拒否)
- 422への変換(既存のドメイン例外→422の仕組みに合わせる)
- `backend/tests/Feature/Attendance/`配下の既存テストファイルにテスト追加(維持・拒否・一括パターン)
- `docs/07-usecases-attendance.md`(上記追記)

## 検証方法
- `cd backend && php artisan test`(追加テスト+既存テスト全件)
- `cd backend && vendor/bin/pint --test`

## レビュー履歴
- 2026-10-09 初版(休暇日の編集で休暇値を維持する)
- 2026-10-09 レビュー指摘を反映: work_type変更ではusageが作られず矛盾することを確認したため、
  勤怠編集・作成からの休暇値の新規指定を422で拒否する論点3を追加。申請不要時は既存の
  休暇申請API(usage作成あり)を唯一の経路とする論点4を追加。タイトルを変更。

## 実装結果
未着手
