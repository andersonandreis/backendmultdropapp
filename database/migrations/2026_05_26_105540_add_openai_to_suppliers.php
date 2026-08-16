<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->text('openai_api_key')->nullable()->after('is_private'); // encrypted via cast
            $t->string('openai_model', 60)->nullable()->after('openai_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->dropColumn(['openai_api_key', 'openai_model']);
        });
    }
};
