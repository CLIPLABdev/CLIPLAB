<?php

declare(strict_types=1);

namespace App\Queue;

use InvalidArgumentException;

final class ProcessingErrorCatalog
{
    /** @var array<string, string> */
    private const MESSAGES = [
        'account_inactive' => 'Sua conta ou plano está inativo. Entre em contato com a administração.',
        'upload_limit_exceeded' => 'O vídeo ultrapassa o tamanho de upload permitido pelo plano.',
        'storage_limit_exceeded' => 'O armazenamento do plano está esgotado. Libere espaço ou solicite a alteração do plano.',
        'monthly_minutes_exceeded' => 'O limite de minutos do plano neste mês foi atingido.',
        'subtitle_failed' => 'Não foi possível gerar as legendas. Revise o áudio ou envie um SRT.',
        'thumbnail_failed' => 'Não foi possível gerar a capa. Revise o vídeo e tente uma nova variante.',
        'subtitle_empty' => 'Não foi possível encontrar fala para gerar legendas neste corte.',
        'thumbnail_request_invalid' => 'Pedido de capa inválido.',
        'subtitle_audio_missing' => 'O corte não possui áudio disponível para transcrição.',
        'subtitle_audio_invalid' => 'Não foi possível preparar o áudio deste corte.',
        'subtitle_unavailable' => 'O processador de áudio está indisponível.',
        'processor_unavailable' => 'Processador temporariamente indisponível.',
        'process_timeout' => 'A inspeção do vídeo excedeu o tempo permitido.',
        'network_timeout' => 'Não foi possível baixar o vídeo agora. Tente novamente.',
        'download_unavailable' => 'Não foi possível baixar o vídeo agora. Tente novamente.',
        'youtube_video_unavailable' => 'Não foi possível importar este vídeo público do YouTube. Tente outro vídeo ou envie o arquivo MP4.',
        'youtube_import_unavailable' => 'A importação do YouTube está indisponível no momento. Envie o arquivo MP4.',
        'youtube_response_invalid' => 'O YouTube retornou dados incompatíveis ou incompletos. Tente novamente ou envie o arquivo MP4.',
        'youtube_metadata_limit' => 'Os metadados do YouTube excederam o limite de leitura do servidor. Informe este erro ao administrador ou envie o MP4.',
        'youtube_bot_challenge' => 'O YouTube exigiu uma verificação humana para este servidor. Não podemos contorná-la. Envie o arquivo do vídeo se você tiver autorização.',
        'youtube_age_restricted' => 'Este vídeo exige confirmação de idade e não pode ser importado anonimamente.',
        'youtube_region_restricted' => 'Este vídeo não está disponível na região do servidor.',
        'youtube_private_video' => 'Este vídeo é privado. A importação por URL aceita somente vídeos públicos acessíveis sem login.',
        'youtube_login_required' => 'O YouTube exige login para acessar este vídeo. Envie um arquivo autorizado.',
        'youtube_format_unavailable' => 'O YouTube não disponibilizou um formato MP4 compatível para este vídeo. Tente novamente ou envie um MP4.',
        'youtube_rate_limited' => 'O YouTube limitou temporariamente as solicitações deste servidor. O sistema tentará novamente dentro do limite de tentativas.',
        'youtube_network_failed' => 'Falha de rede ao consultar o YouTube. O sistema tentará novamente dentro do limite de tentativas.',
        'media_too_large' => 'Não foi encontrada uma versão compatível dentro do limite de tamanho. Envie um arquivo menor.',
        'processing_persistence_failed' => 'Não foi possível salvar o processamento agora. Tente novamente.',
        'source_not_found' => 'A origem do vídeo não foi encontrada.',
        'unsupported_job_type' => 'Tipo de processamento não suportado.',
        'invalid_media' => 'O vídeo enviado não pôde ser processado.',
        'media_validation_failed' => 'O vídeo enviado não pôde ser processado.',
        'worker_error' => 'O processamento falhou. Tente novamente.',
        'ai_timeout' => 'A análise por IA excedeu o tempo limite.',
        'ai_rate_limited' => 'O serviço de IA está temporariamente sobrecarregado.',
        'ai_unavailable' => 'O serviço de IA está temporariamente indisponível.',
        'ai_unconfigured' => 'A análise por IA não está configurada.',
        'ai_provider_rejected' => 'O serviço de IA rejeitou a solicitação.',
        'ai_file_failed' => 'O arquivo não pôde ser analisado pela IA.',
        'ai_response_invalid' => 'A IA não retornou sugestões válidas.',
        'analysis_not_found' => 'A análise solicitada não foi encontrada.',
        'insufficient_credits' => 'Créditos insuficientes para iniciar a análise.',
        'render_timeout' => 'A renderização demorou mais que o esperado.',
        'render_failed' => 'Não foi possível renderizar este corte.',
        'render_output_invalid' => 'O vídeo gerado não passou pela validação.',
        'render_storage_failed' => 'Não foi possível armazenar o vídeo gerado.',
    ];

    public static function message(string $code): ?string
    {
        return self::MESSAGES[$code] ?? null;
    }

    public static function requireMessage(string $code): string
    {
        $message = self::message($code);
        if ($message === null) {
            throw new InvalidArgumentException('Unsupported public job error.');
        }

        return $message;
    }

    public static function assert(string $code, string $message): void
    {
        $expected = self::message($code);
        if ($expected === null || !hash_equals($expected, $message)) {
            throw new InvalidArgumentException('Unsupported public job error.');
        }
    }
}
