<?php

class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];

    public function __construct(array $data, array $rules)
    {
        $this->data  = $data;
        $this->rules = $rules;
    }

    public static function make(array $data, array $rules): static
    {
        return new static($data, $rules);
    }

    // ─────────────────────────────────────────────
    // Run validation
    // ─────────────────────────────────────────────

    public function validate(): array
    {
        foreach ($this->rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $value    = $this->data[$field] ?? null;
            $optional = in_array('optional', $ruleList, true);

            // Skip optional empty fields
            if ($optional && ($value === null || $value === '')) {
                continue;
            }

            foreach ($ruleList as $rule) {
                if ($rule === 'optional') {
                    continue;
                }
                $this->applyRule($field, $value, $rule);
            }
        }

        if (!empty($this->errors)) {
            $request = new Request();
            if ($request->acceptsJson()) {
                Response::json(['errors' => $this->errors], 422)->send();
                exit;
            }
            // Flash errors + old input and redirect back
            $session = app()->getContainer()->make(Session::class);
            $session->flashErrors($this->errors);
            $session->flashOld($this->data);
            $back = $_SERVER['HTTP_REFERER'] ?? '/';
            header("Location: {$back}");
            exit;
        }

        // Return only fields defined in rules
        return array_intersect_key($this->data, $this->rules);
    }

    public function fails(): bool
    {
        if (empty($this->errors)) {
            foreach ($this->rules as $field => $ruleString) {
                $ruleList = explode('|', $ruleString);
                $value    = $this->data[$field] ?? null;
                $optional = in_array('optional', $ruleList, true);

                if ($optional && ($value === null || $value === '')) {
                    continue;
                }

                foreach ($ruleList as $rule) {
                    if ($rule === 'optional') {
                        continue;
                    }
                    $this->applyRule($field, $value, $rule);
                }
            }
        }

        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    // ─────────────────────────────────────────────
    // Rule engine
    // ─────────────────────────────────────────────

    private function applyRule(string $field, mixed $value, string $rule): void
    {
        // Parse rule:param
        [$ruleName, $param] = str_contains($rule, ':')
            ? explode(':', $rule, 2)
            : [$rule, null];

        $valid = match ($ruleName) {
            'required'  => $value !== null && $value !== '',
            'string'    => is_string($value),
            'int'       => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'float'     => filter_var($value, FILTER_VALIDATE_FLOAT) !== false,
            'bool'      => in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true),
            'email'     => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url'       => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'numeric'   => is_numeric($value),
            'date'      => $this->isValidDate($value),
            'confirmed' => $value === ($this->data[$field . '_confirmation'] ?? null),

            'min'     => is_numeric($value)
                ? (float) $value >= (float) $param
                : mb_strlen((string) $value) >= (int) $param,

            'max'     => is_numeric($value)
                ? (float) $value <= (float) $param
                : mb_strlen((string) $value) <= (int) $param,

            'between' => $this->between($value, $param),
            'in'      => in_array($value, explode(',', $param ?? ''), false),

            'unique'  => $this->isUnique($value, $param),
            'exists'  => $this->existsInTable($value, $param),

            'before'  => strtotime($value) < strtotime($param),
            'after'   => strtotime($value) > strtotime($param),

            'file'    => isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK,
            'image'   => $this->isImage($field),
            'max_size'=> $this->isUnderMaxSize($field, $param),

            'regex'   => preg_match($param, (string) $value) === 1,

            default   => true,  // unknown rule = skip
        };

        if (!$valid) {
            $this->errors[$field] = $this->message($field, $ruleName, $param);
        }
    }

    // ─────────────────────────────────────────────
    // Rule helpers
    // ─────────────────────────────────────────────

    private function between(mixed $value, ?string $param): bool
    {
        if (!$param || !str_contains($param, ',')) {
            return false;
        }
        [$min, $max] = explode(',', $param, 2);
        $v = is_numeric($value) ? (float) $value : mb_strlen((string) $value);
        return $v >= (float) $min && $v <= (float) $max;
    }

    private function isUnique(mixed $value, ?string $param): bool
    {
        if (!$param) {
            return true;
        }
        [$table, $column] = str_contains($param, ',')
            ? explode(',', $param, 2)
            : [$param, null];

        // Determine column from field name if not specified
        return !db($table)->where($column ?? 'id', $value)->exists();
    }

    private function existsInTable(mixed $value, ?string $param): bool
    {
        if (!$param) {
            return true;
        }
        [$table, $column] = str_contains($param, ',')
            ? explode(',', $param, 2)
            : [$param, 'id'];

        return db($table)->where($column, $value)->exists();
    }

    private function isValidDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $t = strtotime($value);
        return $t !== false && $t !== -1;
    }

    private function isImage(string $field): bool
    {
        if (!isset($_FILES[$field])) {
            return false;
        }
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        return in_array($_FILES[$field]['type'] ?? '', $allowed, true);
    }

    private function isUnderMaxSize(string $field, ?string $param): bool
    {
        if (!isset($_FILES[$field]) || !$param) {
            return true;
        }
        $maxBytes = $this->parseSize($param);
        return $_FILES[$field]['size'] <= $maxBytes;
    }

    private function parseSize(string $size): int
    {
        $size = strtolower(trim($size));
        if (str_ends_with($size, 'mb')) {
            return (int) $size * 1024 * 1024;
        }
        if (str_ends_with($size, 'kb')) {
            return (int) $size * 1024;
        }
        return (int) $size;
    }

    private function message(string $field, string $rule, ?string $param): string
    {
        $label = ucfirst(str_replace('_', ' ', $field));
        return match ($rule) {
            'required'  => "{$label} é obrigatório.",
            'email'     => "{$label} deve ser um e-mail válido.",
            'url'       => "{$label} deve ser uma URL válida.",
            'min'       => "{$label} deve ter no mínimo {$param} caracteres.",
            'max'       => "{$label} deve ter no máximo {$param} caracteres.",
            'between'   => "{$label} deve estar entre {$param}.",
            'int'       => "{$label} deve ser um número inteiro.",
            'float'     => "{$label} deve ser um número.",
            'bool'      => "{$label} deve ser verdadeiro ou falso.",
            'in'        => "{$label} deve ser um dos valores permitidos.",
            'unique'    => "{$label} já está em uso.",
            'exists'    => "{$label} não encontrado.",
            'confirmed' => "{$label} não confere com a confirmação.",
            'date'      => "{$label} deve ser uma data válida.",
            'before'    => "{$label} deve ser antes de {$param}.",
            'after'     => "{$label} deve ser após {$param}.",
            'file'      => "Falha no upload de {$label}.",
            'image'     => "{$label} deve ser uma imagem (jpg, png, gif, webp).",
            'max_size'  => "{$label} excede o tamanho máximo de {$param}.",
            'regex'     => "{$label} possui formato inválido.",
            default     => "{$label} é inválido.",
        };
    }
}
