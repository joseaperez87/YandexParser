<?php

declare(strict_types=1);

namespace App\Support\AntiBot;

final class Throttle
{
    public function wait(): void
    {
        $milliseconds = max(0, (int) config('yandex.throttle_ms', 800));
        $jitter = max(0, (int) config('yandex.throttle_jitter_ms', 400));

        if ($jitter > 0) {
            $milliseconds += random_int(0, $jitter);
        }

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
