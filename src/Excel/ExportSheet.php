<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Excel;

use Illuminate\Support\Collection;

/**
 * Abstract spreadsheet export plumbing.
 *
 * Peer dependency: `maatwebsite/excel`. Subclass and implement
 * `FromCollection`, `WithHeadings`, `ShouldAutoSize`, etc. as needed.
 *
 * @template TModel of mixed
 */
abstract class ExportSheet
{
    /**
     * @param  Collection<int, TModel>  $rows
     */
    public function __construct(protected Collection $rows)
    {
    }

    /**
     * @return Collection<int, array<int|string, mixed>>
     */
    public function collection(): Collection
    {
        return $this->rows
            ->map(fn (mixed $model): array => $this->mapRow($model))
            ->values();
    }

    /**
     * @return list<string>
     */
    abstract public function headings(): array;

    /**
     * @param  TModel  $model
     * @return array<int|string, mixed>
     */
    abstract protected function mapRow(mixed $model): array;

    protected function localizedColumn(
        mixed $value,
        ?string $locale = null,
        string $textColumn = 'text',
    ): mixed {
        return ExcelColumns::localizedColumn($value, $locale, $textColumn);
    }

    protected function dateColumn(mixed $date, ?string $format = null): ?string
    {
        return ExcelColumns::dateColumn($date, $format);
    }
}
