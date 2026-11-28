<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\UpdateUserDetails;
use App\Domain\UserManagement\Services\FieldAuthorityService;
use App\Domain\UserManagement\Services\UserProfileGuard;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * 利用者詳細のまとめて保存。外部人事の項目の権限・最後の有効なシステム管理者の無効化の禁止・入社日と退社日の
 * 最終状態の検査をして、利用者の集約に今と同じイベントを、送られた項目だけ記録する。
 * 拒否は項目のキー付きの422(ValidationException)。途中の状態の検査は無いので、記録の順序の調整は要らない
 * (docs/changesets/20261128-single-command-entrypoints/spec.md 論点1)。
 *
 * @implements CommandHandler<UpdateUserDetails>
 */
class UpdateUserDetailsHandler implements CommandHandler
{
    private const DATE_KEYS = ['hire_date', 'termination_date', 'usage_start_date'];

    public function __construct(
        private FieldAuthorityService $authorities,
        private UserProfileGuard $guard,
    ) {}

    public function handle(Command $command): User
    {
        assert($command instanceof UpdateUserDetails);

        $user = User::query()->findOrFail($command->userId);
        $profile = $command->profile;
        $fields = $command->fields;

        $errors = $this->collectErrors($user, $profile, $fields);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $aggregate = UserAggregate::retrieve($user->id);
        $actor = $command->changedByUserId;
        $recorded = false;

        if ($profile !== []) {
            $before = $user->only(array_keys($profile));
            $aggregate->updateProfile($before, array_replace($before, $profile), $actor);
            $recorded = true;
        }
        if (array_key_exists('hire_date', $fields)) {
            $aggregate->setHireDate($fields['hire_date'], $actor);
            $recorded = true;
        }
        if (array_key_exists('termination_date', $fields)) {
            $aggregate->setTerminationDate($fields['termination_date'], $actor);
            $recorded = true;
        }
        if (array_key_exists('usage_start_date', $fields)) {
            $aggregate->setUsageStartDate($fields['usage_start_date'], $actor);
            $recorded = true;
        }
        if (array_key_exists('paid_leave_auto_grant_enabled', $fields)) {
            $aggregate->setPaidLeaveAutoGrantEnabled((bool) $fields['paid_leave_auto_grant_enabled'], $actor);
            $recorded = true;
        }
        if (array_key_exists('special_leave_auto_grant_enabled', $fields)) {
            $aggregate->setSpecialLeaveAutoGrantEnabled((bool) $fields['special_leave_auto_grant_enabled'], $actor);
            $recorded = true;
        }

        if ($recorded) {
            $aggregate->persist();
        }

        return $user->refresh();
    }

    /**
     * @return array<string, list<string>>
     */
    private function collectErrors(User $user, array $profile, array $fields): array
    {
        $errors = [];

        // 外部人事が管理する項目は、項目ごとに確かめて、その項目のキーで拒否する。
        foreach (array_keys($profile) as $key) {
            try {
                $this->authorities->assertLocallyEditable([$key]);
            } catch (DomainRuleException $e) {
                $errors["profile.{$key}"] = [$e->getMessage()];
            }
        }
        foreach (self::DATE_KEYS as $key) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }
            try {
                $this->authorities->assertLocallyEditable([$key]);
            } catch (DomainRuleException $e) {
                $errors[$key] = [$e->getMessage()];
            }
        }

        // 入社日・利用開始日は値が必須(null にはできない。集約が文字列だけを受ける)。
        foreach (['hire_date' => '入社日', 'usage_start_date' => '利用開始日'] as $key => $label) {
            if (array_key_exists($key, $fields) && $fields[$key] === null && ! isset($errors[$key])) {
                $errors[$key] = ["{$label}は日付を指定してください。"];
            }
        }

        try {
            $this->guard->assertNotDisablingLastSystemAdministrator($user, $profile);
        } catch (DomainRuleException $e) {
            $errors[array_key_exists('account_status', $profile) ? 'profile.account_status' : 'profile'] = [$e->getMessage()];
        }

        // 最後の状態で入社日 ≤ 退社日になっているか。
        $normalize = fn (mixed $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString();
        $hire = array_key_exists('hire_date', $fields) ? $normalize($fields['hire_date']) : $user->hire_date?->toDateString();
        $termination = array_key_exists('termination_date', $fields) ? $normalize($fields['termination_date']) : $user->termination_date?->toDateString();
        if ($hire !== null && $termination !== null && $hire > $termination && ! isset($errors['hire_date']) && ! isset($errors['termination_date'])) {
            if (array_key_exists('termination_date', $fields)) {
                $errors['termination_date'] = ['退社日は入社日以降の日付を指定してください。'];
            } else {
                $errors['hire_date'] = ['入社日は退社日以前の日付を指定してください。'];
            }
        }

        return $errors;
    }
}
