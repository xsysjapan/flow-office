<?php

namespace App\Models;

/**
 * asset_loan_requests.status の許容値(spec「状態遷移」)。
 * すべてworkflow_requests側イベントの反映結果であり、備品ドメイン自身のCommandでは
 * 発生しない(asset.loanedのみ例外で、承認済み申請をlentに反映する)。
 */
final class AssetLoanRequestStatus
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const CANCELLED = 'cancelled';

    public const LENT = 'lent';

    /**
     * 承認待ちまたは承認済み未貸与(削除可否ガード等が「進行中」とみなす状態)。
     *
     * @return array<int, string>
     */
    public static function active(): array
    {
        return [self::PENDING, self::APPROVED];
    }
}
