<?php

namespace App\Models;

/** assets.lending_method の許容値(management_type=lendingの備品のみ使用)。 */
final class AssetLendingMethod
{
    public const SELF_SERVICE = 'self_service';

    public const BACKOFFICE = 'backoffice';

    public const APPROVAL = 'approval';
}
