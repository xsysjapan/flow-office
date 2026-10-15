<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * Entra IDの外部IDを投影する旧イベントへの externalTenantId の補完結果(論点19 決定9)。
 */
final readonly class ExternalTenantIdRebuild
{
    /**
     * @param  list<RebuiltEvent>  $events  payload に externalTenantId を補って置き換えたイベント(補ったものだけ)
     * @param  list<int>  $consumedIds  置き換えた(元の)行のid
     * @param  array<string, int>  $filledByClass  event_class => 補った件数
     * @param  int  $noRow  補えなかった件数(現在の external_identities に行が無い)
     * @param  int  $nullTenant  補えなかった件数(行はあるが external_tenant_id が null)
     * @param  int  $alreadyComplete  既に externalTenantId の無い旧イベントではなかった件数(新しい記録)
     * @param  string  $mode  検査の結果: 'checked'(設定と一致を検査した)・'row_only'(設定が空のため行の値のみで補完)・'no_rows'(行が無い)
     * @param  ?string  $tenant  補完に使った行のテナントID(行が無いときは null)
     * @param  list<array{key: string, reason: string}>  $issues  前提を満たさず止める理由(空なら補完してよい)
     */
    public function __construct(
        public array $events,
        public array $consumedIds,
        public array $filledByClass,
        public int $noRow,
        public int $nullTenant,
        public int $alreadyComplete,
        public string $mode,
        public ?string $tenant,
        public array $issues,
    ) {}

    /** @return array{filled: array<string, int>, filled_total: int, no_row: int, null_tenant: int, already_complete: int, mode: string, tenant: ?string, issues: int} */
    public function report(): array
    {
        return [
            'filled' => $this->filledByClass,
            'filled_total' => array_sum($this->filledByClass),
            'no_row' => $this->noRow,
            'null_tenant' => $this->nullTenant,
            'already_complete' => $this->alreadyComplete,
            'mode' => $this->mode,
            'tenant' => $this->tenant,
            'issues' => count($this->issues),
        ];
    }
}
