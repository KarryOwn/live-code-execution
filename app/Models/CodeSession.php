<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CodeSession extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id', 
        'problem_id', 
        'language', 
        'code', 
        'status'
    ];

    // belongs to Problem
    public function problem() {
        return $this->belongsTo(Problem::class);
    }

    // has many execution
    public function executions() {
        return $this->hasMany(Execution::class, 'code_session_id');
    }
}