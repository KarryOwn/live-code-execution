# Live Code Execution System

A secure, scalable API for executing user-submitted code in isolated Docker containers. Built with Laravel, PostgreSQL, and Redis for educational platforms, coding interviews, and online IDEs.

## Architecture

### System Overview

```
┌──────────────┐      ┌──────────────┐      ┌──────────────┐
│   API Client │─────▶│  Laravel API │─────▶│  PostgreSQL  │
│   (HTTP)     │      │  (REST API)  │      │  (Database)  │
└──────────────┘      └──────┬───────┘      └──────────────┘
                             │
                             │ Dispatch Job
                             ▼
                      ┌──────────────┐
                      │ Redis Queue  │
                      └──────┬───────┘
                             │
                             │ Process Job
                             ▼
                      ┌──────────────┐      ┌──────────────┐
                      │Queue Workers │─────▶│Docker Engine │
                      │ (RunCodeJob) │      │  (Isolated   │
                      └──────┬───────┘      │   Execution) │
                             │              └──────────────┘
                             │ Update Results
                             ▼
                      ┌──────────────┐
                      │  PostgreSQL  │
                      └──────────────┘
```

### Key Components

1. **API Layer** - Laravel REST API handling HTTP requests
2. **Queue System** - Redis-backed job queue for async processing
3. **Worker Processes** - Background workers executing code jobs
4. **Execution Engine** - Docker containers running user code
5. **Data Store** - PostgreSQL for persistence

## Project Structure

```
.
├── app/
│   ├── Http/Controllers/
|   |   ├── CodeSessionController.php
│   │   └── ExecutionController.php    
│   ├── Jobs/
│   │   └── RunCodeJob.php             
│   └── Models/
│       ├── CodeSession.php           
|       ├── Problem.php
|       ├── User.php                  
│       └── Execution.php              
├── database/
│   └── migrations/                    # Database schema
├── routes/
│   └── api.php                        # API routes       
├── compose.yaml                       # Docker Compose config
├── DESIGN.md                          # Detailed architecture doc
└── README.md                          # This file
```
---

## Quick Start

### Prerequisites

- **Operating System:** Linux or WSL2 (Windows Subsystem for Linux)
  - **Windows users:** Install [WSL2 with Ubuntu](https://docs.microsoft.com/en-us/windows/wsl/install) recommended
  - **macOS users:** Docker Desktop for Mac 
- **Docker** & **Docker Compose** (v2.0+)
- **Git**
- **Ports 80, 5432, 6379** available


### Installation

```bash
# Clone the repository
git clone https://github.com/KarryOwn/live-code-execution.git
cd live-code-execution

# Copy environment file
cp .env.example .env

# Install dependencies (if not already installed)
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs

# Start all services (Laravel, PostgreSQL, Redis)
./vendor/bin/sail up -d

# Generate application key
./vendor/bin/sail artisan key:generate

# Run database migrations
./vendor/bin/sail artisan migrate:fresh --seed

# Install Docker CLI in the Laravel container (required for code execution)
# Need to rerun this and socket permissions if container restarted
./vendor/bin/sail exec laravel.test apt-get update
./vendor/bin/sail exec laravel.test apt-get install -y docker.io

# Set Docker socket permissions
./vendor/bin/sail exec laravel.test chmod 666 /var/run/docker.sock

# Start the queue worker (in background)
./vendor/bin/sail artisan queue:work &
```

**Important Notes:**
- If `./vendor/bin/sail` doesn't exist, run `docker run --rm -v $(pwd):/opt -w /opt laravelsail/php83-composer:latest composer install` first to install dependencies.
- **Docker CLI installation is not persistent!** If you restart the containers (`sail down` then `sail up`), you must reinstall Docker CLI and reset socket permissions 

**Alternative (without Sail):** You can use `docker compose exec laravel.test` instead of `./vendor/bin/sail` for all commands if you prefer.

### Manual check Database

```bash
./vendor/bin/sail tinker
```
Find User, Problem, CodeSession, Execution based on the Models
```bash
\App\Models\User::all();
\App\Models\Problem::all();
```
Create new Problem
```bash
 $problem = App\Models\Problem::create([
    'title' => 'Test Problem',
    'description' => 'Test Description',
    'code_template' => ['python' => 'print("Start")'],
    'time_limit' => 10.0,
]);
```

Output:
```bash
> \App\Models\CodeSession::all();
= Illuminate\Database\Eloquent\Collection {#6616
    all: [
      App\Models\CodeSession {#6614
        id: "019be02a-295a-7111-bbe7-83946ef050c4",
        user_id: 1,
        problem_id: "11111111-1111-1111-1111-111111111111",
        language: "python",
        code: "print("Hello")",
        status: "ACTIVE",
        created_at: "2026-01-21 10:46:58",
        updated_at: "2026-01-21 10:53:52",
      },
    ],
  }

= App\Models\Problem {#6670
    title: "Test Problem",
    description: "Test Description",
    code_template: "{"python":"print(\"Start\")"}",
    time_limit: 2.0,
    id: "019bdf4f-ef7d-728c-b846-c517f0e0ed61",
    updated_at: "2026-01-21 06:48:36",
    created_at: "2026-01-21 06:48:36",
  }

```


---

## API Documentation

Base URL: `http://localhost/api/v1`

### 1. Create Code Session

**Endpoint:** `POST /code-sessions`

**Description:** Creates a new coding session for a user/problem.

**Request:**
```json
{
  "user_id": 1,
  "problem_id": "uuid-of-problem",
  "language": "python"
}

```

**Response:** `201 Created`
```json
{
  "session_id":"uuid",
  "status":"ACTIVE"
}
```

---

### 2. Update Code Session (Autosave)

**Endpoint:** `PATCH /code-sessions/{session_id}`

**Description:** Updates the code in an existing session (for autosave functionality).

**Request:**
```json
{
  "code": "print(\"Hello\")"
}
```

**Response:** `200 OK`
```json
{
  "session_id":"uuid",
  "status":"ACTIVE"
}
```

---

### 3. Execute Code

**Endpoint:** `POST /code-sessions/{session_id}/run`

**Description:** Submits code for execution. Returns immediately with execution ID. Code runs in background.

**Request:**
```json
{
  "code": "print(\"Hello World from API\")"
}
```

**Response:** `202 Accepted`
```json
{
  "execution_id": "uuid-of-execution",
  "status": "QUEUED"
}
```

**Status Codes:**
- `202 Accepted` - Execution queued successfully
- `404 Not Found` - Session not found
- `422 Unprocessable Entity` - Validation error

---

### 4. Get Execution Results

**Endpoint:** `GET /executions/{execution_id}`

**Description:** Retrieves current execution status and results. Poll this endpoint until status is terminal (COMPLETED/FAILED/TIMEOUT).

**Response:** `200 OK`

**While Running:**
```json
{
  "execution_id": "019bdf51-4809-73b2-9d65-6e347c0ec115",
  "status": "RUNNING",
  "stdout": null,
  "stderr": null,
  "execution_time": null
}
```

**Success:**
```json
{
  "execution_id":"019be046-2d54-71b8-bb5a-166e9d59fb05",
  "status":"COMPLETED",
  "stdout":"Hello World from API\n",
  "stderr":"",
  "execution_time":498
}
```

**Error:**
```json
{
  "execution_id": "019bdf51-4809-73b2-9d65-6e347c0ec115",
  "status": "FAILED",
  "stdout": "",
  "stderr": "Traceback (most recent call last):\n  File \"<stdin>\", line 1, in <module>\nNameError: name 'undefined_var' is not defined\n",
  "execution_time": 98
}
```

**Timeout:**
```json
{
  "execution_id": "019bdf51-4809-73b2-9d65-6e347c0ec115",
  "status": "TIMEOUT",
  "stdout": "",
  "stderr": "Execution timed out after 10 seconds.\n",
  "execution_time": 10042
}
```

**Execution States:**
- `QUEUED` - Job in queue, not started
- `RUNNING` - Currently executing in Docker container
- `COMPLETED` - Successful execution (exit code 0)
- `FAILED` - Runtime error or container failure
- `TIMEOUT` - Exceeded 10-second limit

---

## Design Decisions

#### 1. Laravel + PostgreSQL + Redis

Its just my comfortable framework that i used it on some of my school projects and currently my thesis that also use PostgreSQL and Redis. With many tools for settings up and can also interact with the database easily so that is the reason i choose this framework on this assignment. 

#### 2. Docker-in-Docker Execution

I think Docker is perfect for this because of the strong isolation, controlled environments, language flexibility. With faster boot time compare to VMs which it need to boot its own OS.

#### 3. Code Passed via stdin

I chose passing code via stdin instead of mounting files simply because of the simplicity. Mouting files needs to create temp files, clean up,... . On the otherside Mounting files might be better on multi files uploads which i dont think it needed for this project.

---
## What I Would Improve With More Time

- Front-end demo
- Queue monitoring
- Features  testing
- More languages support

## Troubleshooting

### Queue Worker Not Processing Jobs

```bash
# Check if worker is running
docker compose exec laravel.test ps aux | grep queue:work

# Restart worker
docker compose exec laravel.test php artisan queue:restart
docker compose exec -d laravel.test php artisan queue:work

# Check failed jobs
docker compose exec laravel.test php artisan queue:failed
```

### Docker Permission Issues

```bash
# Fix Docker socket permissions
docker compose exec laravel.test chmod 666 /var/run/docker.sock

# Verify Docker access
docker compose exec laravel.test docker ps
```

### Database Connection Issues

```bash
# Check PostgreSQL status
docker compose ps pgsql

# Reset database
docker compose exec laravel.test php artisan migrate:fresh --seed
```

---

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
