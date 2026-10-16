<?php

namespace Brigada\Hq\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Illuminate\Support\HtmlString scriptTag()
 * @method static bool browserEnabled()
 * @method static bool enabled()
 * @method static void report(\Throwable $e)
 *
 * @see \Brigada\Hq\Hq
 */
class Hq extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Brigada\Hq\Hq::class;
    }
}
