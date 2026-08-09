<?php

namespace QCH\Core\Http\Controllers;

use Orion\Http\Controllers\Controller as BaseController;

/**
 * Class Controller.
 */
class Controller extends BaseController
{
    use AppliesCentralUserSearch;
    use TraitController;
}
