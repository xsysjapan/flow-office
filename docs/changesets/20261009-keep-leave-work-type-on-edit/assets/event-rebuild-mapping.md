# 休暇まわりの`stored_events`の作り直し(論点18)

調査: 旧イベント・新イベント・集約IDの対応(2026-10-10、investigator)、独立設計レビュー(2026-10-10、blocker8・major9)。
方針: `data-correction`スキルの「`stored_events`の作り直し」。旧イベント列から新しいイベント構成の列を決定的に生成し、
元の発生順にid・版を振り直した表を作り、複製DBで検証してから入れ替える。移行イベント・補正イベントは作らない。

## 1. 前提となる事実

- 集約の再構築は`aggregate_version`順(`config/event-sourcing.php:723`)、`event-sourcing:replay`は`id`順。一意制約は
  `(aggregate_uuid, aggregate_version)`。スナップショットは未使用。
- 本番のイベントはorigin/main系のコードが書いたもの。旧コードは版ごとに挙動が違う(例: 申請時の`*.usage_designated`は14b64bc以前に
  無い、cutover(d5a726b)の前後で有給の記録先が違う)。変換は「あるはずのイベントが無い」並びにも対応する。
- 旧コードは勤怠日をイベントなしで直接作り、`work_type`・全休の`status=clocked_out`を直接書いていた(勤怠のイベントは`calculated`
  だけ)。これらの事実はReadModel(`attendance_days`)にしか無い。
- 旧の承認は充当できる付与があるときだけ`*.used`を書く。旧の取消は承認済みのときだけ`*.usage_reversed`を書く。cutover後の有給の
  差戻しは口座イベントを書かず(Projectorが`workflow_request.returned`を購読)、差し戻された申請は取り消せず、再提出のイベントも無い。
- cutoverの`paid_leave_account.migrated`の付与IDは、入力JSONの`grant_id`が無ければ乱数(`MigratePaidLeaveAccountHandler.php:22`)。
  cutover後のイベントはその付与IDを参照する。

## 2. 変換の単位: 集約・ワークフローごとの状態機械

イベント種別の1対1の対応ではなく、**申請1件(とそのワークフロー)・付与1件・勤怠日1件ごとに旧イベント列を読み、状態機械で新しい
イベント列を組み立てる**。各新イベントは、その事実を表す旧イベントの位置(id順)に置き、`created_at`を引き継ぐ。旧イベントに
無い事実を補う新イベントは、その事実が起きた旧イベントの直後(または直前)に置く。状態機械で扱えない並びが1つでもあれば、試し
実行でその申請・付与・勤怠日を一覧にして失敗させる(推測で埋めない)。

### 2.1 休暇の申請(有給・特別休暇・代休共通)

申請ごとに、申請のイベント(旧`paid_leave.*`/`special_leave.*`/`compensatory_leave.*`の申請系、cutover後の有給は口座イベント)と、
その申請のワークフロー(`leave_request_workflow_links`の元になる`workflow_request.drafted`と`*.request_shared`で特定)の
`workflow_request.*`を合わせて時系列に読む。

| 事実(元にする旧イベント) | 申請集約に置く新イベント | 口座集約に置く新イベント |
|---|---|---|
| 申請(`*.requested`、cutover後の有給は`paid_leave_account.usage_designated`) | `*.requested`(有給は`paid_leave_request.requested`) | `usage_designated`(旧に無ければ申請から合成) |
| 共有(`*.request_shared`) | `*.shared` | — |
| 承認(`*.request_approved`、cutover後は`usage_confirmed`。旧に申請側が無ければ`workflow_request.approved`) | `*.approved` | `usage_confirmed`(**`*.used`の有無にかかわらず必ず**)。続けて充当(`*.used`・`usage_allocated`があれば付与ごとの充当。無ければ未充当量=申請日数) |
| 差戻し(`*.request_returned`、cutover後は`workflow_request.returned`) | `*.returned` | `usage_cancelled`(承認済みの充当があれば先に解放) |
| 差戻しからの再提出(差戻し後の`workflow_request.submitted`) | `*.resubmitted` | 新しいusageIdで`usage_designated`(以後の確定・充当はこの新しいusageIdに付け替える) |
| 取消(`*.request_cancelled`、cutover後の`usage_cancelled`で差戻し中でないもの、`workflow_request.cancelled`) | `*.cancelled` | `usage_cancelled`(**`*.usage_reversed`の有無にかかわらず**、有効な消化があれば。充当済みなら付与ごとに解放) |
| 却下(`workflow_request.rejected`、申請中のもの) | `*.cancelled` | `usage_cancelled` |

- 値の導出(全項目の一覧は5章)。usageIdは`UUIDv5(名前空間, "{種類}:{申請ID}:{再提出の回数}")`(cutover後の乱数のusageIdは
  そのまま引き継ぎ、再提出のときだけ新しく作る)。以後の新規採番も同じ規則にする(申請集約の再提出の回数から決まる)。
- 特別休暇・代休の`*.used`(付与ごとの複数件)は、承認の`usage_confirmed`のallocationsにまとめる。有給は`usage_allocated`を付与ごとに置く。

### 2.2 付与

| 旧イベント | 新イベント(口座集約) |
|---|---|
| `paid_leave.granted` / `grant_revoked` / `warning_raised` | `paid_leave_account.grant_created` / `grant_revoked` / `grant_warning_raised`(grantId=旧付与ID) |
| `special_leave.granted` / `grant_revoked` | `special_leave_account.grant_registered` / `grant_revoked` |
| `compensatory_leave.grant_synced` / `manually_granted` / `grant_removed` / `grant_confirmed` / `grant_cancelled` | `compensatory_leave_account.grant_*`(userIdは同じ付与の作成イベントから) |

### 2.3 有給のcutover(`paid_leave_account.migrated`)

利用者ごとに、cutoverの付与と旧付与(`paid_leave.granted`)を**付与IDで**突き合わせる。

- 付与IDが一致し、かつ「旧付与日数 − Σ旧`used` + Σ旧`usage_reversed` = `remainingDaysAtCutover`」なら、その付与は旧イベントで
  完全に再現できるので、cutoverの行は作らない(cutover前の消化は2.1で充当まで再現される)。
- 付与IDが一致しない付与(システム外から持ち込んだ残高)は`grant_created`(source=`carried_over`、grantId=cutoverの付与ID、
  grantedDays=`remainingDaysAtCutover`、grantedOn=`originalGrantedOn`(nullならcutover日)、cutoverの`mode`・`notes`は`meta_data`)にする。
- 付与IDは一致するが残数が合わない付与は、試し実行で一覧にして失敗させる(リハーサルで原因を確認し、変換規則を決めてから進む)。
- **未充当の充当(論点19 決定10-1。決定9-3aを置き換える)**: 作り直し後の**全イベント**(変換分+変換されずにそのまま写る口座の行)を
  発生順(anchorId・subOrder)に見て、現行の2つの規則を過去に当てはめる(`UnallocatedUsageAllocator`)。cutoverの日付による対象の絞り込みはしない。
  - **確定時の充当**(規則 confirm。現行の`PaidLeaveAccountAggregate::confirmUsage`→`allocateUsage`): 各`usage_confirmed`の時点で、その消化記録に
    入力全体を通じて`usage_allocated`が1件も無いなら、その時点の利用者の付与(付与日・期限・付与日数・取消・充当済み合計)に
    `AllocationPlanner`を当てはめ、使用日数 − 充当済みの範囲で`usage_allocated`を導出する(対象日に有効な付与の残り → 対象日より後に
    付与日がある付与のうち付与日が最も近い1件の残り)。既存の`usage_allocated`を持つ消化記録は確定時の導出をしない(二重充当防止。
    不足分は付与時の規則で扱う)。
  - **付与時の充当**(規則 grant。現行の`PaidLeaveAccountAggregate::allocateUnallocatedOnGrant`。`grant_created`を購読するReactorが発行):
    各`grant_created`の時点で、それより前に確定した(`usage_confirmed`)未取消の消化記録のうち、充当(`usage_allocated`の合計 −
    `usage_allocation_released`の合計)が使用日数(`usage_designated`の`usedDays`)に足りないものへ、確定の古い順に、**その付与の残り**
    (付与の時点の日数 − 有効な充当の合計)の範囲で充当する`usage_allocated`を導出する。付与の有効期間と消化記録の対象日の関係は問わない
    (`GrantTimeAllocationPlanner`)。
  - **取消**(規則 cancel。現行の`PaidLeaveAccountAggregate::cancelUsage`→`allocateUnallocatedUsages`): 各`usage_cancelled`の時点で、その消化記録に
    残っている充当を付与ごとに`usage_allocation_released`で解除する(残り = 充当の合計 − 解除の合計。付与の取消(revoked)済みの分は除く)。
    入力中でその取消より後に既存の`usage_allocation_released`がある付与の分は、二重に解除しないため導出しない。続けて、確定済み・未取消で
    充当が足りない他の消化記録へ、`usedOn`の昇順(同日は消化の指定の順)に`AllocationPlanner`で充当する(規則 cancel)。既存の`usage_allocated`を
    持つ消化記録は再充当の対象外(確定時と同じ)。
    置く位置: 解除は取消の行より前(同じanchorの中で元の行より前、subOrderは負の値)。ただし取消のanchorに充当の行・確定/付与の行があるとき、
    または取消が同じanchorに複数あるときは取消の直後。再充当は取消の直後(subOrderは`SUB_ORDER_BASE`から)。
  - **付与の取消**(規則 grant_revoked。現行の`PaidLeaveAccountAggregate::revokeGrant`): 各`grant_revoked`の時点で、その付与に残っている充当を
    消化記録ごとに`usage_allocation_released`で解除してから取消を記録する(取消済みの消化記録・既に解除されている分・入力中で取消より後に既存の
    解除がある分は導出しない)。続けて、他の未充当の消化記録へ上の再充当と同じ規則で充当する(規則 grant_revoked。取消済みの付与へは充当しない)。
    解除は取消の行より前、再充当は取消の直後(置き場所は取消と同じ判定)。
  - **付与の変更**(規則 grant_changed。現行の`changeGrantAmount`・`changeGrantDate`・`changeGrantExpiry`): 増額・付与日の変更・期限延長の各イベントの
    時点で、上の再充当と同じ規則で未充当の消化記録へ充当する(解除は導出しない。現行も減額で充当を解除しない)。再充当は契機の直後に置く。
  - 付与日・期限は`grant_created`の後の`grant_date_changed`・`grant_expiry_changed`で更新する(現行の集約と同じ)。付与日数は
    `grant_amount_changed`の時点までの値。取消(`grant_revoked`)済みの付与には充当しない。
  - 既存の充当・解除・取消、導出した充当は発生順に状態へ反映する。取消済みの消化記録は対象外。付与の無い利用者の未充当はそのまま
    (付与されたときに付与時の規則で充当される)。`carried_over`の付与も通常の付与と同じに扱う。
  - 置く位置は導出の契機(`usage_confirmed`または`grant_created`)の直後(同じanchorの中でsubOrderを`SUB_ORDER_BASE`から)。発生日時は
    契機のイベントから引き継ぎ、metaも引き継ぐ(metaの`cutover`は除く)。
    集約UUIDは口座(userId)、usageIdは2.1の規則の消化記録のid。`reconcileCutover`の突き合わせはcutover時点の残数の照合で、影響しない。
  - 試し実行のレポート(`usage_allocation`)に、消化記録ごとの一覧(usageId・利用者・対象日・日数・充当した付与と日数・未充当の日数と理由
    (付与なし/付与の残り不足))、充当した件数・日数、未充当の件数・日数を出す。各充当に規則(`confirm`/`grant`/`cancel`/`grant_changed`/`grant_revoked`)を付す(コマンドの表示も同じ)。
- 結果として論点13の8件は通常の承認済み申請と同じく取消できる(2026-10-10 ユーザー決定)。`PaidLeaveRequestAggregate`の
  「移行前の申請の取消を拒否」は削除する。

### 2.4 勤怠(論点12の補正候補(2)〜(4))

旧コードが直接書いた勤怠の事実はReadModelにしか無いため、**ReadModel(`attendance_days`)の現在値を事実の入力として使う**
(この範囲に限る)。

| 状況 | 新しい列 |
|---|---|
| `attendance_day.created`が無い勤怠日 | その勤怠日の最初のイベント(多くは`calculated`)の直前に`attendance_day.created`を置く。source=休暇の処理が作った日なら`leave`、それ以外は現在値。calendarEntryId・utcOffsetMinutesは現在値、createdByUserIdは休暇の申請者(無ければ利用者)、reasonは固定文言「既存データの引き継ぎ」 |
| `created`/`edited`のpayloadの`workType`が休暇値 | その日の作業内容の値に書き換える(休暇値の前の値。無ければnull) |
| ReadModelの`status=clocked_out`が全休のために書かれたもの(実績・打刻なし) | 置く`created`のstatusを実績から決まる値(`not_started`)にする |
| 全休(`leaveType=full`)の休暇申請がある日の`edited`で、payloadの`status=clocked_out`かつ`actualStartAt`・`actualEndAt`がともにnull(論点19 決定9-2) | `status`を`not_started`に書き換える(決定9-2a。実績が両方無い状態は`not_started`。直前の状態は使わない。先に実績ありの`edited`があった後に実績を消した`edited`も`not_started`)。全休の申請が無い日・実績がある`edited`は変えない。実際に値が変わったものだけ、書き換えた件数と対象(勤怠日id・勤務日・イベントid)を試し実行のレポートに出す |
| 休暇の解除で空になった勤怠日 | **後続のイベントが無い場合だけ**、解除の直後に`attendance_day.deleted`を置く(punchLogActionは打刻ログを変えない値、deletedByUserIdは解除の操作者)。後続のイベントがあれば置かない |

日次計算は作り直しの中では変換しない(計算に当時のReadModelが要るため)。作り直しの後に、候補(3)で休暇値を直した日と休暇の
ある日の日次計算を`attendance:recalculate-days`で再計算する。**締め・提出済みの月も再計算する**(2026-10-10 ユーザー決定)。
月次の提出イベントに記録されたスナップショット・週40時間配賦とは差が出るため、差のある月を一覧にして報告する。

### 2.5 既定値nullの項目の補完

作り直しで、新しいコードが既定値nullで受けている項目(特別休暇・代休の申請イベントの`userId`・`targetDate`・`requiresGrant`、
勤怠の計算イベントの`userId`・`workDate`ほか)を全て補完し、作り直し後は既定値・nullガードを削除する。

## 3. 本番データの補正(論点12)

候補(1)(差し戻された休暇の未取消の消化記録)は2.1の差戻しの規則で、候補(2)〜(4)は2.4で、作り直しの中で直す。
`attendance_day.corrected`・`CorrectAttendanceDay`・補正候補の検出コマンドは不要になるので削除する(少数のイベントを直す
`StoredEventCorrector`と補正コマンドの基底`StoredEventCorrectionCommand`・補正ログは、部分的な書き換え用に残す)。

## 4. 作り直し後に削除するもの

- 旧イベントクラスと別名: `paid_leave.*`(11件)、`special_leave.granted`・`grant_revoked`・`usage_designated`・`used`・`usage_reversed`、
  `compensatory_leave.grant_*`・`manually_granted`・`usage_designated`・`used`・`usage_reversed`、`paid_leave_account.migrated`、
  `special_leave_account.migrated`、`compensatory_leave_account.migrated`、`paid_leave_request.migrated`、`attendance_day.corrected`
- 旧集約: `SpecialLeaveGrantAggregate`ほか旧イベントを記録するクラス
- 旧系統を読む処理: `SpecialLeaveGrantProjector`・`SpecialLeaveUsageProjector`・`CompensatoryLeaveGrantProjector`の旧イベント処理と
  `stored_events`の直接読み取り、`PaidLeaveRequestProjector`・`AttendanceDayLeaveProjector`・出勤率ビューの系統の切り替え
  (`input_source`列)、各口座集約の`migrate()`・`migrateGrants()`、`PaidLeaveRequestAggregate`の移行前申請の取消拒否、既定値nullの
  ガード、`LeaveCorrectionCandidateDetector`・`LeaveCorrectionReportCommand`・`onAttendanceDayCorrected`
- 移行の入口: 移行コマンド4つ(`special-leave:migrate-to-account`・`compensatory-leave:migrate-to-account`・`paid-leave:migrate-requests`・
  `paid-leave:migrate-accounts`)、`/paid-leave/migrate` API と `MigratePaidLeaveAccount` のCommand/Handler
- 休暇ビュー(`attendance_day_leaves`)・出勤率ビューの系統を表す`source`列の値(`legacy_paid`・`paid_account`等)と系統の切り替え
  (休暇申請のイベントだけを入力にする。差戻し・取消で行を残し`request_status`を更新する方式は、履歴を画面で追えるため維持する)
- 列: 消化記録の`stored_event_id`、`paid_leave_grants`の移行監査列(cutoverの情報はイベントの`meta_data`に残す)とその表示
- フロントエンドの履歴表示の旧`event_type`名

## 5. 新イベントの項目の導出

| 項目 | 導出元 |
|---|---|
| 申請者(各イベントの`userId`) | 同じ申請の申請イベント |
| 承認者・差戻し者・取消者 | 旧payloadの`approvedByUserId`等、cutover後は`confirmedByUserId`・`cancelledByUserId`、ワークフローのイベントの操作者 |
| 有給申請の`workflowRequestId` | 同じ申請の`*.request_shared`(後続を先読み) |
| `hours`(時間休) | 旧`usedMinutes`÷60 |
| 特別休暇の`requiresGrant`・未充当量 | 承認時点の種別の設定は履歴が無いため、`*.used`があれば残数を要する、無ければ要しないと判定する。未充当量=申請日数−充当合計(残数を要しない種別は0) |
| 代休の充当の分数・未充当の分数 | 旧`used`の`usedMinutes`、申請の`requestedMinutes` |
| 有給付与の`source` | 旧付与は`manual`(定期付与のバッチが記録した付与は`scheduled_batch`。旧payloadの`grantReason`で判定できなければ`manual`)、cutoverの持ち込みは`carried_over`(新しい値。Projector・画面で表示を追加) |
| 取消・差戻しの`reason` | 旧に値があればその値、無ければ固定文言(「差戻しによる取消」等) |
| 勤怠日の`created`の各項目 | 2.4(ReadModelの現在値) |

## 6. id・版の振り直しで影響するもの

- `stored_events.id`を参照する列: `workflow_request_history_entries.stored_event_id`・`expense_claim_history_entries.stored_event_id`
  (一意。リビルドで作り直す)、補正ログ`stored_event_corrections`(作り直しの前に補正ログが0件であることを確認。あれば旧id→新idの
  対応表を残す)、監査ログAPIの`event_id`(外部に返すidが変わることをリリースノートに記載)、`LeaveHistoryQuery`。
- Projectorは`replay`の前に状態を消さない(`resetState`を持つものが0件)。入れ替えの後、全ReadModelのテーブルを空にしてから全
  Projectorを`replay`する(空にするテーブルの一覧はProjectorの書き込み先から機械的に作り、手順書に載せる)。
- `meta_data`: 元のイベントの独自キーを引き継ぎ、`aggregate-root-version`を新しい版に、`aggregate-root-uuid`を行の集約IDに更新する
  (Spatieはイベントの集約IDを`meta_data`から読むため、別の集約へ移したイベントも合わせる)。
  `stored-event-id`を振り直した新しいidに、`created-at`を発生日時(下記。列`created_at`と同じ値)に更新する(Spatieはイベントのstored event idと作成日時も
  `meta_data`から読み、履歴の投影(`workflow_request_history_entries`の`stored_event_id`・`occurred_at`)が使うため。分割・補完で作った行も同じ)。分割で作るイベントは元のイベントの
  `meta_data`を写し、補った事実であることを示すキー(`rebuilt_from`=元のイベントのid)を付ける。
- 発生日時(`meta_data`の`created-at`と列`created_at`の両方に同じ値を入れる。論点19 決定10-8。`StoredEventRow::occurredAt()`。
  決定8-5・9-4を置き換える):
  - 元の行をそのまま写すイベント・payloadだけを書き換えたイベント: 元の行の`meta_data`の`created-at`(Spatieの`ShouldBeStored::createdAt()`。
    Projectorがliveで読んでいた`recordThat`時刻)を常に使う。書式は`created-at`の文字列をそのまま写す。列`created_at`は保存時刻で、
    旧版の正規化・保守のコマンドが後から書き換えた行がある(13クラス計308件。列が後の時刻)ため使わない。
    `created-at`が無い・解釈できない(`strtotime`で読めない)ときだけ列の値を使う。列とのずれ(負のずれ・数日後・9時間等)は問わない。
  - 導出イベント(置き場所のanchorの行から作るもの): anchorの行について上と同じ規則で決めた値。導出側で明示的に指定したもの
    (照合による補完で行の`created_at`を使う作成イベント、付与予定の置き換えで行の時刻を使うもの等)は指定値を優先する。
  - 例外(論点19 決定10-11): `meta_data`に`created-at`が無い`user.*`のイベント(旧版の正規化が2026-08-10にまとめて作った17件。列は
    正規化の実行時刻で発生日時の根拠にならない)は、次の値を明示指定する(`UserEventOccurredAt`。作り直しの最初に全行へ当てはめ、
    `StoredEventRow`の発生日時の上書きとして持たせる。以降の照合・導出イベントの置き場所・写しは、すべて`StoredEventRow::occurredAt()`の同じ値を使う。
    `StoredEventRow::occurredAt()`の規則は他のイベントでは変えない)。
    - `user.created_from_sso_login`・`user.onboarded_as_admin`・`user.hire_date_set`: その利用者(集約UUID=利用者id)の現在の`users`行の
      `created_at`。
    - `user.logged_in`: payloadの`loggedInAt`(書式は`Y-m-d H:i:s`にそろえる)。利用者の行は要らない。
    - 上記以外の`user.*`(`user.migrated_from_legacy`等)、および行が無い・`created_at`が無い・`loggedInAt`が無い・解釈できないもの:
      従来どおり列の値(試し実行のレポートに件数を出す)。
    - 試し実行のレポート(`leave:rebuild-event-store`)は「metaの無いuserイベントの発生日時: 行のcreated_at N件・loggedInAt N件・列のまま N件」
      と出す(列のままの内訳を併記)。
    - 上の明示指定は照合(`UserReconciler`の`usage_start_date`の畳み込み)・導出イベントの置き場所(anchor)・写しの全てに同じ値で効く
      (経路によって時刻が食い違わない)。

## 7. 実行手順

変換コマンド`leave:rebuild-event-store`(仮称)は、旧イベントクラスに依存せず`stored_events`の生のJSONを読む。

1. 事前条件の確認(試し実行で自動): 本番に新しい種類のイベント(`paid_leave_request.*`・`*_account.*`の新種類)が0件、補正ログ0件、
   スナップショット0件。
2. 試し実行(既定): 申請・付与・勤怠日ごとの変換結果の件数、扱えない並びの一覧(あれば失敗)、cutoverの付与の突き合わせ結果
   (再現・`carried_over`・不一致)、差戻し/取消/却下の判定結果を出す。
3. 本番の書き込みを止める: メンテナンスモード、スケジューラ・キューワーカーの停止、打刻端末・API・MCPの受付停止。
4. `--apply`: 新しい表`stored_events_rebuilt`に書く(休暇以外のイベントも同じ順で写す。id・版を振り直す)。
5. 検証は**複製DB**で行う: 複製に`stored_events_rebuilt`を`stored_events`として入れ、全ReadModelを空にして全Projectorをリビルドし、
   本番のReadModel(作り直し前)と比較する。差が「意図した差」(差し戻された休暇の消化記録の取消、休暇値の削除等)だけであることを
   確認する。
6. 入れ替え(`--swap`): `RENAME TABLE stored_events TO stored_events_before_rebuild_YYYYMMDDHHMMSS, stored_events_rebuilt TO stored_events`
   (1文で原子的)。AUTO_INCREMENTを新しい最大id+1にする。
7. 旧系統を削除した新しいコードを配備し、全ReadModelを空にして全Projectorをリビルドする。日次計算を再計算する(2.4)。
8. 確認の後、書き込みを再開する。
- ロールバック: 7までに問題があれば、表を元に戻し(`RENAME TABLE`)、ReadModelを作り直し前のバックアップから戻し、旧コードを
  再配備する(新しいコードは旧イベントを読めないため、表と同時に戻す)。

## 8. リハーサルで確認すること

- 試し実行の結果(扱えない並び0件、cutoverの不一致0件)
- cutoverの付与の`carried_over`の件数と内容
- 差戻し/取消/却下の判定結果をワークフローの状態と突き合わせる
- 複製DBでの比較の差分が意図した差だけであること
- 日次計算の再計算で値が変わる日と、締め・提出済みの月のスナップショットとの差

## 9. 照合(論点19 決定1・決定10-7)

作り直しの後、行(ReadModelの現在値)とイベントの最終状態を照合する(`SeededDataReconciler`)。差を埋める導出イベントは、
同じ照合で置く。試し実行の報告は、差を2つに分ける。

- **導出で解消した差**: 同じ照合で導出イベントを置き、行の値へ揃えたキー。導出した`event_class`を併記する。
- **解消していない差**: 導出イベントを置けず、イベントの最終状態と行が違ったままのキー(例: 暦の日の行が無い)。
  この件数が0であることを確かめる。

差の理由は「列: イベント側の値 → 行の値」を列ごとに書く。値は`null`を`null`と出す。

- **暦の日(`company_calendar_days`)**: イベントは`days_updated`を発生順に畳み込む。`fiscal_year_corrected`は年の日を全て落とす
  (Projectorの`onCompanyCalendarYearFiscalYearCorrected`と同じ。期間の境界は見ない。決定8-1)。訂正の後に設定された日だけが残る。
  訂正の前に設定された日は、期間の内外を問わず訂正で落ちる。行が無い差は日付だけを報告する(導出しない=解消していない差)。
- **暦の年(`company_calendar_years`)**: 年度・期間・暦の差は導出イベントが無いため解消していない差(`fiscal_year_corrected`は操作者が必須)。
  作成イベントが無い年は`company_calendar_year.created`を導出する(導出で解消した差)。
- **所属(`memberships`)**: 在籍・kind・isPrimaryが違うときだけ差にし、`membership.added`を導出する(導出の操作者は行の`created_by`)。
  `created_by`だけが違う所属は差にも導出にもしない(旧版の正規化が行を直接書いた意図した差。論点19 決定10-7)。
- **その他**: 利用者の属性・権限・グループ・暦の既定・外部ID等は、差の列ごとに理由を書き、導出したイベントを併記する。

## 10. 旧イベントへの externalTenantId の補完(論点19 決定9)

Entra IDの外部ID(`external_identities`)の`external_tenant_id`は、旧イベントでは再生時の`system_settings.m365_tenant_id`を
Projectorが読んで書いていた。イベントだけで再現できないため、次の規則で作り直しの中で補う(`ExternalTenantIdRebuilder`)。

- **対象**: `user.onboarded_as_admin`・`user.created_from_sso_login`・`user.synced_from_ms365`・`user.sso_account_linked`のうち、
  payloadに`externalTenantId`のキーが無いもの(旧イベント)。`entraUserId`が無いもの(ローカルの管理者作成)は対象外。
- **補う値**: 現在の`external_identities`の行(`provider` = `MICROSOFT_ENTRA`、`external_subject_id` = イベントの`entraUserId`)の
  `external_tenant_id`。行のテナントIDは、リンクの投影(その時点の設定値、またはイベントの値)でしか書かれないため、リンク時点の値である。
- **前提の検査(満たさなければ止めて報告。推測で埋めない)**:
  1. 現在のMICROSOFT_ENTRAの行の`external_tenant_id`は、null以外で1種類であること(行がある場合)。
  2. 設定値(`system_settings.m365_tenant_id`)がnullでないときは、行のテナントIDと一致すること(大文字小文字は区別しない)。
     設定値がnullのときは一致を検査せず、行の値のみで補完する(試し実行のレポートに「設定が空のため行の値のみで補完」と出す。
     リハーサルのsourceは設定がNULLだったため)。
- **補わないもの(payloadをそのまま残す。Projectorは再生時に設定値を読む)**: 行が無いイベント(件数を報告)、行の`external_tenant_id`が
  nullのイベント(件数を報告)、既にキーのあるイベント(新しい記録。件数を報告)。
- **置き方**: 元の行の位置(anchor・subOrder 0)に、payloadへ`externalTenantId`を足した行を置き、元の行を消す(発生日時・版は元の行のまま)。
- **報告**: 試し実行のレポート(`leave:rebuild-event-store`)に「前提の検査(checked / row_only / no_rows)・行のテナントID・
  イベント種別ごとの補った件数・行が無く補わなかった件数・tenantがnullで補わなかった件数・既に記録済みの件数」を出す。
- **確認**: 補った後の行を`EventRestorabilityChecker::checkRows`で現行のイベントクラスへ復元できること(`--apply`の検査でも行う)。
