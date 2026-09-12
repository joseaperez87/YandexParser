<?php

declare(strict_types=1);

namespace App\Support\AntiBot;

final class UserAgentRotator
{
    private int $index = 0;

    public function next(): string
    {
        $agents = config('yandex.user_agents', []);

        if ($agents === []) {
            return 'Mozilla/5.0';
        }

        $agent = $agents[$this->index % count($agents)];
        $this->index++;

        return $agent;
    }
}
