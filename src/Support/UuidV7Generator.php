<?php

declare(strict_types=1);

namespace Cbox\Sync\Support;

use Cbox\Sync\Contracts\IdGenerator;

/** UUIDv7 layout per RFC 9562 section 5.7. Random order within a millisecond; never a sync cursor. */
class UuidV7Generator implements IdGenerator
{
    public function generate(): string
    {
        $milliseconds = (int) floor(microtime(true) * 1000);
        $time = str_pad(dechex($milliseconds), 12, '0', STR_PAD_LEFT);
        $random = random_bytes(10);
        $random[0] = chr((ord($random[0]) & 0x0F) | 0x70);
        $random[2] = chr((ord($random[2]) & 0x3F) | 0x80);
        $hex = $time.bin2hex($random);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
