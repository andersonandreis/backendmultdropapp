<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SEL-473 (31/07) — administrar o acervo de avatares.
 *
 * Ruan, várias vezes: "cadê a opção de eu colocar os avatares?". Não existia.
 * Havia `POST /videostudio/avatars/upload`, mas ele é do CLIENTE e só grava uma
 * URL em `client_video_avatars` — não põe nada no acervo `video_avatars`, que é
 * de onde a grade do Studio tira os apresentadores.
 *
 * Ou seja: os 11 avatares que existem foram parar lá por outro caminho, e não
 * havia como acrescentar o décimo segundo. Isto resolve.
 */
class AdminVideoAvatarController extends Controller
{
    private function ehAdmin(Request $r): bool
    {
        return in_array((string) ($r->user()->role ?? ''), ['admin', 'super_admin'], true);
    }

    /** Acervo inteiro, com quem reservou cada um. */
    public function index(Request $r)
    {
        if (! $this->ehAdmin($r)) return response()->json(['message' => 'apenas admin'], 403);

        $linhas = DB::table('video_avatars as a')
            ->leftJoin('clients as c', 'c.id', '=', 'a.reserved_client_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->orderBy('a.id')
            ->get([
                'a.id', 'a.label', 'a.image_url', 'a.gender', 'a.style',
                'a.is_active', 'a.is_reserved', 'a.reserved_client_id', 'a.reserved_at',
                'u.email as dono_email',
            ]);

        return response()->json([
            'avatares'  => $linhas,
            'total'     => $linhas->count(),
            'livres'    => $linhas->where('is_reserved', 0)->count(),
        ]);
    }

    /** Acrescenta um avatar ao acervo — arquivo de verdade, não URL solta. */
    public function store(Request $r)
    {
        if (! $this->ehAdmin($r)) return response()->json(['message' => 'apenas admin'], 403);

        $r->validate([
            'imagem' => 'required|file|mimes:jpeg,jpg,png,webp|max:51200',
            'label'  => 'required|string|max:120',
            'gender' => 'nullable|string|max:20',
            'style'  => 'nullable|string|max:60',
        ]);

        $arquivo = $r->file('imagem');
        $nome = 'avatar-admin-' . now()->timestamp . '-' . substr(md5(uniqid('', true)), 0, 8)
              . '.' . $arquivo->getClientOriginalExtension();

        $arquivo->storeAs('avatars', $nome, 'public');
        $url = rtrim(config('app.url', 'https://api.seller.global'), '/') . '/storage/avatars/' . $nome;

        $id = DB::table('video_avatars')->insertGetId([
            'label'      => $r->string('label')->toString(),
            'image_url'  => $url,
            'gender'     => $r->input('gender'),
            'style'      => $r->input('style'),
            'is_active'  => 1,
            'is_reserved' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('[SEL-473] avatar adicionado ao acervo pelo admin', [
            'id' => $id, 'por' => $r->user()->email ?? null,
        ]);

        return response()->json(['ok' => true, 'id' => $id, 'image_url' => $url], 201);
    }

    /** Liberar um avatar preso num cliente, ou ligar/desligar do acervo. */
    public function update(Request $r, int $id)
    {
        if (! $this->ehAdmin($r)) return response()->json(['message' => 'apenas admin'], 403);

        $r->validate([
            'liberar'   => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'label'     => 'nullable|string|max:120',
        ]);

        $mudanca = ['updated_at' => now()];
        if ($r->boolean('liberar')) {
            // devolve pro acervo — o cliente que estava com ele perde a exclusividade
            $mudanca['is_reserved'] = 0;
            $mudanca['reserved_client_id'] = null;
            $mudanca['reserved_at'] = null;
        }
        if ($r->has('is_active')) $mudanca['is_active'] = $r->boolean('is_active') ? 1 : 0;
        if ($r->filled('label'))  $mudanca['label'] = $r->string('label')->toString();

        DB::table('video_avatars')->where('id', $id)->update($mudanca);
        Log::info('[SEL-473] avatar alterado pelo admin', ['id' => $id, 'mudanca' => array_keys($mudanca)]);

        return response()->json(['ok' => true]);
    }
}
