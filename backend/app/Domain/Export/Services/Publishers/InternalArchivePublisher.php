<?php

namespace App\Domain\Export\Services\Publishers;

use App\Domain\Export\Contracts\ExternalPublisher;
use App\Domain\Export\Contracts\PublishedArtifact;
use Illuminate\Support\Facades\Storage;

/**
 * 証跡アーカイブ(ExpenseExcelBuilder等の出力)をローカルストレージへ内部保存する。
 * 外部システムへは送信しない。保存先は storage/app/internal-archives/expenses/。
 */
class InternalArchivePublisher implements ExternalPublisher
{
    private const DISK = 'local';

    private const DIRECTORY = 'internal-archives/expenses';

    public function key(): string
    {
        return 'internal_archive';
    }

    public function publish(string $content, string $filename, array $context = []): PublishedArtifact
    {
        $path = self::DIRECTORY.'/'.$filename;
        Storage::disk(self::DISK)->put($path, $content);

        return new PublishedArtifact($content, $filename, $path);
    }
}
