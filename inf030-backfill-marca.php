<?php
/**
 * INF-030-MARCA (13/08) — backfill retroativo da coluna marca (subscriptions
 * + clients), criada pela migration 2026_08_13_133500_inf030_add_marca_to_
 * subscriptions_and_clients.php.
 *
 * REGRA:
 *   - 221 e-mails da lista real /root/tokfy-sem-acesso.json -> marca='tokfy'
 *   - todo o resto (marca ainda NULL)                       -> marca='sellerglobal'
 *
 * Por que essa lista e nao o plano: medido (13/08) que plan_id=100 (usado
 * pelo antigo BrandKit::TOKFY_PLAN_IDS como proxy) tem 294 subscriptions no
 * banco, mas so 221 batem por e-mail com compradores Tokfy confirmados — as
 * outras 73 sao clientes seller.global reais que compraram o mesmo plano de
 * video (ex: rodrigo.oliveira.silva.1984@gmail.com, pago 18/07). Usar plano
 * como criterio teria marcado 73 clientes seller.global como Tokfy.
 *
 * SEGURANCA:
 *   - Roda em transaction; se qualquer coisa falhar, rollback total.
 *   - Modo padrao e DRY-RUN (so mede e imprime, nao escreve nada).
 *   - So escreve de verdade com a flag --apply.
 *   - Pre-requisito: a migration precisa ter rodado antes (senao a coluna
 *     'marca' nao existe e o script para com erro claro).
 *
 * Uso (SEMPRE como apifrn0001, nunca root):
 *   sudo -u apifrn0001 /usr/local/lsws/lsphp83/bin/php artisan tinker \
 *     --execute="require '/home/api.seller.global/public_html/inf030-backfill-marca.php';"
 *   (dry-run por padrao; pra aplicar de verdade, editar $apply = true abaixo
 *   ou rodar com APPLY=1 no ambiente antes do comando acima)
 *
 * NAO EXECUTADO em producao por este agente (INF-030 proibe migration/deploy
 * sem OK do Ruan). Preparado e testado em dry-run de LEITURA equivalente
 * (ver relatorio) — o modo --apply nunca rodou.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$apply = (bool) (getenv('APPLY') ?: false); // true = escreve de verdade

if (! Schema::hasColumn('subscriptions', 'marca') || ! Schema::hasColumn('clients', 'marca')) {
    echo "ABORTADO: coluna 'marca' nao existe ainda em subscriptions/clients.\n";
    echo "Rode a migration 2026_08_13_133500_inf030_add_marca_to_subscriptions_and_clients.php primeiro.\n";
    return;
}

$listaPath = '/home/api.seller.global/tokfy-lista-marca.json';
if (! is_readable($listaPath)) {
    echo "ABORTADO: nao consegui ler {$listaPath} (rode como root ou copie pra um caminho legivel por apifrn0001).\n";
    return;
}
$lista = json_decode((string) file_get_contents($listaPath), true) ?: [];
echo 'Lista Tokfy carregada: ' . count($lista) . " e-mails.\n";

$emailsTokfy = array_values(array_unique(array_map(
    fn ($r) => strtolower(trim((string) ($r['email'] ?? ''))),
    $lista
)));

$antesSubMarcada    = DB::table('subscriptions')->whereNotNull('marca')->count();
$antesClientMarcado = DB::table('clients')->whereNotNull('marca')->count();
$totalSub           = DB::table('subscriptions')->count();
$totalClient         = DB::table('clients')->count();

echo "--- ANTES ---\n";
echo "subscriptions com marca preenchida: {$antesSubMarcada} / {$totalSub}\n";
echo "clients com marca preenchida:       {$antesClientMarcado} / {$totalClient}\n\n";

$tokfyClientIds = DB::table('clients as c')
    ->join('users as u', 'u.id', '=', 'c.user_id')
    ->whereIn(DB::raw('LOWER(u.email)'), $emailsTokfy)
    ->pluck('c.id')
    ->all();

echo 'Clients que batem com a lista Tokfy (por e-mail): ' . count($tokfyClientIds) . " de " . count($emailsTokfy) . " e-mails da lista.\n";
$naoBateram = count($emailsTokfy) - count($tokfyClientIds);
if ($naoBateram > 0) {
    echo "ATENCAO: {$naoBateram} e-mail(s) da lista NAO tem client correspondente no banco — nao serao marcados (nada pra marcar).\n";
}

if (! $apply) {
    echo "\n=== DRY-RUN (nada foi escrito) ===\n";
    echo "Para aplicar de verdade: rode com APPLY=1 no ambiente, ou edite \$apply=true neste arquivo.\n";
    echo "Depois de aplicar, contagem esperada:\n";
    $novoTokfyClient = count($tokfyClientIds);
    echo "  clients.marca='tokfy':       {$novoTokfyClient}\n";
    echo "  clients.marca='sellerglobal': " . ($totalClient - $novoTokfyClient) . "\n";
    return;
}

DB::transaction(function () use ($tokfyClientIds) {
    // 1) baseline: tudo que ainda esta NULL vira sellerglobal
    DB::table('subscriptions')->whereNull('marca')->update(['marca' => 'sellerglobal']);
    DB::table('clients')->whereNull('marca')->update(['marca' => 'sellerglobal']);

    // 2) sobrescreve com tokfy quem bate com a lista real
    if ($tokfyClientIds) {
        DB::table('clients')->whereIn('id', $tokfyClientIds)->update(['marca' => 'tokfy']);
        DB::table('subscriptions')->whereIn('client_id', $tokfyClientIds)->update(['marca' => 'tokfy']);
    }
});

$depoisSubTokfy    = DB::table('subscriptions')->where('marca', 'tokfy')->count();
$depoisSubSeller   = DB::table('subscriptions')->where('marca', 'sellerglobal')->count();
$depoisClientTokfy = DB::table('clients')->where('marca', 'tokfy')->count();
$depoisClientSeller = DB::table('clients')->where('marca', 'sellerglobal')->count();

echo "\n=== DEPOIS (aplicado) ===\n";
echo "subscriptions: tokfy={$depoisSubTokfy} sellerglobal={$depoisSubSeller} total=" . ($depoisSubTokfy + $depoisSubSeller) . "\n";
echo "clients:       tokfy={$depoisClientTokfy} sellerglobal={$depoisClientSeller} total=" . ($depoisClientTokfy + $depoisClientSeller) . "\n";
