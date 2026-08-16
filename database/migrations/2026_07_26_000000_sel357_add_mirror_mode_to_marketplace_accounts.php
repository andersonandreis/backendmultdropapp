<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SEL-357: mirror_mode — conta espelho readonly de outra instalacao (ex: multdrop->sellerapp).
     * Campos: mirror_mode enum, mirror_source_backend, mirror_source_client_id.
     * Jobs de write checam mirror_mode === 'readonly' -> return early + log.
     */
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->enum('mirror_mode', ['active', 'readonly'])
                  ->default('active')
                  ->after('status')
                  ->comment('SEL-357: active=conta real; readonly=espelho mascarado LGPD');
            $table->string('mirror_source_backend', 50)
                  ->nullable()
                  ->after('mirror_mode')
                  ->comment('ex: multdrop, fornecefy');
            $table->unsignedBigInteger('mirror_source_client_id')
                  ->nullable()
                  ->after('mirror_source_backend')
                  ->comment('client_id de origem no backend espelhado');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn(['mirror_mode', 'mirror_source_backend', 'mirror_source_client_id']);
        });
    }
};
