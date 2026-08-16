<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SEL-113 fase 2A: marca convite grupo WhatsApp (manual OU auto).
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('clients') && !Schema::hasColumn('clients', 'whatsapp_invited_at')) {
            Schema::table('clients', function (Blueprint $t) {
                $t->timestamp('whatsapp_invited_at')->nullable()->after('survey_skipped_at');
            });
        }
        if (!Schema::hasTable('whatsapp_group_configs')) {
            Schema::create('whatsapp_group_configs', function (Blueprint $t) {
                $t->id();
                $t->string('group_url', 500)->nullable();
                $t->boolean('auto_invite_enabled')->default(false);
                $t->unsignedInteger('auto_invite_limit')->default(0);
                $t->unsignedInteger('auto_invite_used')->default(0);
                $t->timestamps();
            });
            // Row unica id=1 (config global do tenant seller.global)
            \DB::table('whatsapp_group_configs')->insert([
                'id' => 1,
                'group_url' => 'https://chat.whatsapp.com/ItIg7lRQ60Q5HPnsG4S6Jq?s=cl&p=i&ilr=4',
                'auto_invite_enabled' => false,
                'auto_invite_limit' => 0,
                'auto_invite_used' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
    public function down(): void {
        if (Schema::hasColumn('clients', 'whatsapp_invited_at')) {
            Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('whatsapp_invited_at'));
        }
        Schema::dropIfExists('whatsapp_group_configs');
    }
};
