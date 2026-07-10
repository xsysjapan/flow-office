<?php

namespace App\Domain\User\Graph;

/**
 * Microsoft Graph /users から取得する1ユーザーの必要項目のみを持つDTO。
 */
final class MicrosoftGraphUser
{
    public function __construct(
        public readonly string $entraUserId,
        public readonly string $displayName,
        public readonly ?string $mail,
        public readonly ?string $department,
        public readonly ?string $jobTitle,
        public readonly bool $accountEnabled,
    ) {}

    public function employmentStatus(): string
    {
        return $this->accountEnabled ? 'active' : 'retired';
    }
}
