<?php

namespace Modules\User\Models;

use QCH\Core\Models\BaseModel; // para la verificacion  de email con un codigo

class UserRegisterStatus extends BaseModel
{
    public const PENDING = '61e75fdb-4eda-4332-8bab-af5be6e3a9c1';

    public const APPROVED = '76e467c9-8bcf-46ce-8a06-62ff24c82b94';

    public const REJECTED = 'e59fa468-dca3-48e2-a385-1687b2787f24';

    public const INCOMPLETE = 'bab0a71d-0cdd-4fc4-843b-2baab23dc040';

    protected $table = 'user_register_status';

    protected $fillable = [
        'id',
        'name',
    ];
}
