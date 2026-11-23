<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->decimal('confidence', 4, 2)->default(0.25)->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('confidence');
        });
    }
};