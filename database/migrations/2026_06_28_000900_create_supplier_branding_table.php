<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_branding', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id')->unique();
            $t->string('platform_name', 100)->nullable();
            $t->string('logo_url', 500)->nullable();
            $t->string('favicon_url', 500)->nullable();
            $t->string('primary_color', 20)->default('#3b82f6');
            $t->string('secondary_color', 20)->default('#1e40af');
            $t->string('accent_color', 20)->default('#f59e0b');
            $t->string('background_color', 20)->default('#ffffff');
            $t->string('text_color', 20)->default('#111827');
            $t->string('contact_email', 150)->nullable();
            $t->string('contact_phone', 50)->nullable();
            $t->text('custom_css')->nullable();
            $t->json('extra')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_branding');
    }
};
