<?php

namespace Database\Seeders;

use App\Models\Aviso;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * SEL-264 — 8 avisos iniciais no tom "estamos mapeando as lives e percebemos que...".
 * Escalonados published_at 13h-15h hoje pra Ruan ver push disparando.
 */
class AvisosSeeder extends Seeder
{
    public function run(): void
    {
        $base = now()->setTimezone('America/Sao_Paulo')->setTime(13, 0, 0);

        $items = [
            [
                'titulo' => '⚠️ Palavras que dão restrição nas suas lives TT Shop',
                'body_push' => 'Mapeamos as lives e achamos as palavras que estão dando strike. Anota aí antes de perder a conta.',
                'conteudo_markdown' => "**Estamos mapeando ao vivo as lives do TikTok Shop Brasil** e percebemos que muita gente tá levando restrição/strike por falar essas palavras. Anota aí pra não repetir os mesmos erros:\n\n### ❌ Sobre preço — evita a todo custo\n- \"Menor preço do TikTok Shop\"\n- \"Mais barato que você vai encontrar\"\n- \"Preço mais baixo\", \"melhor preço\", \"único com esse valor\"\n- \"Compre agora pelo link, é o preço mais barato de qualquer lugar\"\n\n### ❌ Comparação com concorrente (SEM prova documentada)\n- \"Mais barato que na Shopee\"\n- \"Mais em conta que Mercado Livre / Magalu / Amazon\"\n- \"Você não acha esse preço em lugar nenhum\"\n\n### ✅ Substitui por essas que passam batido\n- \"Preço promocional\"\n- \"Oferta do dia\"\n- \"Aproveita enquanto tá desse jeito\"\n- \"Condição especial da live\"\n- \"Preço com desconto\"\n\n> 💡 Regra prática: se a frase implica \"O MENOR do mercado\" sem screenshot que prova, TT interpreta como propaganda enganosa e restringe a live.",
                'categoria' => 'compliance',
                'prioridade' => 'urgente',
                'offset_min' => 0,
                'cta_label' => 'Ver todas as regras',
                'cta_url' => '/avisos/palavras-proibidas-live',
            ],
            [
                'titulo' => '🩺 Palavras proibidas em saúde/beleza (bloqueio automático)',
                'body_push' => 'Suplemento, cosmético, emagrecedor — essas palavras derrubam a live em segundos. Confere a lista.',
                'conteudo_markdown' => "Quem vende **suplemento, cosmético, emagrecedor, produto de saúde** precisa MUITO cuidado. O algoritmo do TT Shop tem detector de \"promessa milagrosa\" e derruba a live em segundos.\n\n### ❌ Nunca fale\n- \"Cura\", \"trata\", \"elimina de vez\"\n- \"Perde X kg em Y dias\"\n- \"Substitui remédio\"\n- \"Sem efeitos colaterais garantido\"\n- \"Comprovado cientificamente\" (sem estudo público linkável)\n- \"Zero contraindicação\"\n\n### ✅ Substitui por\n- \"Auxilia no processo\"\n- \"Complementa a rotina\"\n- \"Formulado com [ingrediente]\"\n- \"Uso indicado pela bula\"\n\n> 💡 Se for cosmético, sempre diga \"pode variar de pessoa pra pessoa\".",
                'categoria' => 'compliance',
                'prioridade' => 'alta',
                'offset_min' => 15,
            ],
            [
                'titulo' => '⏰ Urgência FALSA — o algoritmo pega e restringe',
                'body_push' => '"Último em estoque" repetido dia após dia = strike. Como criar urgência que passa batido.',
                'conteudo_markdown' => "Urgência funciona pra vender — MAS falsa urgência é motivo direto de restrição.\n\n### ❌ TT identifica como enganoso\n- \"Último em estoque\" (repetido dia após dia)\n- \"Só hoje\" — repetido em várias lives seguidas\n- \"Vai acabar agora\" — quando não vai\n- \"Você é o próximo\" (sem base)\n\n### ✅ Urgência real que passa\n- Contagem regressiva REAL (\"restam 3h de promo\")\n- \"Estoque limitado, últimas 12 unidades\" (se for verdade)\n- \"Preço da live volta ao normal quando terminar\"\n\n> 💡 Se você mantém preço promo direto depois da live, TT cruza dado e restringe.",
                'categoria' => 'compliance',
                'prioridade' => 'alta',
                'offset_min' => 30,
            ],
            [
                'titulo' => '📢 Chamadas de audiência que dão strike',
                'body_push' => 'Pedir like/comentário em troca de sorteio ou desconto = violação. Alternativas legais aqui.',
                'conteudo_markdown' => "TT Shop tem regras específicas contra \"engagement bait\" (isca de engajamento).\n\n### ❌ Proibido\n- \"Comenta X pra desbloquear cupom\"\n- \"Se chegar a 500 curtidas eu solto o preço\"\n- \"Compartilha essa live pra ganhar sorteio\"\n- \"Segue o perfil pra ver o próximo\"\n\n### ✅ Legal\n- \"Deixa uma dúvida no chat que eu respondo\"\n- \"Comenta o que você tá procurando que eu te mostro\"\n- \"Marca alguém que precisa desse produto\"\n\n> 💡 Sorteio real com regulamento registrado (SECAP): pode. Sorteio informal na live: strike.",
                'categoria' => 'compliance',
                'prioridade' => 'media',
                'offset_min' => 45,
            ],
            [
                'titulo' => '🎯 Categorias sensíveis — atenção redobrada',
                'body_push' => 'Beleza, saúde, alimentação, suplemento: TT bate martelo pesado. Confere o que muda.',
                'conteudo_markdown' => "Se seu nicho é uma dessas categorias, TT vigia MAIS de perto:\n\n- **Beleza / cosmético**: proibida promessa antes/depois sem consentimento; nunca \"resultado garantido\"\n- **Saúde / suplemento**: registro ANVISA obrigatório; foto do rótulo com registro na descrição\n- **Alimentação**: tabela nutricional visível; nunca \"emagrece só de comer\"\n- **Eletrônico**: certificação ANATEL/INMETRO obrigatória em live\n- **Infantil**: idade recomendada sempre falada\n- **Vestuário**: sem \"veste manequim X\" sem tabela real\n\n> 💡 Baixa live cai 3x mais rápido quando você tá vendendo produto sem certificação aparente.",
                'categoria' => 'compliance',
                'prioridade' => 'media',
                'offset_min' => 60,
            ],
            [
                'titulo' => '💰 Cuidado com "frete grátis" e imposto',
                'body_push' => 'Prometeu frete grátis e não é? Live cai. Como falar de frete sem risco.',
                'conteudo_markdown' => "Frete e imposto são temas que TT vigia com dado real da entrega.\n\n### ❌ Não fale\n- \"Frete grátis pra Brasil todo\" (se não é)\n- \"Sem imposto\", \"sem taxa\"\n- \"Envio no mesmo dia\" (se prazo real é 3+ dias)\n- \"Chega em 24h em qualquer região\"\n\n### ✅ Fale\n- \"Frete grátis pra região Sudeste\" (se for verdade)\n- \"Envio em [prazo real da sua transportadora]\"\n- \"Consulta o frete pelo CEP na tela\"\n\n> 💡 TT cruza a promessa da live com dados da Correios/Loggi. Se divergir, penaliza vendedor.",
                'categoria' => 'compliance',
                'prioridade' => 'media',
                'offset_min' => 75,
            ],
            [
                'titulo' => '™️ Marca registrada — o strike silencioso',
                'body_push' => 'Falar nome da marca sem autorização = strike. Como falar sem citar marca proibida.',
                'conteudo_markdown' => "Você tá vendendo produto genérico ou versão? Cuidado com o nome que fala.\n\n### ❌ Não fale\n- \"Igual [marca famosa]\" — strike direto\n- \"Inspirado no [marca]\"\n- \"Versão do [marca]\"\n- \"Alternativa do [marca]\"\n\n### ✅ Fale\n- \"Do mesmo estilo\"\n- \"Com a mesma pegada\"\n- \"Categoria [nome genérico]\"\n- Use palavras que descrevem a função, não a marca\n\n> 💡 TT tem base de marcas registradas. Detecta em áudio + texto do overlay. Strike é automático.",
                'categoria' => 'compliance',
                'prioridade' => 'alta',
                'offset_min' => 90,
            ],
            [
                'titulo' => '🎬 Live que converte — 5 padrões que a gente mapeou',
                'body_push' => 'Analisamos 100+ lives em alta essa semana. Aqui os 5 padrões que TODAS usam.',
                'conteudo_markdown' => "Analisamos as lives com maior GMV do TT Shop BR nos últimos 30 dias. Padrões que se repetem:\n\n1. **Primeiros 10 segundos = gancho visual**: produto na mão, ação (mexer, abrir, testar), NUNCA começa com \"oi tudo bem gente\"\n2. **Pin do produto trocado a cada 8-12 min**: mantém audiência re-engajando com CTA de compra\n3. **Resposta em áudio pros top comentários**: nunca ignora — TT premia lives com resposta\n4. **Chamada pra ação clara mas leve**: \"Se você já teve esse problema, comenta abaixo\" (não \"COMPRA AGORA\")\n5. **Encerra com hook do próximo horário**: \"Amanhã 20h eu vou testar [outro produto], vem\"\n\n> 💡 Live com esse padrão fica 2.3x mais tempo no feed \"For You\" do TT Shop.",
                'categoria' => 'dica',
                'prioridade' => 'media',
                'offset_min' => 120,
                'cta_label' => 'Ver plano Pro que libera análise de live',
                'cta_url' => '/upgrade?feature=live_analytics',
            ],
        ];

        foreach ($items as $item) {
            $data = [
                'id'                => (string) Str::uuid(),
                'titulo'            => $item['titulo'],
                'body_push'         => $item['body_push'],
                'conteudo_markdown' => $item['conteudo_markdown'],
                'categoria'         => $item['categoria'],
                'prioridade'        => $item['prioridade'],
                'published_at'      => $base->copy()->addMinutes($item['offset_min']),
                'cta_label'         => $item['cta_label'] ?? null,
                'cta_url'           => $item['cta_url'] ?? null,
            ];
            // idempotente: só cria se não existe aviso com mesmo título
            if (!Aviso::where('titulo', $item['titulo'])->exists()) {
                Aviso::create($data);
            }
        }
    }
}
