<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\RiwayatAkun;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BantuanUji.php';

final class RiwayatAkunTest extends TestCase
{
    private Adapter $db;
    private DaftarUser $daftar;
    private RiwayatAkun $riwayat;

    protected function setUp(): void
    {
        $this->db      = DatabaseUji::baru();
        $this->db->query('PRAGMA foreign_keys = ON', Adapter::QUERY_MODE_EXECUTE);
        $this->daftar  = new DaftarUser($this->db);
        $this->riwayat = new RiwayatAkun($this->db);
    }

    public function testCatatDanBacaPerUserDanPerLingkup(): void
    {
        $a = $this->daftar->buat('a@contoh.com', 'A', 'kata-sandi-panjang');
        $b = $this->daftar->buat('b@contoh.com', 'B', 'kata-sandi-panjang');

        $this->riwayat->catat(RiwayatAkun::BERI_AKSES, $a, $b, 'dashboard', 'peran anggota', '192.0.2.1', 'Browser');
        $this->riwayat->catat(RiwayatAkun::BERI_AKSES, null, $b, 'toko.contoh.com', 'peran admin');

        $untukB = $this->riwayat->untukUser($b);
        self::assertCount(2, $untukB);
        self::assertNull($untukB[0]['pelaku']);
        self::assertSame('a@contoh.com', $untukB[1]['pelaku']);
        self::assertSame('b@contoh.com', $untukB[1]['sasaran']);

        self::assertCount(1, $this->riwayat->untukLingkup('dashboard'));
        self::assertSame([], $this->riwayat->untukUser($a));
    }

    public function testKeteranganDibersihkanDanDipotong(): void
    {
        $a = $this->daftar->buat('a@contoh.com', 'A', 'kata-sandi-panjang');
        $this->riwayat->catat(RiwayatAkun::UBAH_NAMA, $a, $a, 'dashboard', "baris\nbaru" . str_repeat('é', 300));

        $k = $this->riwayat->untukUser($a)[0]['keterangan'];
        self::assertStringStartsWith('barisbaru', $k);
        self::assertLessThanOrEqual(255, strlen($k));
        self::assertTrue(mb_check_encoding($k, 'UTF-8'));
    }

    public function testPelakuDihapusCatatanTetapAda(): void
    {
        $a = $this->daftar->buat('a@contoh.com', 'A', 'kata-sandi-panjang');
        $b = $this->daftar->buat('b@contoh.com', 'B', 'kata-sandi-panjang');
        $this->riwayat->catat(RiwayatAkun::BERI_AKSES, $a, $b, 'dashboard');

        $this->db->query('DELETE FROM "user" WHERE id = ?', [$a]);

        $baris = $this->riwayat->untukUser($b);
        self::assertCount(1, $baris);
        self::assertNull($baris[0]['pelaku']);
    }

    public function testLabel(): void
    {
        self::assertSame('Nonaktifkan', RiwayatAkun::label(RiwayatAkun::NONAKTIFKAN));
        self::assertSame('lain', RiwayatAkun::label('lain'));
    }
}
