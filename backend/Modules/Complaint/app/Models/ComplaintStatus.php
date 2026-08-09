<?php

namespace Modules\Complaint\Models;

use QCH\Core\Models\CentralCatalogModel;

class ComplaintStatus extends CentralCatalogModel
{
    protected $table = 'complaint_status';

    protected $fillable = ['name', 'key', 'color'];

    // FSM state constants — fixed UUIDs committed to code and mirrored in CatalogSeeder.
    // NEVER change these UUIDs after the first migration:fresh, as the code branches on them.
    public const OPEN = 'a9001001-0000-4000-8000-000000000001';

    public const IN_REVIEW = 'a9001001-0000-4000-8000-000000000002';

    public const RESOLVED = 'a9001001-0000-4000-8000-000000000003';

    public const DISMISSED = 'a9001001-0000-4000-8000-000000000004';

    /** Terminal states — reachable from any non-terminal state, and reopenable to open/in_review. */
    public const TERMINAL = [self::RESOLVED, self::DISMISSED];
}
