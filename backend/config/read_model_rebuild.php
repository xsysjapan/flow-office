<?php

// 管理画面からのReadModelの作り直し(docs/changesets/20261025-admin-read-model-rebuild/spec.md)の設定値。
return [
    // 取り出して`running`にした後、テーブルを空にするまで待つ秒数(すでに動いている書き込みの終了待ち)。
    'claim_wait_seconds' => (int) env('READ_MODEL_REBUILD_CLAIM_WAIT_SECONDS', 60),

    // 開始からこの時間(分)を超えたら、コミットせずにロールバックして失敗にする。
    'time_limit_minutes' => (int) env('READ_MODEL_REBUILD_TIME_LIMIT_MINUTES', 180),

    // `running`の記録で、心拍がこの時間(分)より古ければ失敗とみなす(メンテナンスも解ける)。
    'heartbeat_stale_minutes' => (int) env('READ_MODEL_REBUILD_HEARTBEAT_STALE_MINUTES', 10),

    // `queued`のまま、作成からこの時間(分)を超えた記録は失敗とみなす(判定の実行を待っている間は除く)。
    'queued_timeout_minutes' => (int) env('READ_MODEL_REBUILD_QUEUED_TIMEOUT_MINUTES', 30),
];
