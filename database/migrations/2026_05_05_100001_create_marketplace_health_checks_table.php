<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            $table->string('metric'); // 'reputation', 'claims_rate', 'cancellation_rate', 'late_shipment_rate', 'response_time'
            $table->decimal('value', 10, 4);
            $table->string('status'); // 'healthy', 'warning', 'critical'
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['marketplace_account_id', 'metric']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_health_checks');
    }
};
