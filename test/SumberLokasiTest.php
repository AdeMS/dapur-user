<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarLokasi;
use AdeMS\Dapur\User\Handler\LokasiHandler;
use AdeMS\Dapur\User\PabrikLayanan;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\SumberLokasi;
use Laminas\Db\Adapter\Adapter;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class SumberLokasiTest extends TestCase
{
    private Adapter $db;

    /** Pengaturan seperti untuk tabel kota dunia (nama kolom bahasa Inggris). */
    private const PENGATURAN = [
        'kota'         => ['tabel' => 'kota', 'nama' => 'name', 'induk' => 'state_id'],
        'provinsi'     => ['tabel' => 'provinsi', 'nama' => 'name', 'induk' => 'country_id'],
        'negara'       => ['tabel' => 'negara', 'nama' => 'name', 'kode' => 'iso2'],
        'awalan'       => ['Kota ', 'Kabupaten ', 'Kota Administrasi '],
        'negara_utama' => 'ID',
    ];

    protected function setUp(): void
    {
        $this->db = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
        foreach ([
            'CREATE TABLE negara (id INTEGER PRIMARY KEY, name TEXT, iso2 TEXT)',
            'CREATE TABLE provinsi (id INTEGER PRIMARY KEY, name TEXT, country_id INTEGER)',
            'CREATE TABLE kota (id INTEGER PRIMARY KEY, name TEXT, state_id INTEGER)',
            "INSERT INTO negara VALUES (1, 'France', 'FR'), (2, 'Indonesia', 'ID')",
            "INSERT INTO provinsi VALUES (10, 'Île-de-France', 1), (20, 'Sumatera Utara', 2), (21, 'Jawa Barat', 2), (22, 'DKI Jakarta', 2)",
            "INSERT INTO kota VALUES (100, 'Medan Ville', 10), (200, 'Medan', 20), (201, 'Kota Medan', 20),"
                . " (210, 'Bandung', 21), (211, 'Kota Bandung', 21), (212, 'Kabupaten Bandung', 21),"
                . " (220, 'Kota Administrasi Jakarta Pusat', 22), (213, 'Kabupaten Mentawai', 20), (230, 'Persen_100%', 20), (231, 'PersenX1000', 20)",
        ] as $sql) {
            $this->db->query($sql, Adapter::QUERY_MODE_EXECUTE);
        }
    }

    public function testCariMengutamakanNegaraUtamaDanMemakaiAwalan(): void
    {
        $sumber = new SumberLokasi($this->db, self::PENGATURAN);

        self::assertSame([
            ['id' => 201, 'teks' => 'Kota Medan, Sumatera Utara, Indonesia'],
            ['id' => 200, 'teks' => 'Medan, Sumatera Utara, Indonesia'],
            ['id' => 100, 'teks' => 'Medan Ville, Île-de-France, France'],
        ], $sumber->cari('medan'));

        self::assertSame([210, 212, 211], array_column($sumber->cari('Band'), 'id'), 'Nama dasar sama (Bandung): urutan abjad nama lengkap.');
        self::assertSame([220], array_column($sumber->cari('Jakarta'), 'id'), 'Awalan "Kota Administrasi " juga dicoba.');
        self::assertSame([211], array_column($sumber->cari('Kota Band'), 'id'), 'Teks yang sudah berawalan tidak digandakan.');
        self::assertCount(1, $sumber->cari('Medan', 1));
        self::assertSame([201, 200, 213, 100], array_column($sumber->cari('Me'), 'id'), 'Diurutkan menurut nama tanpa awalan Kota/Kabupaten.');
    }

    public function testCariTerlaluPendekAtauKarakterKhusus(): void
    {
        $sumber = new SumberLokasi($this->db, self::PENGATURAN);

        self::assertSame([], $sumber->cari('M'));
        self::assertSame([], $sumber->cari(str_repeat('a', SumberLokasi::PANJANG_MAKS + 1)));
        self::assertSame([], $sumber->cari('%%'), 'Tanda % dari pengunjung bukan wildcard.');
        self::assertSame([230], array_column($sumber->cari('Persen_'), 'id'), 'Tanda _ dari pengunjung bukan wildcard.');
    }

    public function testAmbil(): void
    {
        $sumber = new SumberLokasi($this->db, self::PENGATURAN);

        self::assertSame(['id' => 211, 'teks' => 'Kota Bandung, Jawa Barat, Indonesia'], $sumber->ambil(211));
        self::assertNull($sumber->ambil(999));
    }

    public function testTanpaProvinsiDanNegara(): void
    {
        $sumber = new SumberLokasi($this->db, ['kota' => ['tabel' => 'main.kota', 'nama' => 'name']]);

        self::assertSame([['id' => 210, 'teks' => 'Bandung']], $sumber->cari('Band'));
    }

    public function testPengaturan(): void
    {
        self::assertNull(SumberLokasi::dariPengaturan($this->db, []));
        self::assertInstanceOf(SumberLokasi::class, SumberLokasi::dariPengaturan($this->db, ['lokasi' => self::PENGATURAN]));

        foreach ([
            ['kota' => ['tabel' => 'kota; DROP TABLE x']],
            ['kota' => ['tabel' => 'kota', 'nama' => 'name`']],
            ['provinsi' => ['tabel' => 'provinsi']],
            ['kota' => ['tabel' => 'kota'], 'awalan' => 'Kota '],
            ['kota' => ['tabel' => 'kota'], 'negara_utama' => "ID'"],
        ] as $salah) {
            try {
                new SumberLokasi($this->db, $salah);
                self::fail('Pengaturan salah harus ditolak: ' . json_encode($salah));
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testHandler(): void
    {
        $sesi    = new SesiLarik();
        $minta   = fn (string $cari): ServerRequest => (new ServerRequest([], [], '/lokasi', 'GET'))
            ->withQueryParams(['cari' => $cari])
            ->withAttribute(Sesi::class, $sesi);
        $handler = new LokasiHandler(new SumberLokasi($this->db, self::PENGATURAN));

        $jawab = $handler->handle($minta('kota med'));
        self::assertSame(200, $jawab->getStatusCode());
        self::assertSame('no-store', $jawab->getHeaderLine('Cache-Control'));
        self::assertSame(['hasil' => [['id' => 201, 'teks' => 'Kota Medan, Sumatera Utara, Indonesia']]], json_decode((string) $jawab->getBody(), true));

        self::assertSame(404, (new LokasiHandler(null))->handle($minta('Medan'))->getStatusCode());

        $sesi->simpan('user.lokasi_batas', [intdiv(time(), 60), LokasiHandler::BATAS_PER_MENIT]);
        self::assertSame(429, $handler->handle($minta('Medan'))->getStatusCode());
    }

    public function testPabrikMengutamakanLayananDaftarLokasi(): void
    {
        $wadah = fn (array $isi): ContainerInterface => new class ($isi) implements ContainerInterface {
            /** @param array<string, mixed> $isi */
            public function __construct(private array $isi)
            {
            }

            public function get(string $id): mixed
            {
                return $this->isi[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->isi[$id]);
            }
        };
        $lain = new class implements DaftarLokasi {
            public function cari(string $teks, int $jumlah = 5): array
            {
                return [['id' => 1, 'teks' => 'Dari modul lain']];
            }

            public function ambil(int $id): ?array
            {
                return null;
            }
        };

        $config = ['config' => ['user' => ['lokasi' => self::PENGATURAN]], Adapter::class => $this->db];
        self::assertInstanceOf(SumberLokasi::class, PabrikLayanan::sumberLokasi($wadah($config)));
        self::assertSame($lain, PabrikLayanan::sumberLokasi($wadah($config + [DaftarLokasi::class => $lain])));
        self::assertNull(PabrikLayanan::sumberLokasi($wadah(['config' => []])));
    }
}
