<?php

declare(strict_types=1);

namespace App\Services\YandexMaps\Contracts;

use App\Models\Organization;
use App\Services\YandexMaps\ParseContext;
use App\Services\YandexMaps\ParserResult;

interface ParserStrategy
{
    public function parse(Organization $organization, ParseContext $context): ParserResult;
}
