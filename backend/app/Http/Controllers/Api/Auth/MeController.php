<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user()->load(['customerProfile', 'adminPermissions']);

        return response()->json(['user' => $user]);
    }
}
