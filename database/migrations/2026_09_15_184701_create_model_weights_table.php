<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_weights', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            // best.pt / last.pt

            $table->string('filename');
            $table->string('path');

            $table->string('architecture')->nullable();
            // YOLOv8m

            $table->string('version')->nullable();

            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_weights');
    }
};