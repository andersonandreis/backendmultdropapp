<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HUB-QZ 2026-07-17 — preferências de impressão QZ Tray por usuário.
 *
 * default_printer_name: nome exato da impressora conectada (ex: "Zebra ZD220")
 * qz_print_trigger:    quando disparar auto-print no bip:
 *   - 'disabled': nunca (fluxo manual antigo)
 *   - 'first_beep': quando bipar pra separar (awaiting_dispatch->separated)
 *   - 'second_beep': DEFAULT — quando bipar pra enviar (separated->shipped)
 *   - 'both': ambos os bips
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'default_printer_name')) {
                $t->string('default_printer_name', 191)->nullable();
            }
            if (! Schema::hasColumn('users', 'qz_print_trigger')) {
                $t->string('qz_print_trigger', 20)->default('second_beep');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'default_printer_name')) {
                $t->dropColumn('default_printer_name');
            }
            if (Schema::hasColumn('users', 'qz_print_trigger')) {
                $t->dropColumn('qz_print_trigger');
            }
        });
    }
};
