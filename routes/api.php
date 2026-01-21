<?php

use App\Http\Controllers\CodeSessionController;
use App\Http\Controllers\ExecutionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::prefix('v1')->group(function () {
    
    Route::post('/code-sessions', [CodeSessionController::class, 'store']);           
    Route::patch('/code-sessions/{session}', [CodeSessionController::class, 'update']); 
    
    Route::post('/code-sessions/{session}/run', [ExecutionController::class, 'store']); 
    Route::get('/executions/{execution}', [ExecutionController::class, 'show']);        
});