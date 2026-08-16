<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * SEL-332: Banco de músicas royalty-free para o modo Showcase Silencioso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create("showcase_audio_library", function (Blueprint $t) {
            $t->id();
            $t->string("slug")->unique();
            $t->string("title");
            $t->string("mood");
            $t->string("file_path");
            $t->integer("duration_sec")->default(60);
            $t->string("license")->default("royalty-free");
            $t->boolean("active")->default(true);
            $t->timestamps();
        });

        $tracks = [
            ["slug" => "chill_bloom_01",  "title" => "Cerejeira em Flor",    "mood" => "chill",  "file_path" => "audio-library/chill_bloom_01.mp3",  "duration_sec" => 60],
            ["slug" => "chill_morning_02","title" => "Manha Serena",          "mood" => "chill",  "file_path" => "audio-library/chill_morning_02.mp3","duration_sec" => 55],
            ["slug" => "chill_tide_03",   "title" => "Mare da Tarde",         "mood" => "chill",  "file_path" => "audio-library/chill_tide_03.mp3",  "duration_sec" => 62],
            ["slug" => "chill_dew_04",    "title" => "Orvalho Digital",       "mood" => "chill",  "file_path" => "audio-library/chill_dew_04.mp3",   "duration_sec" => 58],
            ["slug" => "upbeat_glam_05",  "title" => "Glamour Instantaneo",   "mood" => "upbeat", "file_path" => "audio-library/upbeat_glam_05.mp3", "duration_sec" => 45],
            ["slug" => "upbeat_pop_06",   "title" => "Try-Haul Vibes",        "mood" => "upbeat", "file_path" => "audio-library/upbeat_pop_06.mp3",  "duration_sec" => 50],
            ["slug" => "upbeat_spark_07", "title" => "Centelha Criativa",     "mood" => "upbeat", "file_path" => "audio-library/upbeat_spark_07.mp3","duration_sec" => 48],
            ["slug" => "epic_reveal_08",  "title" => "Revelacao Epica",       "mood" => "epic",   "file_path" => "audio-library/epic_reveal_08.mp3", "duration_sec" => 52],
            ["slug" => "epic_crown_09",   "title" => "Coroa de Produto",      "mood" => "epic",   "file_path" => "audio-library/epic_crown_09.mp3",  "duration_sec" => 55],
            ["slug" => "lofi_focus_10",   "title" => "Foco Suave",            "mood" => "lofi",   "file_path" => "audio-library/lofi_focus_10.mp3",  "duration_sec" => 65],
            ["slug" => "lofi_rain_11",    "title" => "Chuva de Pensamentos",  "mood" => "lofi",   "file_path" => "audio-library/lofi_rain_11.mp3",   "duration_sec" => 70],
            ["slug" => "lofi_night_12",   "title" => "Noturno Lo-Fi",         "mood" => "lofi",   "file_path" => "audio-library/lofi_night_12.mp3",  "duration_sec" => 68],
        ];

        foreach ($tracks as $track) {
            DB::table("showcase_audio_library")->insert(array_merge($track, [
                "license"    => "royalty-free",
                "active"     => true,
                "created_at" => now(),
                "updated_at" => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists("showcase_audio_library");
    }
};
