<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SEL-213 — Seed criadores reais Tokfy em ai_creators
 *
 * Fluxo: backup -> truncate -> insert 23 perfis -> rank por gmv DESC
 * Dados de tokfy.io/admin/users coletados 2026-07-18
 */
class Sel213TokfyCreatorsSeeder extends Seeder
{
    private function parseCount(string $raw): int
    {
        $raw = trim($raw);
        $raw = str_replace(',', '.', $raw);
        $raw = str_replace(' ', '', $raw);
        if (preg_match('/^([\d.]+)\s*[kK]$/i', $raw, $m)) return (int) round((float)$m[1] * 1000);
        if (preg_match('/^([\d.]+)\s*[mM]$/i', $raw, $m)) return (int) round((float)$m[1] * 1000000);
        return (int)(preg_replace('/[^0-9]/', '', $raw) ?: 0);
    }

    private function extractHandle(string $url): string
    {
        if (preg_match('/@([^?&\s\/]+)/', $url, $m)) return '@' . $m[1];
        return '@' . basename(parse_url($url, PHP_URL_PATH));
    }

    public function run(): void
    {
        $this->command->info('SEL-213: iniciando...');

        DB::statement('CREATE TABLE IF NOT EXISTS ai_creators_bkp_sel213 AS SELECT * FROM ai_creators');
        $bkp = DB::table('ai_creators_bkp_sel213')->count();
        $this->command->info("Backup: {$bkp} registros.");

        DB::table('ai_creators')->truncate();
        $this->command->info('Truncated.');

        $profiles = [
            ['url'=>'https://www.tiktok.com/@alineshopcreator_','name'=>'Aline shop creator','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775331215085.jpeg','followers'=>'30.6k','following'=>'67','likes'=>'172.6k','gmv'=>424400,'commission'=>91200,'items'=>'5.6K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@creatoranashop','name'=>'comprasdiarias','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775331150301.jpeg','followers'=>'23.7k','following'=>'15','likes'=>'40.4k','gmv'=>902600,'commission'=>133600,'items'=>'6.6K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@isavalli.oficial','name'=>'Isa Valli Shop Creator','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775330920077.jpeg','followers'=>'16.8k','following'=>'11','likes'=>'247.8k','gmv'=>1600000,'commission'=>213100,'items'=>'6.2K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@shopcreator_ana','name'=>'Ana shopcreator_ana','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775330713066.png','followers'=>'13.8k','following'=>'1802','likes'=>'24.9k','gmv'=>1200000,'commission'=>234700,'items'=>'1.7K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@__caylanii','name'=>'Caylane','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775330552495.jpeg','followers'=>'15.3k','following'=>'11','likes'=>'220k','gmv'=>1300000,'commission'=>266700,'items'=>'5.6K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@isashopcreatorr','name'=>'Isa Shop Creator','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775330393067.jpeg','followers'=>'35.3k','following'=>'17','likes'=>'96.4k','gmv'=>1500000,'commission'=>182200,'items'=>'7.5K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@bellashopstore2025','name'=>'Bellashop','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775330323463.jpeg','followers'=>'18.9k','following'=>'164','likes'=>'18.3k','gmv'=>1000000,'commission'=>215900,'items'=>'7.2K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@flowtkjh1k0','name'=>'Creatorshop','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775330232272.jpeg','followers'=>'33.3k','following'=>'32','likes'=>'193.5k','gmv'=>448800,'commission'=>93800,'items'=>'6.1K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@felipe.martins5842','name'=>'Tiktok shop','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775329984544.jpeg','followers'=>'215.5k','following'=>'15','likes'=>'865.6k','gmv'=>4000000,'commission'=>530300,'items'=>'3.5K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@moda_shop_tiktok','name'=>'Moda Shop tiktok','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775329747718.jpeg','followers'=>'17.7k','following'=>'127','likes'=>'130k','gmv'=>515200,'commission'=>110800,'items'=>'4.1K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@pamelauchoa08','name'=>'Pamela Uchoa','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775329526164.jpeg','followers'=>'7535','following'=>'42','likes'=>'35.4k','gmv'=>750800,'commission'=>115600,'items'=>'4.7K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@emilyshoptiktok','name'=>'Emily Shop TikTok','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775329423424.jpeg','followers'=>'6064','following'=>'6','likes'=>'26.4k','gmv'=>734200,'commission'=>143200,'items'=>'5.2K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@sarachloe.ia','name'=>'Sara Chloe','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775329251263.png','followers'=>'23.9k','following'=>'76','likes'=>'212.3k','gmv'=>1200000,'commission'=>168000,'items'=>'7.2K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@maiteohanagpt','name'=>'Maite Ohana GPT','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775328960797.jpeg','followers'=>'27.7k','following'=>'13','likes'=>'161.4k','gmv'=>1400000,'commission'=>199600,'items'=>'1.3K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@amandashoptiktok','name'=>'Amanda Shop TikTok','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775328836608.jpeg','followers'=>'55.5k','following'=>'36','likes'=>'375.2k','gmv'=>1200000,'commission'=>248400,'items'=>'5.1K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@anne.mello514','name'=>'Anne Mello','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775328677720.jpeg','followers'=>'35.7k','following'=>'167','likes'=>'161.5k','gmv'=>1900000,'commission'=>285400,'items'=>'6.9K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@vanessinha_tiktokshop','name'=>'Vanessinha - TikTok Shop','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775276842395.jpeg','followers'=>'9630','following'=>'287','likes'=>'23.6k','gmv'=>425500,'commission'=>77900,'items'=>'4.0K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@bia.tiktokshop.br','name'=>'Beatriz - UGC Shop Creator','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775276574173.jpeg','followers'=>'49k','following'=>'170','likes'=>'274.4k','gmv'=>919300,'commission'=>148000,'items'=>'1.5K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@luane.goms','name'=>'luane.goms','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/7b2073df-97f6-4986-a5a4-ec649463a6b3/1775185532245.jpeg','followers'=>'39.7K','following'=>'75','likes'=>'97.4K','gmv'=>552400,'commission'=>119300,'items'=>'3.8K','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@elena.kovac1','name'=>'elena.kovac1','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/1f1e516d-09b4-4c33-b54a-9fa580bbbe38/1779124950591.png','followers'=>'40k','following'=>'75','likes'=>'50k','gmv'=>0,'commission'=>0,'items'=>'0','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@rimolomarcos','name'=>'Marcos Rimolo','avatar'=>'https://lwdnwaxpfhihheylywsz.supabase.co/storage/v1/object/public/tiktok-assets/profiles/910e86c6-c3f2-4039-a397-42923bfa1907/1778356100758.jpeg','followers'=>'1712','following'=>'753','likes'=>'505','gmv'=>0,'commission'=>0,'items'=>'0','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@ORBapp','name'=>'O seu app de Mobilidade Urbana','avatar'=>null,'followers'=>'67900','following'=>'17','likes'=>'97400','gmv'=>0,'commission'=>0,'items'=>'0','country'=>'BR'],
            ['url'=>'https://www.tiktok.com/@7envia','name'=>'7envia','avatar'=>'https://p16-sign-sg.tiktokcdn.com/tos-alisg-avt-0068/c5cb6b760b533c629f08c90aaa4d991c~tplv-tiktokx-cropcenter:1080:1080.jpeg','followers'=>'2','following'=>'1','likes'=>'2','gmv'=>0,'commission'=>0,'items'=>'0','country'=>'BR'],
        ];

        $now = now();
        $rows = [];
        foreach ($profiles as $p) {
            $rows[] = [
                'handle'            => $this->extractHandle($p['url']),
                'name'              => $p['name'],
                'avatar_url'        => $p['avatar'],
                'bio'               => null,
                'followers'         => $this->parseCount($p['followers']),
                'following'         => $this->parseCount($p['following']),
                'likes_count'       => $this->parseCount($p['likes']),
                'videos_count'      => 0,
                'estimated_revenue' => $p['commission'],
                'gmv'               => $p['gmv'],
                'commission_items'  => $this->parseCount($p['items']),
                'rank_position'     => null,
                'source'            => 'tokfy_real',
                'raw'               => json_encode(['tiktok_url' => $p['url']]),
                'is_visible'        => true,
                'is_approved'       => true,
                'admin_notes'       => 'SEL-213: importado de tokfy.io/admin/users em 2026-07-18',
                'country'           => $p['country'],
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        DB::table('ai_creators')->insert($rows);
        $this->command->info('Inseridos: ' . count($rows));

        $ordered = DB::table('ai_creators')->where('gmv', '>', 0)->orderByDesc('gmv')->pluck('id');
        foreach ($ordered as $rank => $id) {
            DB::table('ai_creators')->where('id', $id)->update(['rank_position' => $rank + 1]);
        }
        $this->command->info('Rank atualizado.');

        $final = DB::table('ai_creators')->selectRaw('COUNT(*) as total, source')->groupBy('source')->get();
        $this->command->info('Final: ' . $final->toJson());
    }
}
