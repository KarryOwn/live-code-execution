<?php

namespace App\Http\Controllers;

use App\Models\CodeSession;
use App\Models\Problem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CodeSessionController extends Controller
{
    public function store(Request $request) {

        $request->validate([
            'problem_id' => 'required|uuid|exists:problems,id',
            'user_id' => 'required|integer',
            'language' => 'required|string',
            'code' => 'nullable|string',
        ]);

        $user_id = $request->input('user_id');
        $problem_id = $request->input('problem_id');

        // find existing active session
        $existingSession = CodeSession::where('user_id', $user_id)
            ->where('problem_id', $problem_id)
            ->where('status', 'ACTIVE')
            ->first();

        if ($existingSession) {
            return response()->json([
                'session_id' => $existingSession->id,
                'code' => $existingSession->code,
                'language' => $existingSession->language,
                'status' => 'ACTIVE',
            ]);
        }

        // if no existing session found
        $problem = Problem::find($problem_id);
        $lang = $request->input('language');
        $codeTemplate = $problem->code_template[$lang] ?? '';

        // create new session
        $session = CodeSession::create([
            'user_id' => $user_id,
            'problem_id' => $problem_id,
            'language' => $lang,
            'code' => $codeTemplate,
            'status' => 'ACTIVE',
        ]);

        return response()->json([
            'session_id' => $session->id,
            'status' => 'ACTIVE',
        ],201);
    }

    public function update(Request $request, CodeSession $session) {
        $request->validate([
            'code' => 'required|string',
            'language' => 'sometimes|string',
        ]);

        $session->update([
            'code' => $request->input('code'),
            'language' => $request->input('language', $session->language)
        ]);

        return response()->json([
            'session_id' => $session->id,
            'status' => $session->status,
        ],201);
    }
}   
