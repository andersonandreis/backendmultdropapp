<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SEL-199: seed inicial de perfis IA (Tokfy-style) pra /admin/creators-review.
 * 30 perfis brasileiros realistas com foto Unsplash + revenue faixa R$5k-45k.
 * Uso: php artisan ai-creators:seed
 */
class SeedAiCreatorsCommand extends Command
{
    protected $signature = 'ai-creators:seed {--count=30}';
    protected $description = 'Seed inicial de perfis IA (Tokfy-style) na tabela ai_creators';

    private array $names = [
        'Ana Beatriz Costa','Bruna Ferreira','Camila Souza','Daniel Oliveira','Elisa Ribeiro',
        'Felipe Almeida','Gabriela Martins','Henrique Nunes','Isabela Rocha','Julia Silveira',
        'Kaique Barbosa','Larissa Duarte','Mateus Pinheiro','Natália Cardoso','Otávio Melo',
        'Patrícia Vargas','Rafael Torres','Sabrina Lima','Thiago Vieira','Ursula Ramos',
        'Vitor Hugo Braga','Wesley Cunha','Yasmin Freitas','Zilda Andrade','André Peixoto',
        'Beatriz Amaral','Caio Machado','Diogo Vasconcelos','Elaine Correa','Fabiana Guerra',
    ];

    private array $avatars = [
        'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=400',
        'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=400',
        'https://images.unsplash.com/photo-1552058544-f2b08422138a?w=400',
        'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=400',
        'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=400',
        'https://images.unsplash.com/photo-1580489944761-15a19d654956?w=400',
        'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=400',
        'https://images.unsplash.com/photo-1544725176-7c40e5a71c5e?w=400',
        'https://images.unsplash.com/photo-1573497019940-1c28c88b4f3e?w=400',
        'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=400',
        'https://images.unsplash.com/photo-1508214751196-bcfd4ca60f91?w=400',
        'https://images.unsplash.com/photo-1517841905240-472988babdf9?w=400',
    ];

    public function handle(): int
    {
        $count = (int) $this->option('count');
        $created = 0;
        for ($i = 0; $i < $count; $i++) {
            $name = $this->names[$i % count($this->names)] . ($i >= count($this->names) ? ' '.($i+1) : '');
            $handle = '@' . strtolower(preg_replace('/[^a-z0-9]/i', '', $name)) . rand(10, 999);
            if (DB::table('ai_creators')->where('handle', $handle)->exists()) continue;
            DB::table('ai_creators')->insert([
                'handle'             => $handle,
                'name'               => $name,
                'avatar_url'         => $this->avatars[array_rand($this->avatars)],
                'bio'                => 'Criador IA Tokfy · vídeos gerados com Kling',
                'followers'          => rand(2500, 180_000),
                'videos_count'       => rand(20, 500),
                'estimated_revenue'  => rand(500_000, 4_500_000) / 100,
                'rank_position'      => $i + 1,
                'source'             => 'tokfy',
                'raw'                => json_encode(['seed' => 'sel199', 'seed_at' => now()->toIso8601String()]),
                'is_visible'         => true,
                'is_approved'        => true,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
            $created++;
        }
        $this->info("SEL-199 seed: $created perfis IA criados em ai_creators");
        return 0;
    }
}
