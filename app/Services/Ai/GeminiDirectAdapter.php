<?php
namespace App\Services\Ai;

use App\Contracts\LlmContract;
use App\Models\AiEngine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-452 -- Adapter Gemini direto (fallback LLM quando OpenAI sem credito ou DICloak GPT nao configurado).
 * Interface identica a OpenAiDirectAdapter, converte mensagens OpenAI-style para Gemini API v1beta generateContent.
 */
class GeminiDirectAdapter implements LlmContract
{
    /**
     * SEL-GEMINI-RODIZIO (14/08) — a CAUSA REAL do "roteiro-tampao" do dia inteiro.
     *
     * O erro 429 do Gemini nao era chave invalida nem conta bloqueada. E cota:
     *     GenerateRequestsPerDayPerProjectPerModel-FreeTier = 20
     * VINTE roteiros por dia. A plataforma gerou 55 videos so hoje — a cota acabava
     * no meio da tarde e a partir dali todo cliente recebia roteiro generico, sem
     * erro na tela e sem rastro no log.
     *
     * A saida estava escrita no nome do limite: PerProject PER MODEL. O teto e POR
     * MODELO, e a mesma chave alcanca 13 modelos de texto. 13 modelos x 20 x 2
     * projetos = mais de 500 roteiros por dia, de graca. So faltava rodar entre eles
     * em vez de bater sempre no mesmo.
     *
     * Ordem: os estaveis primeiro; os "preview" no fim, como ultimo recurso.
     */
    private const MODELOS = [
        // SEL-ROTEIRISTA-VOLTA (15/08): ordem por PROVA, nao por numero de versao.
        // Testados agora com as duas chaves de producao: estes dois respondem 200; o
        // gemini-2.5-flash devolve 404 ("no longer available to new users") e estava
        // no topo, sendo o unico tentado por causa do break de baixo.
        'gemini-3.5-flash',
        'gemini-3.1-flash-lite',
        'gemini-2.5-flash',
        'gemini-flash-latest',
        'gemini-2.5-flash-lite',
        'gemini-flash-lite-latest',
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.6-flash',
        'gemini-3.7-flash',
        'gemini-3.1-flash-lite',
        'gemini-3-flash-preview',
        'gemini-3.1-flash-lite-preview',
    ];

    /** Quanto tempo um modelo fica de castigo depois de estourar a cota do dia. */
    private const CASTIGO_MINUTOS = 90;

    private string $apiKey;
    private string $model;

    public function __construct(private ?AiEngine $engine = null)
    {
        $cfg = $engine?->config_json ?? [];
        $this->apiKey = $cfg["api_key"] ?? (string) env("GEMINI_API_KEY", "");
        $this->model  = $cfg["model"]   ?? "gemini-2.5-flash";
    }

    public function chat(array $messages, float $temperature = 0.7, int $maxTokens = 800): string
    {
        if ($this->apiKey === "") {
            throw new \RuntimeException("gemini_not_configured: GEMINI_API_KEY vazio");
        }

        $contents = [];
        $systemText = null;
        foreach ($messages as $m) {
            $role    = $m["role"] ?? "user";
            $content = (string) ($m["content"] ?? "");
            if ($role === "system") { $systemText = trim(($systemText ? $systemText . "\n\n" : "") . $content); continue; }
            $contents[] = ["role" => $role === "assistant" ? "model" : "user", "parts" => [["text" => $content]]];
        }

        $body = [
            "contents"         => $contents,
            // SEL-GEMINI-PENSA (14/08) — CAUSA RAIZ do "roteiro-tampao" que 9 clientes levaram hoje.
            // O gemini-flash-latest e um modelo que PENSA antes de responder, e o pensamento
            // consome o mesmo orcamento de maxOutputTokens. Com teto baixo ele gasta tudo
            // pensando e devolve texto VAZIO — sem erro, sem log, so um roteiro generico
            // pro cliente. Medido no proprio motor #15:
            //     maxOutputTokens=20   -> pensamento=16  resposta=0  -> VAZIO
            //     maxOutputTokens=2000 -> pensamento=102 resposta=1  -> "OK"
            // Duas travas: desligo o pensamento (roteiro de anuncio nao precisa) e ponho
            // piso de 1024 no teto, pra nunca mais um chamador com teto curto matar a
            // resposta inteira em silencio.
            "generationConfig" => [
                "temperature"      => $temperature,
                "maxOutputTokens"  => max(1024, $maxTokens),
                "responseMimeType" => "application/json",
                // SEL-ROTEIRISTA-VOLTA (15/08): o "thinkingConfig" => ["thinkingBudget" => 0]
                // que ficava AQUI derrubava o roteirista. MEDIDO com as chaves de producao:
                //   gemini-flash-lite-latest  COM ele -> HTTP 400 | SEM ele -> HTTP 200
                //   gemini-3.6-flash          COM ele -> HTTP 400 | SEM ele -> HTTP 200
                // Ele existia pra impedir que o "pensamento" comesse o orcamento de saida e
                // devolvesse texto vazio — mas o piso de 1024 acima ja cobre isso sozinho
                // (medido: com teto de 2000 o modelo pensa 102 tokens e AINDA responde).
                // Ou seja: quebrava dois modelos e nao protegia nada que o piso ja nao cubra.
            ],
        ];
        if ($systemText !== null) {
            $body["systemInstruction"] = ["parts" => [["text" => $systemText]]];
        }

        // SEL-GEMINI-RODIZIO-LOOP (14/08): tenta o modelo configurado e, se ele
        // estourou a cota do dia (429), passa pro proximo da fila. Cada modelo tem o
        // proprio teto de 20/dia, entao trocar de modelo e literalmente ganhar cota
        // nova — nao e gambiarra, e o que o nome do limite (PerModel) permite.
        // Modelo que estourou fica de castigo por 90min pra nao gastar chamada a toa.
        $tentados   = [];
        $ultimoErro = '';
        $resp       = null;

        foreach ($this->filaDeModelos() as $modelo) {
            $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent?key={$this->apiKey}";
            $tentativa = Http::timeout(60)->post($url, $body);
            $tentados[] = $modelo;

            if ($tentativa->successful()) {
                $resp = $tentativa;
                if (count($tentados) > 1) {
                    Log::error('[SEL-GEMINI-RODIZIO] cota do modelo anterior acabou; escrevi com outro', [
                        'usei' => $modelo, 'tentados' => $tentados,
                    ]);
                }
                break;
            }

            $ultimoErro = '[HTTP:' . $tentativa->status() . '] ' . mb_substr($tentativa->body(), 0, 160);

            if ($tentativa->status() === 429) {
                // cota diaria estourada: castigo e proximo modelo
                \Illuminate\Support\Facades\Cache::put(
                    'gemini_castigo:' . md5($this->apiKey) . ':' . $modelo, 1, self::CASTIGO_MINUTOS * 60
                );
                continue;
            }
            if ($tentativa->status() >= 500 || $tentativa->status() === 503) {
                continue;   // instabilidade do lado deles: tenta o proximo
            }
            // SEL-ROTEIRISTA-VOLTA (15/08): aqui havia um `break` seco pra 400/401/403,
            // tratando "ESTE modelo nao aceita" como "NENHUM modelo vai aceitar". Log real
            // do estrago: {"tentados":["gemini-2.5-flash"],"erro":"[HTTP:404] ... no longer
            // available"} — UM modelo tentado, de 11, e os que respondiam 200 (gemini-3.5-flash,
            // gemini-3.1-flash-lite) nunca eram alcancados. Resultado: 46% dos videos de hoje
            // sairam com roteiro enlatado de 13 palavras que nem cita o produto.
            // 400 e 404 sao do MODELO (parametro que ele nao aceita, modelo aposentado) -> proximo.
            // 401/403 sao da CHAVE, e ai trocar de modelo realmente nao resolve -> aborta.
            if (in_array($tentativa->status(), [400, 404], true)) {
                continue;
            }
            break;          // 401/403: chave invalida/sem permissao — quebra em todos
        }

        if (! $resp) {
            Log::error('[SEL-GEMINI-RODIZIO] TODOS os modelos falharam', [
                'tentados' => $tentados, 'erro' => $ultimoErro,
            ]);
            throw new \RuntimeException($ultimoErro ?: 'gemini_sem_modelo_disponivel');
        }

        return trim((string) ($resp->json("candidates.0.content.parts.0.text") ?? ""));
    }

    /**
     * SEL-GEMINI-RODIZIO: o modelo configurado primeiro, depois os outros, pulando
     * quem estourou a cota faz pouco tempo. Sem isso o rodizio gastaria uma chamada
     * so pra ouvir 429 de novo do mesmo modelo.
     */
    private function filaDeModelos(): array
    {
        $fila = array_values(array_unique(array_merge([$this->model], self::MODELOS)));
        $livres = array_values(array_filter($fila, function ($m) {
            return ! \Illuminate\Support\Facades\Cache::has('gemini_castigo:' . md5($this->apiKey) . ':' . $m);
        }));

        // todos de castigo? tenta assim mesmo — melhor uma chamada perdida que
        // devolver roteiro generico pro cliente sem nem tentar.
        return $livres ?: $fila;
    }
}
