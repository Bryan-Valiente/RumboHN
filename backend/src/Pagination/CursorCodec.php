<?php

declare(strict_types=1);

namespace Rumbo\Backend\Pagination;

use JsonException;
use Rumbo\Backend\Http\ApiException;

final class CursorCodec
{
    public function __construct(private readonly string $signingKey)
    {
    }

    public function encode(string $date, string $id): string
    {
        $payload = self::base64Url(json_encode(
            ['date' => $date, 'id' => $id],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        $signature = self::base64Url(hash_hmac('sha256', $payload, $this->signingKey, true));
        return $payload . '.' . $signature;
    }

    /** @return array{date: string, id: string} */
    public function decode(string $cursor): array
    {
        $parts = explode('.', $cursor);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw $this->invalid();
        }
        $expected = self::base64Url(hash_hmac('sha256', $parts[0], $this->signingKey, true));
        if (!hash_equals($expected, $parts[1])) {
            throw $this->invalid();
        }

        $json = self::base64UrlDecode($parts[0]);
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalid();
        }
        if (!is_array($decoded)
            || !isset($decoded['date'], $decoded['id'])
            || !is_string($decoded['date'])
            || !is_string($decoded['id'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $decoded['date'])
            || !preg_match('/^[A-Za-z0-9-]{1,64}$/', $decoded['id'])) {
            throw $this->invalid();
        }

        return ['date' => $decoded['date'], 'id' => $decoded['id']];
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El cursor de paginación no es válido.', ['field' => 'cursor']);
        }
        return $decoded;
    }

    private function invalid(): ApiException
    {
        return new ApiException(422, 'VALIDATION_ERROR', 'El cursor de paginación no es válido.', ['field' => 'cursor']);
    }
}
