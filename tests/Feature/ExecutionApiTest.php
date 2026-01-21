<?php

namespace Tests\Feature;

use App\Jobs\RunCodeJob;
use App\Models\CodeSession;
use App\Models\Problem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExecutionApiTest extends TestCase
{
    use RefreshDatabase; // Resets DB after every test

    /** @test */
    public function it_queues_execution_and_returns_accepted_status()
    {
        // 1. SETUP: Create necessary data
        // We need a user, a problem, and an active session
        $user = User::factory()->create();
        
        $problem = Problem::create([
            'title' => 'Two Sum',
            'description' => 'Add two numbers',
            'code_template' => ['python' => 'print("start")'],
            'time_limit' => 2.0,
        ]);

        $session = CodeSession::create([
            'user_id' => $user->id,
            'problem_id' => $problem->id,
            'language' => 'python',
            'code' => 'print("original")',
            'status' => 'ACTIVE'
        ]);

        // 2. MOCKING: Fake the Queue
        // This prevents the actual Job from running (we don't want Docker in unit tests)
        Queue::fake();

        // 3. ACTION: Hit the API endpoint
        $payload = [
            'code' => 'print("Hello World")' // User submitted new code
        ];

        $response = $this->postJson("/api/v1/code-sessions/{$session->id}/run", $payload);

        // 4. ASSERTIONS: Verify the outcome

        // Check HTTP Status 202 (Accepted)
        $response->assertStatus(202);

        // Check JSON Structure
        $response->assertJsonStructure([
            'execution_id',
            'status'
        ]);

        $response->assertJson([
            'status' => 'QUEUED'
        ]);

        // Check Database: A new execution record should exist
        $this->assertDatabaseHas('code_executions', [
            'code_session_id' => $session->id,
            'code' => 'print("Hello World")', // Should match payload, not session
            'status' => 'QUEUED'
        ]);

        // Check Queue: The job was actually pushed
        Queue::assertPushed(RunCodeJob::class, function ($job) use ($response) {
            // Verify the job has the correct Execution ID
            // We access the protected property via reflection or public getter if available
            // For simplicity in tests, we often just check the class type, 
            // but checking the ID ensures it's the *right* job.
             return $job->executionId === $response->json('execution_id');
        });
    }
}