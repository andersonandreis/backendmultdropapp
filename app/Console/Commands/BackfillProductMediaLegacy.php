<?php

namespace App\Console\Commands;

use App\Observers\ProductObserver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillProductMediaLegacy extends Command
{
    protected $signature = "products:backfill-media-legacy {--chunk=100} {--dry-run}";
    protected $description = "MUL-063: Backfill product_media via legado (sku_pai.img + sku_pai_imagens)";

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option("chunk"));
        $dryRun    = (bool) $this->option("dry-run");
        try { DB::connection("legacy")->getPdo(); }
        catch (\Throwable $e) { $this->error("Conexao legacy indisponivel: ".$e->getMessage()); return 1; }
        $this->info("[MUL-063] chunk=$chunkSize dry=" . ($dryRun?"yes":"no"));
        $withoutMedia = DB::table("products as p")
            ->select("p.id as product_id","p.legacy_sku_pai_id","p.supplier_id")
            ->whereNotNull("p.legacy_sku_pai_id")
            ->whereNotExists(fn($s)=>$s->from("product_media as pm")->whereColumn("pm.product_id","p.id"))
            ->orderBy("p.id");
        $total = (clone $withoutMedia)->count();
        $this->info("Produtos sem media: $total");
        if (!$total) { $this->info("Nada a fazer."); return 0; }
        $processed = $inserted = $skippedNoImg = 0;
        ProductObserver::$disableSync = true;
        try {
            (clone $withoutMedia)->chunk($chunkSize, function($rows) use (&$processed,&$inserted,&$skippedNoImg,$dryRun) {
                $ids = $rows->pluck("legacy_sku_pai_id")->filter()->values()->all();
                if (!$ids) return;
                $covers = DB::connection("legacy")->table("sku_pai")->whereIn("id",$ids)->whereNotNull("img")->where("img","!=","")->select(["id","img"])->get()->keyBy("id");
                $extras = [];
                try {
                    foreach(DB::connection("legacy")->table("sku_pai_imagens")->whereIn("id_sku_pai",$ids)->orderBy("id_sku_pai")->orderBy("posicao")->select(["id_sku_pai","img","posicao"])->get() as $er) {
                        if (!empty($er->img)) $extras[$er->id_sku_pai][] = ["img"=>trim($er->img),"posicao"=>(int)($er->posicao??1)];
                    }
                } catch(\Throwable $e) { Log::warning("[MUL-063] sku_pai_imagens: ".$e->getMessage()); }
                foreach($rows as $row) {
                    $processed++;
                    $lid = (int)$row->legacy_sku_pai_id;
                    $cov = $covers->get($lid);
                    $exs = $extras[$lid] ?? [];
                    if (!$cov && !$exs) { $skippedNoImg++; continue; }
                    $newRows = []; $pos = 0;
                    if ($cov && !empty($cov->img)) {
                        $raw = trim((string)$cov->img);
                        $url = $this->norm($raw);
                        if ($url) { $newRows[] = ["product_id"=>$row->product_id,"type"=>"image","url"=>$url,"original_url"=>$raw,"is_cover"=>1,"position"=>0,"created_at"=>now(),"updated_at"=>now()]; $pos=1; }
                    }
                    foreach($exs as $i=>$ex) {
                        $raw = trim($ex["img"]??""); $url = $this->norm($raw);
                        if (!$url) continue;
                        if ($pos>0 && isset($newRows[0]) && $newRows[0]["url"]===$url) continue;
                        $ic = ($pos===0&&$i===0)?1:0;
                        $newRows[] = ["product_id"=>$row->product_id,"type"=>"image","url"=>$url,"original_url"=>$raw,"is_cover"=>$ic,"position"=>$pos+$i,"created_at"=>now(),"updated_at"=>now()];
                        if ($ic) $pos=1;
                    }
                    if (!$newRows) { $skippedNoImg++; continue; }
                    if (!$dryRun) DB::table("product_media")->insert($newRows);
                    $inserted += count($newRows);
                    if ($processed%500===0) { $this->line("  ...p=$processed i=$inserted s=$skippedNoImg"); Log::info("[MUL-063] p=$processed i=$inserted s=$skippedNoImg"); }
                }
            }, "product_id");
        } finally { ProductObserver::$disableSync = false; }
        $this->info("[MUL-063] Done p=$processed i=$inserted skip=$skippedNoImg".($dryRun?" [DRY]":""));
        return 0;
    }
    private function norm(?string $u): ?string {
        if (!$u) return null; $u=trim($u); if (!$u) return null;
        if (!str_starts_with($u,"http")) return rtrim(config("app.url"),"/")."/".ltrim($u,"/");
        $u=str_replace(["www.sistemagrupoonline.com.br","://sistemagrupoonline.com.br"],["goolhub.io","://goolhub.io"],$u);
        return $u;
    }
}
