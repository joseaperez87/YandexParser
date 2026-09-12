<?php

declare(strict_types=1);

namespace App\Support\AntiBot;

interface ProxyPool
{
    public function next(): ?string;
}
