<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('code_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('code_session_id');
            $table->text('code');
            $table->enum('status', ['QUEUED', 'RUNNING', 'COMPLETED', 'FAILED', 'TIMEOUT']);
            $table->text('stdout')->nullable();
            $table->text('stderr')->nullable();
            $table->float('execution_time')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('code_executions');
    }
};
