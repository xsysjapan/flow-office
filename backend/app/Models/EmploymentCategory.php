<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 雇用区分(正社員/契約社員/パート/アルバイト/嘱託等)。
 * 労働時間制度(work_styles.work_time_system)とは独立した軸として管理する
 * (雇用区分だけで残業計算や適用除外を決定しない)。
 */
#[Fillable(['code', 'name'])]
class EmploymentCategory extends Model
{
    public const REGULAR = 'regular';

    public const CONTRACT = 'contract';

    public const PART_TIME = 'part_time';

    public const TEMPORARY = 'temporary';

    public const COMMISSIONED = 'commissioned';

    public const OTHER = 'other';

    /**
     * @return array<int, string>
     */
    public static function defaultCodes(): array
    {
        return [
            self::REGULAR,
            self::CONTRACT,
            self::PART_TIME,
            self::TEMPORARY,
            self::COMMISSIONED,
            self::OTHER,
        ];
    }
}
