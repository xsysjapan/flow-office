<?php

namespace App\Domain\Export\Contracts;

/** ExternalPublisher::publish() の結果。 */
final class PublishedArtifact
{
    public function __construct(
        public readonly string $content,
        public readonly string $filename,
        /** 内部保存した場合の相対パス(CsvFilePublisherのようにダウンロード専用の場合はnull)。 */
        public readonly ?string $storedPath = null,
    ) {}
}
