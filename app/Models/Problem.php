<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids; 

class Problem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'title', 
        'description', 
        'code_template', 
        'time_limit', 
    ];

    protected $casts = [
        'code_template' => 'array',
        'time_limit' => 'float',
    ];

    // Relationship: A problem has many user sessions
    public function sessions() {
        return $this->hasMany(CodeSession::class);
    }
}