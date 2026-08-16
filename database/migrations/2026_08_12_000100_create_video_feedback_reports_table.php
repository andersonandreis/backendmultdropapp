<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-feedback-video (12/08, Ruan ao vivo): "gerou um vídeo, pede o feedback...
 * duplicou uma frase... que frase?... me dá um minuto que a gente vai
 * atualizar... testa agora... aperta refazer, já refaz."
 *
 * Tabela ISOLADA e nova — de propósito distinta das duas que já existem:
 *   - `video_feedback`  (SEL-361, singular): rating great/ok/bad pro motor de
 *      aprendizado de pipeline (AnalyzeFeedbackJob). Não tem workflow, não
 *      tem responsável, não notifica ninguém.
 *   - `video_feedbacks` (SEL-505, plural): estrelas 1-5 + sugestão livre de
 *      NEGÓCIO ("que produto você quer que a gente crie"). Não é reclamação
 *      de defeito, é garimpo de ideia.
 * Esta aqui (`video_feedback_reports`) é o ciclo de CONSERTO: motivo
 * pré-definido → detalhe → responsável automático → admin conserta → avisa
 * o cliente (push) → cliente aperta Refazer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_feedback_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pipeline_id')->nullable()->index()
                ->comment('Referencia ai_video_pipelines.id (quando o item veio de pipeline)');
            $table->string('video_ref', 64)->nullable()
                ->comment('id bruto da galeria (ex "gen-123") quando nao ha pipeline_id');
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->string('motivo', 32)
                ->comment('repetiu_frase|parou_meio|cortou_final|produto_errado|audio_ruim|tempo_diferente|outro');
            $table->text('detalhe')->nullable()
                ->comment('Resposta do cliente ao follow-up guiado (ex: qual frase repetiu)');
            $table->boolean('produto_confirmado')->nullable()
                ->comment('Fluxo "nao e meu produto": cliente confirmou/negou a foto usada');
            $table->string('responsavel', 16)
                ->comment('prompt|imagem|audio|duracao|outro — mapeado automaticamente pelo motivo');
            $table->string('status', 16)->default('novo')
                ->comment('novo|em_conserto|consertado|avisado');
            $table->text('admin_notes')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable()
                ->comment('user_id do admin que marcou consertado');
            $table->timestamp('consertado_at')->nullable();
            $table->timestamp('avisado_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'responsavel'], 'vfr_status_responsavel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_feedback_reports');
    }
};
