<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\NamaTabel;
use AdeMS\Dapur\User\Perintah;
use AdeMS\Dapur\User\RiwayatAkun;
use AdeMS\Dapur\User\RiwayatMasuk;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BantuanUji.php';

final class PerintahTest extends TestCase
{
    private const SANDI = 'kata-sandi-yang-panjang';

    private Adapter $db;
    private DaftarUser $daftar;

    protected function setUp(): void
    {
        $this->db     = DatabaseUji::baru();
        $this->daftar = new DaftarUser($this->db);
    }

    private string $keluaran = '';

    /**
     * @param list<string>      $jawaban kata sandi yang diketik berurutan
     * @param list<string>|null $tanya   jawaban pertanyaan ya/tidak; null = bukan terminal
     */
    private function perintah(array $jawaban = [], string $lingkup = 'dashboard', ?array $tanya = null): Perintah
    {
        return new Perintah(
            $this->db,
            $this->daftar,
            static function () use (&$jawaban): string {
                return (string) array_shift($jawaban);
            },
            $lingkup,
            $tanya === null ? null : static function () use (&$tanya): string {
                return (string) array_shift($tanya);
            },
            new NamaTabel(),
            new RiwayatMasuk($this->db),
            null,
            new RiwayatAkun($this->db),
        );
    }

    /** @param list<string> $argumen */
    private function jalankan(Perintah $perintah, array $argumen): int
    {
        ob_start();
        try {
            return $perintah->jalankan($argumen);
        } finally {
            $this->keluaran = (string) ob_get_clean();
        }
    }

    public function testPasangAmanDiulang(): void
    {
        self::assertSame(0, $this->jalankan($this->perintah(), ['pasang']));
        self::assertSame(0, $this->jalankan($this->perintah(), ['pasang']));
    }

    public function testPasangMelaporkanTabelYangDibuatDanYangSudahAda(): void
    {
        $this->db->query('DROP TABLE "user_percobaan_masuk"', Adapter::QUERY_MODE_EXECUTE);

        $this->jalankan($this->perintah(), ['pasang']);
        self::assertMatchesRegularExpression('/Tabel user\s+sudah ada/', $this->keluaran);
        self::assertMatchesRegularExpression('/Tabel user_percobaan_masuk\s+dibuat/', $this->keluaran);

        $this->jalankan($this->perintah(), ['pasang']);
        self::assertSame(7, preg_match_all('/^Tabel \S+\s+sudah ada$/m', $this->keluaran));
        self::assertSame(0, preg_match_all('/^Tabel \S+\s+dibuat$/m', $this->keluaran));
    }

    public function testPasangDariVersiSatuSatuHanyaMembuatRiwayatAkun(): void
    {
        $id = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $this->db->query('DROP TABLE "user_riwayat_akun"', Adapter::QUERY_MODE_EXECUTE);

        $this->jalankan($this->perintah(), ['pasang']);

        self::assertSame(1, preg_match_all('/^Tabel \S+\s+dibuat$/m', $this->keluaran));
        self::assertMatchesRegularExpression('/Tabel user_riwayat_akun\s+dibuat/', $this->keluaran);
        self::assertNotNull($this->daftar->cariId($id), 'Data lama tetap ada.');
    }

    public function testBuatAdmin(): void
    {
        $kode = $this->jalankan($this->perintah([self::SANDI, self::SANDI]), ['buat-admin', 'admin@contoh.com', '--nama=Ade']);

        $user = $this->daftar->cariEmail('admin@contoh.com');
        self::assertSame(0, $kode);
        self::assertSame('Ade', $user?->nama);
        self::assertTrue(password_verify(self::SANDI, (string) $user?->kataSandi));
        self::assertTrue($this->daftar->punyaAkses((int) $user?->id, 'dashboard'));
    }

    public function testOpsiWebsiteDiabaikanDanLingkupWebsiteDipakai(): void
    {
        $kode = $this->jalankan(
            $this->perintah([self::SANDI, self::SANDI], 'blog.contoh.com'),
            ['--website=blog.contoh.com', 'buat-admin', 'admin@contoh.com'],
        );

        $user = $this->daftar->cariEmail('admin@contoh.com');
        self::assertSame(0, $kode);
        self::assertTrue($this->daftar->punyaAkses((int) $user?->id, 'blog.contoh.com'));
        self::assertFalse($this->daftar->punyaAkses((int) $user?->id, 'dashboard'));
    }

    public function testSyaratKataSandiDitampilkanSaatDiminta(): void
    {
        $pesan    = [];
        $perintah = new Perintah($this->db, $this->daftar, static function (string $teks) use (&$pesan): string {
            $pesan[] = $teks;

            return 'pendek';
        }, 'dashboard');

        $this->jalankan($perintah, ['buat-admin', 'admin@contoh.com']);

        self::assertSame('Kata sandi baru (minimal 12 karakter): ', $pesan[0]);
    }

    public function testGantiSandiUserBiasaCukupDelapanKarakter(): void
    {
        $id = $this->daftar->buat('user@contoh.com', 'User', 'kata-sandi-lama');
        $this->daftar->tambahAkses($id, 'dashboard', 'anggota');

        self::assertSame(0, $this->jalankan($this->perintah(['delapan8', 'delapan8']), ['ganti-sandi', 'user@contoh.com']));
        self::assertTrue(password_verify('delapan8', (string) $this->daftar->cariId($id)?->kataSandi));
        self::assertSame(1, $this->jalankan($this->perintah(['tujuh77']), ['ganti-sandi', 'user@contoh.com']));
    }

    public function testGantiSandiAdminTetapDuaBelasKarakter(): void
    {
        $id = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $this->daftar->tambahAkses($id, 'blog.contoh.com');

        self::assertSame(1, $this->jalankan($this->perintah(['sebelas-111', 'sebelas-111']), ['ganti-sandi', 'admin@contoh.com']));
        self::assertTrue(password_verify(self::SANDI, (string) $this->daftar->cariId($id)?->kataSandi));
    }

    public function testKataSandiPendekDitolak(): void
    {
        self::assertSame(1, $this->jalankan($this->perintah(['pendek']), ['buat-admin', 'admin@contoh.com']));
        self::assertNull($this->daftar->cariEmail('admin@contoh.com'));
    }

    public function testKataSandiTidakSamaDitolak(): void
    {
        self::assertSame(1, $this->jalankan($this->perintah([self::SANDI, 'berbeda-yang-panjang']), ['buat-admin', 'admin@contoh.com']));
        self::assertNull($this->daftar->cariEmail('admin@contoh.com'));
    }

    public function testEmailTidakSahDitolak(): void
    {
        self::assertSame(1, $this->jalankan($this->perintah(), ['buat-admin', 'bukan-email']));
    }

    public function testUserAdaHanyaDitambahAksesTanpaGantiSandi(): void
    {
        $id = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);

        $kode = $this->jalankan($this->perintah([], 'blog.contoh.com'), ['buat-admin', 'admin@contoh.com']);

        self::assertSame(0, $kode);
        self::assertTrue($this->daftar->punyaAkses($id, 'blog.contoh.com'));
        self::assertTrue(password_verify(self::SANDI, (string) $this->daftar->cariId($id)?->kataSandi));
    }

    public function testUserAdaTanpaTerminalTidakDitanyaDanDiberiPetunjuk(): void
    {
        $id = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $this->daftar->tambahAkses($id, 'dashboard');

        self::assertSame(0, $this->jalankan($this->perintah(), ['buat-admin', 'admin@contoh.com']));
        self::assertStringContainsString("sudah menjadi admin super di 'dashboard'", $this->keluaran);
        self::assertStringContainsString('ganti-sandi', $this->keluaran);
    }

    public function testUserAdaMenjawabYaKataSandiDiganti(): void
    {
        $id   = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $baru = 'kata-sandi-baru-yang-panjang';
        $this->daftar->tambahAkses($id, 'dashboard');

        $kode = $this->jalankan($this->perintah([$baru, $baru], 'dashboard', ['y']), ['buat-admin', 'admin@contoh.com']);

        self::assertSame(0, $kode);
        self::assertTrue(password_verify($baru, (string) $this->daftar->cariId($id)?->kataSandi));
        self::assertStringContainsString('sudah diganti', $this->keluaran);
    }

    public function testUserAdaJawabanBawaanKataSandiTetap(): void
    {
        $id = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);

        $kode = $this->jalankan($this->perintah([], 'blog.contoh.com', ['']), ['buat-admin', 'admin@contoh.com']);

        self::assertSame(0, $kode);
        self::assertTrue(password_verify(self::SANDI, (string) $this->daftar->cariId($id)?->kataSandi));
        self::assertStringContainsString("akses admin web ke 'blog.contoh.com' ditambahkan", $this->keluaran);
        self::assertSame('admin_web', $this->daftar->peran($id, 'blog.contoh.com'));
        self::assertStringContainsString('Kata sandi tidak diubah.', $this->keluaran);
    }

    public function testUserAdaGantiSandiTetapMemakaiAturanAdmin(): void
    {
        $id = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $this->daftar->tambahAkses($id, 'dashboard');

        $kode = $this->jalankan($this->perintah(['sebelas-111'], 'dashboard', ['ya']), ['buat-admin', 'admin@contoh.com']);

        self::assertSame(1, $kode);
        self::assertTrue(password_verify(self::SANDI, (string) $this->daftar->cariId($id)?->kataSandi));
    }

    public function testGantiSandi(): void
    {
        $id   = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $baru = 'kata-sandi-baru-yang-panjang';

        self::assertSame(0, $this->jalankan($this->perintah([$baru, $baru]), ['ganti-sandi', 'admin@contoh.com']));
        self::assertTrue(password_verify($baru, (string) $this->daftar->cariId($id)?->kataSandi));
    }

    public function testRiwayatTerbaruLebihDulu(): void
    {
        $id      = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $riwayat = new RiwayatMasuk($this->db);
        $riwayat->catat(RiwayatMasuk::GAGAL, $id, 'dashboard', '192.0.2.1', 'Browser Lama');
        $riwayat->catat(RiwayatMasuk::MASUK, $id, 'dashboard', '192.0.2.2', 'Browser Baru');

        self::assertSame(0, $this->jalankan($this->perintah(), ['riwayat', 'admin@contoh.com', '--jumlah=1']));
        self::assertStringContainsString('Browser Baru', $this->keluaran);
        self::assertStringNotContainsString('Browser Lama', $this->keluaran);
    }

    public function testRiwayatMemakaiZonaWaktu(): void
    {
        $id = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        (new RiwayatMasuk($this->db))->catat(RiwayatMasuk::MASUK, $id, 'dashboard', '192.0.2.1', '');
        $this->db->query('UPDATE "user_riwayat_masuk" SET waktu = ?', ['2026-11-12 04:19:35']);

        $perintah = new Perintah(
            $this->db,
            $this->daftar,
            static fn (): string => '',
            'dashboard',
            null,
            new NamaTabel(),
            new RiwayatMasuk($this->db),
            new \DateTimeZone('Asia/Jakarta'),
        );

        self::assertSame(0, $this->jalankan($perintah, ['riwayat', 'admin@contoh.com']));
        self::assertStringContainsString('(waktu Asia/Jakarta)', $this->keluaran);
        self::assertStringContainsString('2026-11-12 11:19:35', $this->keluaran);
    }

    public function testRiwayatKosongDanUserTidakAda(): void
    {
        $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);

        self::assertSame(0, $this->jalankan($this->perintah(), ['riwayat', 'admin@contoh.com']));
        self::assertStringContainsString('Belum ada riwayat login', $this->keluaran);
        self::assertSame(1, $this->jalankan($this->perintah(), ['riwayat', 'lain@contoh.com']));
    }

    public function testPerintahTidakDikenal(): void
    {
        self::assertSame(1, $this->jalankan($this->perintah(), ['tidak-ada']));
    }

    public function testGantiSandiDicatatDiRiwayat(): void
    {
        $id = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $this->daftar->tambahAkses($id, 'dashboard');

        self::assertSame(0, $this->jalankan($this->perintah(['sandi-baru-panjang-12', 'sandi-baru-panjang-12']), ['ganti-sandi', 'admin@contoh.com']));

        $riwayat = (new RiwayatMasuk($this->db))->untukUser($id);
        self::assertSame(RiwayatMasuk::GANTI_SANDI, $riwayat[0]['peristiwa']);
        self::assertSame(Perintah::PERANGKAT_TERMINAL, $riwayat[0]['perangkat']);
        self::assertStringContainsString('semua perangkat berakhir', $this->keluaran);
    }

    public function testDaftarAksesLingkup(): void
    {
        $a = $this->daftar->buat('admin@contoh.com', 'Admin Satu', self::SANDI);
        $b = $this->daftar->buat('budi@contoh.com', 'Budi', self::SANDI);
        $this->daftar->tambahAkses($a, 'dashboard');
        $this->daftar->tambahAkses($b, 'toko.contoh.com');

        self::assertSame(0, $this->jalankan($this->perintah(), ['daftar-akses']));
        self::assertStringContainsString("User dengan akses ke 'dashboard'", $this->keluaran);
        self::assertStringContainsString('admin@contoh.com', $this->keluaran);
        self::assertStringContainsString('belum pernah login', $this->keluaran);
        self::assertStringNotContainsString('budi@contoh.com', $this->keluaran);

        // Admin super ikut terdaftar di setiap website sebagai admin otomatis.
        self::assertSame(0, $this->jalankan($this->perintah(lingkup: 'kosong.contoh.com'), ['daftar-akses']));
        self::assertMatchesRegularExpression('/admin@contoh\.com\s+Admin Super \(otomatis\)/', $this->keluaran);

        $this->db->query('DELETE FROM "user_akses"', Adapter::QUERY_MODE_EXECUTE);
        self::assertSame(0, $this->jalankan($this->perintah(lingkup: 'kosong.contoh.com'), ['daftar-akses']));
        self::assertStringContainsString("Belum ada user dengan akses ke 'kosong.contoh.com'", $this->keluaran);
    }

    public function testDaftarAksesSatuUser(): void
    {
        $id = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $this->daftar->tambahAkses($id, 'toko.contoh.com');
        $this->daftar->tambahAkses($id, 'dashboard');

        self::assertSame(0, $this->jalankan($this->perintah(), ['daftar-akses', 'admin@contoh.com']));
        self::assertMatchesRegularExpression('/dashboard\s+Admin Super\R.*toko\.contoh\.com\s+Admin Web/s', $this->keluaran);
        self::assertStringContainsString('juga admin di semua website', $this->keluaran);

        self::assertSame(1, $this->jalankan($this->perintah(), ['daftar-akses', 'tidak@contoh.com']));
    }

    public function testAdminTerakhirTidakBisaDicabut(): void
    {
        $id = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $this->daftar->tambahAkses($id, 'dashboard');

        self::assertSame(1, $this->jalankan($this->perintah(), ['cabut-akses', 'admin@contoh.com']));
        self::assertTrue($this->daftar->punyaAkses($id, 'dashboard'));
    }

    public function testCabutAksesHanyaDariLingkupIni(): void
    {
        $a = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $b = $this->daftar->buat('budi@contoh.com', 'Budi', self::SANDI);
        $this->daftar->tambahAkses($a, 'dashboard');
        $this->daftar->tambahAkses($b, 'dashboard');
        $this->daftar->tambahAkses($b, 'toko.contoh.com');

        self::assertSame(0, $this->jalankan($this->perintah(), ['cabut-akses', 'budi@contoh.com']));
        self::assertFalse($this->daftar->punyaAkses($b, 'dashboard'));
        self::assertTrue($this->daftar->punyaAkses($b, 'toko.contoh.com'));
        self::assertNotNull($this->daftar->cariId($b), 'Akun tidak dihapus.');

        self::assertSame(1, $this->jalankan($this->perintah(), ['cabut-akses', 'budi@contoh.com']), 'Sudah tidak punya akses.');
    }

    public function testCabutAksesDiTerminalBisaDibatalkan(): void
    {
        $a = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $b = $this->daftar->buat('budi@contoh.com', 'Budi', self::SANDI);
        $this->daftar->tambahAkses($a, 'dashboard');
        $this->daftar->tambahAkses($b, 'dashboard');

        self::assertSame(0, $this->jalankan($this->perintah(tanya: ['']), ['cabut-akses', 'budi@contoh.com']));
        self::assertTrue($this->daftar->punyaAkses($b, 'dashboard'));

        self::assertSame(0, $this->jalankan($this->perintah(tanya: ['y']), ['cabut-akses', 'budi@contoh.com']));
        self::assertFalse($this->daftar->punyaAkses($b, 'dashboard'));
    }

    public function testBuatAdminDanCabutAksesTercatatDiRiwayatAkun(): void
    {
        $this->jalankan($this->perintah([self::SANDI, self::SANDI]), ['buat-admin', 'admin@contoh.com', '--nama=Admin']);
        $this->jalankan($this->perintah([self::SANDI, self::SANDI]), ['buat-admin', 'budi@contoh.com', '--nama=Budi']);
        $this->jalankan($this->perintah(), ['cabut-akses', 'budi@contoh.com']);

        $budi    = $this->daftar->cariEmail('budi@contoh.com');
        $riwayat = (new RiwayatAkun($this->db))->untukUser((int) $budi?->id);

        self::assertSame(
            [RiwayatAkun::CABUT_AKSES, RiwayatAkun::BERI_AKSES, RiwayatAkun::BUAT_USER],
            array_column($riwayat, 'peristiwa'),
        );
        self::assertNull($riwayat[0]['pelaku'], 'Lewat terminal tanpa pelaku.');
        self::assertSame('peran admin_super', $riwayat[0]['keterangan']);
    }

    public function testRiwayatAkun(): void
    {
        $this->jalankan($this->perintah([self::SANDI, self::SANDI]), ['buat-admin', 'admin@contoh.com', '--nama=Admin']);

        self::assertSame(0, $this->jalankan($this->perintah(), ['riwayat-akun']));
        self::assertStringContainsString("Riwayat akun di 'dashboard'", $this->keluaran);
        self::assertMatchesRegularExpression('/beri_akses\s+admin@contoh\.com\s+oleh \(terminal\)/', $this->keluaran);

        self::assertSame(0, $this->jalankan($this->perintah(), ['riwayat-akun', 'admin@contoh.com']));
        self::assertStringContainsString('buat_user', $this->keluaran);

        self::assertSame(0, $this->jalankan($this->perintah(lingkup: 'toko.contoh.com'), ['riwayat-akun']));
        self::assertStringContainsString('Belum ada riwayat akun.', $this->keluaran);

        self::assertSame(1, $this->jalankan($this->perintah(), ['riwayat-akun', 'tidak@contoh.com']));
    }
}
