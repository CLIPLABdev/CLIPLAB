<?php

declare(strict_types=1);

namespace App\Media\Reframe;

use InvalidArgumentException;

final class AspectRatio
{
    private const OUTPUTS = [
        'original' => [null, null],
        '9:16' => [1080, 1920],
        '1:1' => [1080, 1080],
        '16:9' => [1920, 1080],
        '4:5' => [1080, 1350],
    ];

    private string $value;
    private ?int $outputWidth;
    private ?int $outputHeight;

    private function __construct(string $value, ?int $outputWidth, ?int $outputHeight)
    {
        $this->value = $value;
        $this->outputWidth = $outputWidth;
        $this->outputHeight = $outputHeight;
    }

    public static function fromString(string $value): self
    {
        if (!array_key_exists($value, self::OUTPUTS)) {
            throw new InvalidArgumentException('Unsupported reframe aspect ratio.');
        }

        [$width, $height] = self::OUTPUTS[$value];

        return new self($value, $width, $height);
    }

    public static function original(): self
    {
        return self::fromString('original');
    }

    public static function fromStored(string $value, ?int $width, ?int $height): self
    {
        $current = self::fromString($value);
        $legacy = ['original'=>[null,null], '9:16'=>[720,1280], '1:1'=>[720,720], '16:9'=>[1280,720], '4:5'=>[720,900]];
        if ([$width,$height] !== [$current->outputWidth(),$current->outputHeight()] && [$width,$height] !== $legacy[$value]) {
            throw new InvalidArgumentException('Stored reframe dimensions are invalid.');
        }
        return new self($value,$width,$height);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function outputWidth(): ?int
    {
        return $this->outputWidth;
    }

    public function outputHeight(): ?int
    {
        return $this->outputHeight;
    }

    public function isOriginal(): bool
    {
        return $this->value === 'original';
    }
}
