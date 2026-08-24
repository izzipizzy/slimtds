<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * Read side of the persisted update state. Split from the repository so the
 * status resolver can be tested without a database, and so the layout only
 * ever depends on reading.
 */
interface UpdateStateReader
{
    /** Null when there is no row yet. May throw; callers must tolerate it. */
    public function read(): ?UpdateState;
}
