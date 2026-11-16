<?php

declare(strict_types=1);

namespace Rumbo\Backend\Financial;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\Input;

final class MovementValidator
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function create(array $data): array
    {
        Input::assertAllowedKeys($data, [
            'transactionType', 'name', 'category', 'amount', 'currency', 'date', 'occurredAt',
            'status', 'sourceType', 'recurrence', 'isQuickExpense', 'notes',
        ]);

        $type = self::enum($data['transactionType'] ?? null, 'transactionType', ['income', 'expense']);
        $status = self::enum($data['status'] ?? 'confirmed', 'status', ['confirmed', 'planned']);
        $source = self::enum($data['sourceType'] ?? 'manual', 'sourceType', ['manual', 'quick-expense']);
        $recurrence = self::enum(
            $data['recurrence'] ?? 'one-time',
            'recurrence',
            ['one-time', 'daily', 'weekly', 'biweekly', 'monthly'],
        );
        $quick = $data['isQuickExpense'] ?? ($source === 'quick-expense');
        if (!is_bool($quick)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El campo debe ser booleano.', ['field' => 'isQuickExpense']);
        }
        if (($quick || $source === 'quick-expense') && ($type !== 'expense' || !$quick || $source !== 'quick-expense')) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Un gasto rápido debe ser un egreso con sourceType quick-expense.', [
                'field' => 'isQuickExpense',
            ]);
        }

        try {
            $amount = Money::normalizeHnl($data['amount'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw new ApiException(422, 'VALIDATION_ERROR', $exception->getMessage(), ['field' => 'amount']);
        }
        $currency = $data['currency'] ?? Money::CURRENCY;
        if ($currency !== Money::CURRENCY) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La moneda autoritativa debe ser HNL.', ['field' => 'currency']);
        }

        $date = self::date($data['date'] ?? null);
        $occurredAt = null;
        if (array_key_exists('occurredAt', $data) && $data['occurredAt'] !== null) {
            if (!is_string($data['occurredAt'])
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/', $data['occurredAt'])) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'La marca de tiempo no es válida.', ['field' => 'occurredAt']);
            }
            try {
                $occurredAt = (new DateTimeImmutable($data['occurredAt']))->setTimezone(new \DateTimeZone('UTC'));
            } catch (Exception) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'La marca de tiempo no es válida.', ['field' => 'occurredAt']);
            }
            $parseErrors = DateTimeImmutable::getLastErrors();
            if ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0)) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'La marca de tiempo no es válida.', ['field' => 'occurredAt']);
            }
        }

        $notes = $data['notes'] ?? null;
        if ($notes !== null) {
            if (!is_string($notes)) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'Las notas no son válidas.', ['field' => 'notes']);
            }
            $notes = trim($notes);
            $length = function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes);
            if ($length > 5000) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'Las notas superan el tamaño permitido.', ['field' => 'notes']);
            }
            $notes = $notes === '' ? null : $notes;
        }

        return [
            'transactionType' => $type,
            'name' => Input::string($data, 'name', 1, 160),
            'category' => Input::string($data, 'category', 1, 100),
            'amount' => $amount,
            'currency' => Money::CURRENCY,
            'date' => $date,
            'occurredAt' => $occurredAt?->format('Y-m-d H:i:s.u'),
            'status' => $status,
            'sourceType' => $source,
            'recurrence' => $recurrence,
            'isQuickExpense' => $quick,
            'notes' => $notes,
        ];
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $changes @return array<string, mixed> */
    public static function update(array $current, array $changes): array
    {
        Input::assertAllowedKeys($changes, [
            'transactionType', 'name', 'category', 'amount', 'currency', 'date', 'occurredAt',
            'status', 'sourceType', 'recurrence', 'isQuickExpense', 'notes',
        ]);
        if ($changes === []) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Debés enviar al menos un cambio.');
        }
        if (($current['sourceType'] ?? null) === 'reminder-payment') {
            throw new ApiException(409, 'CONFLICT', 'Los pagos de recordatorio se administran desde su operación de pago.');
        }

        $mutable = [
            'transactionType' => $current['transactionType'],
            'name' => $current['name'],
            'category' => $current['category'],
            'amount' => $current['amount'],
            'currency' => $current['currency'],
            'date' => $current['date'],
            'occurredAt' => $current['occurredAt'],
            'status' => $current['status'],
            'sourceType' => $current['sourceType'],
            'recurrence' => $current['recurrence'],
            'isQuickExpense' => $current['isQuickExpense'],
            'notes' => $current['notes'],
        ];
        return self::create([...$mutable, ...$changes]);
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, string $field, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El valor no está permitido.', ['field' => $field]);
        }
        return $value;
    }

    private static function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La fecha debe usar YYYY-MM-DD.', ['field' => 'date']);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La fecha no es válida.', ['field' => 'date']);
        }
        return $value;
    }
}
