<?php

declare(strict_types=1);

namespace App\Validation;

use App\Media\YoutubeUrlValidator;

final class ProjectValidator
{
    /** @param array<string, mixed> $input @param array<string, mixed> $files @return array<string, string> */
    public static function creation(array $input, array $files): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $sourceType = (string) ($input['source_type'] ?? '');

        if ($name === '' || mb_strlen($name) > 255) {
            $errors['name'] = 'Informe um nome de projeto com até 255 caracteres.';
        }

        if (!in_array($sourceType, ['upload', 'direct_url'], true)) {
            $errors['source_type'] = 'Escolha como deseja enviar o vídeo.';

            return $errors;
        }

        if ($sourceType === 'upload') {
            $file = $files['video_file'] ?? null;
            $uploadError = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $errors['video_file'] = 'O arquivo ultrapassa o limite permitido.';
            } elseif (!is_array($file) || $uploadError !== UPLOAD_ERR_OK) {
                $errors['video_file'] = 'Selecione um arquivo de vídeo para enviar.';
            }

            return $errors;
        }

        $url = trim((string) ($input['source_url'] ?? ''));
        $parts = $url === '' ? false : parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            $errors['source_url'] = 'Informe uma URL HTTPS direta e sem credenciais.';
        }
        if ((new YoutubeUrlValidator())->recognizes($url)
            && ($input['youtube_rights_confirmed'] ?? null) !== '1') {
            $errors['youtube_rights_confirmed'] = 'Confirme que você tem autorização para importar este vídeo do YouTube.';
        }

        return $errors;
    }
}
