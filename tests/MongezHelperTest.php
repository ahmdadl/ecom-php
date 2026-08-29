<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Helpers\Mongez;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

final class MongezHelperTest extends TestCase
{
    public function test_set_locale_from_request_reads_header_then_input_then_resets(): void
    {
        $headerRequest = Request::create('/', 'GET', [], [], [], [
            'HTTP_LOCALE-CODE' => 'ar',
        ]);

        Mongez::setLocaleFromRequest($headerRequest);

        $this->assertSame('ar', App::getLocale());
        $this->assertSame('ar', Mongez::getRequestLocaleCode());

        $inputRequest = Request::create('/', 'GET', ['localeCode' => 'fr']);

        Mongez::setLocaleFromRequest($inputRequest);

        $this->assertSame('fr', App::getLocale());
        $this->assertSame('fr', Mongez::getRequestLocaleCode());

        Mongez::setLocaleFromRequest(Request::create('/', 'GET'));

        // app locale is kept, only the stored per-request locale is reset
        // so persistent workers (Octane) do not leak it to the next request
        $this->assertSame('', Mongez::getRequestLocaleCode());
        $this->assertFalse(Mongez::requestHasLocaleCode());
    }

    public function test_is_installed_depends_on_storage_marker_file(): void
    {
        $this->markMongezAsInstalled();

        $this->assertTrue(Mongez::isInstalled());
    }
}
