<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSetInterface;
use RuntimeException;

/**
 * Penerapan bawaan DaftarLokasi: saran kota untuk isian Lokasi di bagian Tentang, dibaca dari tabel kota milik Anda sendiri (misalnya database
 * daftar kota dunia). Tabel itu hanya dibaca; akun database cukup diberi hak SELECT.
 *
 * Pengaturan (opsional) di config/autoload/*.local.php:
 *
 *     'user' => [
 *         'lokasi' => [
 *             'kota'     => ['tabel' => 'dunia.kota', 'id' => 'id', 'nama' => 'nama', 'induk' => 'provinsi_id'],
 *             'provinsi' => ['tabel' => 'dunia.provinsi', 'id' => 'id', 'nama' => 'nama', 'induk' => 'negara_id'],
 *             'negara'   => ['tabel' => 'dunia.negara', 'id' => 'id', 'nama' => 'nama', 'kode' => 'kode_iso2'],
 *             'awalan'       => ['Kota ', 'Kabupaten '],  // "Band" juga menemukan "Kota Bandung"
 *             'negara_utama' => 'ID',                     // kota negara ini disarankan lebih dulu
 *         ],
 *     ],
 *
 * Hanya 'kota.tabel' yang wajib; provinsi dan negara boleh tidak ada. Nama tabel boleh berawalan nama database
 * ("database.tabel") selama berada di server database yang sama. Tanpa pengaturan ini Lokasi tetap teks bebas.
 *
 * Pencarian memakai awalan nama (LIKE 'Band%') agar bisa memakai indeks kolom nama; buat indeks itu bila belum ada.
 */
final class SumberLokasi implements DaftarLokasi
{
    public const PANJANG_MIN = 2;
    public const PANJANG_MAKS = 60;
    public const JUMLAH = 5;

    /** Paling banyak sekian calon dibaca dari database per pencarian sebelum diurutkan. */
    private const CALON = 50;

    private const NAMA_SAH = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /** @var array{tabel: string, id: string, nama: string, induk: ?string} */
    private readonly array $kota;
    /** @var array{tabel: string, id: string, nama: string, induk: ?string}|null */
    private readonly ?array $provinsi;
    /** @var array{tabel: string, id: string, nama: string, kode: ?string}|null */
    private readonly ?array $negara;
    /** @var list<string> */
    private readonly array $awalan;
    private readonly ?string $negaraUtama;

    private bool $negaraUtamaDicari = false;
    private int|string|null $idNegaraUtama = null;

    /** @param array<mixed> $pengaturan isi 'user' => ['lokasi' => ...] */
    public function __construct(private readonly Adapter $db, array $pengaturan)
    {
        $kota = self::tabel($pengaturan, 'kota', true);
        assert($kota !== null);
        $this->kota     = $kota + ['induk' => self::kolom($pengaturan['kota'], 'induk', null)];
        $provinsi       = $this->kota['induk'] === null ? null : self::tabel($pengaturan, 'provinsi', false);
        $this->provinsi = $provinsi === null ? null : $provinsi + ['induk' => self::kolom($pengaturan['provinsi'], 'induk', null)];

        $negara       = $this->provinsi === null || $this->provinsi['induk'] === null ? null : self::tabel($pengaturan, 'negara', false);
        $this->negara = $negara === null ? null : $negara + ['kode' => self::kolom($pengaturan['negara'], 'kode', null)];

        $awalan = $pengaturan['awalan'] ?? [];
        if (! is_array($awalan) || array_filter($awalan, static fn (mixed $a): bool => ! is_string($a) || $a === '' || strlen($a) > 40) !== []) {
            throw new RuntimeException("Pengaturan 'lokasi.awalan' user harus berupa daftar teks, misalnya ['Kota ', 'Kabupaten '].");
        }

        $this->awalan = array_values(array_unique($awalan));

        $utama = $pengaturan['negara_utama'] ?? null;
        if ($utama !== null && (! is_string($utama) || preg_match('/^[A-Za-z0-9]{1,10}$/', $utama) !== 1)) {
            throw new RuntimeException("Pengaturan 'lokasi.negara_utama' user harus kode negara, misalnya 'ID'.");
        }

        $this->negaraUtama = $this->negara !== null && $this->negara['kode'] !== null ? $utama : null;
    }

    /**
     * Membuat sumber dari pengaturan 'user' => ['lokasi' => ...]; null bila tidak diatur.
     *
     * @param array<mixed> $configUser
     */
    public static function dariPengaturan(Adapter $db, array $configUser): ?self
    {
        $lokasi = $configUser['lokasi'] ?? null;
        if ($lokasi === null || $lokasi === []) {
            return null;
        }

        if (! is_array($lokasi)) {
            throw new RuntimeException("Pengaturan 'lokasi' user harus berupa array.");
        }

        return new self($db, $lokasi);
    }

    /**
     * Kota yang namanya diawali teks itu (atau diawali salah satu awalan + teks itu), kota negara utama lebih dulu.
     *
     * @return list<array{id: int, teks: string}>
     */
    public function cari(string $teks, int $jumlah = self::JUMLAH): array
    {
        $teks = trim((string) preg_replace('/\s+/u', ' ', $teks));
        $teks = (string) preg_replace('/[\x00-\x1f\x7f]/u', '', $teks);
        $panjang = mb_strlen($teks, 'UTF-8');
        if ($panjang < self::PANJANG_MIN || $panjang > self::PANJANG_MAKS) {
            return [];
        }

        $jumlah = max(1, min(self::JUMLAH, $jumlah));
        $pola   = [self::pola($teks)];
        foreach ($this->awalan as $a) {
            // Teks yang sudah diawali awalan itu (mis. "Kota Ban") tidak perlu dicari lagi dengan awalan ganda.
            if (mb_stripos($teks, rtrim($a), 0, 'UTF-8') !== 0) {
                $pola[] = self::pola($a . $teks);
            }
        }

        $kolom = "k.{$this->q($this->kota['nama'])}";
        $where = '(' . implode(' OR ', array_fill(0, count($pola), "{$kolom} LIKE ? ESCAPE '!'")) . ')';

        // Ambil lebih banyak dari yang ditampilkan, lalu urutkan menurut nama tanpa awalan (lihat urutkan()).
        $ambil    = self::CALON;
        $hasil    = [];
        $negaraId = $this->idNegaraUtama();
        if ($negaraId !== null) {
            $hasil = $this->urutkan($this->baris("{$where} AND p.{$this->q((string) $this->provinsi['induk'])} = ?", [...$pola, $negaraId], $ambil), $teks, $jumlah);
        }

        if (count($hasil) < $jumlah) {
            $lain  = $negaraId === null ? $where : "{$where} AND (p.{$this->q((string) $this->provinsi['induk'])} IS NULL OR p.{$this->q((string) $this->provinsi['induk'])} <> ?)";
            $param = $negaraId === null ? $pola : [...$pola, $negaraId];
            $hasil = [...$hasil, ...$this->urutkan($this->baris($lain, $param, $ambil), $teks, $jumlah - count($hasil))];
        }

        return array_map(static fn (array $h): array => ['id' => $h['id'], 'teks' => $h['teks']], $hasil);
    }

    /**
     * Mengurutkan calon menurut nama kota tanpa awalan ("Kota Medan" dibaca "Medan"): nama yang sama persis dengan
     * ketikan lebih dulu, lalu menurut abjad; nama dasar yang sama tetap dalam urutan semula.
     *
     * @param list<array{id: int, teks: string, kota: string}> $calon
     * @return list<array{id: int, teks: string, kota: string}>
     */
    private function urutkan(array $calon, string $teks, int $jumlah): array
    {
        $awalan = $this->awalan;
        usort($awalan, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $dasar = static function (string $nama) use ($awalan): string {
            foreach ($awalan as $a) {
                if (mb_stripos($nama, $a, 0, 'UTF-8') === 0) {
                    return mb_strtolower(mb_substr($nama, mb_strlen($a, 'UTF-8'), null, 'UTF-8'), 'UTF-8');
                }
            }

            return mb_strtolower($nama, 'UTF-8');
        };

        $cari  = $dasar($teks);
        $kunci = [];
        foreach ($calon as $i => $c) {
            $d         = $dasar($c['kota']);
            $kunci[$i] = [$d === $cari ? 0 : 1, $d, $i];
        }

        uksort($calon, static fn (int $a, int $b): int => $kunci[$a] <=> $kunci[$b]);

        return array_slice(array_values($calon), 0, $jumlah);
    }

    /**
     * Satu kota menurut nomornya, untuk memastikan pilihan yang dikirim form memang ada.
     *
     * @return array{id: int, teks: string}|null
     */
    public function ambil(int $id): ?array
    {
        $hasil = $this->baris("k.{$this->q($this->kota['id'])} = ?", [$id], 1);

        return isset($hasil[0]) ? ['id' => $hasil[0]['id'], 'teks' => $hasil[0]['teks']] : null;
    }

    /**
     * @param list<mixed> $parameter
     * @return list<array{id: int, teks: string, kota: string}>
     */
    private function baris(string $where, array $parameter, int $jumlah): array
    {
        $k      = $this->kota;
        $kolom  = ["k.{$this->q($k['id'])} AS id", "k.{$this->q($k['nama'])} AS kota"];
        $gabung = '';
        if ($this->provinsi !== null) {
            $p        = $this->provinsi;
            $kolom[]  = "p.{$this->q($p['nama'])} AS provinsi";
            $gabung  .= " LEFT JOIN {$this->q($p['tabel'])} p ON p.{$this->q($p['id'])} = k.{$this->q((string) $k['induk'])}";
            if ($this->negara !== null) {
                $n       = $this->negara;
                $kolom[] = "n.{$this->q($n['nama'])} AS negara";
                $gabung .= " LEFT JOIN {$this->q($n['tabel'])} n ON n.{$this->q($n['id'])} = p.{$this->q((string) $p['induk'])}";
            }
        }

        $sql   = 'SELECT ' . implode(', ', $kolom) . " FROM {$this->q($k['tabel'])} k{$gabung} WHERE {$where}"
            . " ORDER BY k.{$this->q($k['nama'])} LIMIT " . $jumlah;
        $hasil = $this->db->query($sql, $parameter);
        $baris = $hasil instanceof ResultSetInterface ? $hasil->toArray() : [];

        return array_values(array_map(static function (array $b): array {
            $bagian = array_filter(
                [(string) $b['kota'], (string) ($b['provinsi'] ?? ''), (string) ($b['negara'] ?? '')],
                static fn (string $s): bool => $s !== '',
            );

            return ['id' => (int) $b['id'], 'teks' => implode(', ', array_unique($bagian)), 'kota' => (string) $b['kota']];
        }, $baris));
    }

    private function idNegaraUtama(): int|string|null
    {
        if ($this->negaraUtama === null || $this->negara === null || $this->provinsi === null) {
            return null;
        }

        if (! $this->negaraUtamaDicari) {
            $this->negaraUtamaDicari = true;
            $hasil = $this->db->query(
                "SELECT {$this->q($this->negara['id'])} AS id FROM {$this->q($this->negara['tabel'])} WHERE {$this->q((string) $this->negara['kode'])} = ?",
                [$this->negaraUtama],
            );
            $baris = $hasil instanceof ResultSetInterface ? $hasil->toArray() : [];
            $this->idNegaraUtama = isset($baris[0]['id']) ? $baris[0]['id'] : null;
        }

        return $this->idNegaraUtama;
    }

    /** Nama tabel atau kolom yang sudah divalidasi, diberi tanda kutip sesuai database. */
    private function q(string $nama): string
    {
        return $this->db->getPlatform()->quoteIdentifierChain(explode('.', $nama));
    }

    /** Pola LIKE untuk "diawali teks ini"; tanda % dan _ dari pengunjung diperlakukan sebagai huruf biasa. */
    private static function pola(string $teks): string
    {
        return strtr($teks, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    /**
     * @param array<mixed> $pengaturan
     * @return array{tabel: string, id: string, nama: string}|null
     */
    private static function tabel(array $pengaturan, string $bagian, bool $wajib): ?array
    {
        $isi = $pengaturan[$bagian] ?? null;
        if ($isi === null && ! $wajib) {
            return null;
        }

        if (! is_array($isi) || ! is_string($isi['tabel'] ?? null) || preg_match(self::NAMA_SAH, $isi['tabel']) !== 1) {
            throw new RuntimeException("Pengaturan 'lokasi.{$bagian}.tabel' user wajib berupa nama tabel, misalnya 'dunia.{$bagian}'.");
        }

        return [
            'tabel' => $isi['tabel'],
            'id'    => (string) self::kolom($isi, 'id', 'id'),
            'nama'  => (string) self::kolom($isi, 'nama', 'nama'),
        ];
    }

    /** @param array<mixed> $isi */
    private static function kolom(mixed $isi, string $kunci, ?string $bawaan): ?string
    {
        $nilai = is_array($isi) ? ($isi[$kunci] ?? $bawaan) : $bawaan;
        if ($nilai !== null && (! is_string($nilai) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $nilai) !== 1)) {
            throw new RuntimeException("Pengaturan lokasi user: kolom '{$kunci}' harus berupa nama kolom.");
        }

        return $nilai;
    }
}
