<?php

declare(strict_types=1);

namespace Nordvarr\Inventory;

use RuntimeException;

/**
 * Neplatný vstup. Nese seznam chyb (pole => kód chyby) a případně
 * seznam chybějících externích id.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors
     * @param list<string> $missing
     */
    public function __construct(public readonly array $errors, public readonly array $missing = [])
    {
        parent::__construct($missing !== [] ? 'missing_objects' : 'validation_failed');
    }

    /**
     * Kód první chyby (pro jednoduché hlášky v UI).
     */
    public function firstError(): string
    {
        return array_values($this->errors)[0] ?? 'invalid';
    }
}
