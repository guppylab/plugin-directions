<?php

namespace Guppylab\Directions\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string|false distances(float $originLat, float $originLng, array $destinations, ?string $id = null, string $transport = 'automobile')
 * @method static array support()
 * @method static bool isSupported()
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
