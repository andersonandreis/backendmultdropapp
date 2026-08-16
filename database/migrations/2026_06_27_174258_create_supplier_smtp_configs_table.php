<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_smtp_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('smtp_host');
            $table->integer('smtp_port')->default(587);
            $table->string('smtp_user');
            $table->string('smtp_password');
            $table->string('smtp_from_name')->nullable();
            $table->string('smtp_from_email')->nullable();
            $table->enum('smtp_encryption', ['tls', 'ssl', 'none'])->default('tls');
            $table->boolean('active')->default(true);
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_success')->nullable();
            $table->text('last_test_error')->nullable();
            $table->timestamps();

            $table->unique('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_smtp_configs');
    }
};
