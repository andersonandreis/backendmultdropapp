<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Ex: Kiwify Checkout
            $table->string('slug')->unique(); // Ex: kiwify-checkout (usado na URL)
            $table->string('security_header')->nullable(); // Ex: X-Kiwify-Signature
            $table->string('event_field')->nullable(); // Ex: ['event_name']
            $table->string('expected_event_value')->nullable(); // Ex: order_approved
            $table->string('customer_email_field')->nullable(); // Ex: ['Customer']['email']
            $table->string('amount_field')->nullable(); // Ex: ['webhook_data']['approved_amount']
            $table->string('subscription_id_field')->nullable(); // Ex: ['Subscription']['id']
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_configs');
    }
};
