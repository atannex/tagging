<?php

namespace Atannex\Tagging\Facades;

use Illuminate\Support\Facades\Facade;

/** @see \Atannex\Tagging\Tagging */
class Tagging extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Atannex\Tagging\Tagging::class;
    }
}
