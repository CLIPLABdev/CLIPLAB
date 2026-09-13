<?php

declare(strict_types=1);

namespace App\Gemini;

final class OpenApiResponseSchema
{
    /** @param array<string, mixed> $schema @return array<string, mixed> */
    public static function fromJsonSchema(array $schema): array
    {
        unset($schema['additionalProperties']);
        if (is_string($schema['type'] ?? null)) {
            $schema['type'] = strtoupper($schema['type']);
        }

        if (is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $name => $property) {
                if (is_array($property)) {
                    $schema['properties'][$name] = self::fromJsonSchema($property);
                }
            }
        }
        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = self::fromJsonSchema($schema['items']);
        }
        if (is_array($schema['anyOf'] ?? null)) {
            foreach ($schema['anyOf'] as $index => $alternative) {
                if (is_array($alternative)) {
                    $schema['anyOf'][$index] = self::fromJsonSchema($alternative);
                }
            }
        }

        return $schema;
    }
}
