# Live Code Execution System - Design Document


## Architecture Overview

### System Components

```
┌──────────────┐      ┌──────────────┐      ┌──────────────┐
│   API Client │─────▶│  Laravel API │─────▶│   PostgreSQL │
└──────────────┘      └──────┬───────┘      └──────────────┘
                             │
                             │ Dispatch Job
                             ▼
                      ┌──────────────┐
                      │  Redis Queue │
                      └──────┬───────┘
                             │
                             │ Process Job
                             ▼
                      ┌──────────────┐      ┌──────────────┐
                      │Queue Workers │─────▶│Docker Engine │
                      │ (RunCodeJob) │      │(Python Image)│
                      └──────┬───────┘      └──────────────┘
                             │
                             │ Update Results
                             ▼
                      ┌──────────────┐
                      │  PostgreSQL  │
                      └──────────────┘
```

### End-to-End Request Flow

#### 1. Code Session Creation
**Endpoint:** `POST /api/v1/code-sessions`

```
Request:
{
  "user_id": 1,
  "problem_id": "uuid-of-problem",
  "language": "python"
}

Response:
{
  "session_id":"uuid",
  "status":"ACTIVE"
}
```

**Flow:**
- Client creates a new coding session
- Session stored in PostgreSQL with initial code
- Returns session ID for subsequent operations

#### 2. Autosave Behavior
**Endpoint:** `PATCH /api/v1/code-sessions/{session_id}`

```
Request:
{
  "code": "print(\"Hello\")"
}
```

**Flow:**
- Client continuously saves code changes 
- Updates session record in database
- No execution triggered - just persistence
- Optimistic updates on client side for responsiveness

#### 3. Execution Request
**Endpoint:** `POST /api/v1/code-sessions/{session_id}/run`

```
Request:
{
  "code": "print(\"Hello World from API\")"
}

Response (202 Accepted):
{
  "execution_id": "uuid-of-execution",
  "status": "QUEUED"
}
```

**Flow:**
1. Controller validates request
2. Creates `Execution` record with:
   - `code`: The code to execute
   - `language`: From session (currently only 'python')
   - `snapshot_source_code`: Snapshot of code at execution time
   - `status`: 'QUEUED'
3. Dispatches `RunCodeJob` to Redis queue
4. Returns immediately with 202 status (non-blocking)

#### 4. Background Execution
**Component:** `RunCodeJob` (Queue Worker)

```php
// Simplified execution flow
1. Fetch execution record from database
2. Update status to 'RUNNING' with started_at timestamp
3. Create Docker container with:
   - Resource limits (128MB RAM, 0.5 CPU)
   - Network isolation (--network=none)
   - Process limits (max 50 processes)
   - Read-only filesystem
   - 10-second timeout
4. Pass code via stdin to Python interpreter
5. Capture stdout, stderr, and execution time
6. Update execution record with results
7. Set status to COMPLETED/FAILED/TIMEOUT
```

**Isolation Strategy:**
- Docker-in-Docker execution
- Python code runs in ephemeral container (`python:3.12-slim`)
- Container destroyed after execution
- No file system persistence (except /tmp in-memory)
- No network access

**Resource Limits:**
```
--memory=128m          # Prevent memory exhaustion
--cpus=0.5            # Limit CPU usage
--pids-limit=50       # Prevent fork bombs
--network=none        # No external network access
--read-only           # Read-only root filesystem
--tmpfs /tmp          # In-memory temporary storage
```

#### 5. Result Polling
**Endpoint:** `GET /api/v1/executions/{id}`

```
Response:
{
  "execution_id": "uuid",
  "status": "COMPLETED",
  "stdout": "Hello World\n",
  "stderr": "",
  "execution_time": 125  // milliseconds
}
```

**Flow:**
- Client polls every 500ms-1s for results
- Returns current state from database
- Polling stops when status is terminal (COMPLETED/FAILED/TIMEOUT)

**Alternative:** Could use WebSockets/Server-Sent Events for push-based updates in production.

### Queue-Based Execution Design

**Why Queues?**
1. **Non-blocking:** API responds immediately while execution happens in background
2. **Scalability:** Can add more workers to handle load
3. **Reliability:** Jobs persisted in Redis, can survive crashes
4. **Rate limiting:** Control concurrent executions
5. **Priority handling:** Can prioritize certain executions

**Queue Configuration:**
- **Backend:** Redis
- **Driver:** Laravel Queue (database + Redis)
- **Workers:** Configurable (default: 1 per container)
- **Retries:** 3 attempts on transient failures
- **Timeout:** 60 seconds for entire job (includes 10s code execution + overhead)

**Job Structure:**
```php
RunCodeJob {
  $executionId: UUID
  $tries: 3
  $timeout: 60
  
  handle(): void
  failed(Throwable): void
}
```

### Execution Lifecycle and State Management

```
┌─────────┐
│ QUEUED  │ ──────▶ Job dispatched to Redis
└────┬────┘
     │
     │ Worker picks up job
     ▼
┌─────────┐
│ RUNNING │ ──────▶ Docker container executing
└────┬────┘
     │
     ├──────▶ Success ──────▶ ┌───────────┐
     │                        │ COMPLETED │
     │                        └───────────┘
     │
     ├──────▶ Error ────────▶ ┌─────────┐
     │                        │ FAILED  │
     │                        └─────────┘
     │
     └──────▶ Timeout ──────▶ ┌─────────┐
                              │ TIMEOUT │
                              └─────────┘
```

**State Transitions:**
- `QUEUED → RUNNING`: Worker starts processing
- `RUNNING → COMPLETED`: Execution successful (exit code 0)
- `RUNNING → FAILED`: Execution error (exit code != 0 or exception)
- `RUNNING → TIMEOUT`: Exceeded 10-second limit

**Timestamps Tracked:**
- `created_at`: When execution was queued
- `started_at`: When worker began processing
- `finished_at`: When execution completed (any terminal state)
- `execution_time`: Actual code execution duration (milliseconds)

---

## Reliability & Data Model

### Database Schema

**code_sessions**
```sql
id               UUID PRIMARY KEY
user_id          UUID NULLABLE
problem_id       UUID NULLABLE
language         VARCHAR(50) DEFAULT 'python'
code             TEXT
status           VARCHAR(20)
created_at       TIMESTAMP
updated_at       TIMESTAMP

```

**code_executions**
```sql
id                    UUID PRIMARY KEY
code_session_id       UUID FOREIGN KEY
code                  TEXT               
language              VARCHAR(50)         
status                ENUM('QUEUED', 'RUNNING', 'COMPLETED', 'FAILED', 'TIMEOUT')
stdout                TEXT NULLABLE
stderr                TEXT NULLABLE
execution_time        INTEGER NULLABLE    
started_at            TIMESTAMP NULLABLE
finished_at           TIMESTAMP NULLABLE
created_at            TIMESTAMP
updated_at            TIMESTAMP

```

### Execution States

```
QUEUED ────────▶ Initial state when job is dispatched
  │
  ▼
RUNNING ───────▶ Worker processing, Docker container running
  │
  ├─▶ COMPLETED ─▶ Success (exit code 0)
  ├─▶ FAILED ────▶ Runtime error or container failure
  └─▶ TIMEOUT ───▶ Exceeded 10-second execution limit
```

**Terminal States:** `COMPLETED`, `FAILED`, `TIMEOUT` (no further transitions)

### Idempotency Handling

#### Prevent Duplicate Execution Runs

**Current Implementation:**
- Each session can only have **one active execution** at a time
- Checks for existing QUEUED or RUNNING executions before creating new one
- Returns existing execution with 409 Conflict if duplicate detected

```php
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
```
#### Safe Reprocessing of Jobs

**Retry Strategy:**
```php
public $tries = 3;
```

**Transient Failures Retried:**
- Database connection errors
- Temporary Docker daemon unavailability
- Network timeouts (Redis connection)

**Not Retried:**
- Code syntax errors (deterministic failure)
- Timeout (already executed, deterministic)
- Invalid execution record

**Failed Job Handler:**
```php
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
```

### Failure Handling

#### 1. Retries
- **Automatic:** 3 attempts with exponential backoff
- **Queue:** Laravel queue handles retry logic
- **Tracking:** `attempts` column in jobs table

#### 2. Error States
```php
try {
    // Execute code
} catch (ProcessTimedOutException $e) {
    // Handle timeout
    $execution->update(['status' => 'TIMEOUT', ...]);
} catch (Exception $e) {
    // Handle general errors
    $execution->update(['status' => 'FAILED', ...]);
}
```

#### 3. Dead Letter / Failed Execution Handling

- Failed jobs logged to Laravel logs
- Execution record updated with error details
- No automatic cleanup

---

## Scalability Considerations

### Handling Many Concurrent Live Coding Sessions

- **Sessions:** Limited only by database 
- **Concurrent Executions:** Limited by queue workers and Docker capacity



### Horizontal Scaling of Workers

- Web Layer: We can add more Laravel API servers behind a Load Balancer.

- Worker Layer: We can scale the number of Worker Nodes independently based on queue depth. If the queue builds up, we simply boot more workers.



### Queue Backlog Handling

- Monitor backlog to trigger, add more worker if there are too many backlog coming in

### Potential Bottlenecks and Mitigation Strategies

- Single Docker daemon handling all executions: Use multiple Docker hosts with load balancing
- Docker container startup overhead: keep idle containers warm in pool 
- High volume of autosave (PATCH): Write through Redis instead of PostgreSQL and sync after the session ends or after 30 seconds.

---

## Trade-offs

### Technology Choices

#### 1. Laravel + PostgreSQL + Redis

Its just my comfortable framework that i used it on some of my school projects and currently my thesis that also use PostgreSQL and Redis. With many tools for settings up and can also interact with the database easily so that is the reason i choose this framework on this assignment. 

#### 2. Docker-in-Docker Execution

I think Docker is perfect for this because of the strong isolation, controlled environments, language flexibility. With faster boot time compare to VMs which it need to boot its own OS.

#### 3. Code Passed via stdin

I chose passing code via stdin instead of mounting files simply because of the simplicity. Mouting files needs to create temp files, clean up,... . On the otherside Mounting files might be better on multi files uploads which i dont think it needed for this project.

### What I Optimized For

For me my priority is reliability then come simplicity and final is speed. Ensure the system working correctly as intented, need to be extra careful when involving untrusted code, and I want to keep the project easy to understand, maintain and develop, while also keep the system running within an acceptable latency.

### Production Readiness Gaps

- Implement Authentication and Authorization
- More languages support
- Need more testing, stress test
- Add Queue monitoring, Dashboard, Live metrics

