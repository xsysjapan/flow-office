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
  同日の休暇の衝突等)は、そのルールを持つ文脈が自分のCommand内で例外を投げて表す。
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
  計算への入力変換、まとめ申請の兄弟承認の対象判定、再提出の可否、`stored_events`の作り直しの変換規則(論点18)、午前・午後の休暇の組合せ)は、
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
  - 入力: 休暇申請文脈のイベント(有給`paid_leave_request.*`(新設、論点4)、特別`special_leave.request_*`、
    代休`compensatory_leave.request_*`の申請・承認・差戻し・取消)。各行は休暇の種類・取得単位・時間数・
    申請ID・申請状態(申請中/承認済み)を持つ。
  - 過去分: 本変更前の休暇(cutover前の有給を含む)は、論点18の`stored_events`の作り直しで最初から
    `paid_leave_request.*`・`special_leave.request_*`・`compensatory_leave.request_*`として記録し直すため、入力は
    上記の休暇申請文脈のイベントだけで足りる(旧`paid_leave.*`・`paid_leave_account.*`を入力にしない)。
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
- 境界条件: 本変更前の申請(旧`paid_leave.*`、cutover後の`paid_leave_account.*`+`workflow_request.*`)は、論点18の
  `stored_events`の作り直しで、元の発生順のまま`paid_leave_request.*`(申請集約)と`paid_leave_account.*`(口座集約の
  消化記録)に変換して記録し直す。各Projector(申請テーブル・勤怠の休暇ビュー・出勤率ビュー)は`paid_leave_request.*`
  だけで状態を作り、旧系統との切り替え(系統の判定・`input_source`)は持たない。新イベント名は旧`paid_leave.*`
  (作り直しで無くなる)と別にする。
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
  - 既存の付与集約・消化記録: 論点18の`stored_events`の作り直しで、旧付与集約・旧申請集約のイベント(`*.granted`・
    `*.usage_designated`・`*.used`・`*.usage_reversed`等)を、元の発生順のまま口座集約のイベントに変換して記録し直す
    (`assets/event-rebuild-mapping.md` 2.1・2.2)。移行イベント(`*_account.migrated`)は作らず、旧イベントクラス・旧集約・
    旧Projectorの旧イベント処理は作り直しの後に削除する。
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
- 決定(2026-10-10 論点18により改訂): 補正は論点18の`stored_events`の作り直しの中で、変換規則として行う。補正イベント・
  補正専用のコマンドは作らない。対象・件数は作り直しの試し実行(本番相当データでのリハーサル)で確認し、本番での実行は
  変換規則と試し実行の結果を示してユーザーの明示的な許可を得てから行う(ユーザー指示)。
  - 対象の候補と直し方(`assets/event-rebuild-mapping.md` 2.1・2.4・3章):
    (1)差し戻された休暇の未取消の消化記録 → 差戻しの変換規則で`usage_cancelled`を差戻しの位置に置く。
    (2)休暇の処理が直接作った勤怠日(`attendance_day.created`が無い) → その勤怠日の最初のイベントの直前に`created`を置く
    (内容はReadModelの現在値)。過去への挿入も、id・版を元の発生順で振り直すため再生順の問題が無い。
    (3)編集イベントに入った休暇値 → `workType`を作業内容の値(休暇値の前の値、無ければnull)に書き換える。
    (4)勤怠日に残る休暇値・全休の`status` → (2)(3)の規則で置く`created`の値を直す。
  - 日次計算・手動調整・週40時間配賦は作り直しの中では変換せず、作り直しの後に`attendance:recalculate-days`で再計算する
    (締め・提出済みの月も対象。提出時のスナップショットとの差は一覧で報告する。論点18のユーザー決定)。
  - 消化記録→勤怠日・消化記録→申請テーブルの外部キーを撤去する(論点6)。作り直しの後は全ReadModelを空にして全Projectorを
    リビルドする(空にするテーブルの一覧は手順書に載せる)。
  - 作り直しのコマンドは既定で試し実行、`--apply`で別表`stored_events_rebuilt`に書き、複製DBで検証してから`--swap`で入れ替える
    (元の表はバックアップとして残す)。
- 未確定・要確認事項: なし(件数・判定結果はリハーサルで確認。論点18)

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
  - D. 論点18の`stored_events`の作り直しで、旧`paid_leave.*`を`paid_leave_request.*`(申請集約)と`paid_leave_account.*`
    (口座集約の付与・消化記録)に変換して記録し直す
- 決定: D(2026-10-10 論点18で置き換え。当初はAと決定し、旧イベントを休暇ビュー・有給申請Projectorの入力にしていた)。
  旧イベントの集約ID=申請IDを有給申請集約のIDに引き継ぐ。cutover時の付与は付与IDと残数で旧付与と突き合わせ
  (`assets/event-rebuild-mapping.md` 2.3)、旧イベントで再現できれば消化記録まで再現する。その場合8件は通常の承認済み申請と
  同じく取消できる(2026-10-10 ユーザー決定)。
- 理由: 旧イベントが唯一の記録で二重にならない(Aと同じ)。加えて、Projectorが旧`paid_leave.*`を読む必要が無くなり、
  旧イベントクラスと系統の切り替えをコードから除ける。
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
- 決定(2026-10-10 改訂): **残数が不足していても承認は拒否しない**。3種とも、充当できる分だけ付与へ充当し、残りは未充当の
  まま確定する(有給は現行どおり。特別・代休は現行の「承認済み・未充当」と同じ扱いで、口座集約が不足量を記録する)。
  承認画面で残数不足を事前に知らせる表示(ワンクッション)は任意で、拒否はしない。
- 理由: ユーザー決定。既存の設計方針(`ApprovePaidLeaveRequestHandler`のdocコメント等)どおり、承認行為は残数不足でも許容する。
- 未確定・要確認事項: なし

### 論点18: 既存データの引き継ぎ(イベント構成の作り直し)
- 前提(2026-10-10 調査、`assets/event-rebuild-mapping.md`): 旧イベントは付与ごと・申請ごとの旧集約に記録されており、
  新しい口座集約・有給申請集約はそれを知らない。旧イベントと新イベントの対応には、1対1(改名・集約IDの付け替え)のほか、
  1件を2件に分けるもの(cutover後の有給の口座イベントは申請の状態と消化記録の両方を表す)、複数件を1件にまとめるもの
  (付与ごとの`*.used`→申請ごとの`usage_confirmed`)がある。
- 選択肢:
  - A. 移行イベント(`*_account.migrated`・`paid_leave_request.migrated`)を今の時点に追記し、Projectorは旧系統も読む(実装済みの方式)
  - B. `stored_events`を新しいイベント構成で作り直す(旧イベント列から新イベント列を決定的に生成し、元の発生順にid・版を
    振り直して入れ替える)。移行イベントも旧系統の読み替えも持たない
- 決定: B(2026-10-10 ユーザー決定「できる限りシンプルなイベント構成にしたい。過去のイベントを書き換える方法で」「データ移行では
  stored_eventsを完全に作り変える」)。cutover時の`paid_leave_account.migrated`も対象とし、旧イベントで再現できない付与
  (システム外から持ち込んだ残高)だけを通常の付与イベント(source=`carried_over`)で表す。変換規則・削除するもの・実行手順は
  `assets/event-rebuild-mapping.md`。論点4・13の「3系統の入力」と仕様確定事項Iの「本変更前に申請された申請の引き継ぎ」
  (移行イベント)はこれで置き換える。
- 理由: 新しい集約が最初から全履歴を持ち、コードから移行イベント・旧系統の読み替え・移行コマンドを除ける。挿入・分割・統合も、
  idを元の発生順で振り直すため再生順の問題が無い(`data-correction`スキル改訂)。
- 本番での実行: 変換規則と試し実行の結果を示したうえで、ユーザーの明示的な許可を得てから行う。
- 独立設計レビュー(2026-10-10)の反映: 変換の単位を申請・付与・勤怠日ごとの状態機械に改め(承認は`*.used`の有無にかかわらず
  消化を確定、取消・差戻し・却下は`*.usage_reversed`の有無にかかわらず消化を取消、cutover後の差戻し・再提出はワークフローの
  イベントから組み立てる)、cutoverの付与は付与IDと残数で突き合わせ、勤怠の直接書き込みの事実はReadModelの現在値を入力とし、
  日次計算は作り直しの後に再計算し、id振り直しの影響・実行手順(書き込み停止・複製DBでの検証・原子的な入れ替え・ロールバック)を
  定めた(`assets/event-rebuild-mapping.md`)。
- ユーザー決定(2026-10-10): cutover前の有給8件は消化記録まで再現できた場合、通常の承認済み申請と同じく取消できる。休暇値の
  削除で日次計算を再計算する日は、締め・提出済みの月も再計算する(提出時のスナップショットとの差を一覧で報告)。
- 未確定・要確認事項(リハーサルで確認してから進む): 試し実行で扱えない並びの有無、cutoverの付与の不一致の有無と`carried_over`の
  内容、差戻し/取消/却下の判定結果、複製DBでの比較の差分、再計算で値が変わる日。

### 論点19: イベントを経ずに作られたデータと、現行のクラスで復元できない旧イベント(リハーサル2回目で判明)

**背景**: 全ReadModelを空にして全Projectorを再生すると、(1)シーダー・マイグレーションがイベントを経ずに作った行が再現されない、
(2)PR #109の版の`paid_leave_schedule.*`(payloadに`userId`・`entryId`、集約UUIDは`uuid5(NS, userId)`)が現行のクラスへ復元できない。
いずれも休暇の変更より前からの欠落だが、作り直し後に全ReadModelを再生成できることを今回のリリースの条件とする(2026-10-14 ユーザー決定)。

**決定**:

1. **照合による補完(共通規則)**: 下表のテーブルについて、`stored_events`を再生した結果と現在の行を、行のキーごとに突き合わせる。
   再生結果が現在の行と一致しない部分だけ、差を埋めるイベント(導出イベント)を作り直しの中で置く。
   - 置く位置: そのキーのイベントが1件も無い(行がイベントを経ずに作られた)ときは、その集約の最初のイベントの直前。集約にイベントが
     無ければ、外部キーの依存順(下表の「順」)に並べて、依存先の作成イベントの直後(依存先も無ければ列の先頭)。
     そのキーにイベントがあるのに最終状態が違う(シーダーの再実行で上書きされた等)ときは、その集約の最後のイベントの直後。
   - 発生日時(`created_at`): 作成イベント(`*.created`・`user.migrated_from_legacy`等、行を作るもの)は、置く位置に関わらず行の
     `created_at`(無ければ置き場所の前のイベントの値)。それ以外は置き場所の前のイベントの値(2026-10-14 実装中の決定: 再生後の
     `created_at`を元の行と一致させるため)。
   - 操作者: 行に作成者・割当者の値があればそれ、無ければ`null`(2026-10-14 ユーザー決定。下記2で`actorUserId`をnull可にする)。
   - 試し実行で、テーブルごとに導出イベントの件数と「イベントがあるのに最終状態が違った」キーの一覧を出す。
   - 実装中の決定(WP12(B)): (a)処理順は外部キーに合わせ、`users`を`role_assignments`・`memberships`・暦の年より前にする。
     (b)`permission_role`・`role_features`・`company_calendar_days`は、キーのイベントが無いとき「親(ロール・年)の作成イベントの直後」に置く
     (「集約の最初のイベントの直前」では親の作成より前になり再生できないため)。(c)「最初のイベントの直前」に置くものでも、依存先の
     作成イベントが後ろにあれば、その直後へ置き直す。(d)集約UUIDが現在の年の`id`と一致しない`days_updated`のうち、作成イベントのある
     年(削除済みを含む)の集約のものは付け替えの対象にしない。(e)作成イベントがあるのに`isSystem`・ID・年度等が行と違うもの、操作者が
     必須の削除・更新でしか埋まらない差は、扱えない並びとして止める。

| 順 | テーブル | 導出するイベント | 集約UUID |
|---|---|---|---|
| 1 | `group_types` | `group_type.created`(差は`group_type.updated`) | `uuid5(URL, 'group-type:'.code)` |
| 2 | `groups` | `group.created`(差は`group.updated`) | 行の`id` |
| 3 | `roles` | `role.created`(差は`role.updated`) | `uuid5(URL, 'role:'.code)` |
| 4 | `permission_role` | `role.permissions_changed`(ロールごとの全置換) | 同上 |
| 4 | `role_features` | `role.features_changed`(全置換) | 同上 |
| 4 | `role_assignments` | `role_assignment.created` | 行の`id` |
| 5 | `group_feature_assignments` | `feature.assigned_to_group`(余分は`feature.removed_from_group`) | `uuid5(URL, 'group-access:'.groupId)` |
| 6 | `users` | `user.migrated_from_legacy`(既存の合成イベント。作成イベントが無い利用者のみ) | 行の`id` |
| 7 | `memberships` | `membership.added` | `UserManagementStreamId::for('user-membership', userId)` |
| 7 | `external_identities` | `external_identity.linked` | `UserManagementStreamId::for('user-identity', userId)` |
| 1 | `field_authorities` | `user.field_authority_changed` | `uuid5(URL, 'field-authority-catalog')` |
| 2 | `company_calendar_years` | `company_calendar_year.created`、状態に応じて`.published`・`.archived` | 行の`id` |
| 3 | `company_calendar_days` | `company_calendar_year.days_updated`(差のある日だけ) | 親の年の`id` |

   - `company_calendar_days`: `days_updated`の集約UUIDが現在の年の`id`と一致しないもの(マイグレーション`2026_08_10_020000`で年を
     新設したときの付け替え漏れ)は、試し実行で一覧にする。旧集約UUIDが`company_calendars.id`で、日付がその暦の年の範囲に入るときは、
     その年の`id`へ付け替える(集約UUID・`meta_data`の両方。版は振り直す)。それ以外は扱えない並びとして止める。
   - `features`・`permissions`・`permission_scope_types`はProjectorの書き込み先ではない参照マスタ(`SyncAccessControlCatalog`が直接
     書く。`system_settings`と同じ扱い)。空にせず、補完もしない。
2. **イベントの変更**(項目の追加・null可にする変更のみ。既存の記録はそのまま復元できる):
   - `RoleCreated`・`GroupTypeCreated`に`isSystem`(bool、既定false)を足し、Projectorは固定値ではなくその値を書く。
   - 次のイベントの`actorUserId`(`company_calendar_year.created`は`createdByUserId`で既にnull可)をnull可にし、Projectorはnullをそのまま
     書く: `group.created`・`group.updated`・`group_type.created`・`group_type.updated`・`role.created`・`role.updated`・
     `role.permissions_changed`・`role.features_changed`・`role_assignment.created`・`feature.assigned_to_group`・
     `feature.removed_from_group`・`membership.added`・`external_identity.linked`・`user.field_authority_changed`。
   - `CompanyCalendarYearProjector`: `published_at`と`created_at`・`updated_at`を`now()`ではなくイベントの発生日時にする(再生で
     同じ結果にする。設計原則2)。`company_calendar_days`の`created_at`・`updated_at`も同様。
3. **旧付与予定イベントの置き換え**(2026-10-14 ユーザー決定): payloadに`entryId`を持ち`scheduleEntryId`を持たない
   `paid_leave_schedule.*`を旧版のイベントとする。旧版のイベントは削除し、旧版のイベントに現れた`entryId`ごとに、現在の
   `paid_leave_schedule_entries`・`paid_leave_schedule_assessments`の行から現行の形のイベント列を導出する。集約UUIDは利用者の`userId`
   (現行の規則)。置く位置は、その利用者の旧版のイベントの最初の位置。発生日時は、作成はその行の`created_at`、それ以外は対応する旧版の
   イベントの発生日時(無ければ手動修正は行の`manual_override_at`、取消は行の`updated_at`、それも無ければ作成と同じ)。
   - `entry_created`(scheduleEntryId=行の`id`、scheduledOn・category・candidateGrantDaysは行の値、`isDeterminate`は行の状態が
     判定保留でなければtrue)。
   - 判定の行ごとに`assessment_recorded`(assessmentId=判定の行の`id`)。最終判定が自動判定と違えば`assessment_overridden`。
   - 手動修正済み(`is_manually_overridden`)なら`entry_manually_edited`(changesは現在のcategory・candidateGrantDays、reason・
     operatorUserIdは`manual_override_*`の値)。
   - `grant_id`があれば`entry_granted`。取消済みなら`entry_cancelled`(reasonは`cancelled_reason`、無ければ「既存データの引き継ぎ」)。
   - 再生後の行が現在の行と一致しないものは、試し実行で一覧にして止める。
4. **シーダー**(2026-10-14 ユーザー決定): `UserManagementSeeder`・`AccessControlSeeder`・`RoleSeeder`はCommandを発行して
   イベントを記録する形に書き換え、既にある行は飛ばす(冪等)。足りないCommandの引数(`CreateRole`の`roleId`・`isSystem`、
   `CreateGroupType`の`groupTypeId`・`isSystem`等)を足し、シーダーからの発行は操作者null。`docs/32`の「作り直しの前にシーダーを
   実行する」手順を見直す(作り直しの後に実行し、イベントを記録させる)。
   実装中の決定: 既にある行は上書きしない(従来のシーダーは毎回上書き・権限は全削除→再挿入していたため、管理者が画面で変えた設定を
   戻していた)。外部ID(Microsoft Entra)はテナント未設定のときは作らない(従来はテナントnullで挿入)。開発・E2E専用の
   `ScenarioAccessSeeder`の直書きは対象外(本番では実行しない)。権限・機能割当は「そのロール/グループの行が無いとき」だけ
   初期値を設定する(イベントの有無は見ない)。管理者が全て外して0件にしたロール・グループは、シーダーの再実行で初期値に戻る(一部を変えた
   設定は戻らない)。
7. **認証情報の分離**(2026-10-14 ユーザー決定): ローカルログインのパスワードのハッシュと`remember_token`は、イベントを経ずに`users`へ
   直接書かれており、全ReadModelの再生で消える。これらを`users`から専用の表(`user_credentials`。利用者IDを主キー)へ移し、
   `system_settings`と同じく「イベントではなく直接更新する正データ」として扱う(ReadModelの再生で空にしない。`--list-readmodels`に
   出さない)。秘密情報を`stored_events`へは入れない。ログイン・オンボーディング・パスワード変更の読み書き先を新しい表へ変え、
   マイグレーションで既存の値を移してから`users`の列を削除する。
8. **リハーサル3回目の差への対処**(2026-10-14 実装中の決定。リハーサル結果 3回目の1〜5):
   1. 年度の訂正: `CorrectCompanyCalendarYearFiscalYearHandler`がイベントを書かずに暦の日を直接削除していた(設計原則2違反)。
      直接削除をやめ、`CompanyCalendarYearProjector`の`fiscal_year_corrected`でその年の日(と日の出典`company_calendar_day_sources`)を
      削除する(訂正後の日は従来どおり後続の`days_updated`で作られる)。
   2. 祝日の同期: `holiday_calendar_source.synced`の`dayChanges`が日を自動採番の`company_calendar_day_id`で指しており、再生で
      採番が変わると別の日を指す・見つからない。作り直しで、作り直し前の`company_calendar_days`の`id`→(年の`id`・日付)の対応から
      各`dayChange`に`companyCalendarYearId`と`date`を補う(対応が無い=既に無い日はそのまま。Projectorが飛ばす)。Projectorは
      `companyCalendarYearId`・`date`があればそれで日を引く(無ければ従来のid)。今後の同期もこの2項目を記録する。同期を戻す
      イベント(`sync_reverted`)も同様。
   3. `company_calendars.is_default`: マイグレーション`2026_08_10_020900`がイベントを経ずに設定していた。照合による補完の対象に
      `company_calendars`の`is_default`を加え、差があれば`company_calendar.default_changed`を導出する(操作者`changedByUserId`は
      null可にしnull。決定2と同じ方針)。置く位置は、既定の暦の集約の最後のイベントの直後と、他の暦の最後の
      `default_changed`の直後のうち後ろ(後続の他の暦の切り替えに上書きされないため。実装中の決定)。
   4. 現在時刻で書くProjector: `HolidayCalendarSourceProjector`(`synced_at`・`applied_at`・`last_synced_at`・`disabled_at`)と
      `CalendarBulkOperationProjector`(`applied_at`・`reverted_at`)を、イベントの発生日時で書く。
   5. イベントの発生日時: 作り直しは`meta_data`の`created-at`を`stored_events.created_at`列の値にそろえていたが、Projectorが
      元々読んでいたのは`meta_data`の値(記録時刻)で、列(保存時刻)とは最大1秒程度ずれる。作り直しでは、そのまま写すイベントは
      元の`meta_data`の`created-at`を、導出イベントは置き場所(anchor)の`meta_data`の`created-at`を発生日時とし、列も同じ値に
      そろえる。ただし列と`meta_data`が2秒以上ずれている行は、旧版の正規化(`StoredEventHistoryNormalizer`)が列を意図して
      直したものとして列の値を使う。
   6. `paid_leave_balances`の行の増加(1→3行)と`unallocated_days`の差は、意図した差として扱う見込み(旧系統のイベントでは行が
      作られず、作り直しで全付与保有者の口座イベントが揃うため。未充当は論点17の部分充当)。次のリハーサルで、増えた行が付与の
      ある利用者であること、`unallocated_days`の差が確認事項(a)の不足量と一致することを確かめる。
9. **リハーサル4回目の差への対処**(2026-10-14):
   1. `attendance_days.locked_at`(ユーザー決定): 列を廃止する。月次の締め(`CloseAttendanceMonthHandler`)がイベントを書かずに
      直接UPDATEしており(設計原則2違反)、編集可否は月次の状態と`attendance_locks`で判定済み。書き込み・`AttendanceDay::isLocked`・
      `AttendanceEditGuard`の判定(1)を削除し、マイグレーションで列を削除する。確定取消(`confirmation_reverted`)の後は日次の
      編集→再提出ができるようになる(Projectorの説明どおり)。締め日時は`attendance_months.closed_at`。
   2. 実績の無い`clocked_out`の日(実装中の決定): 旧コードが全休の申請時に勤怠日の状態を直接`clocked_out`にし、その後の日次編集が
      ReadModelの状態を`attendance_day.edited`のpayloadへ写していた。2.4に規則を足す: その日に全休の申請があり、`edited`の
      `status`が`clocked_out`で`actualStartAt`・`actualEndAt`がともにnullなら、`status`を直前の状態(同じ日の直前のイベントから
      求めた値。無ければ`not_started`)に書き換える。試し実行で対象の一覧を出す。全休の申請が無い日は書き換えない。全休の申請は最終状態(取消・差戻し)を問わない(旧コードは申請時に
      直接書き換えていたため)。
   3. cutover前の有給8件の充当: 旧システムの承認は、対象日に有効で残数のある付与が無いと充当(`paid_leave.used`)を書かなかった。
      8件には`paid_leave.used`が無く、作り直しは2.1どおり未充当として再現している(論点17の部分充当と同じ扱い)。8件の承認時に
      有効で残数のある付与が無かったことをリハーサルのデータで確かめ、無ければこのまま(手順書4(f)・6-3(6)・B09の期待を
      「消化記録のみ・充当0・未充当」に改める)。あれば旧システムの不具合としてユーザーに判断を仰ぐ。
   2a. (2026-10-14 実装中の決定、9-2を改める。リハーサル5回目) 9-2の「直前の状態」では、先に実績を入れて`clocked_out`になった後で
      実績を消した編集(2026-09-08)が`clocked_out`のまま残る。実績(開始・終了)が両方無い状態は`not_started`であるため、全休の日の
      `edited`で`status=clocked_out`かつ実績が両方nullなら`not_started`に書き換える。レポートは実際に値が変わったものだけ数える。
      あわせて`EditAttendanceDayHandler`を直し、実績を両方消す編集では`status`を`not_started`にする(従来は日の現在の状態を写して
      いた)。
   3a. (2026-10-14 ユーザー決定、9-3を改める) cutoverで移した残数(付与2件・計38日)は、cutover前に承認された8件・計7.5日を
      差し引いていない。作り直しで、この8件の消化記録を cutover の付与(`carried_over`の`grant_created`)に充当する
      (`paid_leave_account.usage_allocated`を導出)。充当の順序は現行の口座集約の充当の規則(承認時の充当と同じ順序)に従い、対象日に
      有効な付与から充当する。対象は、消化記録の対象日(`usage_designated`の日付)がcutoverの日付より前で、充当が無く確定済み・
      未取消のもの(確定の日付では選ばない: cutoverの経路の8件は確定がcutover以降に記録されているため。リハーサル5回目)。充当先は、その利用者の全ての付与
      (cutoverの`carried_over`に限らない。リハーサル6回目: 8件の利用者の付与は承認時点より後に付与されたもので、cutoverの付与だけを
      候補にしていたため充当されなかった)。現行の充当規則(`AllocationPlanner`。対象日に有効な付与が無ければ直近の将来の付与)に従う。
      cutoverの日付は、その利用者に`paid_leave_account.migrated`があればその日付、無ければ全利用者のcutoverの日付の最小値。残りの無い付与
      (cutoverの残数0の付与・使い切った付与)は候補から外す(現行の充当規則は直近の将来の付与1件だけを見るため、残り0の付与が直近に
      あると先の付与へ進まない。作り直しでの充当に限った扱いで、承認時の規則は変えない。実装中の決定)。置く位置は、その付与の`grant_created`と消化の`usage_confirmed`のうち後ろの直後。足りない分は未充当
      (論点17)。残数は38日から30.5日になる。手順書4(f)・6-3(6)・B09の期待(充当の再現)はこの規則で満たす。
   4. 日時の9時間の差: `stored_events.created_at`とReadModelの日時列はMySQLの`TIMESTAMP`で、セッションのタイムゾーンで変換される。
      本番とリハーサルのセッションのタイムゾーンが違うと読み書きで9時間ずれる(`meta_data`の文字列はずれない)。決定8-5の規則を
      改め、列と`meta_data`の差が1時間の整数倍(±2秒)ならタイムゾーンのずれとして`meta_data`を使う。リハーサルのMySQLは本番と
      同じタイムゾーンで動かす(手順書1章)。本番とリハーサルの`@@time_zone`をリハーサルで確かめる。
10. **リハーサル7回目の差への対処**(2026-10-14):
   1. 未充当の有給の充当(ユーザー決定。9-3aを置き換える): 先に使用(承認・確定)してから後で付与された場合は、**付与されたときに充当する**
      のが正しい。現行の承認時の充当(論点17。足りない分は未充当)に加え、`paid_leave_account.grant_created`の記録時に、その利用者の
      確定済み・未取消で未充当の残りがある消化記録へ、確定の古い順に、その付与の残りの範囲で充当する(Reactor。冪等。`usage_allocated`
      を記録)。作り直しは同じ規則を過去に当てはめ、各`grant_created`の直後に`usage_allocated`を導出する(cutoverの日付による対象の
      絞り込みはやめ、未充当はすべて対象。ユーザー決定)。付与の無い利用者の未充当はそのまま(付与されたときに充当される)。
   2. 再生で毎回変わるid: `holiday_calendar_events`・`company_calendar_day_sources`の`id`をProjectorが`Str::uuid()`で採番している。
      決定的なid(`uuid5`。祝日の行は取込元+`ics_uid`、日の出典は年+日付+出典の種別+`source_ref`+イベントのid)にする。
   3. `users`: 旧版の正規化(`StoredEventHistoryNormalizer`)が旧来の作成イベントをSSO等のイベントに置き換えた際、`usage_start_date`等の
      属性が落ちた。照合による補完(決定1)の`users`に、属性を設定するイベントがある項目(`usage_start_date`・`hire_date`・退職日・
      タイムゾーン・自動付与の有無等)を加え、現在の行と違えば最後のイベントの直後にそのイベントを導出する。`source_type`は設定する
      イベントが無く、イベントどおり`microsoft_entra`になる(実際にSSOで使っている利用者であり、意図した差とする)。
   4. 意図した差・リハーサルの準備による差として扱うもの: `attendance_days`(休暇値・source・全休の状態・`locked_at`の廃止)、消化記録の
      表(確定・`usage_id`・勤怠日との紐付けの廃止)、新設14表、旧版の正規化が直接書いた行の日時(`attendance_months`・
      `attendance_locks`・`entity_shares`・`workflow_request_history_entries`。再生はイベントの日時に戻す)、リハーサルで空にした認証系の
      表(`authentication_keys`・`device_admin_sessions`と、その外部キーで`attendance_punches.authentication_key_id`がNULLになった行)、
      端末のハートビートの値(`devices.app_version`・`last_seen_at`。イベントを経ない運用の値で、次のハートビートで戻る)。
   1a. (2026-10-14 実装中の決定、10-1を補う) 旧ストアには確定時の充当も記録されていない。作り直しは現行の充当の規則をすべて過去に
      当てはめる: (i) 確定時: 入力に既存の`usage_allocated`が無い消化記録は、`usage_confirmed`の時点の付与の状態で`AllocationPlanner`を
      当てはめ、確定の直後に導出する。(ii) 付与時: 10-1のとおり。(iii) 取消時: 現行の`cancelUsage`と同じく、残っている充当の解除を
      導出し、空いた付与へ他の未充当を再充当する(既存の解除がある分は導出しない)。付与の状態には日付・期限・日数の変更・取消を
      その時点まで反映する。試し実行のレポートは充当ごとに規則(confirm/grant/cancel)を出す。
   5. `membership_change_sets.effective_at`・`notifications.queued_at`が再生時刻になる(リハーサル7回目): どちらも表で最初の
      `TIMESTAMP`列でNOT NULL・既定値なし。MySQLの`explicit_defaults_for_timestamp=OFF`では`DEFAULT CURRENT_TIMESTAMP ON UPDATE
      CURRENT_TIMESTAMP`が自動で付き、その列を含まないUPDATE(適用・取消・失敗、送信・確認の投影)で更新時刻に上書きされる。payloadの
      値(`effectiveAt`・`queuedAt`)が正しい。ReadModelの同じ形の列(表で最初の`TIMESTAMP`でNOT NULL・既定値の指定が無いもの)を
      `DATETIME`に変えるマイグレーションを足す(ReadModelなので再生で正しい値に戻る)。本番の元の値が上書きされていた分は、意図した差
      (不具合の修正)とする。本番とリハーサルの`@@explicit_defaults_for_timestamp`と該当表の`SHOW CREATE TABLE`を確かめる。
      (確認結果)リハーサルのDB(MySQL 8.0.46、`@@explicit_defaults_for_timestamp=1`)でも両表に自動更新句が付いていた。設定がONなら
      作成時に付かないため、句はdumpの`CREATE TABLE`(本番で作成された定義)から来たと判断する。本番でも元の値は適用・送信・確認の
      時刻に上書きされている見込みで、再生後のpayloadの値との差は意図した差とする。本番の`SHOW CREATE TABLE`は本番の試し実行の前に
      サーバー上で確かめる(手順書)。
   6. `attendance_punches.corrected_at`(リハーサル7回目): baselineでNULLの2行は、訂正済み(`attendance_punch.corrected`が2026-07-22
      04:01:08にある)と削除済み(訂正イベントは無い)。現行のProjectorは訂正・削除の投影で`corrected_at`にイベントの発生日時を入れる。
      2026-07-22時点の旧実装(gitの履歴より前)は入れていなかったとみて、再生後の値(イベントの発生日時)を正とし、意図した差とする。
   7. 変換診断の「最終状態が違ったキー」66件(リハーサル8回目):
      - 暦の日61件(年8ff53eaaの2026-04の30日と2027-05の31日): 年度の訂正(`fiscal_year_corrected`)で期間外になった日。Projectorは
        訂正の投影でその年の日を全件消し(決定8-1。ハンドラが続けて期間内の日を`days_updated`で入れ直す)、照合(`CalendarDayReconciler`)の
        畳み込みは訂正を見ていないため、誤って差として出ていた。照合もProjectorと同じく、イベントの順に、訂正でその年の日を全件落とす。
      - 残り5件(`users.usage_start_date`1・年の作成イベント1・所属2・暦の既定1)は、同じ実行で導出イベント(`usage_start_date_set`・
        `company_calendar_year.created`・`membership.added`・`default_changed`)により行の値へ揃えた差。報告を「導出で解消した差」
        (導出したevent_classつき)と「解消していない差」に分けて出し、後者が0件であることを確かめられるようにする。
      - 導出で解消した差のうち`usage_start_date`と所属は、行(本番の現在値)とイベントのどちらが正しいかをリハーサルの照合で確かめる
        (行を正とする規則は決定1・10-3)。
      - (2026-10-14 リハーサル8回目の照合結果)暦の日61件は、source・verifyの両DBで年度が2026-05-01〜2027-04-30、期間外の日が0件、
        日数365・日付の集合が一致し、照合の誤検出と確定。`usage_start_date`(No.1)は行に値(2026-08-04)があり旧イベントに設定の
        イベントが無い型で、導出(ID 2247)で揃えたのは正しい。
      - 所属2件(No.3・4): kind・isPrimaryはイベントと一致し、違うのは`created_by`(行は所属する利用者本人、イベントの`actorUserId`は
        操作した管理者)と`created_at`だけ。行は旧版の正規化(`StoredEventHistoryNormalizer::synchronizeMembershipProjection`)が
        `created_by`=本人・`created_at`=登録日時で直接書いたもので、イベントを経ない書き込み(決定10-4の「旧版の正規化が直接書いた行」と
        同じ型)。**イベントを正とし、導出しない**。照合は、イベントのある所属では`created_by`の違いだけでは差にしない(在籍・kind・
        isPrimaryが違うときだけ導出する)。`created_by`は作成イベントが無い所属を導出するときにだけ行から使う。再生後の`created_by`・
        `created_at`がイベントの値になる差は意図した差とする。
   8. (2026-10-14 リハーサル9回目、決定8-5・9-4を置き換える) イベントの発生日時は常に`meta_data`の`created-at`とする。Spatieでは
      イベントの時刻は`ShouldBeStored::createdAt()`(=`meta_data`の`created-at`。記録時に付く)で、Projectorもこれを読む。
      `stored_events.created_at`列は保存時刻(WHO列に近い)で、旧版の正規化・保守のコマンドが後から書き換えた行がある(13クラス計308件。
      列が後の時刻)。作り直しは`meta_data`の`created-at`を発生日時とし、列も同じ値にそろえる。`meta_data`に無い・解釈できない行だけ
      列の値を使う。導出イベントは置き場所の値、明示指定は従来どおり。ユーザー了承(ライブラリの標準どおり)。
   9. (2026-10-14 リハーサル9回目) `external_identities.external_tenant_id`: Projector(`UserProjector::linkEntraIdentity`)が再生時の
      `system_settings.m365_tenant_id`を読むため、イベントだけで再現できない。Entraの外部IDを作るイベント(SSOログインでの作成・
      SSOアカウントの連携等)に`externalTenantId`(null可)を持たせ、ハンドラは記録時の設定値を入れる。Projectorはイベントの値を使い、
      値が無い旧イベントだけ従来どおり設定値を読む。作り直しは、旧イベントに現在の`external_identities`の行(provider+subject)の
      tenant IDを補う。行の`external_tenant_id`を書くのはリンクの投影(その時点の設定値、またはイベントの値)だけで後から変える経路は
      無いため、行の値がリンク時点の値である。補う前提として、sourceのtenant IDがnull以外の1種類であることを試し実行で検査し、
      違えば止めて報告する。設定値がnullでなければ一致も検査する(リハーサルのsourceは設定がNULLだったため、nullなら検査しない)。
      ユーザー同意。
      `linked_at`の差(source 2026-08-10 14:38:12、イベントのmeta 2026-08-04 08:43:09): 旧版の正規化(2026-08-10)がSSOの作成イベントを
      作り直した際に、行を正規化の実行時刻で書いた。決定10-8でmeta(元の作成時刻)が使われ、再生後は2026-08-04になる。意図した差。
   10. 勤怠日の2日(打刻のある全休の日)の日次計算は`work_minutes`=0・`payroll_work_minutes`=0で、全休の日の勤務時間0を満たす。
5. **復元可否の検査**: 作り直し後の全イベントが現行のクラスへ復元できること(実装済み、f6fdf27)。
6. **ReadModelの照合**: 作り直し前のDBと、作り直し後に全ReadModelを空にして再生したDBとで、Projectorの書き込み先の全テーブルの
   件数と行の内容(`created_at`・`updated_at`・`stored_events.id`を指す列を除く)のハッシュを比べる出力を用意する
   (`leave:rebuild-event-store --readmodel-fingerprint`)。差は、休暇の変更で意図した差(論点18・6-2)だけであること。

**対象外**: `PaidLeaveSchedule`と`PaidLeaveAccount`が同じ集約UUID(`userId`)を共有している現行の問題(PR #112で`uuid5`の
分離が戻った)。作り直しは現行の規則のまま版を振り直す。別の変更セットで扱う。

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
  Projectorだけが更新する。`paid_leave_requests`のProjectorの入力は`paid_leave_request.*`だけ(本変更前の申請も論点18の
  作り直しでこのイベントになる。論点4の境界条件・論点13)。系統の切り替え(`input_source`列)は持たない。

### D. 残数・使用文脈(`App\Domain\PaidLeaveAccount`・新設の特別休暇/代休の口座)
- 有給: `PaidLeaveAccountAggregate`を維持。Reactor:
  - `paid_leave_request.requested`/`.resubmitted` → `DesignatePaidLeaveUsage`
  - `paid_leave_request.approved` → `ConfirmPaidLeaveUsage`(残数不足でも確定し、充当できた分だけ充当。論点17)
  - `paid_leave_request.returned`/`.cancelled` → `CancelPaidLeaveUsage`(承認済みは充当解除)
  - 対応する消化記録は申請IDで特定する(口座集約内で申請ID→usageIdを保持)。
  - `PaidLeaveUsageAllocationProjector`から`paid_leave_requests`の更新処理を削除する。
- 特別休暇・代休: 利用者単位の口座集約`SpecialLeaveAccountAggregate`・`CompensatoryLeaveAccountAggregate`を新設
  (集約IDは種類ごとの派生ID。`UserManagementStreamId`と同じ方式)。付与の残数と消化記録(作成・確定(付与への充当)・
  取消)を持つ。イベント: `special_leave_account.*`・`compensatory_leave_account.*`(付与・付与取消・消化の作成・
  確定・取消)。Reactorは有給と同じ対応。代休の付与は`attendance_day.calculated`を受ける既存の同期Reactorの
  同期先を口座集約に変える(勤怠日テーブルを直接読まず、計算イベントの内容(利用者・日付・休日労働時間)を使う。
  不足する情報は計算イベントに追加する)。
- 既存データ: 論点18の`stored_events`の作り直しで、旧付与集約・旧申請集約のイベントを口座集約のイベントとして元の発生順に
  記録し直す(差し戻された申請の消化記録は差戻しの位置で取り消す)。移行イベント(`*_account.migrated`)・移行コマンド・
  口座集約の`migrate()`/`migrateGrants()`は持たない。口座のProjectorは口座集約のイベントだけを読み、旧Projector
  (`SpecialLeaveGrantProjector`・`SpecialLeaveUsageProjector`・`CompensatoryLeaveGrantProjector`)の旧イベント処理と
  `stored_events`の直接読み取りは削除する。
- 有給の付与の`source`に`carried_over`(cutover時にシステム外から持ち込んだ残高。論点18)を追加し、Projector・画面で表示する。
  cutoverの情報は付与イベントの`meta_data`に残し、`paid_leave_grants`の移行監査列は削除する。
- 消化記録テーブル: 勤怠日への外部キー・申請テーブルへの外部キーを撤去し、`attendance_day_id`を任意にする
  (新規には設定しない)。

### E. 勤怠文脈(`App\Domain\Attendance`)
- 休暇ビュー`attendance_day_leaves`(新設Projection): 列=id、user_id、work_date、leave_kind(paid/special/
  compensatory)、unit(full/am_half/pm_half/hourly)、hours、minutes、special_leave_type_id、leave_request_id、
  workflow_request_id、usage_id、request_status(submitted/approved/returned/cancelled)、source(休暇の種類)。入力=休暇申請文脈のイベント
  (論点2。本変更前の休暇も論点18の作り直しで同じイベントになるため、旧系統は読まない)。差戻し・取消では行を残し`request_status`をreturned/cancelledにする(読み手は申請中・承認済みだけを扱う。実装中の決定(WP5a))。
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
  意図的な変更)。本変更前の休暇・勤怠も論点18の作り直しで同じイベントになるため、引き継ぎ用の入力は持たない。
- 勤怠のイベントに不足する情報があれば勤怠のイベントに追加する(利用者・日付は`attendance_day.created`等が持つ)。

### G. フロントエンド
- 休暇ラベル・バッジは`leaves`から表示(複数あれば複数)。全休・半休・時間休とも現行と同じく状態バッジの代わりに休暇
  ラベルを表示する。打刻漏れ警告・今日の勤怠の出勤ボタンは`leaves`の全休で判定。
- 日次画面: 休暇指定UIは`leaves`から現在の休暇を判定。作業内容欄は常に表示し入力値を送る。
- 申請詳細画面: 業務側を持つ申請で「却下」を出さない。差し戻された休暇の「提出する」は残す。
- 休暇の各画面: 差し戻された申請を表示し、再提出(申請詳細へ)・取消の導線を出す。
- `CancelApprovedLeaveDialog`等の「勤怠区分もクリアされます」の文言を修正。

### I. 最終設計レビューで確定した事項
- **本変更前に申請された申請の引き継ぎ(論点18で改訂)**: 既存の全ての休暇申請(旧`paid_leave.*`の8件、cutover後の有給申請、
  特別・代休の申請)は、`stored_events`の作り直しで申請ごとの状態機械により、申請集約の`*.requested`/`shared`/`approved`/
  `returned`/`resubmitted`/`cancelled`と口座集約の`usage_designated`/`usage_confirmed`/`usage_cancelled`に変換して元の発生順に
  記録し直す(`assets/event-rebuild-mapping.md` 2.1)。消化記録のusageIdは申請IDと再提出の回数から決める(cutover後の乱数の
  usageIdは引き継ぐ)。以後の承認・差戻し・取消・再提出は全て新しい集約で処理する。`paid_leave_request.migrated`・
  `*_account.migrated`は作らない。
- **cutover前の有給の取消(論点18で改訂)**: cutover前の8件は、作り直しで消化記録まで再現できた場合、通常の承認済み申請と
  同じく取消できる(2026-10-10 ユーザー決定)。`PaidLeaveRequestAggregate`の「移行前の申請の取消を拒否」は削除する。
  対象日は2026年8月〜9月4日で、締め済みであれば論点14により取消できない。
- **差し戻された申請の消化記録(論点18で改訂)**: 作り直しの差戻しの規則で、差戻しの位置に`usage_cancelled`を置く
  (承認済みの充当があれば先に解放)。再提出は新しいusageIdで`usage_designated`を置く。
- **月次APIの代休付与表示のビュー**: 勤怠側が代休の口座のイベントから作り、利用者×日付で付与(日数・時間・確定状況・
  充当量)を持つ。
- **代休付与の連携(原則15)**: 付与の同期Reactorは`attendance_day.calculated`・`daily_calculation_adjusted`・`deleted`を
  購読する(現行と同じ)。計算イベントの内容(利用者・日付・日区分・休日労働時間)で判定し、不足する項目は計算イベントに
  追加する。手動付与(`GrantCompensatoryLeaveHandler`)は勤怠日を読まず、代休の口座側が計算イベントから作る休日出勤の
  ビューで休日出勤実績を確認する。月次APIの代休付与表示(`AttendanceMonthResource`)は勤怠側が代休のイベントから作る
  ビューを読む。月次確定での付与確定(`ConfirmCompensatoryLeaveGrantsForMonthHandler`)は口座集約に付け替え、月の判定は
  文字列の前方一致ではなく日付範囲で行う。`compensatory_leave_grants.attendance_day_id`の外部キー(unique)も撤去する。
- **出勤率の入力**: `attendance_day.synced_from_punches`は退勤済みとして扱う(Projectorと同じ)。勤怠日IDと利用者・日付の対応は
  `created`・`synced_from_punches`・`live_status_synced`から持つ(休暇の処理が直接作った勤怠日も、論点18の作り直しで
  `created`を持つ)。「現行と同じ結果」は作り直し後のデータに対する約束とする。差し戻された有給は本変更で休暇から外れるため出勤扱いにならない(意図的な変更)。
- **同じ日に午前・午後で別の休暇がある場合の勤怠計算**: 午前半休と午後半休がそろう日は全休と同じく扱い、所定労働時間は
  半分にしない(全休と同じP)。休暇日数は種類ごとに0.5ずつ。半休が1つだけの日は現行どおり所定労働時間を半分にする。
- **時間休の分数**: 休暇ビューの`minutes`は`round(hours * 60)`(現行の消化記録の算出と同じ)。作り直しで本変更前の申請の
  `hours`は旧`usedMinutes`÷60から求める(`assets/event-rebuild-mapping.md` 5章)ため、元の分数と一致する。
- **勤怠のイベントの必須項目**: 勤怠が休暇に反応して記録する`attendance_day.created`/`deleted`の`createdByUserId`/
  `deletedByUserId`には連鎖の起点の操作者(休暇の申請者・承認者・取消者)を入れる(システム処理で操作者がいない場合は
  申請者)。`utcOffsetMinutes`は利用者の既定タイムゾーン、`punchLogAction`は打刻ログを変更しない値とする。
- **論点15の削除条件の詳細**: 実績=出退勤・休憩・不就労区間・作業内容・勤務形態区分・備考のいずれか、手動調整、週40時間の
  配賦のいずれかがある日は削除しない(再計算する)。
- **通知**: 現行の休暇Handlerが送っている通知を一覧化し(WP3で作成して委譲元がレビュー)、同じ契機・同じ受信者で、状態を
  確定させた文脈のHandlerから送る。連鎖内で同じ通知を二重に送らない。
- **既に却下済みの休暇ワークフロー(論点18で改訂)**: 却下済みのワークフローに紐づく休暇申請が申請中のまま残っていれば、作り直しで
  却下の位置に申請集約の`*.cancelled`と口座集約の`usage_cancelled`を置く(リハーサルで件数を確認する)。

### H. `stored_events`の作り直しと補正(論点12・18)
- 変換コマンド`leave:rebuild-event-store`(仮称): 旧イベントクラスに依存せず`stored_events`の生のJSONを読み、申請・付与・勤怠日
  ごとの状態機械で新しいイベント列を作る(`assets/event-rebuild-mapping.md`)。既定は試し実行(件数・扱えない並びの一覧・cutoverの
  付与の突き合わせ・差戻し/取消/却下の判定)、`--apply`で`stored_events_rebuilt`に書く(休暇以外のイベントも同じ順で写し、id・版を
  振り直す)、`--swap`で`RENAME TABLE`により入れ替える。事前条件(新しい種類のイベント0件・補正ログ0件・スナップショット0件)を
  自動で確認する。変換規則はテストで固定する。
- 作り直しで、新しいコードが既定値nullで受けている項目(特別・代休の申請イベントの`userId`・`targetDate`・`requiresGrant`、勤怠の
  計算イベントの`userId`・`workDate`ほか)を全て補完し、作り直し後は既定値・nullガードを削除する。
- 補正イベント(`attendance_day.corrected`)・`CorrectAttendanceDay`・補正候補の検出(`leave:correction-report`)は作らない
  (論点12。少数のイベントを直す`StoredEventCorrector`は残す)。
- 勤怠日Projectorの全件再生成は、テーブルを空にして`event-sourcing:replay`する。
- 日次計算の一括再計算コマンド`attendance:recalculate-days`(`#[AdminExecutable]`、`--from`/`--to`必須、`--user=*`、
  既定は試し実行、手動調整済みの日は除外し一覧出力)。作り直しの後、休暇値を直した日と休暇のある日を、締め・提出済みの月も
  含めて再計算し、月次の提出時のスナップショットと差のある月を一覧にして報告する。

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
- 同じイベントを2回処理しても各文脈の状態が変わらない。却下済みのワークフローに紐づく申請中の休暇が、作り直しで取消として
  記録される。まとめ申請で兄弟の一部が差戻し中でも承認が通る。cutover前の有給は、作り直しで消化記録まで再現された場合に
  通常の承認済み申請と同じく取消できる。
- 休暇の各Handlerが他文脈の集約・テーブルを直接読み書きしていない(コードレビュー)。
- 同じイベントを購読する複数のReactorの実行順を入れ替えても結果が同じ。通知が重複しない。
- 勤怠・休暇申請・残数の各Projectorを空から全件リビルドしても、リビルド前と同じ状態になる(作り直し後の本番相当
  データで確認)。手動調整した日は作り直し後の再計算で上書きされない。
- `stored_events`の作り直し(論点18): 変換規則(申請・付与・勤怠日ごとの状態機械、cutoverの付与の突き合わせ、勤怠日の`created`の
  補完と休暇値の書き換え、既定値nullの項目の補完)がテストで固定されている。本番相当データで試し実行の扱えない並び・cutoverの
  不一致が0件で、複製DBで作り直し後の表から全Projectorをリビルドした結果と作り直し前のReadModelとの差が、意図した差(差し戻された
  休暇の消化記録の取消、休暇値の削除、論点15の空の勤怠日の削除、`carried_over`の付与等)だけである。作り直し後のコードに移行
  イベント・旧イベントクラス・旧系統の読み替え(`input_source`)・移行コマンド・補正イベントが残っていない。
- 再提出で承認まで通る、業務側を持つ申請は却下できない、残数不足でも承認され充当できた分だけ充当される(3種)、休暇の解除で空の勤怠日が
  削除される、出勤率の判定結果が作り直し後のデータで変わらない、`source=leave`の日に打刻が取り込まれる、本変更前に申請された
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
  口座集約、消化記録の`attendance_day_id`、`paid_leave_grants.source`の`carried_over`、削除する列(`input_source`・消化記録の
  `stored_event_id`・付与の移行監査列))、`docs/17-events.md`(新イベント・購読関係、作り直しで無くなる旧イベント・移行イベント・
  `attendance_day.corrected`の削除)、`docs/32`(休暇まわりの補正を補正イベントから`stored_events`の作り直しに改める)、
  `docs/27-release-runbook.md`(移行コマンドの手順を作り直しの手順(書き込み停止・`--apply`・複製DBでの検証・`--swap`・
  リビルド・再計算・ロールバック)に置き換える。監査ログAPIの`event_id`が変わることをリリースノートに記載)。
- `.claude/skills/data-correction`: 「データ移行は`stored_events`の作り直しで行う」に改訂済み。
- `.claude/skills/add-domain-event`: 文脈間の連携はReactor+冪等Commandで行う旨を追記。
- OpenAPIの注釈(`special_leave_usages`→`leaves`)、`docs/04-domains`(文脈の責務)、`docs/13`(通知の送信元)。

## モック・アセット
- `assets/`配下の調査結果(`impact-usage-derivation.md`、`returned-resubmit-and-overlap.md`、
  `other-request-types-return-resubmit.md`、`data-correction-prerequisites.md`、`context-coupling.md`、
  `monthly-items-and-attendance-rate.md`、`domain-unit-test-coverage.md`、`paid-leave-wiring-map.md`)
- `assets/event-rebuild-mapping.md`: `stored_events`の作り直しの変換規則・削除するもの・実行手順(論点18)
- `assets/rehearsal-checklist.md`: 本番相当データでのリハーサル手順書(作り直しの試し実行・検証)

## 実装対象
文脈単位の作業パッケージ(WP)に分け、implementerへ順に委譲する(依存のないものは並列)。各WPでテストを追加し、
完了ごとに委譲元がレビューする。全WPは同じブランチで開発し一緒にリリースする。WP3〜WP5は互いに依存し単独では既存テストが
通らないため、CIの全件PASSはWP2〜WP5をそろえた時点と全WP完了時点で確認する(それ以前は各WPのテストを確認)。
申請・承認文脈の休暇テーブル読み取り(兄弟承認)の削除はWP3で、休暇申請側の兄弟承認と同時に行う。
| WP | 内容 | 主な対象 | 依存 |
|---|---|---|---|
| WP1 共通基盤 | `viaReactor`・`initiatedByUserId`の規約、冪等ガードの共通化、Projectorの行なし許容 | `App\Domain\EventSourcing`、各Projector | - |
| WP2 申請・承認 | 却下の制限、`CancelWorkflowRequest`・`ApproveWorkflowRequest`の`viaReactor`、取消Reactorの3種統一、有給の提出Reactor・兄弟ワークフロー承認Reactorの新設 | `App\Domain\Workflow` | WP1・WP3のイベント定義 |
| WP3 休暇申請 | `PaidLeaveRequestAggregate`・`paid_leave_request.*`、ワークフロー対応表、兄弟承認の移設(申請・承認側の休暇テーブル読み取りの削除を含む)、通知の一覧化と移設、申請Projector(`paid_leave_request.*`だけを読む。実装済みの`migrated`・3系統の切り替えはWP10で削除)、特別・代休の申請集約から使用操作を削除・`request_resubmitted`、ワークフローイベントのReactor、申請Handlerから他文脈操作を削除 | `App\Domain\PaidLeave`・`SpecialLeave`・`CompensatoryLeave`、`Workflow/Reactors`の休暇系 | WP1・WP2 |
| WP4 残数・使用 | 有給口座のReactor化・申請テーブル更新の削除、特別・代休の口座集約・イベント・Projector(実装済みの移行コマンド・`migrated`はWP10で削除)、代休付与同期の付け替え、消化記録の外部キー撤去・`attendance_day_id`任意化 | `App\Domain\PaidLeaveAccount`、新設の口座、マイグレーション | WP3 |
| WP5 勤怠 | `attendance_day_leaves`、`ApplyLeaveToAttendanceDay`/`ReleaseLeaveFromAttendanceDay`とReactor、`source=leave`、衝突チェック・締め判定、勤怠計算の休暇入力、出勤可否・打刻取り込み・警告・未出勤件数・月次提出ガード、API`leaves`、勤怠日Projectorのリセット、`attendance:recalculate-days`、`work_type`の休暇解釈の削除 | `App\Domain\Attendance`、`AttendanceController`、`AttendanceDayResource` | WP3 |
| WP6 出勤率ビュー | `leave_attendance_rate_days`とProjector、`AttendanceRateAssessor`・`GrantScheduledSpecialLeaveHandler`の入力置き換え | `PaidLeaveSchedule`・`SpecialLeave` | WP3・WP5 |
| WP7 フロントエンド | `leaves`の型・表示、打刻漏れ・出勤ボタン、日次画面の休暇判定・作業内容欄、申請詳細の却下非表示、休暇画面の差戻し表示と導線、文言修正、e2e | `frontend/src` | WP5 |
| WP8 補正の枠組み | (論点18で置き換え)実装済みの補正専用イベント`attendance_day.corrected`・`CorrectAttendanceDay`・`leave:correction-report`はWP10で削除する。補正コマンドの基底・`StoredEventCorrector`は少数のイベントを直す補正用に残す | `App\Console\Commands`、`App\Domain\Attendance` | WP4・WP5 |
| WP9 docs | 「ドキュメントへの影響」の全件 | `docs/`、`.claude/skills/add-domain-event` | 全WP |
| WP10 イベント構成の作り直し | (1)変換コマンド`leave:rebuild-event-store`(仮称。生JSONを読む、申請・付与・勤怠日ごとの状態機械、cutoverの付与の突き合わせと`carried_over`、勤怠日の`created`の補完・休暇値の書き換え、既定値nullの項目の補完、事前条件の確認、試し実行既定・`--apply`で`stored_events_rebuilt`・`--swap`、変換規則のテスト。仕様確定事項H、`assets/event-rebuild-mapping.md` 2・5・6・7章)。(2)旧系統の削除: 旧イベントクラスと別名(`config/event-sourcing.php`)、旧集約、旧Projectorの旧イベント処理と`stored_events`の直接読み取り、`PaidLeaveRequestProjector`・`AttendanceDayLeaveProjector`・出勤率ビューの系統の切り替え(`input_source`列)、既定値nullのガード、消化記録の`stored_event_id`列・付与の移行監査列とその表示、フロントの旧`event_type`名(同4章)。(3)移行コマンドと補正イベントの削除: 移行コマンド4つ(`special-leave:migrate-to-account`・`compensatory-leave:migrate-to-account`・`paid-leave:migrate-requests`・`paid-leave:migrate-accounts`)、`/paid-leave/migrate` APIと`MigratePaidLeaveAccount`、`*_account.migrated`・`paid_leave_request.migrated`、各口座集約の`migrate()`・`migrateGrants()`、`PaidLeaveRequestAggregate`の移行前申請の取消拒否、`attendance_day.corrected`・`CorrectAttendanceDay`・`onAttendanceDayCorrected`・`LeaveCorrectionCandidateDetector`・`LeaveCorrectionReportCommand`。(4)手順書: `assets/rehearsal-checklist.md`、`docs/27`・`docs/32`の作り直しの手順(空にするReadModelのテーブル一覧をProjectorの書き込み先から機械的に作って載せる) | `App\Console\Commands`、`App\Domain\PaidLeave`・`PaidLeaveRequest`・`PaidLeaveAccount`・`SpecialLeave`・`CompensatoryLeave`・`Attendance`・`PaidLeaveSchedule`、マイグレーション、`frontend/src`、`docs/` | WP1〜WP9 |
| WP11 実装漏れの補完 | 2026-10-11の監査に基づく決定(1)〜(7)(実装中の決定の記録): 差戻し通知のリンク(特別休暇・代休)、勤務予定日・時間休の判定を勤怠の`ApplyLeaveToAttendanceDay`へ移す、Reactor発行Commandのviaにそろえる(提出3種・代休の同期・月次確定)、特別休暇の残数不足警告を勤怠側のビューに、テストの補完、docs/13・17の修正 | `App\Domain\PaidLeave`・`SpecialLeave`・`CompensatoryLeave`・`Attendance`・`Workflow`・`CompensatoryLeaveAccount`、`AttendanceController`、`docs/` | WP10 |
| WP12 イベントを経ずに作られたデータの補完・旧付与予定 | 論点19の1〜6: (A)イベント・Projectorの変更(`isSystem`、`actorUserId`のnull可、暦の年・日の発生日時)、(B)照合による補完(作り直しに組み込む)、(C)旧付与予定イベントの置き換え、(D)ReadModelの照合の出力、(E)シーダーのCommand化と`docs/32`、(F)手順書・`docs/17`・`assets/event-rebuild-mapping.md` | `App\Domain\EventSourcing\Rebuild`、`UserManagement`・`AccessControl`・`Attendance`(暦)・`PaidLeaveSchedule`、`database/seeders`、`docs/` | WP10 |

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
    再生され再現できない。→ `data-correction`スキルに「直接修正は書き換え・削除のみ、挿入は扱わない」を追記して是正。(論点18で置き換え)
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
  書き換え・削除・追記で再構成(挿入を撤回)、論点13(cutover前の有給)・14(締め・ロック)・15(取消後の勤怠日)を追加。(論点6の移行(追記)・論点12の補正・論点4の境界条件は論点18で置き換え)

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
  休暇ビュー・有給申請Projectorの入力にする(A)と決定。これで全論点の要確認事項が解消。仕様確定事項の記載へ進む。(論点18で置き換え)
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
  午前・午後で別の休暇がそろう日は全休と同じ扱い(所定を半分にしない)と委譲元で決定(ユーザーに報告)。(`migrated`による引き継ぎは論点18で置き換え)
- 2026-10-10 最終設計レビュー後の再確認の指摘を反映: 3系統の切り替えを`migrated`基準に統一(論点4・13・C・E・F)、逆方向連携に
  勤怠→代休の口座を追加、午前・午後の別休暇がそろう日を出勤可否・警告・出勤率でも全休扱い、兄弟承認は申請中のみ・
  `ApproveWorkflowRequest`の`viaReactor`、drafted Reactorの`workflow_requests`読み取り削除、cutover前8件の取消は拒否
  (委譲元で決定、ユーザーに報告)、論点5の再申請の記述を論点8に合わせて修正、テスト一覧・受け入れ条件の追加、WP2の依存。(3系統の切り替えとcutover前8件の取消拒否は論点18で置き換え)
- 2026-10-10 ユーザーが変更セットを承認(「実装をお願いします」)。ステータスを実装中に更新。
- 2026-10-10 実装中の決定: 有給申請の新しい集約・イベントは、旧イベントクラス(`App\Domain\PaidLeave\Events\PaidLeaveRequestApproved`等、
  再生のため残置)とのクラス名衝突を避けるため、新ドメイン`App\Domain\PaidLeaveRequest`に置き、イベントクラス名は
  `PaidLeaveRequestLifecycle{Requested,Shared,Approved,Returned,Resubmitted,Cancelled,Migrated}`とする(イベント名は
  `paid_leave_request.*`のまま)。implementerが衝突を検出して停止したため委譲元で決定。(`Migrated`は論点18で置き換え)
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
  現行は下書きを含む点を移行後も維持するか(表示用Projectorで対応)。機能無効時の下書き・手動付与の扱いは現行どおり。(移行に関する(a)(b)は論点18で置き換え)
- 2026-10-10 実装中の決定(WP5a 勤怠の休暇ビュー): `attendance_day_leaves`は差戻し・取消で行を削除せず`request_status`をreturned/cancelledに
  する(系統の切り替え判定に行を使うため。読み手は問い合わせクラス経由で申請中・承認済みだけを扱う)。**リリース手順・リハーサルの確認事項**:
  (d)新設Projector(対応表・休暇ビュー)は自動検出で有効になるため、リリース時に対象テーブルを空にして`event-sourcing:replay`で過去分を
  反映する、(e)cutover後の`paid_leave_account.usage_designated`で`paidLeaveRequestId`がnullのものの件数(休暇ビューは無視する)。(系統の切り替えと(d)(e)は論点18で置き換え)
- 2026-10-10 実装中の決定(P1 有給の口座): 集約が申請ID→usageIdを保持し(既存イベントの`paidLeaveRequestId`から構築)、確定・取消は申請IDでも
  指定可能。Reactor経由(`viaReactor`)の二重指定・二重確定・二重取消は何もしない。確定時の残数不足は例外(論点17。誤差1e-9は許容)。
  テストで付与をイベントなしで直接INSERTしていたものは、集約が付与を知らず承認できないため、付与コマンドによる作成に置き換えた
  (本番の付与はcutover時の`paid_leave_account.migrated`イベントで集約に登録済みのため影響なし)。(論点18で置き換え)
- 2026-10-10 実装中の決定(P1のCI対応): 論点17の残数不足の拒否は承認経路(`PaidLeaveAccountAggregate::approveUsage`、
  `ConfirmPaidLeaveUsageHandler`)にだけ適用し、過去の消化記録の再生・移行(`confirmUsage`)は従来どおり部分充当を許す
  (過去の事実の引き継ぎであり新たな承認ではないため)。(移行は論点18で置き換え)
- 2026-10-10 **論点17を改訂(ユーザー指摘)**: 承認時の残数不足は拒否しない(既存の設計方針)。以前の確認で委譲元が「拒否」を推奨
  選択肢として提示し、既存方針との矛盾を示さなかったため誤って決定として記録していた。P1(有給)・WP4a/4b(特別・代休の口座集約)に
  入れた不足時の例外を撤回し、部分充当で確定する(特別・代休は不足量を記録)。受け入れ条件の「残数不足で承認が拒否される」を削除。
- 2026-10-10 実装中の決定(P2a 有給申請テーブル): `paid_leave_requests`は休暇申請文脈の`PaidLeaveRequestProjector`が3系統+migratedから作る
  (系統は`input_source`列で管理)。`paid_leave_usages`の`paid_leave_request_id`・`attendance_day_id`の外部キーを撤去(Projectorの実行順に
  依存しないため。仕様確定事項Dの先行実施)。migratedは元の提出・承認・差戻し・取消日時を任意項目で持つ。承認者nullは申請者を入れる
  (承認不要時の現行と同じ)。リリース時は`paid_leave_requests`・`paid_leave_request_usage_links`・`leave_request_workflow_links`を空にして
  一緒にリビルドする(差戻しの判定が対応表に依存するため)。(3系統+migrated・`input_source`は論点18で置き換え)
- 2026-10-10 論点17改訂の実装: 有給は部分充当の現行挙動に戻し(P1の拒否を撤回)、特別・代休の口座集約は不足でも確定し未充当量
  (`unallocatedDays`/`unallocatedMinutes`)を確定イベントに記録する。**リハーサル確認事項**: (f)移行(migrated)で引き継ぐ過去の
  「承認済み・未充当」の消化記録の未充当量は現状0として扱われるため、移行データに未充当量を持たせるかを件数を見て決める。(論点18で置き換え)
- 2026-10-10 実装中の決定(WP5後半): 勤怠日Projectorの全件再生成は、既存Projectorと同じくテーブルを空にして`event-sourcing:replay`する方式
  とし、専用のリセット処理は追加しない(仕様確定事項Hの「リセット処理」はこの手順で満たす)。`attendance:recalculate-days`は締め・提出済みの
  日も対象に含め一覧に印を付ける(月次スナップショットは変えない)。月次APIの特別休暇の内訳は休暇ビューの承認済みのみ(現行どおり)。
  特別休暇の残数不足警告は特別休暇の配線替え後に残数側の情報から作る(それまでは消化記録テーブルの読み取りが残る)。
- 2026-10-10 実装中の決定(特別休暇の配線替え): 付与の登録・取消は口座集約で記録する。リリース手順: 新コードの配備直後に
  `special-leave:migrate-to-account --apply`を実行する(未実行の間は旧付与の取消・旧申請の承認が口座に反映されない)。移行の冪等は口座の
  `migrated`の有無で判定し、移行前に新しい流れで登録された付与・申請は除外する。旧Projectorは口座の消化記録ID(`usage_id`)を持たない旧来の
  行だけを扱う。取消時の消化記録は現行どおり行を削除する。(論点18で置き換え)
- 2026-10-10 実装中の決定(代休の配線替え): 付与の同期・手動付与・月次確定・付与取消(申請・承認)は代休の口座集約で記録する。付与の同期は
  勤怠の計算イベント(`attendance_day.calculated`・`daily_calculation_adjusted`に利用者・勤務日・日区分・実労働分を末尾に追加)の内容で判定し、
  手動付与は代休の口座側の休日出勤ビューで判定する。月次APIの代休表示は勤怠側の付与ビューを読む。旧付与集約とそのCommand/Handlerは削除する
  (旧イベントとProjectorは移行前の再生用に残す)。リリース手順: 新設Projectorのテーブルを空にして`event-sourcing:replay`の後、
  `compensatory-leave:migrate-to-account --apply`を実行する。(移行コマンドと旧イベントの残置は論点18で置き換え)
- 2026-10-10 実装中の決定(WP6・WP7・有給の引き継ぎ): 出勤率ビューは有給(`leave_attendance_rate_*`)と特別休暇
  (`special_leave_attendance_rate_*`。自動付与の勤務形態の絞り込みのため`work_style_id`を持つ)の各文脈に置き、判定は共有の
  `App\Domain\Leave\Support\LeaveAttendanceRateJudgement`、Projectorは共通の抽象基底を継承する。フロントの差し戻された休暇の再提出は、
  休暇申請APIに`workflow_request_id`を追加して申請詳細へ直接リンクする(後続で対応)。休暇の履歴画面(イベント一覧)には差戻しの導線を
  付けない。有給の引き継ぎ(`paid-leave:migrate-requests`)で、却下済みワークフローの申請中は取消として引き継ぎ口座の未確定消化記録も
  取り消す。**リハーサル確認事項**: (g)引き継ぎコマンドの警告(対応ワークフローが無い申請等)の件数、(h)旧システムが直接作った勤怠日の扱い(WP8)。(有給の引き継ぎコマンドと(g)(h)は論点18で置き換え)
- 2026-10-10 論点18を独立設計レビュー(blocker8・major9)に基づき改訂、spec全体と手順書を方式Bに合わせた
- 2026-10-11 ユーザーが論点18(stored_eventsの作り直し、`assets/event-rebuild-mapping.md`)を承認し、WP10の実装と実装漏れの確認・完了を指示。本番での作り直しの実行は、試し実行の結果を示してから別途許可を得る(`data-correction`スキル)。
- 2026-10-11 実装漏れの監査(investigator)に基づく決定(WP11として実装):
  (1)通知: 休暇の差戻し通知はワークフロー側の差戻し通知だけを送り(二重送信の解消)、その通知のリンクを休暇の種類ごとの履歴画面にする
  (有給は実装済み、特別休暇・代休もそろえる)。仕様確定事項Iの「休暇固有の通知」とdocs/13をこの形に改める。
  (2)勤務予定日の判定: 休暇申請のHandlerが勤怠の勤務予定・勤務形態を読む処理(原則15違反)をやめ、「勤務予定日でない日の休暇」と
  「時間休の時間数と所定労働時間の整合」は勤怠文脈の`ApplyLeaveToAttendanceDay`の判定(判定クラス)で拒否する。`system_settings`は
  全文脈共通の設定として読んでよい。
  (3)HTTPのAPI(読み取り)は、画面の表示のために他文脈の読み取り用クエリクラス(`LeaveUsageQuery`等)を組み合わせてよい。ドメインの
  Handler・Reactor・Projectorは他文脈のテーブルを読まない。
  (4)Reactorが発行するCommandは全てviaReactor/initiatedByUserIdを持つ(ワークフローの提出3種、代休付与の同期・月次確定もそろえる)。
  勤怠の休暇反映(Apply/Recalculate/Release)も冪等にし、日次計算の結果が直近の計算と同じなら記録しない(2026-10-11 CIの指摘で改訂)。
  (5)特別休暇の残数不足警告: 勤怠側が特別休暇口座のイベント(`special_leave_account.usage_confirmed`の未充当量・`usage_cancelled`)から
  自分のビューを作って表示し、`special_leave_usages`を直接読まない。
  (6)テストの補完: 午前有給+午後代休、有給の締め済みの全遷移、特別休暇・代休の承認不要の申請、同じイベントを購読するReactorの実行順を
  入れ替えても結果が同じこと、通知が重複しないこと。
  (7)docs/17の勤怠のイベント名を`config/event-sourcing.php`の別名(`attendance_day.*`)にそろえる。
- 2026-10-11 WP11の実装中の決定: 有給・特別休暇の時間休の申請日数(`requestedDays`=時間数÷所定労働時間)は残数の消化がイベントの日数を使うため申請時に決める必要があり、その換算のための勤務形態の読み取りだけは申請Handlerに残す(原則15の既知の例外。解消するにはイベントを分数で持ち残数側で換算する設計変更が必要)。勤務予定日でない日・時間数の整合の判定は勤怠の`ApplyLeaveToAttendanceDay`で行い、申請・再提出の両方で判定する。代休の差戻し通知のリンクは履歴画面が無いため`/compensatory-leave`。

## 実装結果
2026-10-11 全WP(WP2〜WP11)の実装完了(PR #115、ドラフト)。CI(PHP 8.4のPHPUnit全件・MySQLでのマイグレーション・フロントのビルド)で
確認した。コーディングは全てimplementerに委譲し、委譲ごとのレビュー・CIでの失敗と是正は`docs/delegation-reviews/202610.md`に記録した。

- WP2 申請・承認: 業務側を持つ申請の却下を拒否、取消・承認・提出のReactor経由(冪等)。
- WP3 休暇申請: 有給申請集約(`paid_leave_request.*`)・特別休暇/代休の申請集約を申請状態のみに、再提出イベント、ワークフローID↔申請の
  対応表、各文脈のReactor。差戻し通知はワークフロー側だけ(リンクは休暇の種類ごとの画面)。
- WP4 残数・使用: 有給口座の申請ID対応・冪等化、特別休暇・代休の口座集約とProjector、代休付与の同期を計算イベントの内容で。
  残数不足でも承認し部分充当(論点17改訂)。消化記録IDは申請IDと再提出の回数から決定的に採番。
- WP5 勤怠: 休暇ビュー、休暇の反映・解除(勤務予定日・時間休の整合・締め・衝突・`source=leave`の作成/削除)、勤怠計算の休暇入力、
  全休の出勤可否・打刻・警告、月次提出ガード、API`leaves`、`attendance:recalculate-days`、特別休暇の残数不足のビュー。
- WP6 出勤率ビュー(有給・特別休暇。判定は共有クラス)、WP7 フロントエンド(`leaves`、却下非表示、差し戻された休暇から申請詳細への
  直接リンク、全休の表示、`carried_over`の表示)、WP8 部分的な書き換え用の補正コマンドの基底・補正ログ、WP9 ドキュメント。
- WP10 `stored_events`の作り直し: `leave:rebuild-event-store`(生JSONを読み、申請・付与・勤怠日ごとの状態機械で新しいイベント構成へ
  変換、cutoverの付与の突き合わせと`carried_over`、勤怠日の`created`の補完・休暇値の書き換え、既定値nullの補完、id・版・metaの
  振り直し、試し実行・`--apply`・`--verify`・`--swap`・`--list-readmodels`、事前条件)。旧イベント・旧集約・旧Projector・系統の切り替え・
  移行コマンドとAPI・移行イベント・補正イベント・旧列・未使用の中間テーブルを削除。
- WP11 実装漏れの補完(2026-10-11の監査の決定(1)〜(7))。
- WP12(2026-10-14、リハーサル2回目の是正。論点19): 全イベントの復元可否の検査、旧付与予定イベント(PR #109の版)の置き換え、
  イベントを経ずに作られた行の照合による補完(12表)、作成イベントの`isSystem`・操作者のnull可・暦の年の発生日時、シーダーのCommand化、
  認証情報の`user_credentials`への分離、ReadModelの指紋(`--readmodel-fingerprint`)、空にする一覧からの参照マスタ・認証情報・
  イベントストア(`stored_events`・`snapshots`)の除外。CI(c7c2e16)で全件成功。
- 受け入れ条件: 本番相当データでの確認(作り直しの試し実行・複製DBでのリビルドの比較・出勤率・cutover前8件の表示と取消・作り直し後の
  再計算)はリハーサルで行う。それ以外はシナリオテストで確認済み。
- 残課題: `assets/rehearsal-checklist.md`に沿ったリハーサル(試し実行の扱えない並び・cutoverの不一致・差戻し/取消/却下の判定・
  複製DBでの比較の差分・再計算で値が変わる日)、本番での作り直しの実行(変換規則と試し実行の結果を示してユーザーの明示的な許可を
  得てから)。
- 既知の例外: 有給・特別休暇の時間休の申請日数の換算のための勤務形態の読み取りが申請Handlerに残る(原則15。実装中の決定(WP11))。
- 残課題(対象外): `ShiftSwapRequestController`・`ExpenseClaimController`の`workflow_requests`直読み(休暇以外)、フロントのe2eの実行、
  本変更前からの失敗(`useAuth`による単体テスト6件、Storybookのdecorator不足による13件)。

## リハーサル結果

### 1回目(2026-10-13、ローカルのDocker MySQL、`xsys_flowoffice.sql`から複製)

- 試し実行: 扱えない並び0件、cutoverの突き合わせの不一致0件。`--apply`で`stored_events_rebuilt`へ2830件、自己整合性の検査は問題なし。
  `--swap`も成功(元の表は`stored_events_before_rebuild_20261013035804`)。
- 3-5(`flow_office_verify`での全Projectorのリビルド)が完走しなかった。
  1. `device_roles`の一意制約違反: `--list-readmodels`の一覧に、Projectorがリレーション経由(`$device->roles()->create`等)で
     書くテーブルが含まれず、空にされなかった。**是正(実装中の決定)**: 一覧の作成で、Projectorのソースで呼んでいるリレーション
     メソッド(`->名前()->`)を、参照しているモデルのリレーションとして解決し、その関連先(BelongsToManyなら中間テーブルも)の
     テーブルを加える。対象は`device_roles`・`device_scopes`・`attendance_breaks`・`attendance_leave_segments`等。
     ユニットテストで、リレーション経由のテーブルが一覧に含まれることを固定する。
  2. `attendance_days`の`[user_id, work_date]`の一意制約違反: 同じ利用者・日に、削除されていない勤怠日の集約が2つ再生されている。
     原因は未特定。仮説は、旧コードがイベントを書かずに勤怠日の行を削除したため、同じ日に別の集約が作られて2つ並んだというもの。
     その場合、ReadModelに行が無い集約には`deleted`が置かれない(2.4の解除の規則はReadModelの現在値がある日だけ)。
     リハーサルのDBで対象の集約を特定してから、変換規則(2.4)の追加を決める(手順書3-5の診断SQL)。
- 試し実行・`--apply`の自己整合性の検査が、この重複を検出しなかった。変換規則を決めた後、勤怠日の`[利用者, 勤務日]`の生存期間が
  重ならないことを試し実行の検査に加える。

### 2回目(2026-10-14、9ae0732)

- `--apply`後の`stored_events`は2830件、版の欠番・重複0件、旧有給イベント0件。`--swap`成功。リレーション経由のテーブルの漏れは解消。
- 3-5の全Projectorのリビルドが完走しなかった(2814件中約71%、stored event id 2046で停止)。
  1. **作成イベントが無いマスタ**: `groups`(4件)・`group_types`(7件)・`company_calendar_years`(1件)・`roles`(6件)は、
     `stored_events`に作成イベントが無く、更新系のイベントだけがある(初期データ等、イベントを経ずに作られた行)。空にすると
     外部キー違反(`memberships.group_id`・`permission_role.role_id`)や`findOrFail`で止まる。休暇の変更とは無関係の既存の欠落。
     リハーサルではこの4表を残して再試行した。扱いは論点19で決める。
  2. **現行のイベントクラスで復元できない保存済みイベント**: `paid_leave_schedule.entry_created`(id 2046)のpayloadは
     `userId`・`entryId`を持ち、現行クラスは`scheduleEntryId`を要求する。PR #109(2026-09-09)の版で記録され、PR #112
     (2026-09-19)で付与予定ドメインが作り直されたときに変換されなかった。休暇の変更とは無関係の既存の欠落。
     **是正(実装中の決定)**: 一度に全件を洗い出すため、`leave:rebuild-event-store`の試し実行と`--verify`で、全イベントを
     現行のイベントクラスへ復元できるかを検査し、復元できないものをイベント種別ごとに件数・先頭のid・理由で一覧にする。
     `--apply`の自己整合性の検査は、作り直し後の表に復元できないイベントが1件でもあれば失敗にする。変換規則は論点19で決める。
- 2026-10-14 ユーザー決定(項目1): 作成イベントが無いマスタは、`stored_events`の作り直しの中で、ReadModelの現在の行から作成イベント
  (`group.created`・`group_type.created`・`role.created`・`company_calendar_year.created`)を導出して、その集約の最初のイベントの
  直前に挿入する(欠落した事実の補完。`data-correction`スキル)。除外リストで残す方式は採らない(設計原則2)。導出規則は論点19で決める。
- 2026-10-14 ユーザー決定(続き):
  - **範囲**: 作成イベントの補完は4表に限らず、シーダー・マイグレーションがイベントを経ずに作ったProjectorの書き込み先すべて
    (`permission_role`・`role_assignments`・`group_feature_assignments`・`memberships`・`external_identities`・`field_authorities`等)を
    今回のリリースで扱う。`roles`・`group_types`の`is_system`を表せるよう作成イベントを変更する。全ReadModelを空にして全Projectorを
    再生できる状態をリリースの条件とする(論点19)。
  - **付与予定(PR #109の版のイベント)**: 旧イベントを1件ずつ変換せず、現在のReadModel(マイグレーションで移した
    `paid_leave_schedule_entries`・`paid_leave_schedule_assessments`)から現行の形のイベント列(作成→判定の記録/上書き→付与/取消)を
    導出して置き換える。途中の履歴(その場での再計算・手動修正の日時・NeedsReviewへの移行)は失われ、最終状態は現在の値と一致する。

### 3回目(2026-10-14、1a055c2)

- 試し実行: 旧2,800件→新2,882件。勤怠日の作成の補完17件・既定値nullの補完1,176件。作り直し後の全イベントが現行のクラスへ復元できる。
  `--apply`の自己整合性・復元可否の検査を通過。旧`paid_leave.*`0件・版の欠番0件。
- 3-5: Projectorの書き込み先87表を空にして replay(2,866件)が完走。前回までのエラーは再発しない。
- ReadModelの比較(指紋)で未解決の差が残り、全体は未合格:
  1. `company_calendar_days` 365→426件。増えた61日は年度(2026-05-01〜2027-04-30)の範囲外で、`days_updated`(id 971・1967)の
     payloadに範囲外の日が含まれる。
  2. `company_calendar_day_sources` 760→431件。祝日の`dayChanges`(計1,241件)を、日の行が無いときProjectorが飛ばす。
  3. `company_calendars.is_default`が1件で変化。
  4. `paid_leave_balances` 1→3行、`unallocated_days`に差。
  5. 勤怠の月次・有給申請・通知等で日時の列に差(状態・スナップショットの値は一致)。
  6. 勤怠日の`work_type`の休暇値16件がnull、14日の状態が`clocked_out`→`not_started`: 論点12・2.4で意図した差。
- 1〜5の原因を調査中。日次の再計算・ロールバックの試験・後続の比較は未実施。

### 4回目(2026-10-14、391f2abの上の作業コミット e1484805)

- 試し実行・`--apply`・`--swap`成功(作り直し後2,883件)。新しい検証DB(`flow_office_verify_rerun4`)で replay 2,867件が完走。
- 解消: `company_calendar_days`(365件・内容一致)、`company_calendars.is_default`。休暇ビュー21件・申請との不一致0・孤立0・勤怠日の欠落0。
- 残った差(全体は未合格):
  1. cutover前の有給8件(承認済み・消化記録計7.5日)に充当(`usage_allocated`)が0件で付与に紐づかず、`paid_leave_balances`の未充当が
     計7.5日。旧イベントには`usage_designated`8件はあるが`paid_leave.used`が無い。手順書4(f)・6-3(6)の期待(充当の再現)を満たさない。
  2. `attendance_days.locked_at`: 作り直し前67件に値、再生後はすべてNULL。打刻・実績の無い`clocked_out`が1日残る(B12の期待0)。
  3. 日時の差(月次・申請・通知・ワークフロー等)の多くがちょうど9時間。`notifications.queued_at`はより大きな差もある。
     仮説: これらの列と`stored_events.created_at`はMySQLの`TIMESTAMP`(セッションのタイムゾーンで変換される)で、本番(XSERVER)と
     リハーサル(Docker)のセッションのタイムゾーンが違うと読み書きで9時間ずれる。`meta_data`の`created-at`(文字列)はずれない。
     決定8-5の「2秒以上ずれたら列」の規則が、このずれを正規化と誤認している可能性がある。確認中。
  4. `company_calendar_day_sources.applied_at`57行の差: 決定8-4(発生日時で書く)によるもの。3と同じずれかを確認する。
  5. B12の`no_created`130件は、`live_status_synced`(123件)・`synced_from_punches`(7件)で行が作られる日で、手順書の判定条件の誤り。
     判定条件を行を作る3種のイベントに直した。

### 8回目(2026-10-14、f74e23b)

- 試し実行・`--apply`・`--swap`成功(作り直し後2,886件、全件を現行のクラスへ復元可)。検証DBで全ReadModelの再生が完走。旧イベント2,800件は退避表に残る。
- 有給: cutover前の8件・計7.5日のうち2件・計2日が付与に充当され、5.5日は未充当(付与の割当2日・残数16日)。
  **4回目の後のユーザー確認(「差し引いていない。実際は30.5日」=7.5日すべてを差し引く)と食い違う**ため、原因を確認中(下の未決1)。
- 変換診断の不一致66件(`users.usage_start_date`1・暦の年の作成イベントの欠落1・所属の属性2・イベントにあってReadModelに無い暦の日61・
  暦の既定1)。個別の照合が必要(未決2)。
- 再生でscrubした認証系の行(認証キー5・端末管理セッション6・外部ID5)が戻る。これらはイベントから投影されるReadModelで、
  scrubはReadModelだけに効く。検証DBは機密として扱い、比較結果を保存してから破棄する(手順書に追記)。
- 日の出典760件のidは全件が元と違う(決定10-2で元の乱数のidから決定的なidに変えたため。意図した差)。連続して再生して同じidに
  なることは、同じ検証DBで2回再生して指紋を比べて確かめる(未決3)。
- 手順書のB01〜B15・(a)〜(h)の個別照合と、DB全体のバックアップからの戻しの訓練(B15・B02の比較)は未実施。本番実施の前提として残る。
- 未決: 1. 有給の未充当5.5日の原因(試し実行のレポートの`usage_allocation`の8件分と、その利用者の付与の付与日・期限・日数)。
  2. 変換診断66件の明細(`rebuild-dryrun.txt`の不一致の行)。3. 2回の再生の指紋の一致。
- (ユーザー回答)未充当の2人(a25525ea-3b4e…の4件・3.5日、3b62…の2件・2日)は実際に付与を受けていない。未充当のまま正しい
  (付与なしで承認された有給。承認は残数不足で拒否しない)。今後この2人に付与されたとき、決定10-1により付与の記録時にこの5.5日が
  その付与から差し引かれる。未決1は解消。未決2は決定10-7で解消(照合の修正)。

### 9回目(2026-10-14、b7bee2a相当)

- 試し実行: 解消していない差0件、導出で解消した差3件(No.1・2・5)。verifyで2,868件を2回再生し、指紋が一致(`6719b26b…f45e15`。再現性の確認)。
  所属2件は両方の再生でイベントの値。B01/B02・申請状態・月次の状態は一致。sourceは2,800件のまま(swap・ロールバックの訓練は未実施)。
- イベント数の説明(全件): 2,800件 → 2,884件(+84)= 照合の導出52(group_type 7・field_authority 10・usage_start_date 1・group 4・
  role 6・暦の年 1・permissions 3・role_assignment 4・feature 15・暦の既定 1)+勤怠日の`created`補完17+有給+10(`usage_confirmed`8・
  `usage_allocated`2)+特別休暇+2(`usage_designated`8→10)+代休+3(`usage_confirmed`)。再生2,868件=2,884−`export.created`16(Projectorなし)。
- 日時の差(`UNIX_TIMESTAMP`で−32,400秒): `attendance_months.submitted_at`・`approved_at`、`entity_shares.shared_at`、`attendance_locks`。
  旧版の正規化(`StoredEventHistoryNormalizer`の勤怠の提出・承認の整列)が`stored_events.created_at`列の値(セッションのタイムゾーンで
  読んだJSTの文字列)をReadModelへ直接書いていた。アプリの他の日時はUTCの文字列なので、元の値が9時間進んでおり、再生(metaの
  UTCの文字列)が正しい。決定10-4の「旧版の正規化が直接書いた行の日時」に当たる意図した差。
- 明細による判定(ユーザーの照合結果):
  - 日の出典の日時差57件: 1対1に対応させるといずれも再生後が+1秒(9時間差ではない)。旧Projectorは`applied_at`を投影時の
    `Carbon::now()`で書き、現行はイベントの発生日時(決定8-4)。秒の切り捨て・処理時間による1秒の揺れで、意図した差。
  - 勤怠日18件: 全休の日15件(source manual→leave・clocked_out→not_started・work_typeの休暇値→NULL)、実績のある特別休暇の日2件
    (work_typeの休暇値→NULL)、2026-09-08・09-10(決定9-2a)。休暇値は`attendance_day_leaves`へ移り、日の`work_type`は休暇を
    持たない(決定10-4「休暇値・source・全休の状態」)。意図した差。
  - 代休の消化3件: sourceに代休の付与の行は0件、消化は付与なし・未確定。付与なしの承認済みとして未充当3日が正しい(有給の2人と同じ)。
  - 36表: 新設の表(0件から増える表)、ID の決定化(日の出典・祝日の行)、決定10-4〜10-7の意図した差に分類できる。未分類は
    `users`5件・`external_identities`5件(差のある列が未確認)とワークフロー履歴2件。
  - ワークフロー履歴2件(利用者3b62…の申請のdrafted・submitted): 元は2026-07-31 09:11:43、再生は2026-08-03 06:38:06・:08
    (+2秒は旧版の正規化の`workflowSubmittedAt = draft + 2秒`と同じ形)。旧版の正規化がイベントの時刻を書き換えた可能性があり、
    どちらが事実かをイベントで確かめる(未決)。
  - 勤怠日の2日(利用者の2026-08-13・08-14、manual/clocked_outのまま): 実績はNULLだが同じ日の打刻(4件・2件)があり、作り直しの
    「打刻のある日はsource・statusを写す」に当たる。休暇ビューには各日に取消済みと承認済みの特別休暇の申請があり、結び付きは正しい。
    意図した差(原因1)。ユーザーの業務ルール「全休の日の勤務時間は最終的に0」は、再生後の日次計算(`actual_work_minutes`)で確かめる(未決)。
  - ワークフロー履歴2件: 3イベントともmetaの`created-at`は元の2026-07-31 09:11:43、列は旧版の正規化が書き換えた2026-08-03。
    metaと列のずれは13クラス計308件(列が後の保守の時刻)。発生時刻をmetaに統一する案をユーザーに確認中(決定8-5の改訂)。
  - `external_identities`: Projectorがtenant IDを再生時の`system_settings.m365_tenant_id`から読むため、verify(設定NULL)で5件ともNULL。
    イベントにtenant IDを持たせProjectorはイベントの値を優先する方針にユーザー同意(現在値が過去と同じことを確かめてから確定)。
    `linked_at`は5件とも+32,479秒で原因を確認中。
- 認証系の行の再出現: 検証DBは機密として扱う(8回目と同じ。イベントは消さない)。
