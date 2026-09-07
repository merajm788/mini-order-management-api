<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Laravel 11+ ships a bare base controller; policies are opt-in.
    use AuthorizesRequests;
}
