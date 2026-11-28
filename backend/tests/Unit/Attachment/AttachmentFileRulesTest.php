<?php

namespace Tests\Unit\Attachment;

use App\Domain\Attachment\Support\AttachmentFileRules;
use PHPUnit\Framework\TestCase;

class AttachmentFileRulesTest extends TestCase
{
    public function test_size_boundary(): void
    {
        $this->assertNull(AttachmentFileRules::violation(10, null, 10 * 1024, 'pdf'));
        $this->assertNotNull(AttachmentFileRules::violation(10, null, 10 * 1024 + 1, 'pdf'));
    }

    public function test_extension_is_case_insensitive_and_null_means_unrestricted(): void
    {
        $this->assertNull(AttachmentFileRules::violation(10, ['pdf'], 1, 'PDF'));
        $this->assertNotNull(AttachmentFileRules::violation(10, ['pdf'], 1, 'exe'));
        $this->assertNull(AttachmentFileRules::violation(10, null, 1, 'exe'));
    }

    public function test_expense_item_defaults(): void
    {
        $this->assertNull(AttachmentFileRules::expenseItemViolation(1024, 'jpeg'));
        $this->assertNotNull(AttachmentFileRules::expenseItemViolation(1024, 'gif'));
        $this->assertNotNull(AttachmentFileRules::expenseItemViolation(10241 * 1024, 'pdf'));
    }
}
