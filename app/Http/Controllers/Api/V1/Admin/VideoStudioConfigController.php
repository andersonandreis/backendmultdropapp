<?php
namespace App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VideoStudioConfigController extends Controller
{
    public function index() {
        return response()->json(DB::table('video_studio_configs')->orderBy('display_order')->get());
    }
    public function update(Request $r, int $id) {
        $data = $r->only(['label','description','is_active','cost_cents','client_price_cents','display_order','max_duration_sec']);
        if (array_key_exists('max_duration_sec', $data)) {
            $data['max_duration_sec'] = max(1, min(600, (int) $data['max_duration_sec']));
            \Cache::forget('studio_max_duration'); // SEL-364d: chat reoferece na hora
        }
        DB::table('video_studio_configs')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
        return response()->json(['ok' => true]);
    }
    public function publicList() {
        return response()->json(DB::table('video_studio_configs')->where('is_active', 1)->orderBy('display_order')->get());
    }
}
