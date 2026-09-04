<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Excel;

use Illuminate\Support\Collection;

/**
 * Abstract spreadsheet import with row hooks and error collection.
 *
 * Peer dependency: `maatwebsite/excel` (`ToCollection`). Subclass and
 * `implements ToCollection` in the app.
 *
 * Error shape is compatible with admin UX: `[['row' => int, 'message' => string], ...]`.
 */
abstract class ImportSheet
{
    /**
     * @var list<array{row: int, message: string, column?: string}>
     */
    protected array $errors = [];

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $index = (int) $index;

            if ($this->shouldSkipRow($row, $index)) {
                continue;
            }

            $values = $row instanceof Collection ? $row->toArray() : (array) $row;

            try {
                $this->importRow($values, $index);
            } catch (\Throwable $e) {
                $this->addError($index + 1, $e->getMessage());
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $row
     */
    abstract protected function importRow(array $row, int $index): void;

    /**
     * @return list<array{row: int, message: string, column?: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    protected function addError(int $row, string $message, ?string $column = null): void
    {
        $error = ['row' => $row, 'message' => $message];
        if ($column !== null) {
            $error['column'] = $column;
        }
        $this->errors[] = $error;
    }

    protected function shouldSkipRow(mixed $row, int $index): bool
    {
        if ($this->firstRowIsHeading() && $index === 0) {
            return true;
        }

        if ($row instanceof Collection) {
            return $row->filter(fn ($v) => $v !== null && $v !== '')->isEmpty();
        }

        if (is_array($row)) {
            return collect($row)->filter(fn ($v) => $v !== null && $v !== '')->isEmpty();
        }

        return false;
    }

    protected function firstRowIsHeading(): bool
    {
        return true;
    }

    /**
     * Look up a model by integer `nid`.
     *
     * @template T of object
     * @param  class-string<T>  $modelClass
     * @return T|null
     */
    protected function findByNid(string $modelClass, mixed $nid): ?object
    {
        if ($nid === null || $nid === '') {
            return null;
        }

        return $modelClass::query()->where('nid', (int) $nid)->first();
    }
}
