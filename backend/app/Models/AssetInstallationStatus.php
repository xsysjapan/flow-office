<?php

namespace App\Models;

/** assets.installation_status の許容値(management_type=installationの備品のみ使用)。 */
final class AssetInstallationStatus
{
    public const STORED = 'stored';

    public const INSTALLED = 'installed';

    public const REPAIR = 'repair';

    public const LOST = 'lost';

    public const DISPOSED = 'disposed';
}
