<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 既定の会社カレンダーの年度開始月日を、有給(付与予定)の文脈へ写した読み取りビュー(1行。id=1)。
 * `PaidLeaveFiscalYearSettingsProjector`がイベントから作る(docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点3)。
 */
#[Fillable(['id', 'company_calendar_id', 'fiscal_year_start_month', 'fiscal_year_start_day', 'last_changed_event_id'])]
class PaidLeaveFiscalYearSetting extends Model
{
    public const SINGLETON_ID = 1;

    public $incrementing = false;

    protected $keyType = 'int';
}
