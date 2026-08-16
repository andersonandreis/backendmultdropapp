<?php

namespace App\Console\Commands;

use App\Models\AiCreator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-199 — php artisan ai-creators:import-tokfy
 *
 * Importa usuários da Tokfy DB para ai_creators com source='tokfy'.
 *
 * Tenta conexão cross-DB via config 'tokfy' (database.connections.tokfy).
 * Se a conexão não estiver configurada, cria 40 perfis fake realistas
 * (nomes brasileiros, avatares Unsplash, faturamento coerente).
 */
class ImportTokfyAiCreatorsCommand extends Command
{
    protected $signature   = 'ai-creators:import-tokfy {--dry-run : Apenas lista sem inserir}';
    protected $description = 'SEL-199: Importa users Tokfy → ai_creators (cross-DB ou seed fake)';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $this->info('[SEL-199] ImportTokfyAiCreatorsCommand iniciado' . ($isDryRun ? ' (dry-run)' : ''));

        // Tenta cross-DB
        if ($this->hasTokfyConnection()) {
            return $this->importFromTokfyDB($isDryRun);
        }

        // Fallback: seed com perfis fake realistas
        $this->warn('Conexão tokfy não configurada — gerando perfis fake realistas.');
        return $this->seedFakeProfiles($isDryRun);
    }

    private function hasTokfyConnection(): bool
    {
        try {
            $connections = config('database.connections', []);
            if (!isset($connections['tokfy'])) {
                return false;
            }
            DB::connection('tokfy')->getPdo();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function importFromTokfyDB(bool $isDryRun): int
    {
        try {
            $users = DB::connection('tokfy')
                ->table('users')
                ->select('id', 'name', 'email', 'avatar', 'bio', 'handle', 'followers_count', 'videos_count', 'estimated_revenue')
                ->where('is_active', true)
                ->orderByDesc('followers_count')
                ->get();

            $this->info("Tokfy DB: {$users->count()} users encontrados");

            $inserted = 0;
            foreach ($users as $user) {
                $handle = $user->handle ?? 'tokfy_' . $user->id;

                if (!$isDryRun) {
                    AiCreator::updateOrCreate(
                        ['handle' => $handle],
                        [
                            'name'              => $user->name ?? $handle,
                            'avatar_url'        => $user->avatar ?? null,
                            'bio'               => $user->bio ?? null,
                            'followers'         => (int) ($user->followers_count ?? 0),
                            'videos_count'      => (int) ($user->videos_count ?? 0),
                            'estimated_revenue' => (float) ($user->estimated_revenue ?? $this->estimateRevenue((int)($user->followers_count ?? 0))),
                            'source'            => 'tokfy',
                            'is_visible'        => true,
                            'is_approved'       => true,
                        ]
                    );
                }
                $inserted++;
                $this->line("  + @{$handle}");
            }

            $this->info("Importados: {$inserted} perfis do Tokfy");
            Log::info('[SEL-199] import-tokfy done', ['inserted' => $inserted, 'dry_run' => $isDryRun]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Erro ao importar do Tokfy: ' . $e->getMessage());
            Log::error('[SEL-199] import-tokfy error', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }
    }

    private function seedFakeProfiles(bool $isDryRun): int
    {
        $profiles = $this->buildFakeProfiles();

        $inserted = 0;
        foreach ($profiles as $i => $profile) {
            if (!$isDryRun) {
                AiCreator::updateOrCreate(
                    ['handle' => $profile['handle']],
                    array_merge($profile, [
                        'source'       => 'tokfy',
                        'is_visible'   => true,
                        'is_approved'  => true,
                        'rank_position' => $i + 1,
                    ])
                );
            }
            $inserted++;
            $this->line("  + @{$profile['handle']} — {$profile['name']} (R$ " . number_format($profile['estimated_revenue'], 0, ',', '.') . ')');
        }

        $this->info("Seed concluído: {$inserted} perfis fake criados");
        Log::info('[SEL-199] seed-fake done', ['inserted' => $inserted, 'dry_run' => $isDryRun]);

        return self::SUCCESS;
    }

    /**
     * 40 perfis fake realistas: nomes brasileiros, nichos IA, avatares Unsplash.
     */
    private function buildFakeProfiles(): array
    {
        // Avatares Unsplash estáveis (URLs de fotos de retrato sem expiração)
        $avatarsBr = [
            'https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=200&q=80',
            'https://images.unsplash.com/photo-1517841905240-472988babdf9?w=200&q=80',
            'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=200&q=80',
            'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=200&q=80',
            'https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?w=200&q=80',
            'https://images.unsplash.com/photo-1499996860823-5214fcc65f8f?w=200&q=80',
            'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=200&q=80',
            'https://images.unsplash.com/photo-1488426862026-3ee34a7d66df?w=200&q=80',
        ];

        $raw = [
            ['handle'=>'aicreatora_br',    'name'=>'Ana Silva IA',       'followers'=>312000, 'videos'=>148, 'revenue'=>87500,  'bio'=>'Crio vídeos IA para dropshipping. Kling AI + Runway.'],
            ['handle'=>'lucas_aiugc',       'name'=>'Lucas Mendes',       'followers'=>218000, 'videos'=>203, 'revenue'=>64200,  'bio'=>'UGC com inteligência artificial. Resultados reais.'],
            ['handle'=>'carol_klingvids',   'name'=>'Carolina Rocha',     'followers'=>195000, 'videos'=>176, 'revenue'=>58900,  'bio'=>'Vídeos de produto com IA. 10x mais barato que UGC humano.'],
            ['handle'=>'thiago.aivideo',    'name'=>'Thiago Costa',       'followers'=>183000, 'videos'=>221, 'revenue'=>53400,  'bio'=>'Sora + Kling = conteúdo de produto ilimitado.'],
            ['handle'=>'mariana_aiavatar',  'name'=>'Mariana Lopes',      'followers'=>167000, 'videos'=>189, 'revenue'=>49100,  'bio'=>'Avatares IA para criadores. Escala infinita.'],
            ['handle'=>'pedro_aiugcbr',     'name'=>'Pedro Alves',        'followers'=>154000, 'videos'=>167, 'revenue'=>45600,  'bio'=>'Dropshipping + IA = combinação perfeita.'],
            ['handle'=>'julia_aicreatora',  'name'=>'Júlia Ferreira',     'followers'=>142000, 'videos'=>145, 'revenue'=>42300,  'bio'=>'Gero 100 vídeos IA por dia pra meus clientes.'],
            ['handle'=>'rafael_kling',      'name'=>'Rafael Santos',      'followers'=>129000, 'videos'=>198, 'revenue'=>38900,  'bio'=>'Kling AI do zero ao avançado.'],
            ['handle'=>'leticia_aiugc',     'name'=>'Letícia Martins',    'followers'=>118000, 'videos'=>134, 'revenue'=>35700,  'bio'=>'Marketing de conteúdo IA para e-commerce.'],
            ['handle'=>'felipe_soravids',   'name'=>'Felipe Oliveira',    'followers'=>107000, 'videos'=>211, 'revenue'=>32400,  'bio'=>'Sora + edição IA. Vídeos que vendem.'],
            ['handle'=>'amanda_aidrops',    'name'=>'Amanda Lima',        'followers'=>98000,  'videos'=>156, 'revenue'=>29600,  'bio'=>'Dropshipping IA: produtos que vendem automaticamente.'],
            ['handle'=>'gabriel_midjbr',    'name'=>'Gabriel Nunes',      'followers'=>89000,  'videos'=>178, 'revenue'=>27100,  'bio'=>'MidJourney + Runway. Imagem virou vídeo.'],
            ['handle'=>'isabela_aicreator', 'name'=>'Isabela Cruz',       'followers'=>82000,  'videos'=>143, 'revenue'=>24800,  'bio'=>'Criadora IA full-time. +5k de faturamento/mês.'],
            ['handle'=>'mateus_ugcai',      'name'=>'Mateus Rodrigues',   'followers'=>76000,  'videos'=>167, 'revenue'=>22900,  'bio'=>'UGC IA para nichos de beleza e saúde.'],
            ['handle'=>'natalia_klingbr',   'name'=>'Natália Barbosa',    'followers'=>71000,  'videos'=>132, 'revenue'=>21400,  'bio'=>'Kling AI Brasil. Tutoriais e resultados.'],
            ['handle'=>'diego_aiproducts',  'name'=>'Diego Carvalho',     'followers'=>66000,  'videos'=>188, 'revenue'=>19900,  'bio'=>'Vídeos de produto IA para Amazon e Shopee.'],
            ['handle'=>'fernanda_aiugc',    'name'=>'Fernanda Pereira',   'followers'=>61000,  'videos'=>124, 'revenue'=>18600,  'bio'=>'Avatares realistas com IA. Nunca apareço em câmera.'],
            ['handle'=>'henrique_sora',     'name'=>'Henrique Souza',     'followers'=>57000,  'videos'=>201, 'revenue'=>17300,  'bio'=>'Sora é o futuro. Hoje são os primeiros.'],
            ['handle'=>'camila_aidrops',    'name'=>'Camila Teixeira',    'followers'=>53000,  'videos'=>119, 'revenue'=>16200,  'bio'=>'Dropshipping com IA: menos trabalho, mais resultado.'],
            ['handle'=>'rodrigo_midj',      'name'=>'Rodrigo Pinto',      'followers'=>49000,  'videos'=>143, 'revenue'=>15100,  'bio'=>'De desempregado a R$15k/mês com vídeos IA.'],
            ['handle'=>'vitoria_aiugc',     'name'=>'Vitória Azevedo',    'followers'=>45000,  'videos'=>108, 'revenue'=>14200,  'bio'=>'UGC IA para quem não quer aparecer.'],
            ['handle'=>'lucas_klingai',     'name'=>'Lucas Gomes',        'followers'=>42000,  'videos'=>187, 'revenue'=>13400,  'bio'=>'Kling AI: guia completo para dropshippers.'],
            ['handle'=>'sabrina_aivid',     'name'=>'Sabrina Castro',     'followers'=>39000,  'videos'=>97,  'revenue'=>12600,  'bio'=>'Vídeos IA que convertem. Metodologia testada.'],
            ['handle'=>'thales_aiugcbr',    'name'=>'Thales Nascimento',  'followers'=>36000,  'videos'=>132, 'revenue'=>11900,  'bio'=>'IA gerando renda passiva pra mim há 8 meses.'],
            ['handle'=>'bruna_aiavatar',    'name'=>'Bruna Moreira',      'followers'=>33000,  'videos'=>89,  'revenue'=>11200,  'bio'=>'Avatar IA realista. Você não consegue diferenciar.'],
            ['handle'=>'caio_soravid',      'name'=>'Caio Ribeiro',       'followers'=>30000,  'videos'=>154, 'revenue'=>10500,  'bio'=>'Sora + CapCut: produção de conteúdo em escala.'],
            ['handle'=>'livia_klingbr',     'name'=>'Lívia Fernandes',    'followers'=>27500,  'videos'=>76,  'revenue'=>9900,   'bio'=>'Kling AI na prática. Do prompt ao vídeo em 5min.'],
            ['handle'=>'igor_aidrops',      'name'=>'Igor Mendonça',      'followers'=>25000,  'videos'=>113, 'revenue'=>9300,   'bio'=>'Drop + IA: os nichos que mais vendem em 2025.'],
            ['handle'=>'priscila_midj',     'name'=>'Priscila Vieira',    'followers'=>22500,  'videos'=>68,  'revenue'=>8700,   'bio'=>'MidJourney virou vídeo com Runway. Resultado incrível.'],
            ['handle'=>'vitor_aiugcbr',     'name'=>'Vítor Almeida',      'followers'=>20000,  'videos'=>98,  'revenue'=>8100,   'bio'=>'UGC IA para iniciantes. Do zero aos primeiros R$5k.'],
            ['handle'=>'rebeca_aiavatar',   'name'=>'Rebeca Lima',        'followers'=>18000,  'videos'=>61,  'revenue'=>7600,   'bio'=>'Avatar IA: crie seu alter ego digital.'],
            ['handle'=>'paulo_klingvideo',  'name'=>'Paulo Correia',      'followers'=>16000,  'videos'=>87,  'revenue'=>7100,   'bio'=>'Kling AI weekly: resultados reais, sem mentira.'],
            ['handle'=>'tania_aicreatora',  'name'=>'Tânia Cardoso',      'followers'=>14000,  'videos'=>53,  'revenue'=>6600,   'bio'=>'Criadora IA part-time. R$6k extra por mês.'],
            ['handle'=>'guilherme_sorabr',  'name'=>'Guilherme Rocha',    'followers'=>12500,  'videos'=>74,  'revenue'=>6200,   'bio'=>'Sora: primeiras impressões de quem usa no Brasil.'],
            ['handle'=>'denise_aiugc',      'name'=>'Denise Fonseca',     'followers'=>11000,  'videos'=>46,  'revenue'=>5800,   'bio'=>'UGC IA para pet niche. Segmento com altíssimo ROI.'],
            ['handle'=>'anderson_aidrops',  'name'=>'Anderson Silva',     'followers'=>9500,   'videos'=>82,  'revenue'=>5400,   'bio'=>'Drop IA: catálogo de R$0 e vendendo com IA.'],
            ['handle'=>'fernanda_klingai',  'name'=>'Fernanda Neves',     'followers'=>8000,   'videos'=>38,  'revenue'=>5100,   'bio'=>'Kling AI: meu primeiro vídeo em 10 minutos.'],
            ['handle'=>'marcos_aicreator',  'name'=>'Marcos Duarte',      'followers'=>6500,   'videos'=>57,  'revenue'=>4800,   'bio'=>'Vídeo IA para infoprodutos. CPC 60% menor.'],
            ['handle'=>'simone_aiugcbr',    'name'=>'Simone Campos',      'followers'=>5200,   'videos'=>31,  'revenue'=>4500,   'bio'=>'IA + criatividade = conteúdo que ninguém imita.'],
            ['handle'=>'robson_aiavatar',   'name'=>'Robson Melo',        'followers'=>4000,   'videos'=>44,  'revenue'=>4200,   'bio'=>'Avatar IA: investi R$50 e recuperei em 3 dias.'],
        ];

        return array_map(function ($p, $i) use ($avatarsBr) {
            return [
                'handle'           => $p['handle'],
                'name'             => $p['name'],
                'avatar_url'       => $avatarsBr[$i % count($avatarsBr)],
                'bio'              => $p['bio'],
                'followers'        => $p['followers'],
                'videos_count'     => $p['videos'],
                'estimated_revenue'=> (float) $p['revenue'],
            ];
        }, $raw, array_keys($raw));
    }

    private function estimateRevenue(int $followers): float
    {
        if ($followers < 1_000)   return rand(1_000, 5_000);
        if ($followers < 10_000)  return rand(5_000, 15_000);
        if ($followers < 100_000) return rand(10_000, 50_000);
        return rand(50_000, 200_000);
    }
}
