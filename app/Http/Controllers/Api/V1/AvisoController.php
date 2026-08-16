<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Aviso;
use App\Models\AvisoRead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-264 — endpoints Avisos (canal notícias + push automático).
 * Rotas cliente: /api/v1/avisos, /api/v1/avisos/{id}/read
 * Rotas admin (super_admin): /api/v1/admin/avisos CRUD
 */
class AvisoController extends Controller
{
    /** GET /api/v1/avisos — lista avisos published pro cliente autenticado, com flag read. */
    public function index(Request $request)
    {
        $user = $request->user();
        $client = $user?->client;
        if (!$client) return response()->json(['data' => []]);

        $readIds = AvisoRead::where('client_id', $client->id)->pluck('aviso_id')->toArray();
        $readSet = array_flip($readIds);

        $rows = Aviso::published()
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();

        $data = $rows->map(function ($a) use ($readSet) {
            return [
                'id'                => $a->id,
                'titulo'            => $a->titulo,
                'body_push'         => $a->body_push,
                'conteudo_markdown' => $a->conteudo_markdown,
                'categoria'         => $a->categoria,
                'prioridade'        => $a->prioridade,
                'published_at'      => $a->published_at?->toIso8601String(),
                'cta_label'         => $a->cta_label,
                'cta_url'           => $a->cta_url,
                'cover_url'         => $a->cover_url,
                'requires_plan'     => $a->requires_plan,
                'read'              => isset($readSet[$a->id]),
            ];
        });

        return response()->json([
            'data'         => $data,
            'unread_count' => $data->where('read', false)->count(),
        ]);
    }

    /** POST /api/v1/avisos/{id}/read — marca aviso como lido pelo cliente. */
    public function markRead(Request $request, string $id)
    {
        $client = $request->user()?->client;
        if (!$client) return response()->json(['message' => 'sem cliente'], 403);
        AvisoRead::firstOrCreate(
            ['aviso_id' => $id, 'client_id' => $client->id],
            ['read_at' => now()]
        );
        return response()->json(['ok' => true]);
    }

    /** GET /api/v1/admin/avisos — lista TODOS incluindo unpublished (super_admin). */
    public function adminIndex(Request $request)
    {
        return response()->json([
            'data' => Aviso::orderByDesc('created_at')->limit(200)->get(),
        ]);
    }

    /** POST /api/v1/admin/avisos — cria (super_admin). */
    public function adminStore(Request $request)
    {
        $data = $request->validate([
            'titulo'            => 'required|string|max:200',
            'body_push'         => 'required|string|max:200',
            'conteudo_markdown' => 'required|string',
            'categoria'         => 'required|in:compliance,oferta,dica,alerta,novidade',
            'prioridade'        => 'required|in:urgente,alta,media,baixa',
            'published_at'      => 'nullable|date',
            'cta_label'         => 'nullable|string|max:100',
            'cta_url'           => 'nullable|string|max:500',
            'cover_url'         => 'nullable|string|max:500',
            'requires_plan'     => 'nullable|in:free,scaling,pro',
        ]);
        $data['created_by'] = $request->user()->id;
        $aviso = Aviso::create($data);
        return response()->json($aviso, 201);
    }

    /** PATCH /api/v1/admin/avisos/{id} (super_admin). */
    public function adminUpdate(Request $request, string $id)
    {
        $aviso = Aviso::findOrFail($id);
        $data = $request->validate([
            'titulo'            => 'sometimes|string|max:200',
            'body_push'         => 'sometimes|string|max:200',
            'conteudo_markdown' => 'sometimes|string',
            'categoria'         => 'sometimes|in:compliance,oferta,dica,alerta,novidade',
            'prioridade'        => 'sometimes|in:urgente,alta,media,baixa',
            'published_at'      => 'nullable|date',
            'cta_label'         => 'nullable|string|max:100',
            'cta_url'           => 'nullable|string|max:500',
            'cover_url'         => 'nullable|string|max:500',
            'requires_plan'     => 'nullable|in:free,scaling,pro',
        ]);
        $aviso->update($data);
        return response()->json($aviso);
    }

    /** DELETE /api/v1/admin/avisos/{id} (super_admin, soft delete). */
    public function adminDestroy(string $id)
    {
        Aviso::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }

    /** POST /api/v1/admin/avisos/{id}/publish-now — força published_at = now e enfileira push. */
    public function adminPublishNow(string $id)
    {
        $aviso = Aviso::findOrFail($id);
        $aviso->update(['published_at' => now()]);
        // O cron PublishScheduledAvisosJob roda a cada 1min e dispara push.
        return response()->json(['ok' => true, 'aviso' => $aviso]);
    }
}
