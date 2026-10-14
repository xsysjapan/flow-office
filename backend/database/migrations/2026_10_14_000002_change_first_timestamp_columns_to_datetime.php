<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ReadModel(Projector の書き込み先)で、表の最初の TIMESTAMP 列が NOT NULL かつ既定値なしのものを DATETIME に変える。
 *
 * MySQL の explicit_defaults_for_timestamp=OFF では、表で最初の TIMESTAMP 列が NOT NULL・既定値の指定なしだと
 * DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP が自動で付く。その列を含まない UPDATE(投影)で
 * payload の値が更新時刻に上書きされるため(リハーサル7回目で membership_change_sets.effective_at・
 * notifications.queued_at が再生時刻になった)、DATETIME に変えて自動更新を外す。
 * ReadModel なので、再生すると payload の値で正しく埋まる。
 *
 * 対象は Projector が必ず値を入れる列のみ(Eloquent の create/updateOrCreate・DB::table の insert/updateOrInsert で
 * 指定されていることを確認済み)。ほかの TIMESTAMP 列は nullable のため対象外。
 */
return new class extends Migration
{
    /** @var array<string, array{table: string, column: string}> */
    private const COLUMNS = [
        'asset_default_location_changes.changed_at' => ['table' => 'asset_default_location_changes', 'column' => 'changed_at'],
        'asset_loans.loaned_at' => ['table' => 'asset_loans', 'column' => 'loaned_at'],
        'asset_placements.started_at' => ['table' => 'asset_placements', 'column' => 'started_at'],
        'calendar_bulk_operation_targets.created_at' => ['table' => 'calendar_bulk_operation_targets', 'column' => 'created_at'],
        'company_calendar_day_sources.created_at' => ['table' => 'company_calendar_day_sources', 'column' => 'created_at'],
        'entity_shares.shared_at' => ['table' => 'entity_shares', 'column' => 'shared_at'],
        'expense_claim_history_entries.occurred_at' => ['table' => 'expense_claim_history_entries', 'column' => 'occurred_at'],
        'workflow_request_history_entries.occurred_at' => ['table' => 'workflow_request_history_entries', 'column' => 'occurred_at'],
        'membership_change_sets.effective_at' => ['table' => 'membership_change_sets', 'column' => 'effective_at'],
        'notifications.queued_at' => ['table' => 'notifications', 'column' => 'queued_at'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $target) {
            Schema::table($target['table'], function (Blueprint $table) use ($target) {
                $table->dateTime($target['column'])->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $target) {
            Schema::table($target['table'], function (Blueprint $table) use ($target) {
                $table->timestamp($target['column'])->change();
            });
        }
    }
};
