<?php

namespace Modules\Package\Models;

use QCH\Core\Models\CentralCatalogModel;

class PackageStatus extends CentralCatalogModel
{
    protected $table = 'package_status';

    protected $fillable = ['name', 'key', 'color'];

    // FSM state constants — fixed UUIDs committed to code and mirrored in CatalogSeeder.
    // NEVER change these UUIDs after the first migration:fresh, as the code branches on them.
    public const RECEIVED = 'a2001001-0000-4000-8000-000000000001';

    public const READY = 'a2001001-0000-4000-8000-000000000002';

    public const PICKED_UP = 'a2001001-0000-4000-8000-000000000003';

    public const RETURNED = 'a2001001-0000-4000-8000-000000000004';

    public const INCIDENT = 'a2001001-0000-4000-8000-000000000005';
}
