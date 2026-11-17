<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\Perintah;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

/** Peran admin super, admin web, dan anggota, termasuk data peran dari versi 1.x. */
final class PeranTest extends TestCase
{
    private Adapter $db;
    private DaftarUser $daftar;

    protected function setUp(): void
    {
        $this->db     = DatabaseUji::baru();
        $this->daftar = new DaftarUser($this->db);
    }

    public function testPeranAdminBawaanMenurutLingkup(): void
    {
        $id = $this->daftar->buat('a@contoh.com', 'A', 'kata-sandi-panjang');
        $this->daftar->tambahAkses($id, 'dashboard');
        $this->daftar->tambahAkses($id, 'toko.contoh.com');

        self::assertSame('admin_super', $this->daftar->peranTersimpan($id, 'dashboard'));
        self::assertSame('admin_web', $this->daftar->peranTersimpan($id, 'toko.contoh.com'));
        self::assertSame(['admin_super', 'anggota'], DaftarUser::pilihanPeran('dashboard'));
        self::assertSame(['admin_web', 'anggota'], DaftarUser::pilihanPeran('toko.contoh.com'));
    }

    public function testAdminSuperBerlakuDiSemuaWebsite(): void
    {
        $super   = $this->daftar->buat('super@contoh.com', 'Super', 'kata-sandi-panjang');
        $anggota = $this->daftar->buat('anggota@contoh.com', 'Anggota', 'kata-sandi-panjang');
        $this->daftar->tambahAkses($super, 'dashboard');
        $this->daftar->tambahAkses($anggota, 'dashboard', DaftarUser::PERAN_ANGGOTA);

        self::assertSame('admin_super', $this->daftar->peran($super, 'blog.contoh.com'));
        self::assertNull($this->daftar->peranTersimpan($super, 'blog.contoh.com'));
        self::assertNull($this->daftar->peran($anggota, 'blog.contoh.com'), 'Anggota dashboard utama bukan anggota website.');
        self::assertTrue($this->daftar->adalahAdmin($super));
        self::assertFalse($this->daftar->adalahAdmin($anggota));

        $daftar = $this->daftar->userDiLingkup('blog.contoh.com');
        self::assertSame(['super@contoh.com'], array_column($daftar, 'email'));
        self::assertTrue($daftar[0]['otomatis']);
    }

    public function testPeranLamaDibacaLaluDiubahOlehPasang(): void
    {
        $a = $this->daftar->buat('a@contoh.com', 'A', 'kata-sandi-panjang');
        $b = $this->daftar->buat('b@contoh.com', 'B', 'kata-sandi-panjang');
        $this->daftar->tambahAkses($a, 'dashboard', DaftarUser::PERAN_LAMA_ADMIN);
        $this->daftar->tambahAkses($b, 'toko.contoh.com', DaftarUser::PERAN_LAMA_ADMIN);
        $this->daftar->tambahAkses($b, 'blog.contoh.com', DaftarUser::PERAN_ANGGOTA);

        // Sebelum pasang: nilai lama sudah dibaca dengan arti baru.
        self::assertSame('admin_super', $this->daftar->peran($a, 'dashboard'));
        self::assertSame('admin_web', $this->daftar->peran($b, 'toko.contoh.com'));
        self::assertSame(1, $this->daftar->jumlahAdminSuper());
        self::assertTrue($this->daftar->adalahAdmin($b));

        ob_start();
        (new Perintah($this->db, $this->daftar, static fn (): string => '', 'dashboard'))->jalankan(['pasang']);
        $keluaran = (string) ob_get_clean();

        self::assertStringContainsString('Peran admin lama diperbarui: 2 akses', $keluaran);
        $mentah = $this->db->query('SELECT user_id, lingkup, peran FROM "user_akses" ORDER BY user_id, lingkup', Adapter::QUERY_MODE_EXECUTE)->toArray();
        self::assertSame(
            [['dashboard', 'admin_super'], ['blog.contoh.com', 'anggota'], ['toko.contoh.com', 'admin_web']],
            array_map(static fn (array $b): array => [$b['lingkup'], $b['peran']], $mentah),
        );

        ob_start();
        (new Perintah($this->db, $this->daftar, static fn (): string => '', 'dashboard'))->jalankan(['pasang']);
        self::assertStringNotContainsString('Peran admin lama', (string) ob_get_clean(), 'Aman diulang.');
    }

    public function testAdminSuperTerakhirTidakBisaDicabutLewatTerminal(): void
    {
        $super = $this->daftar->buat('super@contoh.com', 'Super', 'kata-sandi-panjang');
        $this->daftar->tambahAkses($super, 'dashboard');

        ob_start();
        $kode = (new Perintah($this->db, $this->daftar, static fn (): string => '', 'blog.contoh.com'))->jalankan(['cabut-akses', 'super@contoh.com']);
        ob_end_clean();
        self::assertSame(1, $kode, 'Akses otomatis di website tidak bisa dicabut dari sana.');
        self::assertTrue($this->daftar->adalahAdminSuper($super));
    }

    public function testLabel(): void
    {
        self::assertSame('Admin Super', DaftarUser::label('admin_super'));
        self::assertSame('Admin Web', DaftarUser::label('admin_web'));
        self::assertSame('Anggota', DaftarUser::label('anggota'));
        self::assertTrue(DaftarUser::peranAdmin('admin'));
        self::assertFalse(DaftarUser::peranAdmin('anggota'));
    }
}
