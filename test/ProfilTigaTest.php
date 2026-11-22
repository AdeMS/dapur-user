<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\FotoUser;
use AdeMS\Dapur\User\Handler\FotoHandler;
use AdeMS\Dapur\User\Handler\KelolaUserHandler;
use AdeMS\Dapur\User\Handler\ProfilHandler;
use AdeMS\Dapur\User\Identitas;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\PembatasPercobaan;
use AdeMS\Dapur\User\PenyajiDasar;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\ProfilUser;
use AdeMS\Dapur\User\RiwayatAkun;
use AdeMS\Dapur\User\RiwayatMasuk;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\SumberLokasi;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\User;
use DateTimeZone;
use Laminas\Db\Adapter\Adapter;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/BantuanUji.php';
require_once __DIR__ . '/FotoUserTest.php';

/** Profil 2.3: nama depan/tengah/belakang, foto, Tentang, profil per website, dan profil rekan. */
#[RequiresPhpExtension('gd')]
final class ProfilTigaTest extends TestCase
{
    private const SANDI   = 'kata-sandi-yang-panjang';
    private const WEBSITE = 'kantor.co.id';

    private Adapter $db;
    private DaftarUser $daftar;
    private RendererUji $renderer;
    private SesiLarik $sesi;
    private FotoUser $foto;
    private string $folder;
    private int $ade;
    private int $budi;

    protected function setUp(): void
    {
        $this->db       = DatabaseUji::baru();
        $this->daftar   = new DaftarUser($this->db);
        $this->renderer = new RendererUji();
        $this->sesi     = new SesiLarik();
        $this->folder   = sys_get_temp_dir() . '/dapur-profil-uji-' . bin2hex(random_bytes(4));
        $this->foto     = new FotoUser($this->folder . '/avatar');
        $this->ade      = $this->daftar->buat('ade@contoh.com', 'Ade Lama', self::SANDI);
        $this->budi     = $this->daftar->buat('budi@contoh.com', 'Budi', self::SANDI);
        $this->daftar->tambahAkses($this->ade, 'dashboard');
        $this->daftar->tambahAkses($this->ade, self::WEBSITE, DaftarUser::PERAN_ANGGOTA);
        $this->daftar->tambahAkses($this->budi, self::WEBSITE, DaftarUser::PERAN_ANGGOTA);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . '/*/*') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->folder . '/*') ?: [] as $f) {
            is_dir($f) ? rmdir($f) : unlink($f);
        }
        @rmdir($this->folder);
    }

    private function pintu(string $lingkup): Pintu
    {
        return new Pintu($lingkup, $lingkup === 'dashboard' ? '/' : '/dashboard', 3600, 'layout::uji');
    }

    private function handler(string $lingkup, string $jenis = ProfilHandler::HALAMAN_PENGATURAN, ?SumberLokasi $lokasi = null): ProfilHandler
    {
        $pintu = $this->pintu($lingkup);

        return new ProfilHandler(
            $this->daftar,
            new PembatasPercobaan($this->db),
            new RiwayatMasuk($this->db),
            new PenyajiDasar($this->renderer, $pintu),
            $pintu,
            new DateTimeZone('Asia/Jakarta'),
            $jenis === ProfilHandler::HALAMAN_PROFIL ? ProfilHandler::TEMPLATE : ProfilHandler::TEMPLATE_PENGATURAN,
            new RiwayatAkun($this->db),
            jenis: $jenis,
            foto: $this->foto,
            lokasi: $lokasi,
        );
    }

    /** Permintaan sebagai user yang sudah melewati WajibMasukMiddleware di lingkup itu. */
    private function permintaan(string $metode, string $lingkup, int $sebagai, array $isi = [], array $berkas = []): ServerRequest
    {
        if ($metode === 'POST') {
            $isi[TokenCsrf::FIELD] = (new TokenCsrf($this->sesi))->token();
        }

        $user = $this->daftar->cariId($sebagai);
        assert($user !== null);

        return (new ServerRequest(['REMOTE_ADDR' => '192.0.2.1'], $berkas, '/dashboard/pengaturan', $metode, 'php://memory', ['User-Agent' => 'Browser Uji']))
            ->withQueryParams($metode === 'GET' ? $isi : [])
            ->withParsedBody($metode === 'POST' ? $isi : null)
            ->withUploadedFiles($berkas)
            ->withAttribute(Sesi::class, $this->sesi)
            ->withAttribute(User::class, $user)
            ->withAttribute(WajibMasukMiddleware::ATRIBUT_PERAN, $this->daftar->peran($sebagai, $lingkup))
            ->withAttribute(WajibMasukMiddleware::ATRIBUT_IDENTITAS, Identitas::untuk($user, $lingkup, $this->daftar, $this->pintu($lingkup)));
    }

    private function unggah(string $lingkup, string $untuk, int $lebar = 400, int $tinggi = 300): ResponseInterface
    {
        $file   = FotoUserTest::gambar($this->folder . '/unggah-' . bin2hex(random_bytes(3)) . '.png', $lebar, $tinggi);
        $berkas = ['foto' => new UploadedFile($file, (int) filesize($file), UPLOAD_ERR_OK, 'foto.png', 'image/png')];

        return $this->handler($lingkup)->handle($this->permintaan('POST', $lingkup, $this->ade, ['aksi' => 'foto', 'untuk' => $untuk], $berkas));
    }

    public function testNamaDepanTengahBelakang(): void
    {
        $kirim = fn (array $isi): ResponseInterface => $this->handler('dashboard')->handle($this->permintaan('POST', 'dashboard', $this->ade, ['aksi' => 'nama'] + $isi));

        self::assertSame(422, $kirim(['nama_depan' => '', 'nama_tengah' => 'M', 'nama_belakang' => 'Saragih'])->getStatusCode());
        self::assertSame('Saragih', $this->renderer->data['namaBelakang'], 'Isian dikembalikan ke form.');

        self::assertSame(302, $kirim(['nama_depan' => 'Ade', 'nama_tengah' => 'M', 'nama_belakang' => 'Saragih'])->getStatusCode());
        $user = $this->daftar->cariId($this->ade);
        self::assertSame(['Ade M Saragih', 'Ade', 'M', 'Saragih'], [$user?->nama, $user->namaDepan, $user->namaTengah, $user->namaBelakang]);
        self::assertSame('Ade Lama → Ade M Saragih', (new RiwayatAkun($this->db))->untukUser($this->ade)[0]['keterangan']);

        // Form lama (satu isian nama) tetap diterima.
        $kirim(['nama' => 'Ade Saja']);
        self::assertSame('Ade Saja', $this->daftar->cariId($this->ade)?->nama);
    }

    public function testFotoAkunDiunggahDigantiDanDihapus(): void
    {
        self::assertSame(302, $this->unggah('dashboard', 'akun')->getStatusCode());
        $pertama = (string) $this->daftar->cariId($this->ade)?->foto;
        self::assertNotNull($this->foto->file($pertama));
        self::assertSame(RiwayatAkun::UBAH_FOTO, (new RiwayatAkun($this->db))->untukUser($this->ade)[0]['peristiwa']);

        $this->unggah('dashboard', 'akun');
        $kedua = (string) $this->daftar->cariId($this->ade)?->foto;
        self::assertNotSame($pertama, $kedua);
        self::assertNull($this->foto->file($pertama), 'Foto lama dihapus.');

        // Identitas dan halaman memakai alamat foto.
        $this->handler('dashboard')->handle($this->permintaan('GET', 'dashboard', $this->ade));
        self::assertSame('/foto/' . $kedua, $this->renderer->data['fotoAkun']);
        self::assertTrue($this->renderer->data['fotoSiap']);

        $this->handler('dashboard')->handle($this->permintaan('POST', 'dashboard', $this->ade, ['aksi' => 'hapus_foto', 'untuk' => 'akun']));
        self::assertSame('', $this->daftar->cariId($this->ade)?->foto);
        self::assertNull($this->foto->file($kedua));
    }

    public function testFotoTanpaFileAtauTerlaluBesar(): void
    {
        $tanpa = $this->handler('dashboard')->handle($this->permintaan('POST', 'dashboard', $this->ade, ['aksi' => 'foto', 'untuk' => 'akun']));
        self::assertSame(422, $tanpa->getStatusCode());
        self::assertSame('foto', $this->renderer->data['galatBagian']);

        $besar = new UploadedFile('php://memory', FotoUser::UKURAN_MAKS * 2, UPLOAD_ERR_INI_SIZE, 'besar.jpg', 'image/jpeg');
        self::assertSame(413, $this->handler('dashboard')->handle($this->permintaan('POST', 'dashboard', $this->ade, ['aksi' => 'foto', 'untuk' => 'akun'], ['foto' => $besar]))->getStatusCode());

        // Isi form dibuang PHP karena melewati post_max_size.
        $kosong = $this->permintaan('POST', 'dashboard', $this->ade)->withParsedBody([])->withHeader('Content-Length', '9000000');
        self::assertSame(413, $this->handler('dashboard')->handle($kosong)->getStatusCode());
        self::assertStringContainsString('2 MB', (string) $this->renderer->data['galat']);
    }

    public function testFolderFotoBelumBisaDitulis(): void
    {
        file_put_contents($this->folder . '-berkas', 'bukan folder');
        $this->foto = new FotoUser($this->folder . '-berkas/avatar');

        self::assertSame(503, $this->unggah('dashboard', 'akun')->getStatusCode());
        self::assertStringContainsString('folder foto', (string) $this->renderer->data['galat']);
        unlink($this->folder . '-berkas');
    }

    public function testTentang(): void
    {
        $kirim = fn (array $isi): ResponseInterface => $this->handler(self::WEBSITE)->handle($this->permintaan('POST', self::WEBSITE, $this->ade, ['aksi' => 'tentang'] + $isi));

        self::assertSame(422, $kirim(['lokasi' => str_repeat('a', 191)])->getStatusCode());
        self::assertSame(422, $kirim(['lokasi' => "Medan\nKota"])->getStatusCode(), 'Hanya biografi yang boleh beberapa baris.');
        self::assertSame('profil', $this->renderer->data['tab'], 'Galat membuka tab Profil.');
        $hasil = $kirim(['pendidikan' => 'S1 Teknik', 'lokasi' => 'Medan', 'keahlian' => '', 'biografi' => "Suka belajar.\r\nSuka bermain."]);
        self::assertSame(302, $hasil->getStatusCode());
        self::assertSame('/dashboard/pengaturan?tab=profil', $hasil->getHeaderLine('Location'));

        self::assertEquals(['pendidikan' => 'S1 Teknik', 'lokasi' => 'Medan', 'biografi' => "Suka belajar.\nSuka bermain."], $this->daftar->profil()->ambil($this->ade));
        $riwayat = (new RiwayatAkun($this->db))->untukUser($this->ade)[0];
        self::assertSame([RiwayatAkun::UBAH_PROFIL, 'Tentang: pendidikan, lokasi, biografi'], [$riwayat['peristiwa'], $riwayat['keterangan']]);
    }

    public function testLokasiDariSaranKota(): void
    {
        foreach ([
            'CREATE TABLE kota (id INTEGER PRIMARY KEY, nama TEXT, provinsi_id INTEGER)',
            'CREATE TABLE provinsi (id INTEGER PRIMARY KEY, nama TEXT)',
            "INSERT INTO provinsi VALUES (1, 'Sumatera Utara')",
            "INSERT INTO kota VALUES (5, 'Kota Medan', 1)",
        ] as $sql) {
            $this->db->query($sql, Adapter::QUERY_MODE_EXECUTE);
        }

        $sumber = new SumberLokasi($this->db, ['kota' => ['tabel' => 'kota', 'induk' => 'provinsi_id'], 'provinsi' => ['tabel' => 'provinsi']]);
        $kirim  = fn (array $isi): ResponseInterface => $this->handler(self::WEBSITE, lokasi: $sumber)
            ->handle($this->permintaan('POST', self::WEBSITE, $this->ade, ['aksi' => 'tentang'] + $isi));
        $simpan = fn (): array => $this->daftar->profil()->ambil($this->ade);

        $kirim(['lokasi' => 'Kota Medan, Sumatera Utara', 'lokasi_id' => '5']);
        self::assertSame(['lokasi' => 'Kota Medan, Sumatera Utara', 'lokasi_id' => '5'], $simpan());

        $kirim(['lokasi' => 'Medan Johor', 'lokasi_id' => '5']);
        self::assertSame(['lokasi' => 'Medan Johor'], $simpan(), 'Teks diubah sesudah memilih: nomor kota dibuang.');

        $kirim(['lokasi' => 'Kota Medan, Sumatera Utara', 'lokasi_id' => '9']);
        self::assertSame(['lokasi' => 'Kota Medan, Sumatera Utara'], $simpan(), 'Nomor kota yang tidak ada dibuang.');

        $kirim(['lokasi' => '', 'lokasi_id' => '5']);
        self::assertSame([], $simpan());

        $riwayat = (new RiwayatAkun($this->db))->untukUser($this->ade)[0];
        self::assertSame('Tentang: lokasi', $riwayat['keterangan'], 'Nomor kota tidak dicatat terpisah.');

        $this->handler(self::WEBSITE, lokasi: $sumber)->handle($this->permintaan('GET', self::WEBSITE, $this->ade, ['tab' => 'profil']));
        self::assertSame('/dashboard/lokasi', $this->renderer->data['alamatLokasi']);
        $this->handler(self::WEBSITE)->handle($this->permintaan('GET', self::WEBSITE, $this->ade, ['tab' => 'profil']));
        self::assertNull($this->renderer->data['alamatLokasi'], 'Tanpa sumber kota, Lokasi teks bebas tanpa saran.');
    }

    public function testProfilDiWebsite(): void
    {
        $kirim = fn (string $lingkup, array $isi): ResponseInterface => $this->handler($lingkup)->handle($this->permintaan('POST', $lingkup, $this->ade, $isi));

        self::assertSame(400, $kirim('dashboard', ['aksi' => 'nama_website', 'nama_website' => 'Pak Ade'])->getStatusCode(), 'Hanya di website.');
        self::assertSame(302, $kirim(self::WEBSITE, ['aksi' => 'nama_website', 'nama_website' => 'Pak Ade'])->getStatusCode());
        self::assertSame(302, $this->unggah(self::WEBSITE, 'website')->getStatusCode());

        $user  = $this->daftar->cariId($this->ade);
        assert($user !== null);
        $kantor = Identitas::untuk($user, self::WEBSITE, $this->daftar, $this->pintu(self::WEBSITE));
        self::assertSame(['Pak Ade', 'PA'], [$kantor->nama, $kantor->inisial]);
        self::assertStringStartsWith('/dashboard/foto/', (string) $kantor->foto);
        self::assertSame('', $user->foto, 'Foto akun tidak berubah.');

        $utama = Identitas::untuk($user, 'dashboard', $this->daftar, $this->pintu('dashboard'));
        self::assertSame(['Ade Lama', null], [$utama->nama, $utama->foto], 'Dashboard utama memakai nama dan foto akun.');

        // Daftar user website memakai nama dan foto website.
        $pintu = $this->pintu(self::WEBSITE);
        $this->daftar->tambahAkses($this->budi, self::WEBSITE, DaftarUser::PERAN_ADMIN_WEB);
        $this->daftar->ubahPeran($this->budi, self::WEBSITE, DaftarUser::PERAN_ADMIN_WEB);
        (new KelolaUserHandler($this->daftar, new RiwayatAkun($this->db), new PenyajiDasar($this->renderer, $pintu), $pintu, new DateTimeZone('UTC')))
            ->handle($this->permintaan('GET', self::WEBSITE, $this->budi));
        $baris = array_column($this->renderer->data['daftarUser'], null, 'id')[$this->ade];
        self::assertSame('Pak Ade', $baris['namaTampil']);
        self::assertSame($kantor->foto, $baris['foto']);
        self::assertSame('/dashboard/profil', $this->renderer->data['alamatProfil']);

        // Dikosongkan: kembali ke nama akun.
        $kirim(self::WEBSITE, ['aksi' => 'nama_website', 'nama_website' => '']);
        $kirim(self::WEBSITE, ['aksi' => 'hapus_foto', 'untuk' => 'website']);
        self::assertSame([], $this->daftar->profil()->ambil($this->ade, self::WEBSITE));
    }

    public function testProfilRekan(): void
    {
        $this->daftar->profil()->simpan($this->budi, '', ['lokasi' => 'Medan']);
        $this->daftar->profil()->simpan($this->budi, self::WEBSITE, [ProfilUser::NAMA => 'Mas Budi']);
        $lihat = fn (string $lingkup, int $id): ResponseInterface => $this->handler($lingkup, ProfilHandler::HALAMAN_PROFIL)
            ->handle($this->permintaan('GET', $lingkup, $this->ade, ['id' => (string) $id]));

        self::assertSame(200, $lihat(self::WEBSITE, $this->budi)->getStatusCode());
        $profil = $this->renderer->data['profil'];
        self::assertSame(['Mas Budi', 'budi@contoh.com', false], [$profil['nama'], $profil['email'], $profil['diri']]);
        self::assertSame([['kunci' => 'lokasi', 'label' => 'Lokasi', 'nilai' => 'Medan']], $profil['tentang']);
        self::assertSame('anggota', $profil['peran']['nilai']);

        // Budi tidak punya akses ke dashboard utama, jadi profilnya tidak terlihat dari sana.
        self::assertSame(404, $lihat('dashboard', $this->budi)->getStatusCode());
        self::assertTrue($this->renderer->data['tidakDitemukan']);
        self::assertSame(404, $lihat(self::WEBSITE, 999)->getStatusCode());

        // Tanpa id: profil sendiri.
        $this->handler(self::WEBSITE, ProfilHandler::HALAMAN_PROFIL)->handle($this->permintaan('GET', self::WEBSITE, $this->ade));
        self::assertTrue($this->renderer->data['profil']['diri']);
    }

    public function testFotoHanyaUntukRekanDiLingkupYangSama(): void
    {
        $this->unggah('dashboard', 'akun');
        $kode = (string) $this->daftar->cariId($this->ade)?->foto;
        $citra = $this->daftar->buat('citra@contoh.com', 'Citra', self::SANDI);
        $this->daftar->tambahAkses($citra, 'toko.com', DaftarUser::PERAN_ANGGOTA);

        $minta = function (string $lingkup, int $sebagai, string $kode): ResponseInterface {
            $user = $this->daftar->cariId($sebagai);

            return (new FotoHandler($this->daftar, $this->foto, $this->pintu($lingkup)))->handle(
                (new ServerRequest([], [], '/foto/' . $kode, 'GET'))->withAttribute('kode', $kode)->withAttribute(User::class, $user),
            );
        };

        $boleh = $minta(self::WEBSITE, $this->budi, $kode);
        self::assertSame(200, $boleh->getStatusCode(), 'Rekan di website yang sama.');
        self::assertSame('image/webp', $boleh->getHeaderLine('Content-Type'));
        self::assertStringContainsString('immutable', $boleh->getHeaderLine('Cache-Control'));
        self::assertSame(200, $minta('dashboard', $this->ade, $kode)->getStatusCode(), 'Foto sendiri.');
        self::assertSame(404, $minta(self::WEBSITE, $this->budi, str_repeat('0', 32))->getStatusCode());

        // Budi hanya anggota kantor.co.id: fotonya tidak terlihat oleh Citra di toko.com, tetapi terlihat oleh admin
        // super di dashboard utama (ia melihat semua akun).
        $milikBudi = $this->foto->simpan(FotoUserTest::gambar($this->folder . '/budi.png', 80, 80));
        $this->daftar->aturFoto($this->budi, $milikBudi);
        self::assertSame(404, $minta('toko.com', $citra, $milikBudi)->getStatusCode());
        self::assertSame(200, $minta('dashboard', $this->ade, $milikBudi)->getStatusCode());

        // Foto khusus website hanya di website itu.
        $fotoKantor = $this->foto->simpan(FotoUserTest::gambar($this->folder . '/kantor.png', 80, 80));
        $this->daftar->profil()->simpan($this->budi, self::WEBSITE, [ProfilUser::FOTO => $fotoKantor]);
        $this->daftar->tambahAkses($this->budi, 'toko.com', DaftarUser::PERAN_ANGGOTA);
        self::assertSame(200, $minta(self::WEBSITE, $this->ade, $fotoKantor)->getStatusCode());
        self::assertSame(404, $minta('toko.com', $citra, $fotoKantor)->getStatusCode());
    }
}
