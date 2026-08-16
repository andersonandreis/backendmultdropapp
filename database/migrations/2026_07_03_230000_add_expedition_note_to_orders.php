<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table("orders", function (Blueprint $table) {
            if (!Schema::hasColumn("orders", "expedition_note"))
                $table->string("expedition_note", 100)->nullable()->after("seller_notes");
            if (!Schema::hasColumn("orders", "expedition_note_read_at"))
                $table->timestamp("expedition_note_read_at")->nullable()->after("expedition_note");
            if (!Schema::hasColumn("orders", "expedition_note_read_by"))
                $table->string("expedition_note_read_by", 64)->nullable()->after("expedition_note_read_at");
        });
    }
    public function down(): void {
        Schema::table("orders", function (Blueprint $table) {
            foreach (["expedition_note", "expedition_note_read_at", "expedition_note_read_by"] as $col)
                if (Schema::hasColumn("orders", $col)) $table->dropColumn($col);
        });
    }
};
