<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Excel;

use Illuminate\Support\Collection;

/**
 * Export built from an already-fetched repository / list collection.
 *
 * Domain column maps stay in the app; inherit only mapping + heading hooks.
 *
 * @template TModel of mixed
 * @extends ExportSheet<TModel>
 */
abstract class FromRepositoryExport extends ExportSheet
{
    /**
     * @param  iterable<int, TModel>  $items
     */
    public static function fromList(iterable $items): static
    {
        /** @phpstan-ignore new.static */
        return new static(Collection::make($items));
    }

    /**
     * Optional chunk size hint for large exports (consumers may stream).
     */
    public function chunkSize(): int
    {
        return (int) (function_exists('config')
            ? config('mongez.excel.chunk', 500)
            : 500);
    }
}
