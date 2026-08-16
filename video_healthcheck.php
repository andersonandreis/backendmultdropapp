<?php
// Health-check ATIVO da geração de vídeo (Ruan 11/08: "detectar antes do cliente").
// Roda 1x/h via cron. Verifica saúde REAL (não só processos up) e grava status
// num arquivo lido pelo painel admin. SEM Telegram.
use Illuminate\Support\Facades\DB;

$agora = now();
$h1 = $agora->copy()->subHour();
$completos1h = DB::table('ai_video_pipelines')->where('step','done')->whereNotNull('output_url')->where('updated_at','>',$h1)->count();
$criados1h   = DB::table('ai_video_pipelines')->where('created_at','>',$h1)->count();
$fantasma24h = DB::table('ai_video_pipelines')->where('step','done')->whereNull('output_url')->where('created_at','>',$agora->copy()->subDay())->count();
$presos      = DB::table('jobs')->where('queue','video')->whereNotNull('reserved_at')->where('reserved_at','<',time()-600)->count();

// engines disponiveis
$n=0; foreach (DB::table('ai_engines')->whereIn('id',[23,24,25,26,27])->get() as $e) {
    if ($e->is_active && (!$e->cooldown_until || $e->cooldown_until < $agora)) $n++;
}

// diagnostico: PROBLEMA se houve criacao mas nada completou, ou fantasma subiu, ou engines mortas
$saudavel = true; $motivos = [];
if ($criados1h >= 3 && $completos1h == 0) { $saudavel = false; $motivos[] = 'criou mas nao entregou'; }
if ($fantasma24h > 3) { $saudavel = false; $motivos[] = "fantasma=$fantasma24h"; }
if ($presos > 5) { $saudavel = false; $motivos[] = "jobs presos=$presos"; }
if ($n == 0) { $saudavel = false; $motivos[] = 'pool morto (0 engines)'; }

$status = [
    'checked_at'   => $agora->toDateTimeString(),
    'saudavel'     => $saudavel,
    'completos_1h' => $completos1h,
    'criados_1h'   => $criados1h,
    'fantasma_24h' => $fantasma24h,
    'jobs_presos'  => $presos,
    'engines_ok'   => "$n/5",
    'motivos'      => $motivos,
];
file_put_contents('/tmp/video_health_status.json', json_encode($status, JSON_PRETTY_PRINT));
// grava no settings com o campo 'group' (obrigatorio nesta tabela)
try {
    DB::table('settings')->updateOrInsert(
        ['key'=>'video_health_status'],
        ['value'=>json_encode($status), 'group'=>'monitoring', 'updated_at'=>now()]
    );
} catch (\Throwable $e) { /* json ja gravado; settings e best-effort */ }
echo ($saudavel ? 'SAUDAVEL' : 'PROBLEMA: '.implode(', ',$motivos)) . " | completos_1h=$completos1h criados_1h=$criados1h engines=$n/5\n";
