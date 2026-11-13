<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\NamaTabel;
use AdeMS\Dapur\User\PembatasPercobaan;
use AdeMS\Dapur\User\Perintah;
use AdeMS\Dapur\User\RiwayatAkun;
use AdeMS\Dapur\User\RiwayatMasuk;
use Laminas\Db\Metadata\Source\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/BantuanUji.php';

final class NamaTabelTest extends TestCase
{
    public function testTanpaAwalan(): void
    {
        $tabel = NamaTabel::dariPengaturan([]);

        self::assertSame(
            ['user', 'user_akses', 'user_percobaan_masuk', 'user_riwayat_masuk'],
            [$tabel->user(), $tabel->akses(), $tabel->percobaan(), $tabel->riwayat()],
        );
    }

    public function testDenganAwalan(): void
    {
        $tabel = NamaTabel::dariPengaturan(['awalan_tabel' => 'adems_']);

        self::assertSame(
            ['adems_user', 'adems_user_akses', 'adems_user_percobaan_masuk', 'adems_user_riwayat_masuk'],
            [$tabel->user(), $tabel->akses(), $tabel->percobaan(), $tabel->riwayat()],
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function awalanTidakSah(): iterable
    {
        yield 'titik'          => ['db.user_'];
        yield 'spasi'          => ['a b_'];
        yield 'tanda kutip'    => ['x`; DROP TABLE user; --'];
        yield 'tanda hubung'   => ['situs-a_'];
        yield 'terlalu panjang' => [str_repeat('a', 33)];
        yield 'bukan teks'     => [['adems_']];
    }

    #[DataProvider('awalanTidakSah')]
    public function testAwalanTidakSahDitolak(mixed $awalan): void
    {
        $this->expectException(RuntimeException::class);

        NamaTabel::dariPengaturan(['awalan_tabel' => $awalan]);
    }

    public function testPasangDanDataMemakaiAwalan(): void
    {
        $tabel = new NamaTabel('xyz_');
        $db    = DatabaseUji::baru($tabel);

        self::assertEqualsCanonicalizing(
            ['xyz_user', 'xyz_user_akses', 'xyz_user_percobaan_masuk', 'xyz_user_riwayat_masuk', 'xyz_user_riwayat_akun', 'xyz_user_pengaturan'],
            Factory::createSourceFromAdapter($db)->getTableNames(),
        );

        $daftar = new DaftarUser($db, $tabel);
        $id     = $daftar->buat('admin@contoh.com', 'Admin', 'kata-sandi-yang-panjang');
        $daftar->tambahAkses($id, 'dashboard');
        self::assertTrue($daftar->punyaAkses($id, 'dashboard'));

        $pembatas = new PembatasPercobaan($db, $tabel);
        $pembatas->catat('admin@contoh.com', '192.0.2.1');
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS n FROM "xyz_user_percobaan_masuk"', [])->toArray()[0]['n']);

        $riwayat = new RiwayatMasuk($db, $tabel);
        $riwayat->catat(RiwayatMasuk::MASUK, $id, 'dashboard', '192.0.2.1', 'Uji');
        self::assertCount(1, $riwayat->untukUser($id));

        $akun = new RiwayatAkun($db, $tabel);
        $akun->catat(RiwayatAkun::BERI_AKSES, null, $id, 'dashboard');
        self::assertCount(1, $akun->untukUser($id));

        ob_start();
        (new Perintah($db, $daftar, static fn (): string => '', 'dashboard', null, $tabel))->jalankan(['pasang']);
        $keluaran = (string) ob_get_clean();
        self::assertSame(6, preg_match_all('/^Tabel xyz_\S+\s+sudah ada$/m', $keluaran));
    }
}
