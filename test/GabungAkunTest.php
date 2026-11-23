<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\Email\SuratTemplat;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\FotoUser;
use AdeMS\Dapur\User\GabungAkun;
use AdeMS\Dapur\User\Handler\HalamanPublik;
use AdeMS\Dapur\User\Handler\ProfilHandler;
use AdeMS\Dapur\User\Handler\VerifikasiEmailHandler;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\PembatasPercobaan;
use AdeMS\Dapur\User\PenyajiDasar;
use AdeMS\Dapur\User\Perintah;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\ProfilUser;
use AdeMS\Dapur\User\RiwayatAkun;
use AdeMS\Dapur\User\RiwayatMasuk;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\SuratUser;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\TokenUser;
use AdeMS\Dapur\User\User;
use DateTimeZone;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSetInterface;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BantuanUji.php';
require_once __DIR__ . '/AlurEmailTest.php';

final class GabungAkunTest extends TestCase
{
    private const SANDI_A = 'kata-sandi-akun-a-panjang';
    private const SANDI_B = 'kata-sandi-akun-b-panjang';

    private Adapter $db;
    private DaftarUser $daftar;
    private TokenUser $token;
    private PengirimUji $pengirim;
    private SuratUser $surat;
    private RendererUji $renderer;
    private SesiLarik $sesi;
    private Pintu $pintu;
    private string $folder;
    private FotoUser $foto;
    private int $a;
    private int $b;
    private int $super;

    protected function setUp(): void
    {
        $this->db       = DatabaseUji::baru();
        $this->daftar   = new DaftarUser($this->db);
        $this->token    = new TokenUser($this->db);
        $this->pengirim = new PengirimUji();
        $this->surat    = new SuratUser($this->pengirim, new SuratTemplat(new RendererEmailUji()));
        $this->renderer = new RendererUji();
        $this->sesi     = new SesiLarik();
        $this->pintu    = new Pintu('toko.test', '/dashboard', 3600, 'layout::uji');
        $this->folder   = sys_get_temp_dir() . '/dapur-gabung-' . bin2hex(random_bytes(4));
        mkdir($this->folder);
        $this->foto     = new FotoUser($this->folder);

        $this->super = $this->daftar->buat('super@contoh.com', 'Super', self::SANDI_A);
        $this->daftar->tambahAkses($this->super, 'dashboard');
        $this->a = $this->daftar->buat('ade@pribadi.com', 'Ade Pribadi', self::SANDI_A);
        $this->b = $this->daftar->buat('ade@kantor.com', 'Ade Kantor', self::SANDI_B);
        $this->daftar->tambahAkses($this->a, 'toko.test', DaftarUser::PERAN_ADMIN_WEB);
        $this->daftar->tambahAkses($this->b, 'toko.test', DaftarUser::PERAN_ANGGOTA);
        $this->daftar->tambahAkses($this->b, 'kantor.test', DaftarUser::PERAN_ADMIN_WEB);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->folder);
    }

    private function gabung(): GabungAkun
    {
        return new GabungAkun($this->db, $this->daftar, foto: $this->foto);
    }

    private function user(int $id): User
    {
        $user = $this->daftar->cariId($id);
        self::assertNotNull($user);

        return $user;
    }

    private function jumlah(string $sql, array $parameter = []): int
    {
        $hasil = $this->db->query($sql, $parameter);

        return $hasil instanceof ResultSetInterface ? (int) ($hasil->toArray()[0]['n'] ?? 0) : 0;
    }

    public function testGabungMemindahkanEmailAksesDanRiwayat(): void
    {
        (new RiwayatMasuk($this->db))->catat(RiwayatMasuk::MASUK, $this->b, 'kantor.test', '192.0.2.1', 'Browser', 'ade@kantor.com');
        (new RiwayatAkun($this->db))->catat(RiwayatAkun::UBAH_NAMA, $this->b, $this->b, 'kantor.test', 'lama → baru');
        $this->token->buat($this->b, TokenUser::LUPA_SANDI);

        $ringkasan = $this->gabung()->gabung($this->user($this->a), $this->user($this->b));

        self::assertNull($this->daftar->cariId($this->b), 'Akun B ditutup.');
        self::assertSame($this->a, $this->daftar->cariEmail('ade@kantor.com')?->id, 'Email B bisa dipakai masuk ke akun A.');
        self::assertSame('ade@pribadi.com', $this->user($this->a)->email, 'Email utama A tetap.');
        self::assertSame(DaftarUser::PERAN_ANGGOTA, $this->daftar->peranTersimpan($this->a, 'toko.test'), 'Akses bentrok: peran lebih rendah.');
        self::assertSame(DaftarUser::PERAN_ADMIN_WEB, $this->daftar->peranTersimpan($this->a, 'kantor.test'), 'Akses B pindah apa adanya.');
        self::assertSame(1, $this->jumlah('SELECT COUNT(*) AS n FROM user_riwayat_masuk WHERE user_id = ?', [$this->a]));
        self::assertSame(1, $this->jumlah('SELECT COUNT(*) AS n FROM user_riwayat_akun WHERE sasaran_id = ? AND pelaku_id = ?', [$this->a, $this->a]));
        self::assertSame(0, $this->jumlah('SELECT COUNT(*) AS n FROM user_token WHERE user_id = ?', [$this->b]));
        self::assertSame(['ade@kantor.com'], $ringkasan['email']);
        self::assertSame(
            [['lingkup' => 'kantor.test', 'peranB' => 'admin_web', 'peranA' => null, 'peranAkhir' => 'admin_web'],
             ['lingkup' => 'toko.test', 'peranB' => 'anggota', 'peranA' => 'admin_web', 'peranAkhir' => 'anggota']],
            $ringkasan['akses'],
        );
    }

    public function testEmailWebsitePilihanBIkutPindah(): void
    {
        $kantor = $this->daftar->email()->id($this->b, 'ade@kantor.com');
        $this->daftar->email()->tambah($this->b, 'ade@cabang.com');
        $this->daftar->tambahAkses($this->b, 'cabang.test', DaftarUser::PERAN_ANGGOTA, 'ade@cabang.com');

        $this->gabung()->gabung($this->user($this->a), $this->user($this->b));

        self::assertSame('ade@cabang.com', $this->daftar->email()->emailLingkup($this->a, 'cabang.test'));
        self::assertNotNull($kantor);
        self::assertSame('ade@kantor.com', $this->daftar->email()->milik($this->a, $kantor));
    }

    public function testProfilBHanyaMengisiYangKosong(): void
    {
        $profil = $this->daftar->profil();
        $profil->simpan($this->a, '', ['pendidikan' => 'S1 A', 'lokasi' => 'Bekasi']);
        $profil->simpan($this->b, '', ['pendidikan' => 'S1 B', 'keahlian' => 'Masak', 'lokasi' => 'Kota Medan', ProfilUser::LOKASI_ID => '201']);
        $profil->simpan($this->a, 'toko.test', [ProfilUser::NAMA => 'Pak Ade']);
        $profil->simpan($this->b, 'toko.test', [ProfilUser::NAMA => 'Ade Toko', ProfilUser::FOTO => str_repeat('a', 32)]);
        $profil->simpan($this->b, 'kantor.test', [ProfilUser::NAMA => 'Ade Kantor Resmi', ProfilUser::FOTO => str_repeat('b', 32)]);
        $this->daftar->aturFoto($this->b, str_repeat('c', 32));
        foreach (['a', 'b', 'c'] as $h) {
            touch($this->folder . '/' . str_repeat($h, 32) . '.webp');
        }

        $this->gabung()->gabung($this->user($this->a), $this->user($this->b));

        self::assertSame(['keahlian' => 'Masak', 'lokasi' => 'Bekasi', 'pendidikan' => 'S1 A'], $this->urut($profil->ambil($this->a)), 'Tentang A tetap; yang kosong diisi B; nomor kota B tidak dipakai untuk Lokasi A.');
        self::assertSame([ProfilUser::NAMA => 'Pak Ade'], $profil->ambil($this->a, 'toko.test'), 'Profil website A tetap.');
        self::assertSame([ProfilUser::FOTO => str_repeat('b', 32), ProfilUser::NAMA => 'Ade Kantor Resmi'], $this->urut($profil->ambil($this->a, 'kantor.test')), 'Profil website B dipakai di website tanpa profil A.');
        self::assertFileDoesNotExist($this->folder . '/' . str_repeat('a', 32) . '.webp', 'Foto website B yang tidak dipakai dihapus.');
        self::assertFileExists($this->folder . '/' . str_repeat('b', 32) . '.webp');
        self::assertFileDoesNotExist($this->folder . '/' . str_repeat('c', 32) . '.webp', 'Foto akun B dihapus.');
        self::assertSame('', $this->user($this->a)->foto);
    }

    /** @param array<string, string> $x */
    private function urut(array $x): array
    {
        ksort($x);

        return $x;
    }

    public function testAlasanDitolak(): void
    {
        $g = $this->gabung();
        self::assertNull($g->alasanDitolak($this->user($this->a), $this->user($this->b), true));
        self::assertSame('Email itu sudah milik akun Anda.', $g->alasanDitolak($this->user($this->a), $this->user($this->a), true));
        self::assertStringContainsString('terminal', (string) $g->alasanDitolak($this->user($this->super), $this->user($this->b), true));
        self::assertStringContainsString('terminal', (string) $g->alasanDitolak($this->user($this->a), $this->user($this->super), true));

        $this->daftar->aturAktif($this->b, false);
        self::assertStringContainsString('nonaktif', (string) $g->alasanDitolak($this->user($this->a), $this->user($this->b), true));

        // Lewat terminal: admin super boleh digabung, sebagai A maupun B.
        $this->daftar->aturAktif($this->b, true);
        $this->daftar->tambahAkses($this->a, 'dashboard', DaftarUser::PERAN_ANGGOTA);
        self::assertNull($g->alasanDitolak($this->user($this->a), $this->user($this->super), false));
        self::assertNull($g->alasanDitolak($this->user($this->super), $this->user($this->a), false));
    }

    public function testAdminSuperTidakTurunSaatMenerimaAkunAnggota(): void
    {
        // Kasus yang ditemukan saat uji MariaDB: anggota dashboard digabung ke satu-satunya admin super.
        $this->daftar->tambahAkses($this->a, 'dashboard', DaftarUser::PERAN_ANGGOTA);
        $ringkasan = $this->gabung()->gabung($this->user($this->super), $this->user($this->a));

        self::assertTrue($this->daftar->adalahAdminSuper($this->super));
        self::assertSame(1, $this->daftar->jumlahAdminSuper());
        $dashboard = array_values(array_filter($ringkasan['akses'], static fn (array $x): bool => $x['lingkup'] === 'dashboard'));
        self::assertSame(DaftarUser::PERAN_ADMIN_SUPER, $dashboard[0]['peranAkhir']);
    }

    public function testAdminSuperTidakTurunSaatDigabungKeAkunAnggota(): void
    {
        // Lewat terminal: admin super B digabung ke A yang anggota dashboard; A menjadi admin super.
        $this->daftar->tambahAkses($this->a, 'dashboard', DaftarUser::PERAN_ANGGOTA);
        $this->gabung()->gabung($this->user($this->a), $this->user($this->super));

        self::assertTrue($this->daftar->adalahAdminSuper($this->a));
        self::assertSame(1, $this->daftar->jumlahAdminSuper());
    }

    public function testPeranGabungan(): void
    {
        self::assertSame(DaftarUser::PERAN_ADMIN_SUPER, GabungAkun::peranGabungan(DaftarUser::PERAN_ADMIN_SUPER, DaftarUser::PERAN_ANGGOTA));
        self::assertSame(DaftarUser::PERAN_ADMIN_SUPER, GabungAkun::peranGabungan(DaftarUser::PERAN_ANGGOTA, DaftarUser::PERAN_ADMIN_SUPER));
        self::assertSame(DaftarUser::PERAN_ANGGOTA, GabungAkun::peranGabungan(DaftarUser::PERAN_ADMIN_WEB, DaftarUser::PERAN_ANGGOTA));
        self::assertSame(DaftarUser::PERAN_ADMIN_WEB, GabungAkun::peranGabungan(DaftarUser::PERAN_ADMIN_WEB, DaftarUser::PERAN_ADMIN_WEB));
    }

    public function testAdminSuperPindahBilaATanpaAksesDashboard(): void
    {
        $this->gabung()->gabung($this->user($this->a), $this->user($this->super));

        self::assertTrue($this->daftar->adalahAdminSuper($this->a));
        self::assertSame(1, $this->daftar->jumlahAdminSuper());
    }

    // ---- Halaman Pengaturan & Privasi ----

    private function profil(): ProfilHandler
    {
        return new ProfilHandler(
            $this->daftar,
            new PembatasPercobaan($this->db),
            new RiwayatMasuk($this->db),
            new PenyajiDasar($this->renderer, $this->pintu),
            $this->pintu,
            new DateTimeZone('Asia/Jakarta'),
            ProfilHandler::TEMPLATE_PENGATURAN,
            new RiwayatAkun($this->db),
            $this->token,
            $this->surat,
            'Dapur Uji',
            ProfilHandler::HALAMAN_PENGATURAN,
            gabung: $this->gabung(),
        );
    }

    private function verifikasi(): VerifikasiEmailHandler
    {
        return new VerifikasiEmailHandler($this->daftar, $this->token, $this->surat, new RiwayatAkun($this->db), new HalamanPublik($this->renderer, $this->pintu), $this->pintu, 'Dapur Uji', $this->gabung());
    }

    /** @param array<string, string> $isi */
    private function permintaan(string $metode, array $isi = [], ?int $sebagai = null, string $alamat = '/dashboard/pengaturan'): ServerRequest
    {
        if ($metode === 'POST') {
            $isi[TokenCsrf::FIELD] = (new TokenCsrf($this->sesi))->token();
        }

        $request = (new ServerRequest(['REMOTE_ADDR' => '192.0.2.7'], [], 'https://toko.test' . $alamat, $metode, 'php://memory', ['User-Agent' => 'Browser Uji']))
            ->withAttribute(Sesi::class, $this->sesi)
            ->withQueryParams($metode === 'GET' ? $isi : [])
            ->withParsedBody($metode === 'POST' ? $isi : null);

        if ($sebagai !== null) {
            $request = $request->withAttribute(User::class, $this->user($sebagai))
                ->withAttribute(WajibMasukMiddleware::ATRIBUT_PERAN, $this->daftar->peran($sebagai, 'toko.test'));
        }

        return $request;
    }

    public function testDenganKataSandiAkunB(): void
    {
        $this->profil()->handle($this->permintaan('GET', [], $this->a));
        self::assertTrue($this->renderer->data['gabungAkun']['tersedia']);
        self::assertTrue($this->renderer->data['gabungAkun']['bisaTautan']);

        $tanpaSetuju = $this->profil()->handle($this->permintaan('POST', ['aksi' => 'gabung_akun', 'email_akun' => 'ade@kantor.com', 'cara' => 'sandi', 'sandi_akun' => self::SANDI_B], $this->a));
        self::assertSame(422, $tanpaSetuju->getStatusCode());
        self::assertSame('gabung', $this->renderer->data['galatBagian']);

        $salah = $this->profil()->handle($this->permintaan('POST', ['aksi' => 'gabung_akun', 'email_akun' => 'ade@kantor.com', 'cara' => 'sandi', 'sandi_akun' => 'salah', 'setuju' => '1'], $this->a));
        self::assertSame(422, $salah->getStatusCode());
        self::assertSame('Email atau kata sandi akun itu salah.', $this->renderer->data['galat']);
        self::assertNotNull($this->daftar->cariId($this->b));

        $tidakAda = $this->profil()->handle($this->permintaan('POST', ['aksi' => 'gabung_akun', 'email_akun' => 'tidak@ada.com', 'cara' => 'sandi', 'sandi_akun' => self::SANDI_B, 'setuju' => '1'], $this->a));
        self::assertSame('Email atau kata sandi akun itu salah.', $this->renderer->data['galat'], 'Email tidak terdaftar dijawab sama.');
        self::assertSame(422, $tidakAda->getStatusCode());

        $ok = $this->profil()->handle($this->permintaan('POST', ['aksi' => 'gabung_akun', 'email_akun' => 'Ade@Kantor.com', 'cara' => 'sandi', 'sandi_akun' => self::SANDI_B, 'setuju' => '1'], $this->a));
        self::assertSame(302, $ok->getStatusCode());
        self::assertNull($this->daftar->cariId($this->b));
        self::assertSame($this->a, $this->daftar->cariEmail('ade@kantor.com')?->id);
        self::assertStringContainsString('sudah digabungkan', (string) $this->sesi->ambil(ProfilHandler::SESI_PESAN));
        self::assertSame(['ade@pribadi.com', 'ade@kantor.com'], array_map(static fn ($p) => $p->kepada, $this->pengirim->terkirim), 'Kedua email diberi tahu.');

        $riwayat = (new RiwayatAkun($this->db))->untukUser($this->a, 5);
        self::assertSame(RiwayatAkun::GABUNG_AKUN, $riwayat[0]['peristiwa']);
        self::assertStringContainsString('ade@kantor.com', $riwayat[0]['keterangan']);
    }

    public function testKataSandiSalahDibatasi(): void
    {
        for ($i = 0; $i < PembatasPercobaan::BATAS_AKUN_IP; $i++) {
            $this->profil()->handle($this->permintaan('POST', ['aksi' => 'gabung_akun', 'email_akun' => 'ade@kantor.com', 'cara' => 'sandi', 'sandi_akun' => 'salah', 'setuju' => '1'], $this->a));
        }

        $terkunci = $this->profil()->handle($this->permintaan('POST', ['aksi' => 'gabung_akun', 'email_akun' => 'ade@kantor.com', 'cara' => 'sandi', 'sandi_akun' => self::SANDI_B, 'setuju' => '1'], $this->a));
        self::assertSame(429, $terkunci->getStatusCode());
        self::assertNotNull($this->daftar->cariId($this->b));
    }

    public function testAdminSuperTidakBisaLewatWeb(): void
    {
        $this->profil()->handle($this->permintaan('GET', [], $this->super));
        self::assertFalse($this->renderer->data['gabungAkun']['tersedia']);
        self::assertTrue($this->renderer->data['gabungAkun']['adminSuper']);

        $res = $this->profil()->handle($this->permintaan('POST', ['aksi' => 'gabung_akun', 'email_akun' => 'ade@kantor.com', 'cara' => 'sandi', 'sandi_akun' => self::SANDI_B, 'setuju' => '1'], $this->super));
        self::assertSame(403, $res->getStatusCode());
        self::assertNotNull($this->daftar->cariId($this->b));

        // Akun B admin super: ditolak setelah kata sandinya benar.
        $res = $this->profil()->handle($this->permintaan('POST', ['aksi' => 'gabung_akun', 'email_akun' => 'super@contoh.com', 'cara' => 'sandi', 'sandi_akun' => self::SANDI_A, 'setuju' => '1'], $this->a));
        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('terminal', (string) $this->renderer->data['galat']);
        self::assertNotNull($this->daftar->cariId($this->super));
    }

    public function testDenganTautanKonfirmasi(): void
    {
        $res = $this->profil()->handle($this->permintaan('POST', ['aksi' => 'gabung_akun', 'email_akun' => 'ade@kantor.com', 'cara' => 'tautan', 'setuju' => '1'], $this->a));
        self::assertSame(302, $res->getStatusCode());
        self::assertCount(1, $this->pengirim->terkirim);
        self::assertSame('ade@kantor.com', $this->pengirim->terkirim[0]->kepada);
        self::assertStringStartsWith('user::email-gabung-konfirmasi|https://toko.test/dashboard/verifikasi-email?token=', $this->pengirim->terkirim[0]->html);
        self::assertNotNull($this->daftar->cariId($this->b), 'Belum digabung sebelum tautan dikonfirmasi.');

        parse_str((string) parse_url(explode('|', $this->pengirim->terkirim[0]->html)[1], PHP_URL_QUERY), $query);
        $token = (string) $query['token'];

        // Email yang tidak terdaftar: jawaban sama, tanpa email.
        $this->profil()->handle($this->permintaan('POST', ['aksi' => 'gabung_akun', 'email_akun' => 'tidak@ada.com', 'cara' => 'tautan', 'setuju' => '1'], $this->a));
        self::assertCount(1, $this->pengirim->terkirim);

        $lihat = $this->verifikasi()->handle($this->permintaan('GET', ['token' => $token], null, '/dashboard/verifikasi-email'));
        self::assertSame(200, $lihat->getStatusCode());
        self::assertTrue($this->renderer->data['gabung']);
        self::assertSame('ade@pribadi.com', $this->renderer->data['emailLama']);
        self::assertSame('anggota', $this->renderer->data['ringkasan']['akses'][1]['peranAkhir']);
        self::assertNotNull($this->daftar->cariId($this->b), 'Membuka tautan saja tidak menggabungkan.');

        $ok = $this->verifikasi()->handle($this->permintaan('POST', ['token' => $token], null, '/dashboard/verifikasi-email'));
        self::assertSame(200, $ok->getStatusCode());
        self::assertTrue($this->renderer->data['selesai']);
        self::assertNull($this->daftar->cariId($this->b));
        self::assertSame($this->a, $this->daftar->cariEmail('ade@kantor.com')?->id);

        $lagi = $this->verifikasi()->handle($this->permintaan('POST', ['token' => $token], null, '/dashboard/verifikasi-email'));
        self::assertSame(410, $lagi->getStatusCode(), 'Tautan hanya sekali pakai.');
    }

    public function testTautanTidakBerlakuBilaEmailBSudahPindah(): void
    {
        $this->profil()->handle($this->permintaan('POST', ['aksi' => 'gabung_akun', 'email_akun' => 'ade@kantor.com', 'cara' => 'tautan', 'setuju' => '1'], $this->a));
        parse_str((string) parse_url(explode('|', $this->pengirim->terkirim[0]->html)[1], PHP_URL_QUERY), $query);

        // Sementara itu email B diganti, jadi tautan lama tidak boleh menggabungkan akun B.
        $this->daftar->gantiEmail($this->b, 'ade@baru.com');
        $res = $this->verifikasi()->handle($this->permintaan('GET', ['token' => (string) $query['token']], null, '/dashboard/verifikasi-email'));
        self::assertSame(410, $res->getStatusCode());
        self::assertNotNull($this->daftar->cariId($this->b));
    }

    // ---- Terminal ----

    public function testPerintahGabungAkun(): void
    {
        $perintah = fn (?\Closure $tanya): Perintah => new Perintah(
            $this->db,
            $this->daftar,
            static fn (string $p): string => '',
            'dashboard',
            $tanya,
            riwayatAkun: new RiwayatAkun($this->db),
            gabung: $this->gabung(),
        );

        ob_start();
        $kode = $perintah(null)->jalankan(['gabung-akun', 'ade@pribadi.com', 'ade@kantor.com']);
        ob_end_clean();
        self::assertSame(1, $kode, 'Di luar terminal tanpa --ya: ditolak.');
        self::assertNotNull($this->daftar->cariId($this->b));

        ob_start();
        $kode = $perintah(static fn (string $p): string => 'n')->jalankan(['gabung-akun', 'ade@pribadi.com', 'ade@kantor.com']);
        $keluaran = (string) ob_get_clean();
        self::assertSame(0, $kode);
        self::assertStringContainsString('Akun tidak diubah', $keluaran);
        self::assertNotNull($this->daftar->cariId($this->b));

        // Admin super boleh digabung lewat terminal.
        ob_start();
        $kode = $perintah(null)->jalankan(['gabung-akun', 'super@contoh.com', 'ade@kantor.com', '--ya']);
        $keluaran = (string) ob_get_clean();
        self::assertSame(0, $kode, $keluaran);
        self::assertStringContainsString("akses 'kantor.test': Admin Web", $keluaran);
        self::assertNull($this->daftar->cariId($this->b));
        self::assertSame($this->super, $this->daftar->cariEmail('ade@kantor.com')?->id);

        $riwayat = (new RiwayatAkun($this->db))->untukUser($this->super, 1);
        self::assertSame(RiwayatAkun::GABUNG_AKUN, $riwayat[0]['peristiwa']);
        self::assertNull($riwayat[0]['pelaku']);
    }
}
