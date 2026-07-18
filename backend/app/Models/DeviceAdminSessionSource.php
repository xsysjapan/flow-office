<?php

namespace App\Models;

/**
 * device_admin_sessions.source。管理者モードへ入った経路。
 */
final class DeviceAdminSessionSource
{
    /** 端末アクティベーション直後のブートストラップ登録(管理者ICカードがまだ無い/確認できない場合) */
    public const BOOTSTRAP = 'bootstrap';

    /** 登録済みの管理者ICカード(認証キー)をかざして開始 */
    public const NFC_TAP = 'nfc_tap';
}
