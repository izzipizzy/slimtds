<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RrwebPointerActivity extends AbstractMigration
{
    public function up(): void
    {
        // The previous classifier counted scrolling/input as interaction.
        // Invalidate derived labels; rrweb:classify recomputes the pointer-only
        // rule from preserved recordings. Unavailable evidence remains NULL.
        $this->execute('UPDATE stats.rrweb_sessions SET has_interaction = NULL WHERE has_interaction IS NOT NULL');
    }

    public function down(): void
    {
        // This is derived metadata; replay payloads are never changed.
        // Rolling back cannot recreate the previous classification.
    }
}
