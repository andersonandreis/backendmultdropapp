<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('admin_domain')->nullable()->unique()->after('slug')
                  ->comment('Dominio do painel Filament deste supplier. Usado por ScopePanelToSupplier.');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('admin_domain');
        });
    }
};
