<?php

namespace App\Domain\EventSourcing;

use App\Domain\EventSourcing\Contracts\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Command → CommandHandler の解決とディスパッチ、およびDBトランザクション境界を担う。
 * ハンドラの対応表は config/domain.php の 'command_handlers' で定義する。
 */
class CommandBus
{
    public function __construct(private readonly Container $container) {}

    public function dispatch(Command $command): mixed
    {
        $handlerClass = config('domain.command_handlers.'.$command::class);

        if ($handlerClass === null) {
            throw new RuntimeException(
                $command::class.' に対応するCommandHandlerがconfig/domain.phpに登録されていません。'
            );
        }

        $handler = $this->container->make($handlerClass);

        return DB::transaction(fn () => $handler->handle($command));
    }
}
