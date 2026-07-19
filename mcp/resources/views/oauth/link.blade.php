<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>flow-office 連携</title>
    <style>
        body { font-family: sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; }
        label { display: block; margin-top: 12px; font-weight: bold; }
        textarea, input[type=text] { width: 100%; padding: 8px; box-sizing: border-box; }
        .scope { font-weight: normal; margin: 4px 0; }
        .error { color: #b00020; }
        button { margin-top: 16px; padding: 8px 16px; }
    </style>
</head>
<body>
    <h1>flow-office 連携トークンの登録</h1>
    <p>
        flow-officeのフロントエンド「アプリ・API連携」画面(または
        <code>POST /users/me/integrations</code>)で発行した個人連携トークン(<code>client_type: mcp_client</code>)を
        貼り付けてください。発行時に選んだスコープと同じものを、下でも選んでください
        (mcp/側では選択内容を照合できないため、自己申告となります)。
    </p>

    @if (session('status'))
        <p>{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p class="error">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="{{ route('link.store') }}">
        @csrf
        <input type="hidden" name="redirect" value="{{ $redirect }}">

        <label for="token">連携トークン</label>
        <textarea id="token" name="token" rows="3" required></textarea>

        <label>付与されているスコープ</label>
        @foreach ($scopeLabels as $scope => $label)
            <div class="scope">
                <label style="font-weight: normal;">
                    <input type="checkbox" name="scopes[]" value="{{ $scope }}" {{ $scope === 'profile:self:read' ? 'checked disabled' : '' }}>
                    {{ $scope }} — {{ $label }}
                </label>
                @if ($scope === 'profile:self:read')
                    <input type="hidden" name="scopes[]" value="{{ $scope }}">
                @endif
            </div>
        @endforeach

        <button type="submit">紐付ける</button>
    </form>
</body>
</html>
