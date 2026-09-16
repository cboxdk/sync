<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Data\RecordCriteria;

/**
 * Optional capability: a view whose membership a store can express as a query.
 *
 * A store that cannot narrow by these criteria must refuse the view rather than
 * scan every record. Narrowing is an optimization only; includes() still decides.
 */
interface QueryableView extends ViewDefinition
{
    public function criteria(): RecordCriteria;
}
