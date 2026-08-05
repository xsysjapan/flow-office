<?php

namespace App\Models;

final class CompensatoryLeaveGrantStatus
{
    /** 勤怠実績から自動導出された直後の状態。月次未提出のため消化申請の対象にならない。 */
    public const DRAFT = 'draft';

    /** 月次提出時に確定した状態。消化申請の対象になる。 */
    public const CONFIRMED = 'confirmed';

    /** 未使用のまま取消申請が承認された状態。 */
    public const CANCELLED = 'cancelled';
}
