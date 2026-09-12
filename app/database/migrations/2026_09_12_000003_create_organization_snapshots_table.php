<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parse_run_id')->constrained()->cascadeOnDelete();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->unique('parse_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_snapshots');
    }
};
