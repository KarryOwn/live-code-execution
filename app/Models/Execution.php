<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Execution extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code_session_id', 
        'code',
        'status', 
        'stdout', 
        'stderr', 
        'execution_time',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'execution_time' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // belongs to CodeSession
    public function session() {
        return $this->belongsTo(CodeSession::class, 'session_id');
    }
}
