<?php

namespace App\Domain\Export\Services\Publishers;

use App\Domain\Export\Contracts\ExternalPublisher;
use App\Domain\Export\Contracts\PublishedArtifact;

/** CSV/TSVをダウンロード用にそのまま返す(内部保存はしない)。 */
class CsvFilePublisher implements ExternalPublisher
{
    public function key(): string
    {
        return 'csv_file';
    }

    public function publish(string $content, string $filename, array $context = []): PublishedArtifact
    {
        return new PublishedArtifact($content, $filename);
    }
}
