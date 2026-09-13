<?php
declare(strict_types=1);
namespace App\Exceptions;
use InvalidArgumentException;use RuntimeException;
final class SubtitleException extends RuntimeException { private const CODES=['subtitle_audio_missing','subtitle_audio_invalid','subtitle_unavailable'];private function __construct(private string $publicCode){if(!in_array($publicCode,self::CODES,true))throw new InvalidArgumentException('Unsupported subtitle failure code.');parent::__construct('Não foi possível preparar o áudio para as legendas.');}public static function withCode(string $code):self{return new self($code);}public function publicCode():string{return $this->publicCode;} }
