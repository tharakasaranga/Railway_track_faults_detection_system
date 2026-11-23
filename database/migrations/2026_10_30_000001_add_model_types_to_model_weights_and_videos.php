<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_weights', function (Blueprint $table) {
            $table->string('model_type')->default('yolov8');
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->string('model_type')->default('yolov8');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('model_type');
        });

        Schema::table('model_weights', function (Blueprint $table) {
            $table->dropColumn('model_type');
        });
    }
};
