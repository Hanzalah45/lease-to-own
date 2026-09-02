<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DealerNoteController extends Controller
{
    public function store(Request $request, Application $application)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:1000'],
        ]);

        $note = $application->dealerNotes()->create([
            'author_user_id' => Auth::id(),
            'text' => $data['text'],
        ]);

        return response()->json(['data' => $note->load('author:id,name')], 201);
    }
}
