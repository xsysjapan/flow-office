<?php

// 運用コマンド`read-models:rebuild`の設定値(docs/changesets/20261025-read-model-rebuild-admin-command/spec.md)の設定値。
return [
    // `running`にした後、テーブルを空にするまで待つ秒数(すでに動いている書き込みの終了待ち)。
    'claim_wait_seconds' => (int) env('READ_MODEL_REBUILD_CLAIM_WAIT_SECONDS', 60),

    // 開始からこの時間(分)を超えたら、コミットせずにロールバックして失敗にする。
    'time_limit_minutes' => (int) env('READ_MODEL_REBUILD_TIME_LIMIT_MINUTES', 180),

    // `running`の記録で、心拍(無ければ開始時刻)がこの時間(分)より古ければ、メンテナンス中とみなさない(プロセスが落ちた場合に解ける)。
    'heartbeat_stale_minutes' => (int) env('READ_MODEL_REBUILD_HEARTBEAT_STALE_MINUTES', 10),
];
