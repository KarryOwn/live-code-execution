<?php

namespace App\Jobs;

use App\Models\Execution as CodeExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Carbon\Carbon;
use Exception;
use Throwable;

class RunCodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;                  // Retries for transient failures
    public $timeout = 60;               // Max time for whole job (prevents stuck workers)

    public $executionId;

    public function __construct(string $executionId)  // Use string since UUID
    {
        $this->executionId = $executionId;
    }

    public function handle(): void
    {
        $execution = CodeExecution::findOrFail($this->executionId);

        $execution->update([
            'status' => 'RUNNING',
            'started_at' => Carbon::now(),
        ]);

        $startTime = microtime(true);

        try {
            $language = strtolower($execution->language);

            // only python for now
            if ($language !== 'python') {
                throw new Exception('Unsupported language: ' . $execution->language);
            }

            // Docker command for isolated execution - pass code via stdin
            $command = [
                'docker', 'run', '--rm', '-i',
                '--memory=128m',            // Memory limit
                '--cpus=0.5',               // CPU limit
                '--pids-limit=50',          // Limit processes (prevent forks/bombs)
                '--network=none',           // No network access
                '--read-only',              // Read-only filesystem
                '--tmpfs', '/tmp',          // In-memory tmp for Python needs
                'python:3.12-slim',         // Lightweight Python image
                'python', '-u'              // -u for unbuffered output, read from stdin
            ];

            $process = new Process($command);
            $process->setInput($execution->snapshot_source_code);  // Pass code via stdin
            $process->setTimeout(10);  // Hard execution timeout (seconds) – prevents infinite loops

            try {
                $process->run();
            } catch (ProcessTimedOutException $e) {
                $executionTimeMs = round((microtime(true) - $startTime) * 1000);
                
                $execution->update([
                    'status' => 'TIMEOUT',
                    'stderr' => "Execution timed out after {$process->getTimeout()} seconds.\n" .
                               $process->getErrorOutput(),
                    'execution_time' => $executionTimeMs,
                    'finished_at' => Carbon::now(),
                ]);
                
                return;
            }

            $executionTimeMs = round((microtime(true) - $startTime) * 1000);

            if ($process->isSuccessful()) {
                $execution->update([
                    'status' => 'COMPLETED',
                    'stdout' => $process->getOutput(),
                    'stderr' => $process->getErrorOutput(),
                    'execution_time' => $executionTimeMs,
                    'finished_at' => Carbon::now(),
                ]);
            } else {
                $execution->update([
                    'status' => 'FAILED',
                    'stderr' => $process->getErrorOutput() ?: 'Container failed to start/run.',
                    'execution_time' => $executionTimeMs,
                    'finished_at' => Carbon::now(),
                ]);
            }
        } catch (Exception $e) {
            // Fallback for any unexpected error (e.g., docker not available)
            $execution->update([
                'status' => 'FAILED',
                'stderr' => 'Execution error: ' . $e->getMessage(),
                'finished_at' => Carbon::now(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        $execution = CodeExecution::find($this->executionId);
        if ($execution) {
            $execution->update([
                'status' => 'FAILED',
                'stderr' => 'Job failed after retries: ' . $exception->getMessage(),
                'finished_at' => Carbon::now(),
            ]);
        }
    }
}