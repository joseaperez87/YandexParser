<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parse_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('snapshot_id')->constrained('organization_snapshots')->cascadeOnDelete();
            $table->string('field');
            $table->text('old')->nullable();
            $table->text('new')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parse_changes');
    }
};
