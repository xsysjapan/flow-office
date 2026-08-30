<?php

namespace App\Models;

/** assets.lending_status の許容値(management_type=lendingの備品のみ使用)。 */
final class AssetLendingStatus
{
    public const AVAILABLE = 'available';

    public const LOANED = 'loaned';

    public const REPAIR = 'repair';

    public const LOST = 'lost';

    public const DISPOSED = 'disposed';
}
