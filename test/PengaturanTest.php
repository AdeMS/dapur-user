<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\Pengaturan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PengaturanTest extends TestCase
{
    public function testAlamatWebsite(): void
    {
        self::assertNull((new Pengaturan([]))->alamatWebsite);
        self::assertSame('https://dapur.contoh.com', (new Pengaturan(['alamat_website' => 'https://dapur.contoh.com/']))->alamatWebsite);
        self::assertSame('http://lokal.test:8080', (new Pengaturan(['alamat_website' => 'http://lokal.test:8080']))->alamatWebsite);
    }

    /** @return array<string, array{0: mixed}> */
    public static function alamatTidakSah(): array
    {
        return [
            'tanpa skema'  => ['dapur.contoh.com'],
            'dengan jalur' => ['https://contoh.com/dapur'],
            'skema lain'   => ['javascript://x'],
            'bukan teks'   => [123],
        ];
    }

    #[DataProvider('alamatTidakSah')]
    public function testAlamatWebsiteTidakSah(mixed $alamat): void
    {
        $this->expectException(RuntimeException::class);

        new Pengaturan(['alamat_website' => $alamat]);
    }
}
