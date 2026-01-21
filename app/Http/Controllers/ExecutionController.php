<?php

namespace App\Http\Controllers;

use App\Models\CodeSession;
use App\Models\Execution;
use App\Jobs\RunCodeJob;
use Illuminate\Http\Request;

class ExecutionController extends Controller
{
    // POST /code-sessions/{session_id}/run
    public function store(Request $request, CodeSession $session)
    {
        $request->validate([
            'code' => 'required|string' 
        ]);

        $code = $request->input('code');

        $execution = $session->executions()->create([
            'code' => $code,
            'language' => $session->language ?? 'python',
            'snapshot_source_code' => $code,
            'status' => 'QUEUED', 
        ]);

        // This pushes the ID to Redis 
        RunCodeJob::dispatch($execution->id);

        // Return Immediately
        return response()->json([
            'execution_id' => $execution->id,
            'status'       => 'QUEUED'
        ], 202); // 
    }

    // GET /executions/{execution_id}
    public function show(Execution $execution)
    {
        // Simply return the current state from the database
        return response()->json([
            'execution_id'      => $execution->id,
            'status'            => $execution->status,
            'stdout'            => $execution->stdout,     
            'stderr'            => $execution->stderr,    
            'execution_time' => $execution->execution_time,
        ]);
    }
}
