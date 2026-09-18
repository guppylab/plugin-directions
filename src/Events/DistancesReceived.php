<?php

namespace Guppylab\Directions\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Result of a route calculation (Directions.Distances).
 *
 * Always dispatched once per call, on both platforms. Destinations that could
 * not be routed are present with ok: false and an `error` code, so a listener
 * never has to guess whether more rows are coming:
 *
 *   unsupported  no routing engine available (Android without a provider)
 *   timeout      the engine did not answer within the configured timeout
 *   over_limit   dropped because the batch exceeded max_destinations
 *   no_route     the engine answered but found no route
 *   failed       the engine returned an error
 */
class DistancesReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        /** JSON of list<array{id:string,meters?:int,seconds?:int,ok:bool,error?:string}> */
        public readonly string $results = '[]',
        public readonly ?string $id = null,
    ) {}

    /**
     * Decoded rows, keyed by destination id.
     *
     * @return array<string,array{id:string,meters?:int,seconds?:int,ok:bool,error?:string}>
     */
    public function rows(): array
    {
        $decoded = json_decode($this->results, true);

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn ($row) => is_array($row) && isset($row['id']))
            ->keyBy('id')
            ->all();
    }

    /**
     * Rows that were routed successfully, keyed by destination id.
     *
     * @return array<string,array{id:string,meters:int,seconds:int,ok:bool}>
     */
    public function routed(): array
    {
        return array_filter($this->rows(), fn ($row) => ($row['ok'] ?? false) === true);
    }

    /**
     * True when nothing could be routed — the signal to fall back to a
     * straight-line estimate.
     */
    public function failed(): bool
    {
        return $this->routed() === [];
    }
}
