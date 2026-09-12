<?php

declare(strict_types=1);

namespace App\Support\AntiBot;

final class NullProxyPool implements ProxyPool
{
    public function next(): ?string
    {
        return null;
    }
}
