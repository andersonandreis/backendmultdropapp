<?php
/**
 * SEL-EMAILTOKFY — SOMENTE LEITURA / RENDER. Nao envia nada, nao grava nada.
 * Renderiza o SellerWelcomeMail pras duas marcas e prova que a versao Tokfy
 * nao tem nenhum rastro de seller.global.
 */
require '/home/api.seller.global/public_html/vendor/autoload.php';
$app = require_once '/home/api.seller.global/public_html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Mail\SellerWelcomeMail;
use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use App\Support\BrandKit;
use Illuminate\Support\Facades\DB;

/* ---------- 1. a regra de marca, plano a plano ---------- */
echo "=== REGRA DE MARCA (BrandKit::idForPlan) ===\n";
foreach (DB::table('plans')->orderBy('id')->get(['id', 'name', 'slug']) as $p) {
    printf("  plano %3d  %-34s %-14s -> %s\n", $p->id, mb_substr($p->name, 0, 34), $p->slug, BrandKit::idForPlan($p));
}

/* ---------- 2. render das duas marcas ---------- */
$render = function (int $planId, string $arquivo) {
    $plan = Plan::find($planId);

    // cliente FICTICIO em memoria — nada tocado no banco
    $user = new User(['name' => 'Cliente Teste', 'email' => 'suporte@tokfy.io']);
    $user->id = 999999;
    $client = new Client(['user_id' => 999999]);
    $client->id = 999999;

    $m = new SellerWelcomeMail($user, $client, $plan, '123456', 'https://whatsapp.com/channel/0029VbAzaW30gcfNCtU7MZ0U');

    $env = $m->envelope();
    printf("\n=== plano %d (%s) -> marca %s ===\n", $planId, $plan->name, $m->brand['id']);
    printf("  mailer   : %s\n", $m->mailer ?: '(padrao)');
    printf("  from     : %s <%s>\n", $env->from->name, $env->from->address);
    printf("  assunto  : %s\n", $env->subject);
    printf("  view HTML: %s\n", $m->brand['view_html']);
    printf("  view TEXT: %s\n", $m->brand['view_text'] ?: '(sem texto puro)');

    $html = $m->render();
    file_put_contents("/tmp/{$arquivo}.html", $html);

    $texto = '';
    if ($m->brand['view_text']) {
        $texto = view($m->brand['view_text'], array_merge($m->buildViewData(), []))->render();
        file_put_contents("/tmp/{$arquivo}.txt", $texto);
    }

    $proibidas = ['seller', 'Seller', 'SELLER', 'd7ff60', 'D7FF60', 'tiktok', 'TikTok', 'kalodata', 'Kalodata', 'dropship', 'Dropship', 'fornecedor', 'Fornecedor', 'gabriel', 'Gabriel'];
    $corpo = $html . "\n" . $texto . "\n" . $env->subject . "\n" . $env->from->address . "\n" . $env->from->name;
    $achou = [];
    foreach ($proibidas as $t) {
        if (mb_strpos($corpo, $t) !== false) { $achou[] = $t; }
    }
    printf("  palavras proibidas encontradas: %s\n", $achou ? implode(', ', array_unique($achou)) : 'NENHUMA');
    printf("  html salvo em /tmp/%s.html (%d bytes)\n", $arquivo, strlen($html));
    if ($texto !== '') { printf("  texto salvo em /tmp/%s.txt (%d bytes)\n", $arquivo, strlen($texto)); }
};

$render(100, 'tokfy_mail');   // Video IA — Ilimitado  -> TOKFY
$render(85,  'seller_mail');  // Start                 -> SELLER.GLOBAL (regressao)

echo "\nFIM (nada foi enviado, nada foi gravado)\n";
