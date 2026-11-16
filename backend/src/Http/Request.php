<?php

declare(strict_types=1);

namespace Rumbo\Backend\Http;

use JsonException;

final class Request
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly string $requestId,
        private readonly string $rawBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($name, 'HTTP_')) {
                $header = strtolower(str_replace('_', '-', substr($name, 5)));
                $headers[$header] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        $requestId = strtolower((string) ($headers['x-request-id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) {
            $requestId = bin2hex(random_bytes(16));
        }

        $rawBody = file_get_contents('php://input');
        return new self($method, self::normalizePath($path), $headers, $requestId, $rawBody === false ? '' : $rawBody);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if (strlen($this->rawBody) > 1_048_576) {
            throw new ApiException(413, 'VALIDATION_ERROR', 'El cuerpo de la solicitud supera el límite permitido.');
        }

        if ($this->rawBody === '') {
            return [];
        }

        if (!str_starts_with(strtolower((string) $this->header('content-type')), 'application/json')) {
            throw new ApiException(415, 'VALIDATION_ERROR', 'Se requiere Content-Type application/json.');
        }

        try {
            $decoded = json_decode($this->rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'VALIDATION_ERROR', 'El JSON de la solicitud no es válido.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException(400, 'VALIDATION_ERROR', 'El cuerpo JSON debe ser un objeto.');
        }

        return $decoded;
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        return $path === '/' ? $path : rtrim($path, '/');
    }
}
