<?php
declare(strict_types=1);
namespace App\Media\Editor;

final class BrandLogoPng
{
    public const MAX_BYTES = 2097152;
    /** Validates the exact bytes later persisted, including chunk CRC and raster scanlines. No GD dependency. */
    public static function dimensions(string $bytes): array
    {
        $invalid = static function (): void { throw new \InvalidArgumentException('Envie um PNG válido de até 2 MiB e 2048 × 2048 pixels.'); };
        if (strlen($bytes) > self::MAX_BYTES || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n"
            || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/png') $invalid();
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_PNG || min($info[0], $info[1]) < 1 || max($info[0], $info[1]) > 2048) $invalid();
        $offset = 8; $compressed = ''; $header = null; $ended = false; $palette = false;
        while ($offset + 12 <= strlen($bytes)) {
            $length = unpack('N', substr($bytes, $offset, 4))[1];
            if ($length > strlen($bytes) - $offset - 12) $invalid();
            $type = substr($bytes, $offset + 4, 4); $data = substr($bytes, $offset + 8, $length);
            if (pack('N', crc32($type . $data)) !== substr($bytes, $offset + 8 + $length, 4)) $invalid();
            if ($offset === 8 && $type !== 'IHDR') $invalid();
            if ($type === 'IHDR') {
                if ($header !== null || $length !== 13) $invalid();
                $header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data);
            }
            if (in_array($type, ['acTL', 'fcTL', 'fdAT'], true)) $invalid();
            if ($type === 'PLTE') {
                if ($palette || $compressed !== '' || $length < 3 || $length > 768 || $length % 3 !== 0) $invalid();
                $palette = true;
            }
            if ($type === 'IDAT') $compressed .= $data;
            $offset += 12 + $length;
            if ($type === 'IEND') { if ($length !== 0 || $offset !== strlen($bytes)) $invalid(); $ended = true; break; }
        }
        if (!$ended || $header === null || $compressed === '' || $header['compression'] !== 0 || $header['filter'] !== 0 || $header['interlace'] > 1) $invalid();
        if ($header['color'] === 3 && !$palette) $invalid();
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];
        $depths = [0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]];
        if (!isset($channels[$header['color']]) || !in_array($header['depth'], $depths[$header['color']], true)) $invalid();
        $raster = @zlib_decode($compressed, 40 * 1024 * 1024);
        if (!is_string($raster)) $invalid();
        $passes = $header['interlace'] === 0 ? [[0, 0, 1, 1]] : [[0,0,8,8], [4,0,8,8], [0,4,4,8], [2,0,4,4], [0,2,2,4], [1,0,2,2], [0,1,1,2]];
        $cursor = 0;
        foreach ($passes as [$x, $y, $dx, $dy]) {
            $width = (int) ceil(max(0, $info[0] - $x) / $dx); $height = (int) ceil(max(0, $info[1] - $y) / $dy);
            if ($width === 0 || $height === 0) continue;
            $stride = 1 + (int) ceil($width * $channels[$header['color']] * $header['depth'] / 8);
            for ($row = 0; $row < $height; $row++) {
                if ($cursor + $stride > strlen($raster) || ord($raster[$cursor]) > 4) $invalid();
                $cursor += $stride;
            }
        }
        if ($cursor !== strlen($raster)) $invalid();
        return ['width' => $info[0], 'height' => $info[1]];
    }
}
