<?php

namespace Versatile\Core\Database\Types\Postgresql;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Versatile\Core\Database\Types\Type;

class TsQueryType extends Type
{
    const NAME = 'tsquery';

    public function getSQLDeclaration(array $field, AbstractPlatform $platform)
    {
        return 'tsquery';
    }
}
