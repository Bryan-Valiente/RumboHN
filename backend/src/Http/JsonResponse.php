<?php

declare(strict_types=1);

namespace Rumbo\Backend\Http;

use JsonException;

final class JsonResponse
{
    /** @param array<string, mixed>|list<mixed> $data @param array<string, mixed> $meta */
    public static function success(array $data, string $requestId, int $status = 200, array $meta = []): never
    {
        self::send($status, [
            'data' => $data,
            'meta' => ['requestId' => $requestId, ...$meta],
        ]);
    }

    /** @param array<string, mixed> $details */
    public static function error(int $status, string $code, string $message, string $requestId, array $details = []): never
    {
        self::send($status, [
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'requestId' => $requestId,
            ],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private static function send(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        try {
            echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            http_response_code(500);
            echo '{"error":{"code":"INTERNAL_ERROR","message":"No se pudo generar la respuesta.","details":{},"requestId":"unavailable"}}';
        }

        exit;
    }
}
