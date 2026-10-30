<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anthropic_requests', function (Blueprint $table) {
            $table->id();
            $table->string('connection');
            $table->string('model')->nullable()->index();
            $table->string('request_id')->nullable();
            $table->string('batch_id')->nullable()->index();
            $table->string('custom_id')->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_input_tokens')->default(0);
            $table->unsignedBigInteger('cache_creation_input_tokens')->default(0);
            $table->json('server_tool_use')->nullable();
            // The whole usage as the API sent it: the cache's 5-minute and 1-hour split, the iterations, the region.
            $table->json('usage')->nullable();
            $table->string('service_tier')->nullable();
            $table->string('speed')->nullable();
            $table->boolean('batch')->default(false);
            $table->boolean('completed')->default(true);
            $table->unsignedInteger('duration_ms')->nullable();
            // Billables with UUID or ULID keys: nullableUuidMorphs() or nullableUlidMorphs() instead.
            $table->nullableMorphs('billable');
            $table->string('feature')->nullable()->index();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anthropic_requests');
    }
};
