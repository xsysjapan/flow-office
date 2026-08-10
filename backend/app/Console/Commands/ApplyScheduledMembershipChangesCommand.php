<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\UserManagement\Commands\ApplyMembershipChange;
use App\Domain\UserManagement\Commands\FailMembershipChange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApplyScheduledMembershipChangesCommand extends Command
{
    protected $signature = 'user-management:apply-membership-changes {--limit=100}';

    protected $description = '期限到来済みの所属変更セットを原子的に適用する';

    public function handle(CommandBus $bus): int
    {
        $sets = DB::table('membership_change_sets')->where('status', 'scheduled')->where('effective_at', '<=', now())->orderBy('effective_at')->limit((int) $this->option('limit'))->get();
        foreach ($sets as $set) {
            $actor = $set->created_by ?: $set->user_id;
            try {
                $bus->dispatch(new ApplyMembershipChange($set->id, $actor));
            } catch (Throwable $e) {
                try {
                    $bus->dispatch(new FailMembershipChange($set->id, mb_substr($e->getMessage(), 0, 2000), $actor));
                } catch (Throwable) {
                } $this->error("{$set->id}: {$e->getMessage()}");
            }
        }
        $this->info($sets->count().'件を処理しました。');

        return self::SUCCESS;
    }
}
