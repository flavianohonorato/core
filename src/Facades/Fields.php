<?php

namespace Versatile\Core\Facades;

use Illuminate\Support\Facades\Facade;

class Fields extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'fields';
    }
}
