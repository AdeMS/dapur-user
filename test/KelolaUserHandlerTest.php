<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\BatasanDomain;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\DaftarWebsite;
use AdeMS\Dapur\User\Handler\KelolaUserHandler;
use AdeMS\Dapur\User\Handler\ProfilHandler;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\PenyajiDasar;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\RiwayatAkun;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\User;
use DateTimeZone;
use Laminas\Db\Adapter\Adapter;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/BantuanUji.php';

final class KelolaUserHandlerTest extends TestCase
{
    private const SANDI   = 'kata-sandi-yang-panjang';
    private const LINGKUP = 'toko.contoh.com';

    private Adapter $db;
    private DaftarUser $daftar;
    private RiwayatAkun $riwayatAkun;
    private RendererUji $renderer;
    private SesiLarik $sesi;
    private Pintu $pintu;
    private int $admin;
    private int $budi;

    protected function setUp(): void
    {
        $this->db          = DatabaseUji::baru();
        $this->daftar      = new DaftarUser($this->db);
        $this->riwayatAkun = new RiwayatAkun($this->db);
        $this->renderer    = new RendererUji();
        $this->sesi        = new SesiLarik();
        $this->pintu       = new Pintu(self::LINGKUP, '/dashboard', 3600, 'layout::uji');
        $this->admin       = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $this->budi        = $this->daftar->buat('budi@contoh.com', 'Budi', self::SANDI);
        $this->daftar->tambahAkses($this->admin, self::LINGKUP, DaftarUser::PERAN_ADMIN_WEB);
        $this->daftar->tambahAkses($this->budi, self::LINGKUP, DaftarUser::PERAN_ANGGOTA);
    }

    private function handler(bool $kelolaAkun = false): KelolaUserHandler
    {
        return new KelolaUserHandler(
            $this->daftar,
            $this->riwayatAkun,
            new PenyajiDasar($this->renderer, $this->pintu),
            $this->pintu,
            new DateTimeZone('Asia/Jakarta'),
            $kelolaAkun,
        );
    }

    private function permintaan(string $metode, int $sebagai): ServerRequest
    {
        $user = $this->daftar->cariId($sebagai);
        assert($user !== null);

        return (new ServerRequest(['REMOTE_ADDR' => '192.0.2.1'], [], '/dashboard/user', $metode, 'php://memory', ['User-Agent' => 'Browser Uji']))
            ->withAttribute(Sesi::class, $this->sesi)
            ->withAttribute(User::class, $user)
            ->withAttribute(WajibMasukMiddleware::ATRIBUT_PERAN, $this->daftar->peran($sebagai, $this->pintu->lingkup));
    }

    private function get(?int $sebagai = null): ResponseInterface
    {
        return $this->handler()->handle($this->permintaan('GET', $sebagai ?? $this->admin));
    }

    /** @param array<string, string> $isi */
    private function post(array $isi, bool $kelolaAkun = false, ?int $sebagai = null, bool $denganToken = true): ResponseInterface
    {
        if ($denganToken) {
            $isi[TokenCsrf::FIELD] = (new TokenCsrf($this->sesi))->token();
        }

        return $this->handler($kelolaAkun)->handle($this->permintaan('POST', $sebagai ?? $this->admin)->withParsedBody($isi));
    }

    public function testAnggotaDitolak(): void
    {
        $response = $this->get($this->budi);

        self::assertSame(403, $response->getStatusCode());
        self::assertTrue($this->renderer->data['ditolak']);
        self::assertSame([], $this->renderer->data['daftarUser']);

        self::assertSame(403, $this->post(['aksi' => 'cabut', 'user_id' => (string) $this->admin], sebagai: $this->budi)->getStatusCode());
        self::assertTrue($this->daftar->punyaAkses($this->admin, self::LINGKUP));
    }

    public function testDaftarUser(): void
    {
        self::assertSame(200, $this->get()->getStatusCode());

        $daftar = array_column($this->renderer->data['daftarUser'], null, 'email');
        self::assertTrue($daftar['admin@contoh.com']['diriSendiri']);
        self::assertFalse($daftar['admin@contoh.com']['bisaCabut']);
        self::assertTrue($daftar['budi@contoh.com']['bisaCabut']);
        self::assertFalse($daftar['budi@contoh.com']['bisaAturAktif'], 'Aktif/nonaktif hanya di dashboard utama.');
        self::assertSame('/dashboard/user', $this->renderer->data['alamatKelola']);
        self::assertSame(self::LINGKUP, $this->renderer->data['lingkup']);
    }

    public function testTambahUserBaruDenganSandiSementaraSekali(): void
    {
        $response = $this->post(['aksi' => 'tambah', 'email' => ' Citra@Contoh.com ', 'nama' => 'Citra', 'peran' => 'anggota']);

        self::assertSame('/dashboard/user', $response->getHeaderLine('Location'));
        $citra = $this->daftar->cariEmail('citra@contoh.com');
        self::assertNotNull($citra);
        self::assertSame('anggota', $this->daftar->peran($citra->id, self::LINGKUP));

        $this->get();
        $baru = $this->renderer->data['sandiBaru'];
        self::assertSame('citra@contoh.com', $baru['email']);
        self::assertTrue(password_verify($baru['sandi'], $citra->kataSandi));

        $this->get();
        self::assertNull($this->renderer->data['sandiBaru'], 'Kata sandi hanya tampil sekali.');
        self::assertArrayNotHasKey(KelolaUserHandler::SESI_SANDI_BARU, $this->sesi->isi);

        $akun = $this->riwayatAkun->untukUser($citra->id);
        self::assertSame([RiwayatAkun::BERI_AKSES, RiwayatAkun::BUAT_USER], array_column($akun, 'peristiwa'));
        self::assertSame('admin@contoh.com', $akun[0]['pelaku']);
        self::assertSame(self::LINGKUP, $akun[0]['lingkup']);
        self::assertSame('Browser Uji', $akun[0]['perangkat']);
    }

    public function testTambahEmailTerdaftarHanyaMemberiAkses(): void
    {
        $dewi = $this->daftar->buat('dewi@contoh.com', 'Dewi', self::SANDI);
        $this->daftar->tambahAkses($dewi, 'dashboard', DaftarUser::PERAN_ANGGOTA);

        $this->post(['aksi' => 'tambah', 'email' => 'dewi@contoh.com', 'nama' => '', 'peran' => 'admin_web']);

        self::assertSame('admin_web', $this->daftar->peran($dewi, self::LINGKUP));
        self::assertSame('anggota', $this->daftar->peran($dewi, 'dashboard'), 'Akses lain tidak berubah.');
        self::assertTrue(password_verify(self::SANDI, (string) $this->daftar->cariId($dewi)?->kataSandi), 'Kata sandi tidak diubah.');
        $this->get();
        self::assertNull($this->renderer->data['sandiBaru']);
        self::assertStringContainsString('sudah terdaftar', (string) $this->renderer->data['pesan']);
    }

    public function testTambahDitolakBilaTidakSah(): void
    {
        self::assertSame(422, $this->post(['aksi' => 'tambah', 'email' => 'bukan-email', 'nama' => 'X', 'peran' => 'anggota'])->getStatusCode());
        self::assertSame(422, $this->post(['aksi' => 'tambah', 'email' => 'baru@contoh.com', 'nama' => '', 'peran' => 'anggota'])->getStatusCode());
        self::assertSame('baru@contoh.com', $this->renderer->data['formEmail'], 'Isian form dikembalikan.');
        self::assertSame(422, $this->post(['aksi' => 'tambah', 'email' => 'baru@contoh.com', 'nama' => 'X', 'peran' => 'raja'])->getStatusCode());
        self::assertSame(422, $this->post(['aksi' => 'tambah', 'email' => 'budi@contoh.com', 'nama' => '', 'peran' => 'admin_web'])->getStatusCode());
        self::assertNull($this->daftar->cariEmail('baru@contoh.com'));
        self::assertSame('anggota', $this->daftar->peran($this->budi, self::LINGKUP));
    }

    public function testUbahPeran(): void
    {
        $this->post(['aksi' => 'peran', 'user_id' => (string) $this->budi, 'peran' => 'admin_web']);

        self::assertSame('admin_web', $this->daftar->peran($this->budi, self::LINGKUP));
        self::assertSame('anggota → admin_web', $this->riwayatAkun->untukUser($this->budi)[0]['keterangan']);

        self::assertSame(422, $this->post(['aksi' => 'peran', 'user_id' => (string) $this->admin, 'peran' => 'anggota'])->getStatusCode());
        self::assertSame('admin_web', $this->daftar->peran($this->admin, self::LINGKUP));

        // Admin super hanya bisa dipilih di dashboard utama.
        self::assertSame(422, $this->post(['aksi' => 'peran', 'user_id' => (string) $this->budi, 'peran' => 'admin_super'])->getStatusCode());
    }

    public function testCabutAkses(): void
    {
        $this->post(['aksi' => 'cabut', 'user_id' => (string) $this->budi]);

        self::assertNull($this->daftar->peran($this->budi, self::LINGKUP));
        self::assertNotNull($this->daftar->cariId($this->budi), 'Akun tidak dihapus.');
        self::assertSame(RiwayatAkun::CABUT_AKSES, $this->riwayatAkun->untukLingkup(self::LINGKUP)[0]['peristiwa']);

        self::assertSame(422, $this->post(['aksi' => 'cabut', 'user_id' => (string) $this->admin])->getStatusCode());
    }

    public function testUserLingkupLainTidakBisaDisentuh(): void
    {
        $lain = $this->daftar->buat('lain@contoh.com', 'Lain', self::SANDI);
        $this->daftar->tambahAkses($lain, 'blog.contoh.com');

        self::assertSame(404, $this->post(['aksi' => 'cabut', 'user_id' => (string) $lain])->getStatusCode());
        self::assertSame(404, $this->post(['aksi' => 'aktif', 'user_id' => (string) $lain, 'nilai' => '0'], true)->getStatusCode());
        self::assertSame(404, $this->post(['aksi' => 'peran', 'user_id' => 'abc', 'peran' => 'admin_web'])->getStatusCode());
        self::assertTrue($this->daftar->punyaAkses($lain, 'blog.contoh.com'));
        self::assertTrue((bool) $this->daftar->cariId($lain)?->aktif);
    }

    public function testAktifNonaktifHanyaBilaDiizinkan(): void
    {
        self::assertSame(403, $this->post(['aksi' => 'aktif', 'user_id' => (string) $this->budi, 'nilai' => '0'])->getStatusCode());
        self::assertTrue((bool) $this->daftar->cariId($this->budi)?->aktif);

        $this->post(['aksi' => 'aktif', 'user_id' => (string) $this->budi, 'nilai' => '0'], true);
        self::assertFalse((bool) $this->daftar->cariId($this->budi)?->aktif);
        self::assertSame(RiwayatAkun::NONAKTIFKAN, $this->riwayatAkun->untukUser($this->budi)[0]['peristiwa']);

        $this->post(['aksi' => 'aktif', 'user_id' => (string) $this->budi, 'nilai' => '1'], true);
        self::assertTrue((bool) $this->daftar->cariId($this->budi)?->aktif);

        self::assertSame(422, $this->post(['aksi' => 'aktif', 'user_id' => (string) $this->admin, 'nilai' => '0'], true)->getStatusCode());
        self::assertTrue((bool) $this->daftar->cariId($this->admin)?->aktif);
    }

    public function testAdminSuperOtomatisDiWebsiteDanTidakBisaDiubahDariSana(): void
    {
        $super = $this->daftar->buat('super@contoh.com', 'Super', self::SANDI);
        $this->daftar->tambahAkses($super, 'dashboard');

        $this->get();
        $baris = array_values(array_filter($this->renderer->data['daftarUser'], static fn (array $u): bool => $u['id'] === $super))[0];
        self::assertSame('admin_super', $baris['peran']);
        self::assertTrue($baris['otomatis']);
        self::assertFalse($baris['bisaUbahPeran']);
        self::assertFalse($baris['bisaCabut']);
        self::assertSame(['admin_web' => 'Admin Web', 'anggota' => 'Anggota'], $this->renderer->data['peranTersedia']);

        // Admin web tidak bisa mengubah atau mencabut admin super lewat ID.
        self::assertSame(404, $this->post(['aksi' => 'peran', 'user_id' => (string) $super, 'peran' => 'anggota'])->getStatusCode());
        self::assertSame(404, $this->post(['aksi' => 'cabut', 'user_id' => (string) $super])->getStatusCode());
        self::assertSame(422, $this->post(['aksi' => 'tambah', 'email' => 'super@contoh.com', 'nama' => '', 'peran' => 'anggota'])->getStatusCode());
        self::assertSame('admin_super', $this->daftar->peran($super, self::LINGKUP));
    }

    public function testAdminSuperBisaMengelolaWebsiteTanpaAksesTersendiri(): void
    {
        $super = $this->daftar->buat('super@contoh.com', 'Super', self::SANDI);
        $this->daftar->tambahAkses($super, 'dashboard');

        $this->post(['aksi' => 'peran', 'user_id' => (string) $this->budi, 'peran' => 'admin_web'], sebagai: $super);
        self::assertSame('admin_web', $this->daftar->peran($this->budi, self::LINGKUP));
    }

    public function testAdminSuperTerakhirDiDashboardUtamaTerlindungi(): void
    {
        $pintu   = new Pintu('dashboard', '/', 3600, 'layout::uji');
        $super   = $this->daftar->buat('super@contoh.com', 'Super', self::SANDI);
        $kedua   = $this->daftar->buat('kedua@contoh.com', 'Kedua', self::SANDI);
        $this->daftar->tambahAkses($super, 'dashboard');
        $this->daftar->tambahAkses($kedua, 'dashboard', DaftarUser::PERAN_ANGGOTA);
        $this->pintu = $pintu;

        $this->get($super);
        self::assertSame(['admin_super' => 'Admin Super', 'anggota' => 'Anggota'], $this->renderer->data['peranTersedia']);

        // Kedua dijadikan admin super, lalu boleh diturunkan lagi karena masih ada admin super lain.
        $this->post(['aksi' => 'peran', 'user_id' => (string) $kedua, 'peran' => 'admin_super'], true, sebagai: $super);
        self::assertTrue($this->daftar->adalahAdminSuper($kedua));
        self::assertSame(2, $this->daftar->jumlahAdminSuper());
        $this->post(['aksi' => 'peran', 'user_id' => (string) $kedua, 'peran' => 'anggota'], true, sebagai: $super);
        self::assertFalse($this->daftar->adalahAdminSuper($kedua));

        // Admin web tidak bisa dipilih di dashboard utama.
        self::assertSame(422, $this->post(['aksi' => 'peran', 'user_id' => (string) $kedua, 'peran' => 'admin_web'], true, sebagai: $super)->getStatusCode());
    }

    public function testTanpaTokenDitolak(): void
    {
        self::assertSame(400, $this->post(['aksi' => 'cabut', 'user_id' => (string) $this->budi], denganToken: false)->getStatusCode());
        self::assertSame('anggota', $this->daftar->peran($this->budi, self::LINGKUP));
    }

    public function testAktivitasMenampilkanWaktuSetempatDanPelaku(): void
    {
        $this->post(['aksi' => 'peran', 'user_id' => (string) $this->budi, 'peran' => 'admin_web']);
        $this->get();

        $a = $this->renderer->data['aktivitas'][0];
        self::assertSame('Ubah peran', $a['label']);
        self::assertSame('admin@contoh.com', $a['pelaku']);
        self::assertSame('budi@contoh.com', $a['sasaran']);
        self::assertSame('Perangkat lain', $a['perangkatRingkas']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $a['waktuSetempat']);
        self::assertStringContainsString('Peran budi@contoh.com sekarang admin web.', (string) $this->renderer->data['pesan']);
    }

    public function testTabelRiwayatAkunBelumDibuatMemberiPesanJelas(): void
    {
        $this->db->query('DROP TABLE "user_riwayat_akun"', Adapter::QUERY_MODE_EXECUTE);

        self::assertSame(503, $this->get()->getStatusCode());
        self::assertSame(RiwayatAkun::PESAN_BELUM_DIPASANG, $this->renderer->data['belumSiap']);

        self::assertSame(503, $this->post(['aksi' => 'cabut', 'user_id' => (string) $this->budi])->getStatusCode());
        self::assertSame('anggota', $this->daftar->peran($this->budi, self::LINGKUP), 'Tidak ada yang diubah.');
    }

    public function testTabDariAlamat(): void
    {
        $tab = function (string $nilai): string {
            $this->handler(true)->handle($this->permintaan('GET', $this->admin)->withQueryParams(['tampil' => $nilai]));

            return $this->renderer->data['tampil'];
        };

        self::assertSame('aktivitas', $tab('aktivitas'));
        self::assertSame('akses', $tab('semua'), 'Tab Semua Akun sudah digabung ke Daftar User.');
        self::assertNull($this->renderer->data['semuaAkun'], 'Admin web tidak melihat semua akun.');
        self::assertSame('akses', $tab('lain'));
    }

    public function testAdminSuperMelihatSemuaAkunDiDashboardUtama(): void
    {
        $super = $this->daftar->buat('super@contoh.com', 'Super', self::SANDI);
        $this->daftar->tambahAkses($super, 'dashboard');
        $citra = $this->daftar->buat('citra@lain.com', 'Citra', self::SANDI);
        $this->daftar->email()->tambah($citra, 'citra@kantor.co.id');
        $utama = new Pintu('dashboard', '/', 3600, 'layout::uji');
        $handler = new KelolaUserHandler(
            $this->daftar, $this->riwayatAkun, new PenyajiDasar($this->renderer, $utama), $utama, new DateTimeZone('Asia/Jakarta'), true,
        );
        $minta = function (string $metode, int $sebagai, array $isi = [], array $query = []) use ($handler): ResponseInterface {
            if ($metode === 'POST') {
                $isi[TokenCsrf::FIELD] = (new TokenCsrf($this->sesi))->token();
            }

            return $handler->handle(
                (new ServerRequest(['REMOTE_ADDR' => '192.0.2.1'], [], '/user', $metode))
                    ->withQueryParams($query)
                    ->withParsedBody($metode === 'POST' ? $isi : null)
                    ->withAttribute(Sesi::class, $this->sesi)
                    ->withAttribute(User::class, $this->daftar->cariId($sebagai))
                    ->withAttribute(WajibMasukMiddleware::ATRIBUT_PERAN, $this->daftar->peran($sebagai, 'dashboard')),
            );
        };

        // Email yang dipakai di website ditampilkan bila berbeda dari email utama.
        $this->daftar->tambahAkses($citra, 'kantor.co.id', DaftarUser::PERAN_ANGGOTA, 'citra@kantor.co.id');

        $this->daftar->buat('eka@contoh.com', 'Eka', self::SANDI);
        $minta('GET', $super);
        self::assertSame('akses', $this->renderer->data['tampil']);
        $semua = array_column($this->renderer->data['semuaAkun'], null, 'email');
        self::assertSame(['admin@contoh.com', 'budi@contoh.com', 'citra@lain.com', 'eka@contoh.com', 'super@contoh.com'], array_keys($semua));
        self::assertSame([], $semua['eka@contoh.com']['akses'], 'Akun tanpa akses tetap terlihat.');
        self::assertSame(2, $semua['citra@lain.com']['jumlahEmail']);
        self::assertSame('citra@kantor.co.id', $semua['citra@lain.com']['akses'][0]['email']);
        self::assertSame([['email' => null, 'lingkup' => self::LINGKUP, 'peran' => 'anggota', 'labelPeran' => 'Anggota']], $semua['budi@contoh.com']['akses']);
        self::assertSame(['citra@lain.com', 'citra@kantor.co.id'], $semua['citra@lain.com']['semuaEmail'], 'Email utama lebih dulu.');
        self::assertTrue($semua['super@contoh.com']['adminSuper']);
        self::assertFalse($semua['super@contoh.com']['bisaAturAktif'], 'Akun sendiri.');

        // Akses dashboard diatur dari baris yang sama: hanya akun yang punya akses ke dashboard ini.
        self::assertNull($semua['eka@contoh.com']['peran']);
        self::assertFalse($semua['eka@contoh.com']['bisaCabut']);
        self::assertFalse($semua['super@contoh.com']['bisaUbahPeran'], 'Akun sendiri.');

        // Akun yang hanya punya akses ke website bisa dinonaktifkan dari dashboard utama.
        $hasil = $minta('POST', $super, ['aksi' => 'aktif', 'user_id' => (string) $this->budi, 'nilai' => '0']);
        self::assertSame('/user', $hasil->getHeaderLine('Location'));
        self::assertFalse($this->daftar->cariId($this->budi)?->aktif);

        // Pencarian di Semua akun mencakup email tambahan; jumlah total tetap untuk tab.
        $minta('GET', $super, [], ['cari' => 'KANTOR.co']);
        self::assertSame(['citra@lain.com'], array_column($this->renderer->data['semuaAkun'], 'email'));
        self::assertSame(5, $this->renderer->data['jumlahSemua']);
        self::assertSame('KANTOR.co', $this->renderer->data['cari']);

        // Admin super melepas email tambahan; email utama tidak bisa dilepas.
        $lepas = fn (string $email) => $minta('POST', $super, ['aksi' => 'lepas_email', 'user_id' => (string) $citra, 'email' => $email]);
        self::assertSame(422, $lepas('citra@lain.com')->getStatusCode());
        self::assertSame(404, $lepas('tidak@ada.com')->getStatusCode());
        self::assertSame('/user', $lepas('citra@kantor.co.id')->getHeaderLine('Location'));
        self::assertNull($this->daftar->cariEmail('citra@kantor.co.id'));
        self::assertSame('citra@lain.com', $this->daftar->email()->emailLingkup($citra, 'kantor.co.id'), 'Website kembali ke email utama.');
        $terakhir = $this->riwayatAkun->untukUser($citra)[0];
        self::assertSame([RiwayatAkun::HAPUS_EMAIL, 'super@contoh.com', 'citra@kantor.co.id'], [$terakhir['peristiwa'], $terakhir['pelaku'], $terakhir['keterangan']]);

        // Bukan admin super: tidak ada daftar semua akun, dan akun di luar lingkup tidak bisa disentuh.
        $anggota = $this->daftar->buat('dewi@contoh.com', 'Dewi', self::SANDI);
        $this->daftar->tambahAkses($anggota, 'dashboard', DaftarUser::PERAN_ANGGOTA);
        self::assertSame(403, $minta('GET', $anggota)->getStatusCode());
        self::assertNull($this->renderer->data['semuaAkun']);

        // Di dashboard website (tanpa kelola akun), admin super pun tidak mendapat daftar semua akun.
        $this->handler()->handle($this->permintaan('GET', $super));
        self::assertNull($this->renderer->data['semuaAkun']);
    }

    public function testCariDiWebsiteHanyaEmailWebsite(): void
    {
        $this->daftar->email()->tambah($this->budi, 'budi@kantor.co.id');

        $cari = fn (string $kata) => $this->handler()->handle($this->permintaan('GET', $this->admin)->withQueryParams(['cari' => $kata]));

        $cari('bud');
        self::assertSame(['budi@contoh.com'], array_column($this->renderer->data['daftarUser'], 'email'));
        $cari('kantor.co.id');
        self::assertSame([], $this->renderer->data['daftarUser'], 'Email yang tidak dipakai di website ini tidak bisa dicari.');
        self::assertNull($this->renderer->data['semuaAkun']);
    }

    /**
     * Handler dashboard utama dengan daftar website tiruan: toko (tanpa batasan) dan kantor (hanya @kantor.co.id).
     *
     * @return \Closure(string, int, array<string, string>=): ResponseInterface
     */
    private function utamaDenganWebsite(?DaftarWebsite $website = null): \Closure
    {
        $website ??= new class implements DaftarWebsite {
            public function website(): array
            {
                return ['kantor.co.id', KelolaUserHandlerTest::LINGKUP_UJI, 'rusak.com'];
            }

            public function batasanDomain(string $host): BatasanDomain
            {
                return match ($host) {
                    'kantor.co.id' => new BatasanDomain(['kantor.co.id']),
                    'rusak.com'    => throw new \RuntimeException('pengaturan rusak'),
                    default        => new BatasanDomain(),
                };
            }
        };
        $utama   = new Pintu('dashboard', '/', 3600, 'layout::uji');
        $handler = new KelolaUserHandler(
            $this->daftar, $this->riwayatAkun, new PenyajiDasar($this->renderer, $utama), $utama, new DateTimeZone('Asia/Jakarta'), true,
            daftarWebsite: $website,
        );

        return function (string $metode, int $sebagai, array $isi = []) use ($handler): ResponseInterface {
            if ($metode === 'POST') {
                $isi[TokenCsrf::FIELD] = (new TokenCsrf($this->sesi))->token();
            }

            return $handler->handle(
                (new ServerRequest(['REMOTE_ADDR' => '192.0.2.1'], [], '/user', $metode))
                    ->withParsedBody($metode === 'POST' ? $isi : null)
                    ->withAttribute(Sesi::class, $this->sesi)
                    ->withAttribute(User::class, $this->daftar->cariId($sebagai))
                    ->withAttribute(WajibMasukMiddleware::ATRIBUT_PERAN, $this->daftar->peran($sebagai, 'dashboard')),
            );
        };
    }

    public const LINGKUP_UJI = self::LINGKUP;

    public function testAdminSuperMengaturAksesWebsiteDariDashboardUtama(): void
    {
        $super = $this->daftar->buat('super@contoh.com', 'Super', self::SANDI);
        $this->daftar->tambahAkses($super, 'dashboard');
        $minta = $this->utamaDenganWebsite();

        $minta('GET', $super);
        self::assertSame(['kantor.co.id', self::LINGKUP, 'rusak.com'], array_column($this->renderer->data['websiteTersedia'], 'host'));
        self::assertSame(['kantor.co.id'], $this->renderer->data['websiteTersedia'][0]['domain']);
        self::assertFalse($this->renderer->data['websiteTersedia'][2]['terbaca']);
        $baris = array_column($this->renderer->data['semuaAkun'], null, 'email');
        self::assertTrue($baris['budi@contoh.com']['bisaAturWebsite']);
        self::assertFalse($baris['super@contoh.com']['bisaAturWebsite'], 'Admin super sudah admin di semua website.');

        // Email pribadi ditolak website kantor; email kantor milik akun yang sama diterima.
        $hasil = $minta('POST', $super, ['aksi' => 'akses_website', 'user_id' => (string) $this->budi, 'website' => 'kantor.co.id', 'peran' => 'admin_web']);
        self::assertSame(422, $hasil->getStatusCode());
        self::assertStringContainsString('@kantor.co.id', $this->renderer->data['galat']);
        self::assertNull($this->daftar->peran($this->budi, 'kantor.co.id'));

        $this->daftar->email()->tambah($this->budi, 'budi@kantor.co.id');
        self::assertSame(422, $minta('POST', $super, ['aksi' => 'akses_website', 'user_id' => (string) $this->budi, 'website' => 'kantor.co.id', 'peran' => 'admin_web', 'email' => 'orang@lain.com'])->getStatusCode(), 'Email bukan milik akun.');
        $hasil = $minta('POST', $super, ['aksi' => 'akses_website', 'user_id' => (string) $this->budi, 'website' => 'kantor.co.id', 'peran' => 'admin_web', 'email' => 'budi@kantor.co.id']);
        self::assertSame(302, $hasil->getStatusCode());
        self::assertSame('/user', $hasil->getHeaderLine('Location'));
        self::assertSame('admin_web', $this->daftar->peran($this->budi, 'kantor.co.id'));
        self::assertSame('budi@kantor.co.id', $this->daftar->email()->emailLingkup($this->budi, 'kantor.co.id'));

        // Riwayat tercatat di lingkup website itu, jadi admin website tersebut ikut melihatnya.
        $riwayat = $this->riwayatAkun->untukLingkup('kantor.co.id');
        self::assertSame(RiwayatAkun::BERI_AKSES, $riwayat[0]['peristiwa']);
        self::assertSame('Super', $riwayat[0]['pelaku'], 'Admin super tanpa akses tersendiri ke website tampil dengan namanya.');

        // Ubah peran di website lain yang sudah ada (toko: Budi anggota) dan cabut.
        $minta('POST', $super, ['aksi' => 'akses_website', 'user_id' => (string) $this->budi, 'website' => self::LINGKUP, 'peran' => 'admin_web']);
        self::assertSame('admin_web', $this->daftar->peran($this->budi, self::LINGKUP));
        self::assertSame(RiwayatAkun::UBAH_PERAN, $this->riwayatAkun->untukLingkup(self::LINGKUP)[0]['peristiwa']);
        self::assertSame(422, $minta('POST', $super, ['aksi' => 'akses_website', 'user_id' => (string) $this->budi, 'website' => self::LINGKUP, 'peran' => 'admin_super'])->getStatusCode());

        $minta('POST', $super, ['aksi' => 'cabut_website', 'user_id' => (string) $this->budi, 'website' => self::LINGKUP]);
        self::assertNull($this->daftar->peran($this->budi, self::LINGKUP));
        self::assertSame(404, $minta('POST', $super, ['aksi' => 'cabut_website', 'user_id' => (string) $this->budi, 'website' => self::LINGKUP])->getStatusCode());

        // Website tidak terdaftar, pengaturan rusak, dan admin super sebagai sasaran ditolak.
        self::assertSame(422, $minta('POST', $super, ['aksi' => 'akses_website', 'user_id' => (string) $this->budi, 'website' => 'asing.com', 'peran' => 'anggota'])->getStatusCode());
        self::assertSame(422, $minta('POST', $super, ['aksi' => 'akses_website', 'user_id' => (string) $this->budi, 'website' => 'dashboard', 'peran' => 'anggota'])->getStatusCode());
        self::assertSame(500, $minta('POST', $super, ['aksi' => 'akses_website', 'user_id' => (string) $this->budi, 'website' => 'rusak.com', 'peran' => 'anggota'])->getStatusCode());
        self::assertSame(422, $minta('POST', $super, ['aksi' => 'akses_website', 'user_id' => (string) $super, 'website' => self::LINGKUP, 'peran' => 'anggota'])->getStatusCode());
    }

    public function testTambahUserLangsungUntukWebsite(): void
    {
        $super = $this->daftar->buat('super@contoh.com', 'Super', self::SANDI);
        $this->daftar->tambahAkses($super, 'dashboard');
        $minta = $this->utamaDenganWebsite();

        // Admin super hanya untuk dashboard; email di luar domain website ditolak.
        self::assertSame(422, $minta('POST', $super, ['aksi' => 'tambah', 'email' => 'eka@kantor.co.id', 'nama' => 'Eka', 'peran' => 'admin_super', 'lingkup' => 'kantor.co.id'])->getStatusCode());
        self::assertSame(422, $minta('POST', $super, ['aksi' => 'tambah', 'email' => 'eka@gmail.com', 'nama' => 'Eka', 'peran' => 'admin_web', 'lingkup' => 'kantor.co.id'])->getStatusCode());
        self::assertSame('kantor.co.id', $this->renderer->data['formLingkup']);

        $hasil = $minta('POST', $super, ['aksi' => 'tambah', 'email' => 'eka@kantor.co.id', 'nama' => 'Eka', 'peran' => 'admin_web', 'lingkup' => 'kantor.co.id']);
        self::assertSame(302, $hasil->getStatusCode());
        $eka = $this->daftar->cariEmail('eka@kantor.co.id');
        self::assertNotNull($eka);
        self::assertSame('admin_web', $this->daftar->peran($eka->id, 'kantor.co.id'));
        self::assertNull($this->daftar->peran($eka->id, 'dashboard'), 'Tidak diberi akses dashboard utama.');
        self::assertSame([RiwayatAkun::BERI_AKSES, RiwayatAkun::BUAT_USER], array_column($this->riwayatAkun->untukLingkup('kantor.co.id'), 'peristiwa'));

        // Akun yang sudah ada cukup diberi akses.
        $minta('POST', $super, ['aksi' => 'tambah', 'email' => 'budi@contoh.com', 'peran' => 'anggota', 'lingkup' => 'kantor.co.id']);
        self::assertSame(422, $minta('POST', $super, ['aksi' => 'tambah', 'email' => 'budi@contoh.com', 'peran' => 'anggota', 'lingkup' => 'kantor.co.id'])->getStatusCode(), 'Email pribadi tetap ditolak website kantor.');
        $this->daftar->email()->tambah($this->budi, 'budi@kantor.co.id');
        self::assertSame(302, $minta('POST', $super, ['aksi' => 'tambah', 'email' => 'budi@kantor.co.id', 'peran' => 'anggota', 'lingkup' => 'kantor.co.id'])->getStatusCode());
        self::assertSame('anggota', $this->daftar->peran($this->budi, 'kantor.co.id'));
        self::assertSame('budi@kantor.co.id', $this->daftar->email()->emailLingkup($this->budi, 'kantor.co.id'), 'Email yang diketik dipakai di website itu.');
        self::assertSame(500, $minta('POST', $super, ['aksi' => 'tambah', 'email' => 'budi@contoh.com', 'peran' => 'anggota', 'lingkup' => 'rusak.com'])->getStatusCode());
        self::assertNull($this->daftar->peran($this->budi, 'rusak.com'));
    }

    public function testAksesWebsiteHanyaUntukAdminSuperDiDashboardUtama(): void
    {
        // Admin web di website (tanpa daftar website) tidak bisa memakai aksi ini.
        $hasil = $this->post(['aksi' => 'akses_website', 'user_id' => (string) $this->budi, 'website' => 'lain.com', 'peran' => 'anggota']);
        self::assertSame(403, $hasil->getStatusCode());
        self::assertSame([], $this->renderer->data['websiteTersedia']);
    }

    public function testDashboardUtamaMelihatAktivitasSemuaLingkup(): void
    {
        $super = $this->daftar->buat('super@contoh.com', 'Super', self::SANDI);
        $this->daftar->tambahAkses($super, 'dashboard');
        $this->riwayatAkun->catat(RiwayatAkun::BERI_AKSES, $this->admin, $this->budi, self::LINGKUP, 'peran anggota');
        $this->riwayatAkun->catat(RiwayatAkun::UBAH_NAMA, $super, $super, 'dashboard', 'A → B');
        for ($i = 0; $i < 25; $i++) {
            $this->riwayatAkun->catat(RiwayatAkun::UBAH_PROFIL, $this->budi, $this->budi, 'blog.contoh.com', "ubah {$i}");
        }

        $utama   = new Pintu('dashboard', '/', 3600, 'layout::uji');
        $handler = new KelolaUserHandler($this->daftar, $this->riwayatAkun, new PenyajiDasar($this->renderer, $utama), $utama, new DateTimeZone('UTC'), true);
        $lihat   = function (array $query) use ($handler, $super): array {
            $handler->handle(
                (new ServerRequest([], [], '/user', 'GET'))
                    ->withQueryParams(['tampil' => 'aktivitas'] + $query)
                    ->withAttribute(Sesi::class, $this->sesi)
                    ->withAttribute(User::class, $this->daftar->cariId($super))
                    ->withAttribute(WajibMasukMiddleware::ATRIBUT_PERAN, 'admin_super'),
            );

            return $this->renderer->data;
        };

        $data = $lihat([]);
        self::assertTrue($data['aktivitasSemua']);
        self::assertSame(['dashboard', 'blog.contoh.com', self::LINGKUP], $data['pilihanLingkup']);
        self::assertCount(20, $data['aktivitas']);
        self::assertSame(40, $data['aktivitasLagi']);

        $data = $lihat(['jumlah' => '40']);
        self::assertCount(27, $data['aktivitas']);
        self::assertNull($data['aktivitasLagi']);
        self::assertContains(self::LINGKUP, array_column($data['aktivitas'], 'lingkup'), 'Perubahan di website ikut tampil.');
        self::assertContains('budi@contoh.com', array_column($data['aktivitas'], 'sasaran'));

        $data = $lihat(['lingkup' => self::LINGKUP]);
        self::assertSame([self::LINGKUP], array_unique(array_column($data['aktivitas'], 'lingkup')));
        self::assertSame(self::LINGKUP, $data['saringLingkup']);
        self::assertSame('', $lihat(['lingkup' => 'asing.com'])['saringLingkup'], 'Lingkup tak dikenal diabaikan.');

        // Dashboard website tetap hanya lingkupnya sendiri, tanpa saringan.
        $this->get();
        self::assertFalse($this->renderer->data['aktivitasSemua']);
        self::assertSame([self::LINGKUP], array_unique(array_column($this->renderer->data['aktivitas'], 'lingkup')));
    }
}
