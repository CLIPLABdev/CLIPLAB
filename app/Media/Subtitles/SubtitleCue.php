<?php

declare(strict_types=1);

namespace App\Media\Subtitles;

use InvalidArgumentException;

final class SubtitleCue
{
    private int $startMs;
    private int $endMs;
    private string $text;
    private array $words;

    public function __construct(int $startMs, int $endMs, string $text, array $words = [])
    {
        if ($startMs < 0 || $endMs <= $startMs || $endMs > 180000) {
            throw new InvalidArgumentException('Os tempos da legenda são inválidos.');
        }
        self::validateText($text, 350);
        if ($text[0] === "\n" || substr($text, -1) === "\n" || preg_match('/\n[ \t]*\n/u', $text)) {
            throw new InvalidArgumentException('Separe linhas vazias em blocos de legenda distintos.');
        }
        if (count($words) > 150 || ($words !== [] && array_keys($words) !== range(0, count($words) - 1))) {
            throw new InvalidArgumentException('As palavras da legenda são inválidas.');
        }
        $previous = $startMs;
        $wordText = '';
        foreach ($words as $word) {
            if (!is_array($word) || count($word) !== 3 || !isset($word['start_ms'], $word['end_ms'], $word['text'])
                || !is_int($word['start_ms']) || !is_int($word['end_ms']) || !is_string($word['text'])
                || $word['start_ms'] < $previous || $word['end_ms'] <= $word['start_ms'] || $word['end_ms'] > $endMs) {
                throw new InvalidArgumentException('O alinhamento das palavras é inválido.');
            }
            self::validateText($word['text'], 80);
            $previous = $word['end_ms'];
            $wordText .= $word['text'];
        }
        if ($words !== [] && preg_replace('/\s+/u', '', $wordText) !== preg_replace('/\s+/u', '', $text)) {
            throw new InvalidArgumentException('As palavras não correspondem ao texto da legenda.');
        }
        $this->startMs = $startMs;
        $this->endMs = $endMs;
        $this->text = $text;
        $this->words = $words;
    }

    public static function validateText(string $text, int $maximum, bool $allowNewline = true): void
    {
        if (!mb_check_encoding($text, 'UTF-8') || trim($text) === '' || mb_strlen($text, 'UTF-8') > $maximum
            || preg_match($allowNewline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $text)) {
            throw new InvalidArgumentException('O texto contém caracteres inválidos ou excede o limite.');
        }
    }

    public function startMs(): int { return $this->startMs; }
    public function endMs(): int { return $this->endMs; }
    public function text(): string { return $this->text; }
    public function words(): array { return $this->words; }
    public function toArray(): array
    {
        $result = ['start_ms' => $this->startMs, 'end_ms' => $this->endMs, 'text' => $this->text];
        if ($this->words !== []) {
            $result['words'] = $this->words;
        }
        return $result;
    }
}
