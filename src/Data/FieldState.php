<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\ValueObjects\FieldValue;
use Cbox\Sync\ValueObjects\FieldVersion;

readonly class FieldState
{
    /**
     * @param  FieldVersion|null  $version  null on a record that reached a client
     * @param  Candidate|null  $origin  null on a record that reached a client
     *
     * The version and the origin are server bookkeeping: which write last
     * touched this field, and who made it. A projection deliberately withholds
     * the origin, because it names an actor the reader may not be allowed to
     * see, so a record that has travelled to a client carries neither. The
     * engine always sets both on canonical state.
     */
    public function __construct(public FieldValue $value, public ?FieldVersion $version = null, public ?Candidate $origin = null) {}
}
