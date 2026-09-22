<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Data\EntityRecord;

/**
 * A view whose rule judges a record as it stands NOW - a host's permission
 * check against its own row, say - and so cannot say whether a reader could
 * see an older version of it.
 *
 * Replaying history through such a rule gets both directions wrong: a row
 * deleted since is judged by nothing, and a row whose owner changed hides its
 * old versions from the old owner too, who then never hears it left. So a
 * change the rule does not include now is delivered as a removal - the id and
 * nothing else - whenever the underlying window spans either side of it. A
 * removal of something a reader never had is a no-op for it; the content of
 * something it may not see is never sent.
 */
interface CurrentStateView extends ViewDefinition
{
    /** Whether the record is inside the window at all, before the current-state rule. */
    public function spans(EntityRecord $record): bool;
}
