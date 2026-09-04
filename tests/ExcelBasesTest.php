<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use Carbon\Carbon;
use HZ\Illuminate\Mongez\Excel\ExcelColumns;
use HZ\Illuminate\Mongez\Excel\FromRepositoryExport;
use HZ\Illuminate\Mongez\Excel\ImportSheet;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class ExcelBasesTest extends BaseTestCase
{
    public function test_localized_column_reads_locale_text(): void
    {
        $value = [
            ['localeCode' => 'en', 'text' => 'Riyadh'],
            ['localeCode' => 'ar', 'text' => 'الرياض'],
        ];

        $this->assertSame('Riyadh', ExcelColumns::localizedColumn($value, 'en'));
        $this->assertSame('الرياض', ExcelColumns::localizedColumn($value, 'ar'));
    }

    public function test_date_column_formats_carbon(): void
    {
        $date = Carbon::parse('2026-09-04 15:00:00');

        $this->assertSame('04-09-2026 15:00:00', ExcelColumns::dateColumn($date, 'd-m-Y H:i:s'));
        $this->assertNull(ExcelColumns::dateColumn(null));
    }

    public function test_export_sheet_maps_rows(): void
    {
        $export = new class (Collection::make([
            (object) ['nid' => 1, 'name' => [['localeCode' => 'en', 'text' => 'A']]],
            (object) ['nid' => 2, 'name' => [['localeCode' => 'en', 'text' => 'B']]],
        ])) extends FromRepositoryExport {
            public function headings(): array
            {
                return ['#', 'Name'];
            }

            protected function mapRow(mixed $model): array
            {
                return [
                    $model->nid,
                    $this->localizedColumn($model->name, 'en'),
                ];
            }
        };

        $this->assertSame([
            [1, 'A'],
            [2, 'B'],
        ], $export->collection()->all());
        $this->assertSame(['#', 'Name'], $export->headings());
    }

    public function test_from_repository_export_from_list(): void
    {
        $export = DemoExport::fromList([
            (object) ['nid' => 9, 'name' => 'X'],
        ]);

        $this->assertSame([[9, 'X']], $export->collection()->all());
    }

    public function test_import_sheet_collects_errors_and_skips_heading(): void
    {
        $import = new class extends ImportSheet {
            /** @var list<array<int|string, mixed>> */
            public array $imported = [];

            protected function importRow(array $row, int $index): void
            {
                if (($row[0] ?? null) === 'bad') {
                    throw new \RuntimeException('invalid nid');
                }
                $this->imported[] = $row;
            }
        };

        $import->collection(Collection::make([
            ['nid', 'name'],
            [1, 'ok'],
            ['bad', 'nope'],
            [2, 'ok2'],
        ]));

        $this->assertSame([[1, 'ok'], [2, 'ok2']], $import->imported);
        $this->assertTrue($import->hasErrors());
        $this->assertSame(3, $import->errors()[0]['row']);
        $this->assertSame('invalid nid', $import->errors()[0]['message']);
    }
}

/**
 * @extends FromRepositoryExport<object>
 */
final class DemoExport extends FromRepositoryExport
{
    public function headings(): array
    {
        return ['#', 'Name'];
    }

    protected function mapRow(mixed $model): array
    {
        return [$model->nid, $model->name];
    }
}
