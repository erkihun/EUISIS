<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/** The same base as EUISIS, so copied EUISIS controllers call authorize() and validate() as there. */
abstract class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;
}
