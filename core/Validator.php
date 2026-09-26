<?php
declare(strict_types=1);

namespace App\Core;

/** Fluent input validator. Collects the first error per field. */
final class Validator
{
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    public function value(string $field): mixed
    {
        $v = $this->data[$field] ?? null;
        return is_string($v) ? trim($v) : $v;
    }

    private function present(string $field): bool
    {
        $v = $this->value($field);
        return !($v === null || $v === '' || (is_array($v) && !$v));
    }

    public function required(string $field, string $label): self
    {
        if (!$this->present($field)) {
            $this->add($field, $label . ' is required.');
        }
        return $this;
    }

    public function max(string $field, string $label, int $max): self
    {
        $v = $this->value($field);
        if (is_string($v) && mb_strlen($v) > $max) {
            $this->add($field, $label . ' must not exceed ' . $max . ' characters.');
        }
        return $this;
    }

    public function min(string $field, string $label, int $min): self
    {
        $v = $this->value($field);
        if ($this->present($field) && is_string($v) && mb_strlen($v) < $min) {
            $this->add($field, $label . ' must be at least ' . $min . ' characters.');
        }
        return $this;
    }

    public function numeric(string $field, string $label, ?float $min = null, ?float $max = null): self
    {
        if (!$this->present($field)) {
            return $this;
        }
        $v = $this->value($field);
        if (!is_numeric($v)) {
            $this->add($field, $label . ' must be a number.');
        } elseif ($min !== null && (float) $v < $min) {
            $this->add($field, $label . ' must be at least ' . num($min) . '.');
        } elseif ($max !== null && (float) $v > $max) {
            $this->add($field, $label . ' must not exceed ' . num($max) . '.');
        }
        return $this;
    }

    public function integer(string $field, string $label, ?int $min = null, ?int $max = null): self
    {
        if (!$this->present($field)) {
            return $this;
        }
        $v = (string) $this->value($field);
        if (!preg_match('/^-?\d+$/', $v)) {
            $this->add($field, $label . ' must be a whole number.');
            return $this;
        }
        return $this->numeric($field, $label, $min === null ? null : (float) $min, $max === null ? null : (float) $max);
    }

    public function date(string $field, string $label): self
    {
        if (!$this->present($field)) {
            return $this;
        }
        $v = (string) $this->value($field);
        $d = \DateTime::createFromFormat('Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            $this->add($field, $label . ' is not a valid date.');
        }
        return $this;
    }

    public function in(string $field, string $label, array $allowed): self
    {
        if ($this->present($field) && !in_array((string) $this->value($field), array_map('strval', $allowed), true)) {
            $this->add($field, $label . ' has an invalid value.');
        }
        return $this;
    }

    public function email(string $field, string $label): self
    {
        if ($this->present($field) && !filter_var($this->value($field), FILTER_VALIDATE_EMAIL)) {
            $this->add($field, $label . ' is not a valid email address.');
        }
        return $this;
    }

    public function cnic(string $field, string $label): self
    {
        if ($this->present($field) && normalize_cnic((string) $this->value($field)) === null) {
            $this->add($field, $label . ' must have 13 digits (e.g. 35202-1234567-1).');
        }
        return $this;
    }

    public function phone(string $field, string $label): self
    {
        if ($this->present($field) && normalize_phone((string) $this->value($field)) === null) {
            $this->add($field, $label . ' must have 10 to 13 digits.');
        }
        return $this;
    }

    public function check(string $field, bool $ok, string $message): self
    {
        if (!$ok) {
            $this->add($field, $message);
        }
        return $this;
    }

    public function add(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function first(): string
    {
        return (string) (array_values($this->errors)[0] ?? '');
    }
}
