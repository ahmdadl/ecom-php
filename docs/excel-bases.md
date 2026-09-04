# Excel export / import bases

Peer dependency: `composer require maatwebsite/excel`.

Mongez provides mapping / heading / error plumbing only — keep domain column maps in the app.

## Columns

```php
use HZ\Illuminate\Mongez\Excel\ExcelColumns;

ExcelColumns::localizedColumn($model->name, 'en');
ExcelColumns::dateColumn($model->createdAt, 'd-m-Y');
```

## Export

```php
use HZ\Illuminate\Mongez\Excel\FromRepositoryExport;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ExportCities extends FromRepositoryExport implements
    FromCollection,
    WithHeadings,
    ShouldAutoSize
{
    public function headings(): array
    {
        return ['#', 'Name English', 'Name Arabic'];
    }

    protected function mapRow(mixed $model): array
    {
        return [
            $model->nid,
            $this->localizedColumn($model->name, 'en'),
            $this->localizedColumn($model->name, 'ar'),
        ];
    }
}

Excel::download(ExportCities::fromList($cities), 'cities.xlsx');
```

`ExportSheet` is the thinner base when you already hold a `Collection`.

## Import

```php
use HZ\Illuminate\Mongez\Excel\ImportSheet;
use Maatwebsite\Excel\Concerns\ToCollection;

class ImportCities extends ImportSheet implements ToCollection
{
    protected function importRow(array $row, int $index): void
    {
        $nid = $row[0] ?? null;
        // validate / upsert…
        // $city = $this->findByNid(City::class, $nid);
    }
}

Excel::import($import = new ImportCities(), $path);

if ($import->hasErrors()) {
    // [['row' => 3, 'message' => '...'], ...]
    return $import->errors();
}
```

Heading row is skipped by default (`firstRowIsHeading()`). Empty rows are skipped.
