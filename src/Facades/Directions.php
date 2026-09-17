<?php

namespace Guppylab\Directions\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool distances(float $originLat, float $originLng, array $destinations, ?string $id = null, string $transport = 'automobile')
 *
 * @see \Guppylab\Directions\Directions
 */
class Directions extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Guppylab\Directions\Directions::class;
    }
}
