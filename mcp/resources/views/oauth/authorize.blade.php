<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>flow-office 連携の確認</title>
    <style>
        body { font-family: sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; }
        ul { line-height: 1.8; }
        .actions { margin-top: 24px; display: flex; gap: 8px; }
        button { padding: 8px 16px; }
        .approve { background: #1a73e8; color: #fff; border: none; border-radius: 4px; }
        .deny { background: #eee; border: 1px solid #ccc; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>「{{ $clientName }}」への連携を許可しますか?</h1>
    <p>この連携は、あなた自身の以下の操作ができるようになります。他の社員の勤怠にはアクセスできません。</p>
    <ul>
        @foreach ($scopes as $scope)
            <li>{{ $scopeLabels[$scope] ?? $scope }}</li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('authorize.approve') }}">
        @csrf
        @foreach ($fields as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach

        <div class="actions">
            <button type="submit" name="approve" value="1" class="approve">許可する</button>
            <button type="submit" name="approve" value="0" class="deny">拒否する</button>
        </div>
    </form>
</body>
</html>
