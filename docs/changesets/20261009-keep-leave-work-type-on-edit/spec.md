# 休暇まわり(申請・承認/休暇申請/残数・使用管理/勤怠)をイベント連携で独立させる

ステータス: 実装中

## 変更要望(原文)
> 代休を取得した時に対象の勤怠に代休のバッジがでません。(代休バッジ修正後)おそらく代休が設定されていた
> 日の労働時間を更新した際にラベルが取れてしまったような気がします。代休だけでなく有給や、特別休暇も
> ですが、当該日の労働時間を編集しても休暇設定が無効にならないように対応をお願いします。

(レビュー時の主な指摘・指示。全文はレビュー履歴)
> 勤怠入力でwork_typeを設定できるのであれば申請する意味が消えてしまいます。
> Eventと同期して欲しいので、ReadModelのみを変更することはデータ補正であっても許容できません。
> 休暇をwork_typeに入れるのは誤りでは？(→ 方針B: 休暇は消化記録から求める)
> 有給の設計書の挙動が他の部分と一貫していて良いです。有給の実装が違うということですが、実装を修正してください。
> コレオグラフィーのオーケストレーションで、勤怠の休暇設定、有給・特別休暇・代休の残数・使用管理、
> 承認・申請が互いに独立した状態を保てるようにし、かつ連動して動く設計にして欲しいです。

## 背景・目的
休暇設定が勤怠編集で消える不具合の調査から、(1)休暇を勤怠日の`work_type`へイベントなしでコピーしている、
(2)差し戻した休暇の消化記録が取り消されない(設計書と不一致)、(3)休暇の各Handlerが申請・残数・勤怠の
集約やテーブルを直接読み書きしている、という設計上の問題が判明した。「申請・承認」「休暇申請」「残数・
使用管理」「勤怠」をそれぞれ自分の状態だけを持つ独立した文脈とし、他の文脈のイベントにReactorで反応して
連動する(コレオグラフィー)設計に改める。あわせて本番データを補正する。

## 現状(As-Is)
調査結果の詳細は`assets/`配下(`impact-usage-derivation.md`、`returned-resubmit-and-overlap.md`、
`other-request-types-return-resubmit.md`、`data-correction-prerequisites.md`、`context-coupling.md`)。

- 休暇の各Handlerが、申請・残数のCommand発行に加え、勤怠日を直接create/saveし(`work_type`・全休の
  `status=clocked_out`)、勤怠の日次計算を直接実行している。特別・代休の承認は申請集約と付与集約を同一
  Handlerで更新する。重複チェックは他の休暇申請テーブルを直接読む。
- `docs/03-architecture.md:118-124`は「複数集約にまたがる副作用はCommandHandlerが直接読み書きする」と
  この結合を正当化している。
- 有給の申請状態(`paid_leave_requests`)は残数側のProjector(`PaidLeaveUsageAllocationProjector`)が更新し、
  有給申請の集約は存在しない。
- 差戻し: 休暇申請はRETURNEDになるが消化記録は取り消されない(設計書`docs/09:315-316`と不一致)。差し戻された
  休暇は取消も承認もできず、ワークフローの「提出する」で再提出すると承認が必ず失敗する。
- 勤怠: 休暇を勤怠日の`work_type`で判定する箇所と、消化記録テーブルを直接読む箇所が混在。勤怠側に休暇の
  変化へ反応する仕組みは無い。月次の休暇日数・給与連携は日次計算(勤怠日の行ごと)の集計。
- Reactorは同期実行で、1回の利用者操作の連鎖は入れ子トランザクションで原子的。replayではReactorは走らない。
- 有給はcutover時に移行前の個別の消化記録を再現していない(`PaidLeaveAccountAggregate.php:283-300`)。
- 承認済みのワークフローは取消できない(`WorkflowRequestStatus::cancellable()`)。`CancelWorkflowRequest`は申請者本人を要求する。

## 設計前提の検証
- 正データと派生データ:
  - 申請の進行状況の正: `workflow_requests`(統合ワークフローのイベント)。
  - 休暇申請の正: 各休暇申請(有給は集約が無く、残数側Projectorが申請テーブルを更新している=所有者不明確)。
  - 残数・使用の正: 有給は`PaidLeaveAccountAggregate`、特別・代休は付与集約。ただし特別・代休の消化記録の
    作成(designate)は申請集約が行っており、使用の所有者が申請側と残数側に分かれている。
  - 勤怠の正: 勤怠日・日次計算。休暇値(`work_type`)・全休の`status`はイベントを伴わない他文脈のコピーで、
    設計原則2違反。
- 定義と実態: `work_type`は「有給区分」と定義されるが作業内容としても使われている。`docs/03`の方針は、
  ユーザーが求める文脈の独立と矛盾する。
- 原因の分類: 設計の誤り(文脈間の直接結合、正データの二重化、設計書と実装の乖離)。

## 文脈と責務(本変更の全体像)

```
[申請・承認]  workflow_request.drafted/submitted/approved/returned/cancelled
      │ Reactor(休暇申請側)
      ▼
[休暇申請]   *_leave_request.requested/approved/returned/cancelled
      │ Reactor(残数側)                    │ Reactor(申請・承認側): 休暇申請の取消→ワークフロー取消
      ▼
[残数・使用] 利用者単位の口座集約(有給・特別・代休): 消化記録の作成・確定・取消、付与の残数

[休暇申請] ──Projector(勤怠側の休暇ビュー)・Reactor(勤怠側: 衝突・締めの検証、日次再計算)──▶ [勤怠]
             休暇ビュー(attendance_day_leaves)・月次集計は休暇ビューを読む
```

- 各文脈は自分の集約・テーブルだけを書き込み、他文脈へはイベントでのみ伝える。他文脈のイベントへの反応は
  Reactor(自文脈のCommandを発行)またはProjector(自文脈の読み取りビューを作る)で行う。
- Reactorは同期実行を維持し、1回の利用者操作から始まる連鎖全体を1トランザクションとする(途中で例外が出れば
  全体が取り消される)。
- Reactorから発行されるCommandは冪等にする(既に目的の状態なら何もしない)。双方向の連動(ワークフロー取消
  ⇔休暇取消)はこれで無限連鎖を防ぐ。

## 横断ルール(全論点に共通)
- **書き込み**: 各文脈は自分の集約・テーブルだけを書き込む(ルート`CLAUDE.md`設計原則15)。
- **連鎖の実行**: Reactorは同期実行。1回の利用者操作から始まる連鎖全体が1トランザクションで、途中の
  Reactor/Handlerで例外が出れば全体が取り消され、利用者にはそのエラーが返る。業務ルール違反(締め済み・
  同日の休暇の衝突・残数不足で承認できない等)は、そのルールを持つ文脈が自分のCommand内で例外を投げて表す。
- **実行者と認可**: 利用者・管理者の操作はControllerと入口のCommandで認可する。Reactorが発行するCommandは
  `initiatedByUserId`(連鎖の起点となった操作者。イベントのメタデータから引き継ぐ)と`viaReactor=true`を持ち、
  利用者向けの認可(本人確認・権限)は行わず、状態のガードのみ行う。
- **冪等性**: `viaReactor=true`のCommandは、既に目的の状態なら何もしない(例外にしない)。利用者の操作として
  同じCommandが来た場合は従来どおり状態不正を例外にする。
- **Reactorの独立性**: 同じイベントを購読するReactorが複数あっても、互いの結果に依存しない(実行順に依存
  しない)。連鎖は「申請・承認→休暇申請→残数・使用→勤怠」の一方向を基本とし、逆方向は次の3つのみ:
  休暇申請の申請(`*.shared`)→ワークフローの提出、休暇申請の承認(まとめ申請の兄弟)→兄弟ワークフローの承認、
  休暇申請の取消→ワークフローの取消。このほか勤怠→代休の口座の連携(日次計算からの代休付与の同期、月次提出での
  付与確定)は、休暇の連鎖とは別の向きの連携として維持する。
- **テスト(原則16、`domain-test`スキル)**: 本変更で新設・変更する業務ルール(口座集約の残数・充当・取消、休暇申請の
  状態遷移、付与日数換算・有効期限、同じ日の衝突判定、締め判定、論点15の削除条件、出勤率の判定、休暇ビューから勤怠
  計算への入力変換、まとめ申請の兄弟承認の対象判定、再提出の可否、`migrated`による引き継ぎ、午前・午後の休暇の組合せ)は、
  テストで直接呼べる単位(Aggregate/判定・計算クラス)に置き、ルールのテストで正常・境界・異常を
  確かめる。文脈間の連鎖は、受け入れ条件の各シナリオをUI非依存のシナリオテスト(API/Command起点、SQLite)で通し、
  各ステップ後にワークフロー・休暇申請・消化記録/残高・勤怠(休暇ビュー・日次計算)の状態を確認する。失敗系は全文脈の
  状態が変わらないこと、冪等性・リビルドの再現性も確認する。代休の現状(`assets/domain-unit-test-coverage.md`)で
  Handler/Projector内にある業務ルールは、口座集約・判定クラスへ移す。
- **リビルド**: ReadModelは全て、自文脈のイベントと購読している他文脈のイベントだけから、空の状態から
  再生成できる。Projectorは再生順に依存しない(行の存在を前提とする更新は、行が無ければ何もしない)。

## 仕様検討

### 論点1: 勤怠側での休暇の持ち方
- 選択肢:
  - A. 勤怠日の`work_type`に休暇値をコピーする(現状)
  - B. 勤怠が、休暇の各文脈のイベントから自分の休暇ビュー`attendance_day_leaves`(利用者×日付×休暇)を
    Projectorで作り、勤怠の計算・表示・打刻可否・月次集計はこのビューだけを読む
  - C. 勤怠が残数側の消化記録テーブルを直接読む
- 決定: B
- 理由: Aは正データの二重化、Cは原則15違反。Bは勤怠が自分の読み取りビューを持ち、イベントから再生成できる。
  (休暇だけの日の勤怠日・日次計算は論点3のとおり勤怠が自分の集約で記録する)
- 未確定・要確認事項: なし

### 論点2: 休暇ビューの入力イベントと、休暇として扱う範囲
- 決定:
  - 入力: 休暇申請文脈のイベント(有給`paid_leave_request.*`(新設、論点4)、特別`special_leave_request.*`、
    代休`compensatory_leave_request.*`の申請・承認・差戻し・取消)。各行は休暇の種類・取得単位・時間数・
    申請ID・申請状態(申請中/承認済み)を持つ。
  - 過去分: 有給の申請イベントは本変更以前に存在しないため、有給は`paid_leave_account.usage_designated/
    usage_confirmed/usage_cancelled`(cutover以降)からも同じ行を作る(論点4の境界条件と同じ規則で二重に
    作らない)。cutover前の有給は論点13。
  - 休暇として扱う範囲: 申請中・承認済み(差戻し・取消で外れる)。現行の`work_type`(申請時に設定・取消で解除)と
    同じく、計算は申請中から休暇として扱う。差戻しは本変更で休暇から外れる(論点5)。
- 理由: 申請状態(申請中/承認済み)は休暇申請の事実であり、消化記録のイベントだけでは区別できない
  (特別休暇で残数を要しない種別は`used`が出ない)。勤怠の表示・集計に必要なのは「申請された休暇」である。
- 未確定・要確認事項: なし

### 論点3: 休暇だけの日の勤怠日(月次集計)
- 前提(`assets/monthly-items-and-attendance-rate.md`): 休暇だけの日も、日次計算が所定労働時間(全休P・半休P/2・休日0)・
  休暇日数・時間休分を`attendance_day.calculated`イベントとして記録し、月次スナップショット(`day_count`・所定労働時間ほか)・
  給与連携(freeeの所定労働時間等)・Excelの日別行と休暇注記がそれを使っている。
- 選択肢:
  - A. 休暇の処理は勤怠に触れない。勤怠が休暇申請のイベントにReactorで反応し、勤怠日が無ければ勤怠自身のCommandで
    勤怠日を記録し(`attendance_day.created`、`source=leave`。打刻・日次編集で通常どおり上書き可能)、日次計算を
    イベントとして記録する
  - B. 勤怠日の集約は作らず、休暇だけの日の値をReadModelでその都度計算する
- 決定: A(2026-10-10 ユーザー決定。当初Bと決定したが、上記前提の判明により再検討)
- 理由: 休暇だけの日も他の日と同じく日次計算をイベントで記録するため、値が記録時点で固定され、月次・給与連携・
  Excel・画面APIは現行のまま同じ値になる。Bは値がイベントで固定されず(勤務形態マスタ変更後の再生成で過去月が変わり
  得る)、月次集計・Excel・画面APIの改修が広い。休暇の処理が勤怠を直接作る現状の問題(原則2・15違反、`source=manual`
  で打刻が反映されない)はAでも解消する。
- 未確定・要確認事項: なし

### 論点4: 有給の申請状態の所有者
- 決定: 有給申請の集約`PaidLeaveRequestAggregate`を新設し、`paid_leave_request.requested/approved/
  returned/cancelled`を記録する。`paid_leave_requests`は休暇申請側の新Projectorが作り、残数側
  (`PaidLeaveUsageAllocationProjector`)からは申請テーブルの更新を削除する。
- 境界条件: 本変更前の申請は、仕様確定事項Iの`paid_leave_request.migrated`で新しい集約へ引き継ぐ。各Projector
  (申請テーブル・勤怠の休暇ビュー・出勤率ビュー)は申請IDごとに、`migrated`(または`requested`)より前は旧系統
  (旧`paid_leave.*`、cutover後の`paid_leave_account.*`+`workflow_request.returned`)で、それ以後は
  `paid_leave_request.*`(`migrated`を含む)だけで状態を作る。`migrated`以後に記録された旧系統のイベント
  (`paid_leave_account.usage_confirmed`等)は申請状態の入力にしない。新イベント名は旧`paid_leave.*`
  (廃止済み・記録元なし)と別にする。
- 未確定・要確認事項: なし

### 論点5: 差戻し・再申請・取消(設計書`docs/09:315-316`への準拠)
- 決定:
  - 差戻し: `workflow_request.returned`→休暇申請文脈がRETURNEDを記録→残数文脈が未確定の消化記録を取り消す
    (有給`usage_cancelled`、特別・代休は残数集約の取消イベント。論点6)→勤怠の休暇ビューから外れ、勤怠が
    該当日を再計算する。
  - 再申請: 申請詳細の「提出する」で同じ内容のまま再提出する(論点8)。内容を変える場合は取消→新規申請。
  - 申請者・管理者による取消(申請中・承認済み): 休暇申請文脈がCANCELLEDを記録→残数文脈が消化記録を取り消す
    (承認済みは充当を解除し残高を戻す)→勤怠が再計算。ワークフローは、申請中なら申請・承認文脈のReactorが
    取消す(`viaReactor`のため申請者本人チェックなし)。**承認済みのワークフローは状態を変えない**
    (`WorkflowRequestStatus::cancellable()`に従い、承認の取消を作らない。設計原則13)。業務側の取消は休暇申請の
    イベントとして残る。
  - ワークフロー側からの取消(申請中・差戻し中): 休暇申請文脈のReactorが休暇申請を取り消す(差戻し中は消化記録が
    既に無いため休暇申請の状態だけを変える)。
  - 複数日まとめ申請(`request_group_id`): 1日ごとに別のワークフローになっている。現在は申請・承認文脈のReactorが
    `paid_leave_requests`を読んで兄弟の申請と兄弟のワークフローを承認している(原則15違反)。休暇申請文脈が承認時に
    自分のテーブルの`request_group_id`で兄弟の申請を承認し、申請・承認文脈は休暇申請の`approved`を受けて兄弟の
    ワークフローを承認する(逆方向のReactor。既に承認済みなら何もしない)。差戻し・取消は現行どおり連動させない。
- 理由: 経費・月次勤怠と同じ標準パターン、設計書準拠。二重計上が起きない。
- 未確定・要確認事項: なし

### 論点6: 特別休暇・代休の「使用」の所有者
- 決定: 有給と同じく、利用者単位の残数集約(`SpecialLeaveAccountAggregate`・`CompensatoryLeaveAccountAggregate`)を
  新設し、消化記録の作成(申請時)・確定(承認時の付与への充当)・取消(差戻し・取消)と付与の残数を一本化する。
  申請集約は申請状態だけを持つ。
  - 集約ID: `userId`をそのまま使うと有給の口座集約とストリームIDが衝突するため、種類ごとの派生ID
    (既存の`UserManagementStreamId`と同じ方式)を使う。
  - 既存の付与集約・消化記録: 移行用の補正イベント(口座開設時に既存の付与・未取消の消化記録の状態を引き継ぐ
    イベント)を今の時点に追記して口座集約へ移す(`data-correction`ステップ3。過去への挿入はしない)。旧イベントを
    購読する既存Projectorは、移行イベント以前の分の再生に引き続き使う。
  - 代休の付与は休日出勤の日次計算から同期されている(`SyncCompensatoryLeaveGrantOnAttendanceDayCalculatedReactor`)。
    同期先を代休の口座集約に変える(勤怠→残数のイベント連携は維持)。
  - 消化記録の`attendance_day_id`: 残数文脈は勤怠日を知らない。新規の消化記録には設定せず、列・イベントの項目は
    任意にする(既存値は残すが使わない)。勤怠との対応は利用者×日付で行う。
- 理由: ユーザー指示(申請と残数・使用管理の独立、一括変更に含める)。
- 未確定・要確認事項: なし

### 論点7: 同じ日の休暇の衝突チェック
- 決定: 勤怠の日に関するルールとして**勤怠文脈**が持つ。勤怠の休暇ビューへの反映と同じ連鎖の中で、勤怠の
  Reactorが発行する`ApplyLeaveToAttendanceDay`(仮称)が、その日の有効な休暇(申請中・承認済み)と新しい休暇を
  比べ、全休を含む併存・同じ半休の重複・休暇の合計(半休は所定労働時間の半分、時間休は時間数)が所定労働時間を
  超える場合に例外を投げる(連鎖全体が取り消され、申請はエラーになる)。所定労働時間は勤怠が持つ勤務予定から
  求める。判定対象から、今回の連鎖を起こした休暇自身は除く。
- 理由: 衝突は「1日の中で休暇が両立するか」という勤怠の日のルールで、所定労働時間も勤怠が持つ。休暇申請文脈が
  勤怠や他の休暇の申請テーブルを読む必要がなくなる。ユーザー決定(時間が重なる組合せだけ拒否・時間休は合計)。
- 未確定・要確認事項: なし

### 論点8: 差し戻されたワークフローの再提出
- 前提(ユーザー定義): 差し戻されると申請前の状態に戻り(差戻しのメッセージとともに)、申請者は「提出する」で
  再提出できる。
- 決定: 「提出する」はそのまま残す。休暇申請文脈が`workflow_request.submitted`(差戻しからの再提出)を受け、
  同じ内容で休暇申請を申請中に戻す(新しい消化記録を作る。設計書`docs/09:315-316`「再提出時は新規Usage」)。
  内容(対象日・取得単位)は再提出では変えられないため、変える場合は取消→新規申請とする。差し戻された休暇は
  申請前の状態なので、月次提出のガードの対象外とする(下書きと同じ)。
- 理由: ユーザー定義どおり。現状は再提出で休暇申請側が戻らず承認が必ず失敗する(業務側が再提出に反応しない)。
- 未確定・要確認事項: なし(経費・シフト交代・月次勤怠の同じ不整合は対象外とし別の変更セットで扱う)

### 論点9: 全休日の出勤・打刻・出勤率
- 決定:
  - 休暇の処理は勤怠日の`status`を書かない。出勤可否(`ClockInHandler`)・打刻の取り込み(`AttendanceDayPunchSyncer`)・
    打刻漏れ警告・未出勤件数(`DevicePunchController`)・今日の勤怠表示は、勤怠の休暇ビューで全休かどうかを見る
    (全休の日は現行と同じく出勤不可・打刻を取り込まない・警告しない)。
  - 出勤率(`AttendanceRateAssessor`・`GrantScheduledSpecialLeaveHandler`)は**現行の結果を維持**する。現行は
    全休の休暇(3種)が`status=clocked_out`のため出勤扱いになっており、これを変えない。出勤率の入力は、有給・特別休暇
    の各文脈が勤怠のイベントから自分の出勤ビュー(利用者×日付の出勤/休暇区分と所定労働日)をProjectorで作り、
    それを読む(原則15の例外は作らない)。勤怠のイベントに不足する情報(所定労働日か否か等)は勤怠のイベントに追加する。
- 理由: ユーザー決定(現行維持+休暇側のビュー)。
- 未確定・要確認事項: なし

### 論点10: `work_type`
- 決定: 休暇値の書き込み・解釈を全て削除し、意味を「作業内容」に一本化する(列名はそのまま)。日次画面は
  休暇日でも作業内容欄を表示し、入力値をそのまま送る(休暇日にnullを送る処理・休暇値を初期値にする処理を削除)。
- 未確定・要確認事項: なし

### 論点11: 申請不要(承認不要設定)
- 決定: 休暇申請APIが休暇申請の申請・承認を続けて実行する現行方式を維持する。ワークフローは作らず、休暇申請→
  残数→勤怠の連鎖は承認ありの場合と同じイベントで動く。
- 未確定・要確認事項: なし

### 論点12: 本番データの補正(`data-correction`スキル)
- 決定: 補正の対象・件数・方法(書き換え・削除・追記)は、移行前のリハーサル(本番相当データ)で確定させ、その時点で
  直接修正(書き換え・削除)についてユーザーの明示的な許可を得る(ユーザー指示)。本変更セットでは方針と補正コマンドの
  枠組みまでを定める:
  - 対象の候補: (1)差し戻された休暇の未取消の消化記録、(2)休暇の処理が直接作った勤怠日(`attendance_day.created`が
    無い)、(3)編集イベントに入った休暇値、(4)勤怠日に残る休暇値・全休の`status`。
  - 挿入は行わない(`data-correction`スキル)。追記する補正イベントは、復元対象の状態(勤怠日・休憩・不就労区間・
    日次計算・手動調整・週40時間配賦など)を全て含む補正専用イベントとする。
  - 消化記録→勤怠日・消化記録→申請テーブルの外部キーを撤去する(論点6)。リビルドするProjectorの順序をリハーサルで確定する。
  - 補正コマンドは既定で試し実行、`--apply`でバックアップテーブル作成→1トランザクション。
- 未確定・要確認事項: なし(リハーサルで確定)

### 論点13: cutover前の有給
- 前提: 有給は新しい残数管理への移行(cutover)時に、移行前の個別の消化記録を再現していない
  (`PaidLeaveAccountAggregate.php:283-300`、`docs/09:448`)。
- 本番確認(2026-10-10、ユーザー実行): 旧`paid_leave.requested`/`request_shared`/`request_approved`/`usage_designated`が
  各8件(2026-08-31〜2026-09-04記録、対象日2026-08-10〜2026-09-04、全休7件・午後半休1件、全て承認済み、取消なし)。
  8件とも新ドメインの`paid_leave_account.usage_designated`に対応する記録は無い(二重記録なし)。新ドメインの最初の
  イベントは2026-09-11。
- 選択肢:
  - A. 勤怠の休暇ビュー・有給申請の新Projectorが、旧`paid_leave.*`イベントも入力にする
  - B. cutover前は対象外にする
  - C. 補正イベントを追記する
- 決定: A。旧`paid_leave.requested`(申請中)・`paid_leave.request_approved`(承認済み)から休暇ビューの行と
  `paid_leave_requests`の状態を作る(旧イベントの集約ID=申請ID)。論点4の境界条件に「旧`paid_leave.*`を持つ申請ID
  は旧イベントで作る」を加える(切り替えは論点4の境界条件に従う。3系統: 旧`paid_leave.*`/cutover後〜本変更前の`paid_leave_account.*`/本変更後の
  `paid_leave_request.*`。申請IDごとに排他)。
- 理由: 旧イベントが唯一の記録で二重にならず、履歴を変えずにリビルドで再現できる。
- 未確定・要確認事項: なし

### 論点14: 締め・ロックと月次提出のガード
- 決定: 締め・ロック(`AttendanceEditGuard`)の判定は勤怠文脈が持つ。休暇の各Handlerは呼ばない。締め済みの日に
  かかる休暇は、申請・承認・差戻し・取消・再提出の**全ての遷移で拒否**する(勤怠のReactorが休暇の反映・解除の
  Commandで例外を投げ、連鎖全体を取り消す。管理者の例外は設けない)。締め済みの月の休暇を変える場合は、先に月次の
  確定取消(既存の巻戻し手段)を行う。月次提出時の「有給申請が未承認なら提出不可」(`PaidLeaveApprovalGuard`)は、
  勤怠の休暇ビューの申請中の休暇で判定する(差し戻された休暇は申請前の状態のため対象外。論点8)。
- 理由: ユーザー決定(全ての遷移で確認)。締めは勤怠の状態(原則15)。
- 未確定・要確認事項: なし

### 論点15: 休暇の解除後の勤怠日
- 決定: 休暇が差戻し・取消で外れたとき、その勤怠日が`source=leave`で、実績(出退勤・休憩・不就労区間・作業内容)が
  無く、他の有効な休暇も無い場合は、勤怠が自分のCommandで勤怠日を削除する(`attendance_day.deleted`。休暇の前の
  状態に戻す)。それ以外は日次計算をやり直す(手動調整は現行どおり解除される)。
- 理由: 休暇のために勤怠が記録した日は、休暇が無くなれば記録する理由が無い。現行は行が残り`day_count`に数えられて
  いるが、これは休暇の処理が行を直接作っていたことの副作用であり、休暇の前の状態に戻すのが正しい。
- 未確定・要確認事項: なし

### 論点16: 却下
- 決定: 業務側(subject_type)を持つワークフローは却下できない(申請・承認文脈の一般ルール。画面にも出さない)。
  差戻しを使う。
- 理由: ユーザー決定。却下されると業務側が申請中のまま残り行き詰まる。
- 未確定・要確認事項: なし

### 論点17: 承認時の残数不足
- 決定: 3種とも、残数・使用の文脈が承認時の充当で残数不足を検出したら例外を投げ、承認の連鎖全体を取り消す
  (承認者にエラーを表示)。特別休暇で残数を要しない種別(`requires_grant=false`)は従来どおり充当なしで確定する。
- 理由: ユーザー決定(3種とも承認を拒否)。3種で挙動をそろえる。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

### A. 共通基盤(横断ルールの実装)
- `viaReactor`と`initiatedByUserId`: Reactorから発行するCommandは、コンストラクタ引数`bool $viaReactor = false`と
  `?string $initiatedByUserId`を持つ。起点の操作者は、起点となったイベントのpayloadに含まれる操作者ID
  (`approvedByUserId`・`cancelledByUserId`・`returnedByUserId`・申請者ID等)をReactorが引き継ぐ(イベントのmeta_dataは
  使わない)。システム処理(承認不要時の自動承認等)はnullを許す。
- 冪等: `viaReactor=true`のHandlerは、対象が既に目的の状態なら何もせず正常終了する。`viaReactor=false`(利用者の
  操作)は従来どおり状態不正を`DomainRuleException`にする。
- Projectorの順序非依存: 行の存在を前提とする更新は、行が無ければ何もしない(`findOrFail`を使わない)。
- 通知: 通知は状態を確定させた文脈が出す(ワークフローの差戻し・承認通知は申請・承認文脈、休暇固有の通知は休暇
  申請文脈)。同じ利用者操作の連鎖で同じ通知を二重に出さない。

### B. 申請・承認文脈(`App\Domain\Workflow`)
- 却下: `RejectWorkflowRequestHandler`は`subject_type`を持つワークフローを拒否する(論点16)。画面の「却下」も出さない。
- 再提出: 変更なし(差戻しから「提出する」で`workflow_request.submitted`)。
- 取消: `CancelWorkflowRequest`に`viaReactor`を追加。`viaReactor=true`は申請者本人チェックを行わず、既に取消済み・
  承認済みなら何もしない(承認済みは状態を変えない。論点5)。
- 休暇申請の取消→ワークフローの取消Reactor(`CancelWorkflowRequestOn{PaidLeave,SpecialLeave,CompensatoryLeave}RequestCancelledReactor`)
  を3種にそろえる(有給は新設、インライン呼び出しを削除)。
- 申請・承認文脈から休暇のテーブルを読む処理を削除する(`{PaidLeave,SpecialLeave,CompensatoryLeave}ApprovalOnWorkflowRequestApprovedReactor`の
  兄弟申請・兄弟ワークフローの承認、`{PaidLeave,SpecialLeave,CompensatoryLeave}RequestOnWorkflowRequestDraftedReactor`の
  `workflow_requests`読み取り)。drafted→申請・approved/returned/submitted/cancelled→申請の各Reactorは休暇申請文脈へ移す。
- `ApproveWorkflowRequest`に`viaReactor`を追加。`viaReactor=true`は承認者チェックを行わず、既に承認済みなら何もしない
  (兄弟ワークフローの承認用)。

### C. 休暇申請文脈(`App\Domain\PaidLeave`・`SpecialLeave`・`CompensatoryLeave`の申請部分)
- 有給申請集約`PaidLeaveRequestAggregate`(集約ID=申請ID)を新設。イベント: `paid_leave_request.requested`
  (申請者・対象日・取得単位・時間数・申請日数・承認者・理由・まとめ申請ID・ワークフローID)、`.approved`、
  `.returned`、`.resubmitted`、`.cancelled`、`.shared`。
- 特別・代休の申請集約は申請状態のみ扱う。`designateUsage`等の使用の操作を削除し、`*.request_resubmitted`を追加する。
- 遷移: requested(申請中) → approved / returned / cancelled、returned → resubmitted(申請中) / cancelled。
  approved → cancelled。
- Reactor(申請・承認文脈のイベント→自文脈のCommand):
  - `workflow_request.drafted` → 申請(イベントの`formData`・`approverUserId`・`applicantUserId`・`subjectId`だけで処理し、
    `workflow_requests`を読まない)
  - ワークフローID→休暇申請の対応: ワークフローのイベントは業務側(subject)を持たないため、休暇申請文脈が
    `workflow_request.drafted`(subjectType・subjectId)と自文脈の`*.shared`(workflowRequestId)から自分の対応表
    (`leave_request_workflow_links`)を作り、以下のReactorはこれで申請を特定する(`workflow_requests`を読まない)。
  - `workflow_request.approved` → 対応する申請を承認し、まとめ申請の兄弟のうち**申請中のものだけ**を承認する
    (差戻し中・取消済み・承認済みの兄弟は対象外。現行どおり。論点5)
  - `workflow_request.returned` → 対応する申請を差戻し
  - `workflow_request.submitted`(差戻しからの再提出) → 対応する差し戻された申請を再申請(論点8)
  - `workflow_request.cancelled` → 対応する申請を取消
  - 有給も特別・代休と同じく`paid_leave_request.shared`を記録し、申請・承認文脈の`SubmitWorkflowRequestOnPaidLeaveRequestSharedReactor`
    (新設)がワークフローを提出する(`RequestPaidLeaveHandler`のインライン提出を削除)。
- 申請Handlerは他文脈の集約・テーブルを読み書きしない(勤怠日・勤怠計算・締め判定・他の休暇申請の重複チェック・
  残数Projectionの読み取りを全て削除)。
- 申請テーブル(`paid_leave_requests`・`special_leave_requests`・`compensatory_leave_requests`)は休暇申請文脈の
  Projectorだけが更新する。`paid_leave_requests`のProjectorの入力は申請IDごとに排他の3系統(論点4の境界条件・論点13。
  `migrated`より前は旧系統、以後は新イベント):
  旧`paid_leave.requested/request_approved`、cutover後〜本変更前の`paid_leave_account.usage_designated/usage_confirmed/
  usage_cancelled`+`workflow_request.returned`、本変更後の`paid_leave_request.*`。

### D. 残数・使用文脈(`App\Domain\PaidLeaveAccount`・新設の特別休暇/代休の口座)
- 有給: `PaidLeaveAccountAggregate`を維持。Reactor:
  - `paid_leave_request.requested`/`.resubmitted` → `DesignatePaidLeaveUsage`
  - `paid_leave_request.approved` → `ConfirmPaidLeaveUsage`(残数不足は例外。論点17)
  - `paid_leave_request.returned`/`.cancelled` → `CancelPaidLeaveUsage`(承認済みは充当解除)
  - 対応する消化記録は申請IDで特定する(口座集約内で申請ID→usageIdを保持)。
  - `PaidLeaveUsageAllocationProjector`から`paid_leave_requests`の更新処理を削除する。
- 特別休暇・代休: 利用者単位の口座集約`SpecialLeaveAccountAggregate`・`CompensatoryLeaveAccountAggregate`を新設
  (集約IDは種類ごとの派生ID。`UserManagementStreamId`と同じ方式)。付与の残数と消化記録(作成・確定(付与への充当)・
  取消)を持つ。イベント: `special_leave_account.*`・`compensatory_leave_account.*`(付与・付与取消・消化の作成・
  確定・取消・移行)。Reactorは有給と同じ対応。代休の付与は`attendance_day.calculated`を受ける既存の同期Reactorの
  同期先を口座集約に変える(勤怠日テーブルを直接読まず、計算イベントの内容(利用者・日付・休日労働時間)を使う。
  不足する情報は計算イベントに追加する)。
- 移行: 運用コマンドで、利用者ごとに既存の付与集約・消化記録の現在の状態(付与残・未取消の消化記録。差し戻された
  申請の消化記録は含めない)を`*_account.migrated`イベントとして今の時点に追記する。Projectorは同じテーブルに元の
  IDでupsertする。移行以前の旧イベントは既存Projectorで再生する(移行イベントは同じ行を上書きするだけ)。
- 消化記録テーブル: 勤怠日への外部キー・申請テーブルへの外部キーを撤去し、`attendance_day_id`を任意にする
  (新規には設定しない)。

### E. 勤怠文脈(`App\Domain\Attendance`)
- 休暇ビュー`attendance_day_leaves`(新設Projection): 列=id、user_id、work_date、leave_kind(paid/special/
  compensatory)、unit(full/am_half/pm_half/hourly)、hours、minutes、special_leave_type_id、request_id、
  workflow_request_id、request_status(submitted/approved)、source_event_id。入力=休暇申請文脈のイベント
  (論点2、有給は論点4の境界条件の3系統と`paid_leave_request.migrated`)。差戻し・取消で行を削除する。
- Reactor(休暇申請のイベント→勤怠のCommand):
  - 申請・再申請 → `ApplyLeaveToAttendanceDay`: 締め判定(論点14、全遷移)と同じ日の衝突チェック(論点7、判定対象から
    今回の休暇自身を除く)を行い、違反なら例外。勤怠日が無ければ`attendance_day.created`(`source=leave`、
    status=not_started、実績なし)を記録する(論点3)。日次計算を記録する。
  - 承認 → 締め判定。日次計算を記録する。
  - 差戻し・取消 → `ReleaseLeaveFromAttendanceDay`: 締め判定。論点15の条件を満たせば勤怠日を削除
    (`attendance_day.deleted`)、満たさなければ日次計算を記録する。
- `source=leave`の勤怠日: 打刻の取り込み(`AttendanceDayPunchSyncer`)・Web打刻・日次編集は`source=punch`の日と同じく
  上書きできる(`source=manual`のように打刻を止めない)。打刻で取り込んだ時点で`source=punch`に変わる。
- 勤怠計算(`AttendanceCalculator`)は休暇を`attendance_day_leaves`から読む(消化記録テーブル・`work_type`を読まない)。
  計算結果は現行と同じにする: 有給日数・特別休暇日数は全休1.0・半休0.5(代休は0)、時間休分は有給・特別の時間休の
  分数の合計、半休の日(3種とも。現行の`_am_half`/`_pm_half`判定と同じ)は所定労働時間を半分にする。
- 月次集計・スナップショット・給与連携・Excel・画面APIの集計ロジックは変更しない(休暇だけの日も勤怠日と日次計算が
  論点3で存在するため)。`special_leave_breakdown`(月次API)と残数不足警告は`attendance_day_leaves`(特別休暇の種別ID)
  から作る。
- 全休日の出勤可否・打刻取り込み・打刻漏れ警告・未出勤件数・今日の表示は`attendance_day_leaves`の全休で判定(論点9)。
  「全休」には、午前半休と午後半休が(種類を問わず)そろう日を含む(仕様確定事項I)。
- 月次提出ガードは`attendance_day_leaves`の`leave_kind=paid`かつ申請中で判定(現行どおり有給のみ。論点14)。
- 勤怠APIの日次・週次・月次・今日の応答に`leaves`(休暇ビューの行)を含める。
  既存の`special_leave_usages`項目は`leaves`に置き換える。(休暇だけの日も論点3で勤怠日があるため、勤怠日の無い
  休暇日を別途返す必要はない)
- `work_type`: 休暇値の書き込み・解釈を全て削除(論点10)。

### F. 有給・特別休暇の出勤率(論点9)
- 有給(`PaidLeaveSchedule`)・特別休暇の文脈に、出勤率用のビュー`leave_attendance_rate_days`(利用者×日付:
  is_working_day、attended、full_leave_kinds、partial_leave_kinds)を新設し、`AttendanceRateAssessor`・
  `GrantScheduledSpecialLeaveHandler`はこれだけを読む(勤怠日・カレンダーのテーブルを読まない)。
- 入力: 分母=`employee_calendar_entry.assigned`の`isWorkingDay`(同じ利用者・日付の最新)。分子の出勤=勤怠のイベントから
  求めた「退勤済み」(`attendance_day.created`/`edited`/`synced_from_punches`/`live_status_synced`/`deleted`のstatus。
  勤怠日IDから利用者・日付への対応は同じイベントから保持)。休暇=休暇申請3種のイベント(申請中・承認済み)。
- 判定は現行の結果と同じにする: Assessorの分子=退勤済み ∪ 全休の休暇(3種) ∪ 有給の半休・時間休。Grantの分子=
  退勤済み ∪ 全休の休暇(3種) ∪ 有給・特別の半休・時間休。分母日と重なる日だけを数える。午前半休と午後半休が
  (種類を問わず)そろう日は全休の休暇として扱う(仕様確定事項I。現行は後から書いた片方だけが効いていたための差で、
  意図的な変更)。入力に`paid_leave_request.migrated`等の引き継ぎイベントを含める。
- 勤怠のイベントに不足する情報があれば勤怠のイベントに追加する(利用者・日付は`attendance_day.created`等が持つ)。

### G. フロントエンド
- 休暇ラベル・バッジは`leaves`から表示(複数あれば複数)。全休・半休・時間休とも現行と同じく状態バッジの代わりに休暇
  ラベルを表示する。打刻漏れ警告・今日の勤怠の出勤ボタンは`leaves`の全休で判定。
- 日次画面: 休暇指定UIは`leaves`から現在の休暇を判定。作業内容欄は常に表示し入力値を送る。
- 申請詳細画面: 業務側を持つ申請で「却下」を出さない。差し戻された休暇の「提出する」は残す。
- 休暇の各画面: 差し戻された申請を表示し、再提出(申請詳細へ)・取消の導線を出す。
- `CancelApprovedLeaveDialog`等の「勤怠区分もクリアされます」の文言を修正。

### I. 最終設計レビューで確定した事項
- **本変更前に申請された申請の引き継ぎ**: 運用コマンドで、既存の全ての有給申請(旧`paid_leave.*`の8件、cutover後の申請)に
  ついて現在の状態(申請中/差戻し/承認済み/取消、対象日・取得単位・時間数・ワークフローID・まとめ申請ID・対応する消化記録ID)を
  `paid_leave_request.migrated`として今の時点に追記する。以後の承認・差戻し・取消・再提出は全て`PaidLeaveRequestAggregate`で
  処理する。特別・代休の口座の`*_account.migrated`には申請ID→消化記録IDの対応を含める。これにより論点4・13の3系統の
  入力は「`migrated`以前の再生」にだけ使われる(論点4の境界条件)。
- **消化記録の無い引き継ぎ済み有給の取消**: cutover前の8件(承認済み・消化記録なし。cutover時に残数へ反映済み)は、
  システム上の取消を拒否する(「移行前の申請のため取消できません。付与日数の調整で対応してください」)。対象日は
  2026年8月〜9月4日で、締め済みであれば論点14により元々取消できない。
- **差し戻された申請の消化記録(特別・代休の移行時)**: `*_account.migrated`から除外した差戻し分の消化記録は、補正(H)で
  取消イベントを追記して行を残さない。
- **月次APIの代休付与表示のビュー**: 勤怠側が代休の口座のイベントから作り、利用者×日付で付与(日数・時間・確定状況・
  充当量)を持つ。
- **代休付与の連携(原則15)**: 付与の同期Reactorは`attendance_day.calculated`・`daily_calculation_adjusted`・`deleted`を
  購読する(現行と同じ)。計算イベントの内容(利用者・日付・日区分・休日労働時間)で判定し、不足する項目は計算イベントに
  追加する。手動付与(`GrantCompensatoryLeaveHandler`)は勤怠日を読まず、代休の口座側が計算イベントから作る休日出勤の
  ビューで休日出勤実績を確認する。月次APIの代休付与表示(`AttendanceMonthResource`)は勤怠側が代休のイベントから作る
  ビューを読む。月次確定での付与確定(`ConfirmCompensatoryLeaveGrantsForMonthHandler`)は口座集約に付け替え、月の判定は
  文字列の前方一致ではなく日付範囲で行う。`compensatory_leave_grants.attendance_day_id`の外部キー(unique)も撤去する。
- **出勤率の入力**: `attendance_day.synced_from_punches`は退勤済みとして扱う(Projectorと同じ)。勤怠日IDと利用者・日付の対応は
  `created`・`synced_from_punches`・`live_status_synced`・補正専用イベントから持つ。「現行と同じ結果」は補正(H)後のデータに
  対する約束とする。差し戻された有給は本変更で休暇から外れるため出勤扱いにならない(意図的な変更)。
- **同じ日に午前・午後で別の休暇がある場合の勤怠計算**: 午前半休と午後半休がそろう日は全休と同じく扱い、所定労働時間は
  半分にしない(全休と同じP)。休暇日数は種類ごとに0.5ずつ。半休が1つだけの日は現行どおり所定労働時間を半分にする。
- **時間休の分数**: 休暇ビューの`minutes`は`round(hours * 60)`(現行の消化記録の算出と同じ)。
- **勤怠のイベントの必須項目**: 勤怠が休暇に反応して記録する`attendance_day.created`/`deleted`の`createdByUserId`/
  `deletedByUserId`には連鎖の起点の操作者(休暇の申請者・承認者・取消者)を入れる(システム処理で操作者がいない場合は
  申請者)。`utcOffsetMinutes`は利用者の既定タイムゾーン、`punchLogAction`は打刻ログを変更しない値とする。
- **論点15の削除条件の詳細**: 実績=出退勤・休憩・不就労区間・作業内容・勤務形態区分・備考のいずれか、手動調整、週40時間の
  配賦のいずれかがある日は削除しない(再計算する)。
- **通知**: 現行の休暇Handlerが送っている通知を一覧化し(WP3で作成して委譲元がレビュー)、同じ契機・同じ受信者で、状態を
  確定させた文脈のHandlerから送る。連鎖内で同じ通知を二重に送らない。
- **既に却下済みの休暇ワークフロー**: 却下済みのワークフローに紐づく休暇申請が申請中のまま残っていれば、`migrated`時に
  取消として引き継ぐ(リハーサルで件数を確認する)。

### H. 補正(論点12)
- 補正コマンドの枠組み(試し実行既定、`--apply`、バックアップテーブル、1トランザクション、版の連続性検証)と、補正専用
  イベント(勤怠日の現在の正しい状態一式を含む)を用意する。対象・件数・方法はリハーサルで確定し許可を得る。
- 勤怠日Projectorにリセット処理(全件再生成)を追加する。
- 日次計算の一括再計算コマンド`attendance:recalculate-days`(`#[AdminExecutable]`、`--from`/`--to`必須、`--user=*`、
  既定は試し実行、手動調整済みの日は除外し一覧出力)。

## 受け入れ条件
- 休暇3種それぞれについて、申請・承認・差戻し・申請者による取消・管理者による取消・ワークフロー側からの取消・
  承認不要での申請・複数日まとめ申請の各シナリオで、ワークフロー/休暇申請/消化記録・残高/勤怠の休暇ビュー・
  日次計算・月次集計が設計どおりに連動する(シナリオテスト)。
- 承認済みの休暇を取り消してもワークフローは承認済みのまま。管理者による取消が失敗しない。
- 差し戻した日に同じ休暇を申請し直しても二重に計上されない。差し戻された休暇は残高(申請中)に含まれない。
- 同じ日の休暇の衝突(全休を含む併存・同じ半休・合計が所定超え)が3種共通で拒否される。午前有給+午後代休は通る。
- 締め済みの日への休暇の申請・取消はエラーになり、どの文脈の状態も変わらない。
- 休暇日の勤怠を編集しても休暇の表示・集計・残高は変わらない。`work_type`は休暇に影響しない。
- 全休の日は出勤不可・打刻を取り込まない・打刻漏れ警告が出ない。半休の日は打刻できる。
- 休暇だけの日(勤怠が`source=leave`で記録)も月次の休暇日数・給与連携に含まれ、同じ休暇データに対して改修前と同じ値になる。
- 同じイベントを2回処理しても各文脈の状態が変わらない。却下済みのワークフローに紐づく申請中の休暇が取消として引き継がれる。
  まとめ申請で兄弟の一部が差戻し中でも承認が通る。cutover前の有給の取消は拒否される。
- 休暇の各Handlerが他文脈の集約・テーブルを直接読み書きしていない(コードレビュー)。
- 同じイベントを購読する複数のReactorの実行順を入れ替えても結果が同じ。通知が重複しない。
- 勤怠・休暇申請・残数の各Projectorを空から全件リビルドしても、リビルド前と同じ状態になる(補正後の本番相当
  データで確認)。手動調整した日は補正後の再計算で上書きされない。
- 再提出で承認まで通る、業務側を持つ申請は却下できない、残数不足で承認が拒否される(3種)、休暇の解除で空の勤怠日が
  削除される、出勤率の判定結果が補正後データで変わらない、`source=leave`の日に打刻が取り込まれる、本変更前に申請された
  申請を本変更後に承認・差戻し・取消できる、cutover前8件が休暇ビューに表示される、午前有給+午後代休の日の計算が上記I
  のとおりになる。
- 本変更で新設・変更した業務ルールごとのテストと、受け入れ条件の各シナリオを通すUI非依存のシナリオテストが存在し、
  CIでPASSする。
- backend/frontendのテストがCIで全てPASSする。

## 対象外
- 代休を勤怠計算の休暇日数に算入するか
- 出勤率の算定対象とする休暇の種類の見直し(法令判断)
- `work_type`の改名
- MCP側の変更

## ドキュメントへの影響
- ルート`CLAUDE.md`: 設計原則15を追加済み。原則14の記述との整合を確認する。
- `docs/03-architecture.md`: 118-124の記述を原則15の方針に改め、3.9・横断ルール(同期Reactor・実行者と認可・
  冪等性・Reactorの独立性・リビルド)と文脈の図を追加。
- `docs/29-event-sourcing-framework-migration.md:312-322`: Reactorの定義に冪等性・`viaReactor`を追記。
- `docs/09-usecases-paid-leave.md`ほか特別休暇・代休・勤怠(`docs/07`)・ワークフロー(`docs/10` UC-W004/W005)の
  ユースケース、`docs/16-database-schema.md`(`work_type`、`attendance_day_leaves`、各申請テーブルの所有者、
  口座集約、消化記録の`attendance_day_id`)、`docs/17-events.md`(新イベント・購読関係)、`docs/32`(補正手順)。
- `.claude/skills/add-domain-event`: 文脈間の連携はReactor+冪等Commandで行う旨を追記。
- OpenAPIの注釈(`special_leave_usages`→`leaves`)、`docs/04-domains`(文脈の責務)、`docs/13`(通知の送信元)。

## モック・アセット
- `assets/`配下の調査結果(`impact-usage-derivation.md`、`returned-resubmit-and-overlap.md`、
  `other-request-types-return-resubmit.md`、`data-correction-prerequisites.md`、`context-coupling.md`、
  `monthly-items-and-attendance-rate.md`)

## 実装対象
文脈単位の作業パッケージ(WP)に分け、implementerへ順に委譲する(依存のないものは並列)。各WPでテストを追加し、
完了ごとに委譲元がレビューする。全WPは同じブランチで開発し一緒にリリースする。WP3〜WP5は互いに依存し単独では既存テストが
通らないため、CIの全件PASSはWP2〜WP5をそろえた時点と全WP完了時点で確認する(それ以前は各WPのテストを確認)。
申請・承認文脈の休暇テーブル読み取り(兄弟承認)の削除はWP3で、休暇申請側の兄弟承認と同時に行う。
| WP | 内容 | 主な対象 | 依存 |
|---|---|---|---|
| WP1 共通基盤 | `viaReactor`・`initiatedByUserId`の規約、冪等ガードの共通化、Projectorの行なし許容 | `App\Domain\EventSourcing`、各Projector | - |
| WP2 申請・承認 | 却下の制限、`CancelWorkflowRequest`・`ApproveWorkflowRequest`の`viaReactor`、取消Reactorの3種統一、有給の提出Reactor・兄弟ワークフロー承認Reactorの新設 | `App\Domain\Workflow` | WP1・WP3のイベント定義 |
| WP3 休暇申請 | `PaidLeaveRequestAggregate`・`paid_leave_request.*`(`migrated`含む)、ワークフロー対応表、兄弟承認の移設(申請・承認側の休暇テーブル読み取りの削除を含む)、通知の一覧化と移設、申請Projector(有給は3系統)、特別・代休の申請集約から使用操作を削除・`request_resubmitted`、ワークフローイベントのReactor、申請Handlerから他文脈操作を削除 | `App\Domain\PaidLeave`・`SpecialLeave`・`CompensatoryLeave`、`Workflow/Reactors`の休暇系 | WP1・WP2 |
| WP4 残数・使用 | 有給口座のReactor化・申請テーブル更新の削除・残数不足の拒否、特別・代休の口座集約・イベント・Projector・移行コマンド、代休付与同期の付け替え、消化記録の外部キー撤去・`attendance_day_id`任意化 | `App\Domain\PaidLeaveAccount`、新設の口座、マイグレーション | WP3 |
| WP5 勤怠 | `attendance_day_leaves`、`ApplyLeaveToAttendanceDay`/`ReleaseLeaveFromAttendanceDay`とReactor、`source=leave`、衝突チェック・締め判定、勤怠計算の休暇入力、出勤可否・打刻取り込み・警告・未出勤件数・月次提出ガード、API`leaves`、勤怠日Projectorのリセット、`attendance:recalculate-days`、`work_type`の休暇解釈の削除 | `App\Domain\Attendance`、`AttendanceController`、`AttendanceDayResource` | WP3 |
| WP6 出勤率ビュー | `leave_attendance_rate_days`とProjector、`AttendanceRateAssessor`・`GrantScheduledSpecialLeaveHandler`の入力置き換え | `PaidLeaveSchedule`・`SpecialLeave` | WP3・WP5 |
| WP7 フロントエンド | `leaves`の型・表示、打刻漏れ・出勤ボタン、日次画面の休暇判定・作業内容欄、申請詳細の却下非表示、休暇画面の差戻し表示と導線、文言修正、e2e | `frontend/src` | WP5 |
| WP8 補正の枠組み | 補正コマンドの枠組み・補正専用イベント(対象と内容はリハーサルで確定) | `App\Console\Commands`、`App\Domain\Attendance` | WP4・WP5 |
| WP9 docs | 「ドキュメントへの影響」の全件 | `docs/`、`.claude/skills/add-domain-event` | 全WP |

## 検証方法
- backend: CI(PHP 8.4)。この作業環境はPHP 8.3のためローカルでは実行できない。
- frontend: `npm test`・`npm run lint`(ローカル)

## レビュー履歴
- 2026-10-09 初版(休暇日の編集で休暇値を維持する)
- 2026-10-09 work_type変更ではusageが作られず矛盾することを確認。休暇値の新規指定を422で
  拒否し、申請不要時も休暇申請APIを唯一の経路とする論点を追加。
- 2026-10-09 ReadModelのみの補正は不可(ユーザー指示、CLAUDE.md原則2に追記)。休暇値を
  休暇イベントからProjectorで決める根本対応と、`data-correction`スキルに沿った過去イベントの
  直接修正による補正(ユーザーが補正方法として「既存イベントを書き換え」を選択)に全面改訂。
  タイトルを変更。
- 2026-10-09 ユーザーがイベント履歴の直接修正(補正コマンドの対象・範囲・書き換え内容)を明示的に
  許可し、変更セットを承認。日次計算の再計算コマンドを仕様確定事項に追加。ステータスを実装中に更新。

- 2026-10-10 **方針B(休暇を勤怠日に保存せず、usageから導出)へ変更**。ユーザー指摘:
  `work_type`を日の区分と考えていたが、日の区分は`day_classification`が持ち、代休は
  「所定労働日+休暇」。休暇の正はusage(申請・消化記録)であり、`work_type`の休暇値は
  イベントを伴わないコピー(正データの二重化)で、今回の不具合群の根本原因と判断した。
  これにより、イベント履歴の直接修正(補正コマンド)・`workTypeProvided`・休暇イベント購読
  Projectorの案は全て取りやめ(書き換えの許可も不要になった)。本変更セットは改訂後の
  `changeset`スキル手順に従い「設計前提の検証」から作り直す(未着手)。作り直し時の前提:
  - 休暇の有無・種別・取得単位はusage(`attendance_day_id`・`usage_type`)から求める。
    勤怠計算(`AttendanceCalculator`)・`AttendanceRateAssessor`・`GrantScheduledSpecialLeaveHandler`・
    勤怠APIの出力・フロントのバッジ判定を置き換え、休暇の申請・取消Handlerの直接書き込みを削除する。
  - `work_type`は休暇値を持たない列に戻す(日次画面では「作業内容」)。過去イベント内の休暇値は
    読まなくなるだけで書き換えない。列名・意味の整理は論点として検討する。
  - 全休日の`status=clocked_out`直接書き換え(退勤忘れ警告の回避)の代替を検討する。
  - 本番の復旧は、読み方の変更+日次計算の再実行(計算イベントの追記)+月次スナップショット
    再計算で行う。日次計算の一括再実行コマンドは既存に無い。
  - 提示前に`changeset`スキルの独立設計レビューを行う。
  - 影響範囲(usageテーブル構造、休暇値の全読み手、テスト・docs、決めるべき論点)は
    `assets/impact-usage-derivation.md`に調査結果を保存済み。
- 2026-10-10 `changeset`スキルの改訂手順(設計前提の検証)に従い、方針Bで変更セットを作り直した。
  論点1〜9を新規に検討。前案(休暇イベント購読Projector・`workTypeProvided`・イベント履歴の
  直接修正・休暇値の入力拒否)は全て破棄。
- 2026-10-10 独立設計レビュー(同等モデルのサブエージェント)の指摘(委譲元で主要箇所を検証済み):
  - blocker: 論点2「取り消されていない消化記録すべて」は現行と同じではない。差し戻された申請は取消も
    再承認もできず(`CancelPaidLeaveRequestHandler.php:61`等)、重複チェックが差戻しを除外するため
    同日に再申請でき、二重計上になる。
  - blocker: 本変更単独のリリースで、既存の全休(`status=clocked_out`)を取り消した日に出勤・打刻が
    できなくなる(`ClockInHandler.php:40`、`AttendanceDayPunchSyncer.php:86-93`)。旧休暇値が作業内容
    欄の初期値から編集イベントに記録されてしまう。
  - major: `status`を「出勤または休暇」として読む箇所の洗い出し不足(`DevicePunchController.php:92`、
    `TodayAttendancePanel.tsx`、`AttendanceDayRow.tsx:55`、`statusLabels.ts:222`、PunchSyncer)。
  - major: 半休の所定半減は代休にも効いている(`AttendanceCalculator.php:157-158`は接頭辞を見ない)。
    As-Isの「代休は未算入」は日数のみ正しく、所定半減については誤り。
  - major: 論点5で勤怠日の行を作らない選択肢が未検討。有給の`attendance_day_id`はnullable。
    `CreateAttendanceDay`は`source=MANUAL`の行を作り以後の打刻が反映されない。
  - major: 論点8の再計算は手動調整を解除する(`AttendanceDailyCalculationProjector.php:87`)。補正前の
    行への計算イベント追記・月次再計算との二重実行・実行順序の考慮が必要。
  - major: 論点3「いずれかの有効な申請があれば拒否」は異なる種類の半休の組合せも拒否する業務ルール変更。
  - minor: 休暇ラベルは半休・時間休でも状態バッジの代わりに表示されている、`leaves`の返却範囲・
    eager loadの粒度、docs/16の行番号、UI文言(`CancelApprovedLeaveDialog.tsx:55`)・OpenAPI注釈の更新漏れ、
    削除ガードが取消済み有給も拒否する既存不具合。
- 2026-10-10 レビュー指摘に対するユーザー決定:
  - 差戻し: 再申請で置き換え(取消)できるなら休暇として扱う。再申請できない場合は再申請できるようにする。
  - 重複チェック: 3種共通で、時間が重なる組合せ(全休を含む併存・同じ半休の重複)だけを拒否する。
  - 本番データの補正: 本変更セットに含める(`data-correction`スキルの手順で検討)。
  - 休暇では勤怠日の行を作らない(休暇は対象者と日付で勤怠日と紐づける)。
  → 論点2・3・5・7を改訂する。差戻しの再申請と補正の前提事実をinvestigatorで調査中。
- 2026-10-10 ユーザー決定: 差戻しは有給の設計書(`docs/09-usecases-paid-leave.md:315-316`、差戻しで未確定Usageを
  取り消し、再提出は新規Usage)の挙動に揃え、有給・特別休暇・代休の実装を設計書どおりに修正する
  (他申請種別の調査は`assets/other-request-types-return-resubmit.md`)。
- 2026-10-10 ユーザー指示: 「勤怠の休暇設定」「有給・特別休暇・代休の残数・使用管理」「承認・申請」を
  コレオグラフィー(イベント駆動の連携)で、互いに独立した状態を保ちつつ連動して動く設計にする。
  → アーキテクチャ上の設計前提が変わるため、`changeset`スキルに従い「設計前提の検証」から作り直す。
  文脈間の現在の結合をinvestigatorで調査中。
- 2026-10-10 ユーザー指示(コレオグラフィー)により設計前提が変わったため、`changeset`スキルに従い「設計前提の検証」
  から作り直した。文脈(申請・承認/休暇申請/残数・使用/勤怠)と責務、論点1〜12を新規に検討。前案の論点(消化記録の
  直接参照、休暇では勤怠日の行を作らない等)は本版の論点に置き換えた。
- 2026-10-10 独立設計レビュー(同等モデル)の指摘。blocker4件は委譲元で検証済み:
  - B1: 論点12(1)(2)の「履歴への挿入+版の振り直し」は、replayが`stored_events.id`順のため挿入イベントが最後に
    再生され再現できない。→ `data-correction`スキルに「直接修正は書き換え・削除のみ、挿入は扱わない」を追記して是正。
  - B2: 特別・代休には「申請を取り消さずに未確定の消化記録だけを取り消す」イベントが無い(`SpecialLeaveUsageProjector`の
    取消は`request_cancelled`のみ・検証済み)。差戻し補正には新イベントが要り、論点6とも絡む。
  - B3: 有給はcutover時に過去の個別Usage履歴を再現していない(`PaidLeaveAccountAggregate.php:283-300`、`docs/09:448`・
    検証済み)。「全履歴分のイベントが既にある」は有給については誤り。cutover前の日の扱いを論点化する必要がある。
  - B4: 承認済みのワークフローは取消できない(`WorkflowRequestStatus::cancellable()`はDRAFT/SUBMITTED/RETURNEDのみ・
    検証済み)。また`CancelWorkflowRequest`は申請者本人を要求し、管理者による休暇取消の連鎖で失敗する。
    Reactor発行Commandの実行者・認可モデルが未定。
  - major: 文脈横断の洗い出し漏れ(複数日まとめ申請の連鎖承認、締め・ロック判定`AttendanceEditGuard`、月次提出ガード等)、
    残数不足時の挙動(特別休暇は不足でも承認され`used`が出ない)、論点7が所定労働時間(勤怠側)に依存、
    勤怠Reactorの副作用(手動調整の解除、代休付与同期Reactorの連鎖、取消後の`source=leave`の空行、特別・代休の
    `attendance_day_id`のNOT NULL)、論点6の規模(集約IDの衝突、移行イベント)、論点4の新旧イベント二重処理、
    冪等性(利用者操作とReactor経由の区別、Reactor実行順)。
  - 推奨: 段階分け(CS0 方針docs → CS1 現行構造での不具合修正 → CS2 勤怠の休暇ビュー → CS3 有給申請集約 →
    CS4 特別・代休の残数集約)。各段階で空からのリビルドの再現性を確認してから次へ進む。
- 2026-10-10 ユーザー決定: 段階分けせず1つの変更セットで一括変更(論点6を含む)。文脈間連携の方針はルート`CLAUDE.md`
  の設計原則15として全体ルールにする(追加済み)。論点3は勤怠日の集約を作らずReadModel(休暇ビュー)で月次集計する
  (ReadModelのみなら許可不要)。
- 2026-10-10 設計レビューの指摘を反映して論点を改訂: 横断ルール(実行者と認可・冪等性・Reactorの独立性・リビルド)を
  追加。論点2の入力を休暇申請のイベントに変更(申請状態の区別のため)、論点3をBに変更、論点4に新旧イベントの境界条件、
  論点5に承認済みワークフローは変えない・管理者取消・複数日まとめ申請、論点6に集約ID・移行(追記)・代休付与同期・
  `attendance_day_id`任意化、論点7の衝突チェックを勤怠文脈へ移動、論点9の出勤率は対象種類を現行維持、論点12を
  書き換え・削除・追記で再構成(挿入を撤回)、論点13(cutover前の有給)・14(締め・ロック)・15(取消後の勤怠日)を追加。

- 2026-10-10 2回目の独立設計レビュー(同等モデル)。前回B1・B2・M3は解消、B3・B4・M1・M4〜M7は部分、M2は未解消。新たな指摘
  (主要箇所は委譲元で検証済み):
  - blocker: 論点3Bで「改修前と同じ値」にならない。全休日も日次計算は所定労働時間を満額で返し(`AttendanceCalculator.php:146-155`)、
    月次が合計し(`MonthlyOvertimeCalculator.php:120`・検証済み)、freeeの`total_normal_work_mins`等が使う。`day_count`・Excel日別備考も変わる。
  - blocker: 消化記録3種が勤怠日への外部キー(連鎖削除なし)を持ち、勤怠日の削除・リビルドが失敗する。
  - blocker: 論点12(2)の補正`created`追記では計算行・手動調整・週40時間配賦が戻らず、打刻同期等は行が無いと作るため「行が無い間は無視」が一様でない。
  - major: 論点9は事実誤認。現行は全休の特別休暇・代休も`status=clocked_out`のため出勤率で出勤扱い(`AttendanceRateAssessor.php:70-73`・検証済み)。
    出勤率の入力の取り方を先送りしている(勤怠の問い合わせインターフェースを原則15の例外とする案)。
  - major: 却下(`workflow_request.rejected`)が未考慮(却下は種別を問わず可能・検証済み)。
  - major: 論点14で締め後に申請中の休暇が行き詰まる。有給・代休は現在締めを検証していない。管理者の扱いが未定。
  - major: 月次提出ガードは現行で差戻しも提出不可(`PaidLeaveApprovalGuard.php:21`)。休暇ビューは差戻しを外すため挙動が変わる。
  - major: 本変更以前に申請され処理中の有給申請の扱い、cutover前の申請行の再生、論点6・12(1)の取消の明示、
    原則15違反の追加漏れ(`SyncCompensatoryLeaveGrantHandler.php:34`の勤怠日直接読取、月次APIの特別休暇消化記録読取、
    有給消化記録→申請テーブルの外部キー)、残数不足時の挙動(M2)、実行者メタデータの仕組み(イベントmetaは現状BackOffice集約のみ)、通知の担当文脈。
- 2026-10-10 ユーザー決定: 論点8は「差し戻されると申請前の状態に戻り(メッセージとともに)、再提出できる」定義のまま
  「提出する」を残し、休暇側が再提出に反応する。論点9は出勤率の結果を現行維持し、休暇側が勤怠イベントから自分の出勤
  ビューを作る(原則15の例外なし)。却下は業務側を持つ申請では不可(論点16)。締め済みの日は全ての遷移で拒否・管理者
  例外なし(論点14)。残数不足は3種とも承認拒否(論点17)。論点12はリハーサルで確定。論点13は本番の`paid_leave.*`
  イベント(requested/request_shared/request_approved/usage_designated 各8件、2026-08-31〜2026-09-04)を確認済みで、
  新ドメインとの二重記録の有無を追加確認中。
- 2026-10-10 論点13: 本番確認の結果、旧`paid_leave.*`の8申請は新ドメインに記録が無く二重にならないため、旧イベントも
  休暇ビュー・有給申請Projectorの入力にする(A)と決定。これで全論点の要確認事項が解消。仕様確定事項の記載へ進む。
- 2026-10-10 論点3を再検討しA(勤怠が自分の集約で休暇だけの日を記録)に変更(ユーザー決定)。調査で、休暇だけの日も日次計算
  イベントが所定労働時間等を記録し月次・給与連携・Excelが使っていることが判明し、ReadModelのみでは値がイベントで固定されない
  ため。論点15を「休暇の解除で、勤怠が記録しただけの空の勤怠日は勤怠が削除する」に変更。
- 2026-10-10 仕様確定事項(A〜H)と実装対象(WP1〜WP9)を記載。
- 2026-10-10 ユーザー指示により原則16を新設し(同日、ユーザーの補足で「SQLite+Eloquent可、UI非依存の軽量テストで
  文脈間連鎖を含めて網羅する」に改訂、スキル名を`domain-test`に変更)、本変更セットの
  横断ルール・受け入れ条件に反映。代休の業務ルールの置き場所の現状を`assets/domain-unit-test-coverage.md`に保存。
- 2026-10-10 最終設計レビュー(3回目、同等モデル)の指摘を反映(blocker3件は委譲元で検証済み): まとめ申請は1日ごとに別ワーク
  フローのため兄弟承認を休暇申請側+逆方向Reactorに整理、ワークフローID→申請の対応表を休暇申請側に新設、本変更前の申請を
  `migrated`で引き継ぎ、有給の提出Reactor、出勤率の入力の穴、代休付与連携の原則15違反と外部キー、午前・午後の別休暇の計算、
  時間休分の算出、月次提出ガードを有給に限定、WPの束ね方、勤怠イベントの必須項目、削除条件、通知、受け入れ条件・docsの漏れ。
  午前・午後で別の休暇がそろう日は全休と同じ扱い(所定を半分にしない)と委譲元で決定(ユーザーに報告)。
- 2026-10-10 最終設計レビュー後の再確認の指摘を反映: 3系統の切り替えを`migrated`基準に統一(論点4・13・C・E・F)、逆方向連携に
  勤怠→代休の口座を追加、午前・午後の別休暇がそろう日を出勤可否・警告・出勤率でも全休扱い、兄弟承認は申請中のみ・
  `ApproveWorkflowRequest`の`viaReactor`、drafted Reactorの`workflow_requests`読み取り削除、cutover前8件の取消は拒否
  (委譲元で決定、ユーザーに報告)、論点5の再申請の記述を論点8に合わせて修正、テスト一覧・受け入れ条件の追加、WP2の依存。
- 2026-10-10 ユーザーが変更セットを承認(「実装をお願いします」)。ステータスを実装中に更新。
- 2026-10-10 実装中の決定: 有給申請の新しい集約・イベントは、旧イベントクラス(`App\Domain\PaidLeave\Events\PaidLeaveRequestApproved`等、
  再生のため残置)とのクラス名衝突を避けるため、新ドメイン`App\Domain\PaidLeaveRequest`に置き、イベントクラス名は
  `PaidLeaveRequestLifecycle{Requested,Shared,Approved,Returned,Resubmitted,Cancelled,Migrated}`とする(イベント名は
  `paid_leave_request.*`のまま)。implementerが衝突を検出して停止したため委譲元で決定。
- 2026-10-10 実装中の決定(WP4a 特別休暇の口座集約): 充当の有効判定は承認日ではなく利用日基準(現行どおり。`confirmUsage`は
  `$today`を取らない)。取消済みの付与からは充当しない(現行は取消済みでも残数が残り充当できてしまう潜在不具合のため、意図的な
  変更として是正)。同じ失効日の付与は登録順。集約IDは`UserManagementStreamId::for('special_leave_account', userId)`。
- 2026-10-10 CI対応(本変更セットの範囲): `SpecialLeaveRequestTest`の差戻し通知のテストが、通知一覧の並び(`queued_at`秒単位)と
  差戻し時の同一秒の2通知(ワークフロー側・特別休暇側)により不定に失敗したため、テストを「リンクが`/special-leave/history`の通知が
  あること」の確認に変更(製品コードは不変)。二重通知自体はWP3の通知の整理で解消する。
- 2026-10-10 実装中の決定(WP4b 代休の口座集約): 現行ルールを移植(同期・月次確定(日付範囲で判定)・手動付与・付与取消・承認時の
  充当(利用日基準・失効日昇順・無期限最後)・取消時の戻し・残数)。論点17により充当不足は例外。時間単位の消化は分数を必須にした
  (現行は分数nullで充当なしのまま承認されていた)。**リハーサルで確認する事項**: (a)現行で残数不足のまま承認された消化記録の引き継ぎ方
  (migrateは不足のまま引き継ぐ)、(b)時間単位で分数がnullの既存の消化記録の有無(migrateが拒否するため)、(c)付与一覧の残数表示が
  現行は下書きを含む点を移行後も維持するか(表示用Projectorで対応)。機能無効時の下書き・手動付与の扱いは現行どおり。
- 2026-10-10 実装中の決定(WP5a 勤怠の休暇ビュー): `attendance_day_leaves`は差戻し・取消で行を削除せず`request_status`をreturned/cancelledに
  する(系統の切り替え判定に行を使うため。読み手は問い合わせクラス経由で申請中・承認済みだけを扱う)。**リリース手順・リハーサルの確認事項**:
  (d)新設Projector(対応表・休暇ビュー)は自動検出で有効になるため、リリース時に対象テーブルを空にして`event-sourcing:replay`で過去分を
  反映する、(e)cutover後の`paid_leave_account.usage_designated`で`paidLeaveRequestId`がnullのものの件数(休暇ビューは無視する)。
- 2026-10-10 実装中の決定(P1 有給の口座): 集約が申請ID→usageIdを保持し(既存イベントの`paidLeaveRequestId`から構築)、確定・取消は申請IDでも
  指定可能。Reactor経由(`viaReactor`)の二重指定・二重確定・二重取消は何もしない。確定時の残数不足は例外(論点17。誤差1e-9は許容)。
  テストで付与をイベントなしで直接INSERTしていたものは、集約が付与を知らず承認できないため、付与コマンドによる作成に置き換えた
  (本番の付与はcutover時の`paid_leave_account.migrated`イベントで集約に登録済みのため影響なし)。
- 2026-10-10 実装中の決定(P1のCI対応): 論点17の残数不足の拒否は承認経路(`PaidLeaveAccountAggregate::approveUsage`、
  `ConfirmPaidLeaveUsageHandler`)にだけ適用し、過去の消化記録の再生・移行(`confirmUsage`)は従来どおり部分充当を許す
  (過去の事実の引き継ぎであり新たな承認ではないため)。

## 実装結果
未着手
