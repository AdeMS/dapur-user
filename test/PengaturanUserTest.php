<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\PengaturanUser;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

final class PengaturanUserTest extends TestCase
{
    public function testSimpanAmbilTimpaDanHapus(): void
    {
        $db         = DatabaseUji::baru();
        $daftar     = new DaftarUser($db);
        $pengaturan = new PengaturanUser($db);
        $a          = $daftar->buat('a@contoh.com', 'A', 'kata-sandi-panjang');
        $b          = $daftar->buat('b@contoh.com', 'B', 'kata-sandi-panjang');

        self::assertTrue($pengaturan->tersedia());
        self::assertNull($pengaturan->ambil($a, 'dashboard.uji'));

        $pengaturan->simpan($a, 'dashboard.uji', 'satu');
        $pengaturan->simpan($a, 'dashboard.uji', 'dua');
        $pengaturan->simpan($b, 'dashboard.uji', 'lain');

        self::assertSame('dua', $pengaturan->ambil($a, 'dashboard.uji'));
        self::assertSame('lain', $pengaturan->ambil($b, 'dashboard.uji'), 'Setiap user punya nilainya sendiri.');

        $pengaturan->hapus($a, 'dashboard.uji');
        self::assertNull($pengaturan->ambil($a, 'dashboard.uji'));
        self::assertSame('lain', $pengaturan->ambil($b, 'dashboard.uji'));
    }

    public function testTidakTersediaSebelumPasang(): void
    {
        $db = DatabaseUji::baru();
        $db->query('DROP TABLE "user_pengaturan"', Adapter::QUERY_MODE_EXECUTE);

        self::assertFalse((new PengaturanUser($db))->tersedia());
    }
}
