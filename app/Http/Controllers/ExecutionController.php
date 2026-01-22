<?php

namespace App\Http\Controllers;

use App\Models\CodeSession;
use App\Models\Execution;
use App\Jobs\RunCodeJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;

class ExecutionController extends Controller
{
    // POST /code-sessions/{session_id}/run
    public function store(Request $request, CodeSession $session)
    {
        $request->validate([
            'code' => 'required|string' 
        ]);

        // Rate limiting: Max 10 executions per minute per session
        $rateLimitKey = 'execute:' . $session->id;
        $executed = RateLimiter::attempt(
            $rateLimitKey,
            10, 
            function() {},
            60  
        );

        if (!$executed) {
            Log::warning('Rate limit exceeded for code execution', [
                'code_session_id' => $session->id,
                'ip' => $request->ip(),
            ]);
            
            return response()->json([
                'error' => 'Rate limit exceeded. Maximum 10 executions per minute.',
                'retry_after' => RateLimiter::availableIn($rateLimitKey)
            ], 429);
        }

        // Check for pending/running executions (prevent duplicate abuse)
        $pendingExecution = $session->executions()
            ->whereIn('status', ['QUEUED', 'RUNNING'])
            ->first();

        if ($pendingExecution) {
            Log::info('Duplicate execution prevented - returning existing', [
                'existing_execution_id' => $pendingExecution->id,
                'status' => $pendingExecution->status,
            ]);
            
            return response()->json([
                'execution_id' => $pendingExecution->id,
                'status' => $pendingExecution->status,
                'message' => 'An execution is already in progress for this session'
            ], 409); 
        }

        $code = $request->input('code');

        $execution = $session->executions()->create([
            'code' => $code,
            'language' => $session->language ?? 'python',
            'status' => 'QUEUED', 
        ]);

        Log::info('Execution lifecycle: CREATED -> QUEUED', [
            'execution_id' => $execution->id,
            'code_session_id' => $session->id,
            'language' => $execution->language,
            'code_length' => strlen($code),
            'queued_at' => $execution->created_at,
        ]);

        // This pushes the ID to Redis 
        RunCodeJob::dispatch($execution->id);

        // Return Immediately
        return response()->json([
            'execution_id' => $execution->id,
            'status'       => 'QUEUED'
        ], 202); 
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
