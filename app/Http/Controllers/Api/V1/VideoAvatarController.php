<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RefillAvatarPoolJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * SEL-120 F2: Avatares de vídeo — index / reserve / upload.
 * SEL-195 Gap A+B: reserve() agora valida pool livre antes de confirmar e
 * dispara RefillAvatarPoolJob assíncrono pra repor o avatar no catálogo público.
 *
 * SEL-11 (06/08): client_video_avatars deixou de ser 1:1 por client_id na
 * migration SEL-361 (agora N linhas por cliente, cada uma com `source`
 * upload/pool_shared/auto_geracao/generated_exclusive). reserve() e upload()
 * ainda faziam updateOrInsert(['client_id'=>...]) — sem o unique antigo isso
 * atualiza TODAS as linhas do cliente de uma vez, sobrescrevendo avatar
 * exclusivo/auto_gerado com dado do pool (corrupção confirmada no client_id
 * 730). Agora cada método escopa por (client_id, source) e só mexe na SUA
 * própria linha.
 *
 * Tabelas:
 *   video_avatars       — pool de avatares (is_reserved, reserved_client_id, reserved_at)
 *   client_video_avatars— avatares do cliente (N linhas, uma por source)
 */
class VideoAvatarController extends Controller
{
    /** Retorna avatares disponíveis + avatar reservado do cliente atual. */
    public function index(Request $r)
    {
        $clientId = optional($r->user()?->client)->id ?? 0;

        // SEL-195: avatares livres OU próprios do cliente (reservados por outro ficam fora)
        // SEL-431 (30/07, Ruan: "nem os avatares você colocou"). Os 11 avatares
        // prontos estão TODOS reservados, um por cliente -- então o admin via
        // apenas 1 e a vitrine parecia vazia. Admin enxerga o acervo inteiro;
        // a regra de exclusividade do cliente segue igual.
        $ehAdmin = in_array((string) (optional($r->user())->role ?? ''), ['admin', 'super_admin'], true);

        $avatars = DB::table('video_avatars')
            ->where('is_active', 1)
            ->when(! $ehAdmin, function ($q) use ($clientId) {
                $q->where(function ($q2) use ($clientId) {
                    $q2->where('is_reserved', 0)
                       ->orWhere('reserved_client_id', $clientId);
                });
            })
            ->orderBy('id')
            ->get();

        $mine = DB::table('client_video_avatars')
            ->where('client_id', $clientId)
            ->first();

        // Inclui o avatar reservado (pode estar fora da lista pública se is_reserved=1 por outro)
        $mineAvatar = null;
        if ($mine && $mine->video_avatar_id) {
            $mineAvatar = DB::table('video_avatars')->where('id', $mine->video_avatar_id)->first();
        }

        // INF-030 (07/08) — item 5 do briefing: `mine`/`mine_avatar` acima
        // pegam só 1 linha (->first()) de client_video_avatars, mas SEL-11
        // (06/08) mudou a tabela pra N linhas por cliente (uma por `source`:
        // upload, pool_shared, auto_geracao, generated_exclusive). O avatar
        // que o ClientAvatarHarvester salva (SEL-497, source=auto_geracao)
        // toda vez que o cliente gera um vídeo com rosto NUNCA aparecia pra
        // reuso — a tela só lia `avatars` (acervo compartilhado). `mine_all`
        // devolve TODAS as linhas ativas do cliente já resolvidas em
        // image_url, pra front oferecer "usar meu avatar" de verdade.
        $mineAll = DB::table('client_video_avatars')
            ->where('client_id', $clientId)
            ->where('is_active', 1)
            ->orderByDesc('id')
            ->get()
            ->map(function ($row) {
                $img = $row->custom_avatar_url;
                if (! $img && $row->video_avatar_id) {
                    $pool = DB::table('video_avatars')->where('id', $row->video_avatar_id)->first();
                    $img = $pool->image_url ?? null;
                }
                return [
                    'id'        => $row->id,
                    'source'    => $row->source,
                    'label'     => $row->label ?: 'Meu avatar',
                    'image_url' => $img,
                ];
            })
            ->filter(fn ($r) => ! empty($r['image_url']))
            ->values();

        return response()->json([
            'avatars' => $avatars,
            'mine'    => $mine,
            'mine_avatar' => $mineAvatar,
            'mine_all' => $mineAll,
        ]);
    }

    /**
     * SEL-195 Gap A: Reserva avatar pra um cliente.
     * Regra: avatar já reservado por outro → 409.
     * Pós-reserva: dispara RefillAvatarPoolJob pra gerar novo avatar assíncrono.
     */
    public function reserve(Request $r, int $id)
    {
        $client = $r->user()?->client;
        if (!$client) {
            return response()->json(['message' => 'sem client'], 422);
        }

        $avatar = DB::table('video_avatars')
            ->where('id', $id)
            ->where('is_active', 1)
            ->first();

        if (!$avatar) {
            return response()->json(['message' => 'avatar nao encontrado'], 404);
        }

        // Bloqueia se já reservado por OUTRO cliente
        if ($avatar->is_reserved && $avatar->reserved_client_id != $client->id) {
            return response()->json(['message' => 'avatar ja reservado por outro cliente'], 409);
        }

        // Libera avatar anterior do cliente (se tiver) -- escopado por
        // source='pool_shared', nunca pega a linha exclusiva/auto_geracao
        // do cliente (SEL-11: client_video_avatars agora tem N linhas/cliente).
        $prev = DB::table('client_video_avatars')
            ->where('client_id', $client->id)
            ->where('source', 'pool_shared')
            ->first();
        if ($prev && $prev->video_avatar_id && $prev->video_avatar_id !== $id) {
            DB::table('video_avatars')->where('id', $prev->video_avatar_id)->update([
                'is_reserved'       => 0,
                'reserved_client_id' => null,
                'reserved_at'       => null,
            ]);
        }

        // Registra avatar ativo do cliente -- escopado por (client_id, source)
        // pra so afetar a linha de pool deste cliente, nunca as outras.
        DB::table('client_video_avatars')->updateOrInsert(
            ['client_id' => $client->id, 'source' => 'pool_shared'],
            [
                'video_avatar_id'        => $id,
                'label'                  => $avatar->label,
                'custom_avatar_url'      => null,
                'is_exclusive_to_client' => false,
                'is_active'              => true,
                'updated_at'             => now(),
                'created_at'             => now(),
            ]
        );

        // Marca avatar como reservado
        DB::table('video_avatars')->where('id', $id)->update([
            'is_reserved'        => 1,
            'reserved_client_id' => $client->id,
            'reserved_at'        => now(),
        ]);

        // SEL-195 Gap B: dispara job de reposição do pool assíncrono
        Bus::dispatch(new RefillAvatarPoolJob());

        return response()->json([
            'ok'        => true,
            'avatar_id' => $id,
            'reserved'  => true,
        ]);
    }

    /**
     * Upload de avatar personalizado (URL).
     * SEL-11: agora seta source='upload' + is_exclusive_to_client=true --
     * antes ficava no default 'pool_shared', então o ClientAvatarResolver
     * nunca tratava uma foto de verdade enviada pelo cliente como exclusiva
     * (o gate anti-strike do TikTok não reconhecia esse avatar).
     * Escopado por (client_id, source='upload'): só mexe na linha de upload
     * deste cliente, nunca na exclusiva gerada ou na do pool.
     * {avatar_url:null} (SEL-337, "saveRandom" do frontend) desativa o
     * upload anterior em vez de gravar uma linha "exclusiva" vazia.
     */
    public function upload(Request $r)
    {
        $client = $r->user()?->client;
        if (!$client) {
            return response()->json(['message' => 'sem client'], 422);
        }
        $r->validate(['avatar_url' => 'nullable|string|max:512']);

        $url = $r->input('avatar_url');

        if ($url) {
            DB::table('client_video_avatars')->updateOrInsert(
                ['client_id' => $client->id, 'source' => 'upload'],
                [
                    'custom_avatar_url'      => $url,
                    'video_avatar_id'        => null,
                    'label'                  => 'Meu avatar',
                    'is_exclusive_to_client' => true,
                    'is_active'              => true,
                    'updated_at'             => now(),
                    'created_at'             => now(),
                ]
            );
        } else {
            // avatar_url null = "saveRandom": desliga o upload anterior sem
            // apagar/mexer nas outras linhas do cliente.
            DB::table('client_video_avatars')
                ->where('client_id', $client->id)
                ->where('source', 'upload')
                ->update(['is_active' => false, 'updated_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * SEL-AVATAR-APAGAR (14/08) — a tela mandava o cliente fazer o impossivel.
     *
     * Ao criar o 4o apresentador o backend respondia, literalmente:
     *   "Voce ja tem 3 apresentadores criados. Apague um para criar outro."
     * ...e NAO existia nenhuma rota DELETE de avatar em todo o projeto (conferido em
     * `route:list --path=avatar`: 8 rotas, nenhuma de apagar). Ou seja: o cliente batia
     * no limite e ficava preso pra sempre, seguindo uma instrucao que nao tinha botao.
     * Medido: 6 clientes ja estavam nesse beco.
     *
     * Apaga SOFT (is_active=0): o avatar pode estar amarrado a video ja entregue na
     * galeria do cliente, e sumir o rosto de um video que ele ja baixou seria pior que
     * o problema que estou consertando.
     */
    public function destroy(Request $r, int $id)
    {
        $client = \Illuminate\Support\Facades\DB::table('clients')
            ->where('user_id', $r->user()->id)->first();
        if (! $client) {
            return response()->json(['message' => 'Cadastro incompleto.'], 403);
        }

        $avatar = \Illuminate\Support\Facades\DB::table('client_video_avatars')
            ->where('id', $id)->where('client_id', $client->id)->first();

        // 404 tambem quando o avatar e de OUTRO cliente: nao confirmo pra ninguem que
        // o id existe na conta alheia.
        if (! $avatar) {
            return response()->json(['message' => 'Apresentador nao encontrado.'], 404);
        }

        if (! $avatar->is_active) {
            return response()->json(['ok' => true, 'ja_estava_apagado' => true]);
        }

        \Illuminate\Support\Facades\DB::table('client_video_avatars')
            ->where('id', $id)
            ->update(['is_active' => 0, 'updated_at' => now()]);

        $restam = \Illuminate\Support\Facades\DB::table('client_video_avatars')
            ->where('client_id', $client->id)
            ->where('source', 'generated_exclusive')
            ->where('is_active', true)->count();

        \Illuminate\Support\Facades\Log::error('[SEL-AVATAR-APAGAR] cliente apagou apresentador', [
            'client_id' => $client->id, 'avatar_id' => $id, 'restam' => $restam,
        ]);

        return response()->json([
            'ok'      => true,
            'restam'  => $restam,
            'message' => 'Apresentador apagado. Voce pode criar outro agora.',
        ]);
    }
}
