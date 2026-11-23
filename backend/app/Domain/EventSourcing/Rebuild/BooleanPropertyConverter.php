<?php

namespace App\Domain\EventSourcing\Rebuild;

use stdClass;

/**
 * 旧項目名で記録された stored_events の event_properties を、新しい項目名へ書き換える純粋な変換
 * (docs/changesets/20261123-boolean-column-naming/spec.md 論点4・5。DBは読み書きしない)。
 *
 * 変換表(RULES)がコードの正。キーは stored_events.event_class(config/event-sourcing.php の別名)。
 * - top: トップレベルのプロパティの旧名 => 新名
 * - defaults: 旧名のキーが無い(新名も無い)行へ補う新名 => 値(旧 Projector/Handler が欠落時に使っていた値)。旧名の値が null の行も、この値にする
 * - arrays: 配列のプロパティ名 => ['rename' => 旧キー => 新キー, 'defaults' => 新キー => 補う値]。配列そのものが無い(JSONオブジェクトでない)行は何もしない
 *
 * 値は変えない。表に無いイベント・キーは変えない。新キーが既にあれば何もしない(冪等)。旧キーと新キーが両方あれば conflict。トップレベルの必須の新キーが旧キーも無く補う値も無ければ unfillable(変換しない)。
 * JSONは stdClass で読み、`{}` と `[]` の区別・キーの順序を保つ(旧キーは同じ位置で新キーへ置き換える)。
 */
final class BooleanPropertyConverter
{
    /**
     * @var array<string, array{top?: array<string, string>, defaults?: array<string, bool>, arrays?: array<string, array{rename: array<string, string>, defaults?: array<string, bool>}>}>
     */
    public const RULES = [
        'device.registered' => ['top' => self::DEVICE],
        'device.settings_updated' => ['top' => self::DEVICE],
        'attendance_punch.recorded' => [
            'top' => ['offline' => 'isOffline'],
            'defaults' => ['isOffline' => false],
        ],
        'company_calendar.created' => self::CALENDAR,
        'company_calendar.updated' => self::CALENDAR,
        'group_type.created' => ['top' => ['primaryMembershipRequired' => 'requiresPrimaryMembership']],
        'group_type.updated' => ['top' => ['primaryMembershipRequired' => 'requiresPrimaryMembership']],
        'role_assignment.created' => ['top' => ['includeDescendants' => 'includesDescendants']],
        'role_assignment.updated' => ['top' => ['includeDescendants' => 'includesDescendants']],
        'asset_number_rule.configured' => ['top' => ['enabled' => 'isActive']],
        'work_style.created' => [
            'arrays' => ['attributes' => ['rename' => ['system_generated' => 'is_system_generated']]],
        ],
        'attendance_day.calculated' => [
            'arrays' => ['calculation' => [
                'rename' => ['core_time_violation' => 'has_core_time_violation'],
                'defaults' => ['has_core_time_violation' => false],
            ]],
        ],
        'system_settings.updated' => [
            'arrays' => [
                'before' => ['rename' => ['prohibit_self_privileged_role_assignment' => 'prohibits_self_privileged_role_assignment']],
                'after' => ['rename' => ['prohibit_self_privileged_role_assignment' => 'prohibits_self_privileged_role_assignment']],
            ],
        ],
        'external_integration_connection.created' => ['arrays' => ['after' => ['rename' => ['enabled' => 'publishing_enabled']]]],
        'external_integration_connection.updated' => [
            'arrays' => [
                'before' => ['rename' => ['enabled' => 'publishing_enabled']],
                'after' => ['rename' => ['enabled' => 'publishing_enabled']],
            ],
        ],
        'external_integration_connection.deleted' => ['arrays' => ['before' => ['rename' => ['enabled' => 'publishing_enabled']]]],
    ];

    private const DEVICE = [
        'allowOffline' => 'allowsOffline',
        'requireLocation' => 'requiresLocation',
        'autoDetectPunchType' => 'punchTypeAutoDetectionEnabled',
    ];

    private const CALENDAR = [
        'top' => ['allowDailyHolidayOverride' => 'allowsDailyHolidayOverride'],
        'defaults' => ['allowsDailyHolidayOverride' => true],
    ];

    /** @return list<string> 変換の対象になる event_class */
    public static function eventClasses(): array
    {
        return array_keys(self::RULES);
    }

    public function convert(string $eventClass, string $json): BooleanPropertyConversion
    {
        $rule = self::RULES[$eventClass] ?? null;
        $payload = $rule === null ? null : json_decode($json);
        if ($rule === null || ! $payload instanceof stdClass) {
            return new BooleanPropertyConversion(null, [], false, null);
        }

        $changes = [];
        $filled = false;
        $conflict = null;

        $payload = $this->apply($payload, $rule['top'] ?? [], $rule['defaults'] ?? [], '', $changes, $filled, $conflict);
        foreach ($rule['arrays'] ?? [] as $name => $arrayRule) {
            if (! isset($payload->{$name}) || ! $payload->{$name} instanceof stdClass) {
                continue;
            }
            $payload->{$name} = $this->apply($payload->{$name}, $arrayRule['rename'], $arrayRule['defaults'] ?? [], "{$name}.", $changes, $filled, $conflict);
        }

        // 改名で必須にしたトップレベルの項目が、旧キーも無く補う値も無いため欠けている行(過去の事実が決められないので変換しない)。
        $unfillable = null;
        foreach ($rule['top'] ?? [] as $old => $new) {
            if (! property_exists($payload, $new) && ! array_key_exists($new, $rule['defaults'] ?? [])) {
                $unfillable = "{$old} も {$new} も無く、補う値が無い";
                break;
            }
        }

        if ($conflict !== null || $unfillable !== null || $changes === []) {
            return new BooleanPropertyConversion(null, $changes, $filled, $conflict, $unfillable);
        }

        return new BooleanPropertyConversion($this->encode($payload), $changes, $filled, null);
    }

    /**
     * @param  array<string, string>  $rename
     * @param  array<string, bool>  $defaults
     * @param  list<string>  $changes
     */
    private function apply(stdClass $object, array $rename, array $defaults, string $prefix, array &$changes, bool &$filled, ?string &$conflict): stdClass
    {
        $vars = get_object_vars($object);
        foreach ($rename as $old => $new) {
            $hasOld = array_key_exists($old, $vars);
            $hasNew = array_key_exists($new, $vars);
            if ($hasOld && $hasNew) {
                $conflict ??= "{$prefix}{$old} と {$prefix}{$new} が両方ある";

                continue;
            }
            if ($hasOld) {
                $value = $vars[$old];
                $newValue = $value;
                $note = '';
                if ($value === null && array_key_exists($new, $defaults)) {
                    $newValue = $defaults[$new];
                    $filled = true;
                    $note = '(null を既定値に)';
                }
                $renamed = [];
                foreach ($vars as $key => $item) {
                    if ((string) $key === $old) {
                        $renamed[$new] = $newValue;
                    } else {
                        $renamed[$key] = $item;
                    }
                }
                $vars = $renamed;
                $changes[] = "{$prefix}{$old}={$this->show($value)} → {$prefix}{$new}={$this->show($newValue)}{$note}";
            } elseif (! $hasNew && array_key_exists($new, $defaults)) {
                $vars[$new] = $defaults[$new];
                $filled = true;
                $changes[] = "(なし) → {$prefix}{$new}={$this->show($defaults[$new])}(補完)";
            }
        }

        return (object) $vars;
    }

    private function show(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function encode(stdClass $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
