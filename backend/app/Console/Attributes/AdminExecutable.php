<?php

namespace App\Console\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AdminExecutable
{
    /**
     * @param  array<string, list<string>>  $rules
     * @param  array<string, array<string, mixed>>  $ui
     */
    public function __construct(
        public readonly string $label,
        public readonly array $rules = [],
        public readonly array $ui = [],
        public readonly bool $withoutOverlapping = true,
    ) {}
}
