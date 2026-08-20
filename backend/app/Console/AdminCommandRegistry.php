<?php

namespace App\Console;

use App\Console\Attributes\AdminExecutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use ReflectionClass;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class AdminCommandRegistry
{
    public function __construct(private readonly Kernel $kernel) {}

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $result = [];
        foreach ($this->kernel->all() as $name => $command) {
            if (! $command instanceof Command) {
                continue;
            }
            $attributes = (new ReflectionClass($command))->getAttributes(AdminExecutable::class);
            if ($attributes === []) {
                continue;
            }
            $attribute = $attributes[0]->newInstance();
            $definition = $command->getNativeDefinition();
            $parameters = [];
            foreach ($definition->getArguments() as $argument) {
                $parameters[] = $this->argumentMetadata($argument, $attribute);
            }
            foreach ($definition->getOptions() as $option) {
                $parameters[] = $this->optionMetadata($option, $attribute);
            }
            $rules = [];
            foreach ($parameters as $parameter) {
                $base = [$parameter['required'] ? 'required' : 'nullable'];
                if (($parameter['accepts_value'] ?? true) === false) {
                    $base[] = 'boolean';
                } elseif ($parameter['array']) {
                    $base[] = 'array';
                }
                $rules[$parameter['name']] = array_values(array_unique([...$base, ...($attribute->rules[$parameter['name']] ?? [])]));
            }
            $result[$name] = [
                'name' => $name,
                'label' => $attribute->label,
                'description' => $command->getDescription(),
                'parameters' => $parameters,
                'without_overlapping' => $attribute->withoutOverlapping,
                'rules' => $rules,
            ];
        }
        ksort($result);

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function find(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /** @return array<string, mixed> */
    private function argumentMetadata(InputArgument $argument, AdminExecutable $attribute): array
    {
        return [
            'name' => $argument->getName(), 'kind' => 'argument',
            'required' => $argument->isRequired(), 'array' => $argument->isArray(),
            'default' => $argument->getDefault(), 'description' => $argument->getDescription(),
            'ui' => $attribute->ui[$argument->getName()] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function optionMetadata(InputOption $option, AdminExecutable $attribute): array
    {
        return [
            'name' => $option->getName(), 'kind' => 'option',
            'required' => false, 'array' => $option->isArray(),
            'accepts_value' => $option->acceptValue(), 'value_required' => $option->isValueRequired(),
            'default' => $option->getDefault(), 'description' => $option->getDescription(),
            'ui' => $attribute->ui[$option->getName()] ?? [],
        ];
    }
}
