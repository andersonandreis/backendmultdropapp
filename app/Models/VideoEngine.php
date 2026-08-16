<?php
namespace App\Models;
/**
 * SEL-429 backward compat alias.
 * VideoEngine agora usa a tabela ai_engines via AiEngine.
 * Manter este alias por 24h para zero downtime.
 */
class VideoEngine extends AiEngine {}
