<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StorageAccessTest extends TestCase
{
    public function test_default_disk_is_private_and_public_disk_is_separate(): void
    {
        $this->assertSame(
            'local',
            config('filesystems.default')
        );

        $this->assertSame(
            storage_path('app/private'),
            config('filesystems.disks.local.root')
        );

        $this->assertTrue(
            (bool) config('filesystems.disks.local.serve')
        );

        $this->assertSame(
            storage_path('app/public'),
            config('filesystems.disks.public.root')
        );

        $this->assertSame(
            'public',
            config('filesystems.disks.public.visibility')
        );

        $this->assertNotSame(
            config('filesystems.disks.local.root'),
            config('filesystems.disks.public.root')
        );
    }

    public function test_unsigned_private_download_is_rejected(): void
    {
        Storage::fake('local');

        $path = 'security/private-document.txt';

        Storage::disk('local')->put(
            $path,
            'SRCM private document'
        );

        $this->get(
            route('storage.local', ['path' => $path])
        )->assertForbidden();

        Storage::disk('local')->assertExists($path);
    }

    public function test_unsigned_private_upload_is_rejected(): void
    {
        Storage::fake('local');

        $path = 'security/unauthorized-upload.txt';

        $this->call(
            'PUT',
            route('storage.local.upload', ['path' => $path]),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'Unauthorized payload'
        )->assertForbidden();

        Storage::disk('local')->assertMissing($path);
    }

    public function test_signed_private_download_is_allowed(): void
    {
        Storage::fake('local');

        $path = 'security/signed-document.txt';

        Storage::disk('local')->put(
            $path,
            'Authorized SRCM document'
        );

        $url = URL::temporarySignedRoute(
            'storage.local',
            now()->addMinutes(5),
            ['path' => $path],
            absolute: false
        );

        $response = $this->get($url)->assertOk();

        $cacheControl = (string) $response->headers->get(
            'Cache-Control'
        );

        foreach ([
            'max-age=0',
            'must-revalidate',
            'no-cache',
            'no-store',
            'private',
        ] as $requiredDirective) {
            $this->assertStringContainsString(
                $requiredDirective,
                $cacheControl
            );
        }

        $response->assertHeader(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'unsafe-inline'; sandbox"
        );
    }

    public function test_signed_private_upload_is_allowed(): void
    {
        Storage::fake('local');

        $path = 'security/signed-upload.txt';

        $url = URL::temporarySignedRoute(
            'storage.local.upload',
            now()->addMinutes(5),
            [
                'path' => $path,
                'upload' => true,
            ],
            absolute: false
        );

        $this->call(
            'PUT',
            $url,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'Authorized upload'
        )->assertNoContent();

        Storage::disk('local')->assertExists($path);

        $this->assertSame(
            'Authorized upload',
            Storage::disk('local')->get($path)
        );
    }
}
