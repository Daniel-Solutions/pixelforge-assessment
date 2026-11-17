<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money columns are signed bigints of nanodollars (1 USD = 1,000,000,000). Dates are UTC.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('queued');

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            $table->bigInteger('estimated_min');
            $table->bigInteger('estimated_max');
            $table->json('rates')->nullable();

            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->bigInteger('provider_cost')->nullable();
            $table->bigInteger('charged_amount')->nullable();

            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->unsignedSmallInteger('submit_attempts')->default(0);
            $table->string('last_submit_error')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['status', 'provider_created_at']);
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
