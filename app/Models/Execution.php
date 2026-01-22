<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Execution extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'code_executions';

    protected $fillable = [
        'code_session_id', 
        'code',
        'language',
        'status', 
        'stdout', 
        'stderr', 
        'execution_time',
        'started_at',
        'finished_at'
    ];

    protected $casts = [
        'execution_time' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    // belongs to CodeSession
    public function session() {
        return $this->belongsTo(CodeSession::class, 'session_id');
    }
}
