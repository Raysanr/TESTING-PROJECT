<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fare_reports', function (Blueprint $table) {
            $table->id();
            $table->string('mode')->index();
            $table->decimal('reported_fare', 8, 2);
            $table->string('client_hash')->nullable();
            $table->boolean('applied')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_reports');
    }
};
