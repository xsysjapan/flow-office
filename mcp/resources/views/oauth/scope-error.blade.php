<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>flow-office 連携エラー</title>
    <style>body { font-family: sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; }</style>
</head>
<body>
    <h1>要求された権限が不足しています</h1>
    <p>
        この連携が要求している以下の権限は、あなたが登録したflow-officeの連携トークンには
        含まれていません。
    </p>
    <ul>
        @foreach ($missingScopes as $scope)
            <li>{{ $scopeLabels[$scope] ?? $scope }}</li>
        @endforeach
    </ul>
    <p>
        flow-officeの「アプリ・API連携」画面で、不足しているスコープを含めてトークンを
        再発行し、<a href="{{ route('link.show') }}">連携トークンの登録</a>からやり直してください。
    </p>
</body>
</html>
