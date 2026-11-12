<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\NamaTabel;
use AdeMS\Dapur\User\RiwayatMasuk;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BantuanUji.php';

final class RiwayatMasukTest extends TestCase
{
    private Adapter $db;
    private int $id;

    protected function setUp(): void
    {
        $this->db = DatabaseUji::baru();
        $this->id = (new DaftarUser($this->db))->buat('admin@contoh.com', 'Admin', 'kata-sandi-yang-panjang');
    }

    private function jumlah(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) AS n FROM "user_riwayat_masuk"', [])->toArray()[0]['n'];
    }

    private function mundurkanSemua(int $hari): void
    {
        $this->db->query('UPDATE "user_riwayat_masuk" SET waktu = ?', [gmdate('Y-m-d H:i:s', time() - $hari * 86400 - 60)]);
    }

    public function testCatatanLamaDihapusSaatLoginBerhasil(): void
    {
        $riwayat = new RiwayatMasuk($this->db, new NamaTabel(), 30);
        $riwayat->catat(RiwayatMasuk::MASUK, $this->id, 'dashboard', '192.0.2.1', '');
        $this->mundurkanSemua(30);

        $riwayat->catat(RiwayatMasuk::GAGAL, $this->id, 'dashboard', '192.0.2.1', '');
        self::assertSame(2, $this->jumlah());

        $riwayat->catat(RiwayatMasuk::MASUK, $this->id, 'dashboard', '192.0.2.1', '');
        self::assertSame([RiwayatMasuk::MASUK, RiwayatMasuk::GAGAL], array_column($riwayat->untukUser($this->id), 'peristiwa'));
    }

    public function testNolHariTidakPernahDihapus(): void
    {
        $riwayat = new RiwayatMasuk($this->db, new NamaTabel(), 0);
        $riwayat->catat(RiwayatMasuk::MASUK, $this->id, 'dashboard', '192.0.2.1', '');
        $this->mundurkanSemua(3650);

        $riwayat->catat(RiwayatMasuk::MASUK, $this->id, 'dashboard', '192.0.2.1', '');
        self::assertSame(2, $this->jumlah());
    }

    public function testPerangkatDipotongDanDibersihkan(): void
    {
        $riwayat = new RiwayatMasuk($this->db);
        $riwayat->catat(RiwayatMasuk::MASUK, $this->id, 'dashboard', '192.0.2.1', "Baris\r\nBaru " . str_repeat('é', 300));

        $perangkat = $riwayat->untukUser($this->id)[0]['perangkat'];
        self::assertLessThanOrEqual(255, strlen($perangkat));
        self::assertStringStartsWith('BarisBaru ', $perangkat);
        self::assertTrue(mb_check_encoding($perangkat, 'UTF-8'));
    }

    public function testHanyaMilikUserItu(): void
    {
        $lain    = (new DaftarUser($this->db))->buat('lain@contoh.com', 'Lain', 'kata-sandi-yang-panjang');
        $riwayat = new RiwayatMasuk($this->db);
        $riwayat->catat(RiwayatMasuk::MASUK, $lain, 'dashboard', '192.0.2.9', '');
        $riwayat->catat(RiwayatMasuk::GAGAL, null, 'dashboard', '192.0.2.9', '');

        self::assertSame([], $riwayat->untukUser($this->id));
        self::assertCount(1, $riwayat->untukUser($lain));
    }
}
