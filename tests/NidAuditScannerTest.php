<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Support\NidAuditScanner;
use PHPUnit\Framework\TestCase;

final class NidAuditScannerTest extends TestCase
{
    public function test_scan_contents_detects_legacy_patterns(): void
    {
        $scanner = new NidAuditScanner();
        $source = <<<'PHP'
<?php
class Sample
{
    const INTEGER_DATA = ['id', 'price'];
    const SHARED_INFO = ['id', 'name'];

    public function run($model)
    {
        return $model->id;
    }

    public function query($q)
    {
        return $q->where('id', 1)->orderBy('id');
    }

    public function keys(array $row)
    {
        return $row['id'];
    }

    public function embed()
    {
        return 'customer.id';
    }
}
PHP;

        $findings = $scanner->scanContents('/tmp/Sample.php', $source);
        $categories = array_column($findings, 'category');

        $this->assertContains(NidAuditScanner::CATEGORY_CONSTANT, $categories);
        $this->assertContains(NidAuditScanner::CATEGORY_PROPERTY_READ, $categories);
        $this->assertContains(NidAuditScanner::CATEGORY_QUERY, $categories);
        $this->assertContains(NidAuditScanner::CATEGORY_ARRAY_KEY, $categories);
        $this->assertContains(NidAuditScanner::CATEGORY_EMBEDDED_PATH, $categories);
    }

    public function test_scan_ignores_nid_and_method_calls(): void
    {
        $scanner = new NidAuditScanner();
        $source = <<<'PHP'
<?php
class Clean
{
    const INTEGER_DATA = ['nid', 'price'];

    public function run($model)
    {
        return $model->nid + $this->id();
    }
}
PHP;

        $findings = $scanner->scanContents('/tmp/Clean.php', $source);

        $this->assertSame([], $findings);
    }

    public function test_scan_directory_of_fixture_files(): void
    {
        $dir = sys_get_temp_dir() . '/mongez-nid-audit-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/Legacy.php', "<?php\n\$x = \$model->id;\n");
        file_put_contents($dir . '/Clean.php', "<?php\n\$x = \$model->nid;\n");

        try {
            $findings = new NidAuditScanner()->scan($dir);

            $this->assertCount(1, $findings);
            $this->assertSame(NidAuditScanner::CATEGORY_PROPERTY_READ, $findings[0]['category']);
            $this->assertStringEndsWith('Legacy.php', $findings[0]['file']);
        } finally {
            @unlink($dir . '/Legacy.php');
            @unlink($dir . '/Clean.php');
            @rmdir($dir);
        }
    }

    public function test_scan_contents_detects_multiline_constants(): void
    {
        $scanner = new NidAuditScanner();
        $source = <<<'PHP'
<?php
class Sample
{
    const INTEGER_DATA = [
        'id',
        'price',
    ];
}
PHP;

        $findings = $scanner->scanContents('/tmp/Multi.php', $source);

        $this->assertCount(1, $findings);
        $this->assertSame(NidAuditScanner::CATEGORY_CONSTANT, $findings[0]['category']);
        $this->assertSame(5, $findings[0]['line']);
    }
}
