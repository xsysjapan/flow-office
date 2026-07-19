<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Hiragino Kaku Gothic ProN', Meiryo, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; overflow:hidden;">
                    <tr>
                        <td style="padding:24px;">
                            <h1 style="margin:0 0 16px; font-size:18px; color:#111827;">{{ $title }}</h1>
                            <p style="margin:0 0 24px; font-size:14px; line-height:1.7; color:#374151; white-space:pre-line;">{{ $summary }}</p>
                            @if($detailUrl)
                                <table role="presentation" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="border-radius:6px; background-color:#2563eb;">
                                            <a href="{{ $detailUrl }}" style="display:inline-block; padding:10px 20px; font-size:14px; color:#ffffff; text-decoration:none;">詳細を確認する</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
