<?php

namespace App\Models;

final class PaidLeaveGrantStatus
{
    /** 有効な付与。 */
    public const ACTIVE = 'active';

    /** 管理者により取り消された付与。 */
    public const REVOKED = 'revoked';
}
