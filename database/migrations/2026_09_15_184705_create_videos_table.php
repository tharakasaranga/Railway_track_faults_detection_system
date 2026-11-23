<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();

            $table->string('original_name');
            $table->string('stored_name');
            $table->string('input_path');

            $table->string('output_path')->nullable();

            $table->string('status')->default('pending');
            // pending, processing, completed, failed

            $table->foreignId('model_weight_id')
                ->nullable()
                ->constrained('model_weights')
                ->nullOnDelete();

            $table->string('model_name')->nullable();

            $table->unsignedBigInteger('file_size')->nullable();
            $table->decimal('duration', 10, 2)->nullable();

            $table->unsignedInteger('total_frames')->nullable();
            $table->unsignedInteger('processed_frames')->default(0);

            $table->decimal('processing_time', 10, 2)->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};