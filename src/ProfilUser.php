<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSetInterface;

/**
 * Isian profil user di tabel user_profil, per lingkup:
 *
 * - lingkup kosong: bagian **Tentang** (pendidikan, lokasi, keahlian, biografi), berlaku di semua website dan bisa
 *   dilihat rekan yang sama-sama punya akses ke website tempat profil itu dibuka;
 * - lingkup website: **profil di website itu**, yaitu nama tampilan dan foto yang menggantikan nama dan foto akun,
 *   hanya di website tersebut.
 *
 * Isian kosong tidak disimpan (barisnya dihapus).
 */
final class ProfilUser
{
    public const PESAN_BELUM_DIPASANG = 'Tabel profil user belum dibuat. Jalankan "vendor/bin/dapur-user pasang" dari folder inti Dapur.';

    /** Isian bagian Tentang: kunci => [label, panjang maksimal]. */
    public const TENTANG = [
        'pendidikan' => ['Pendidikan', 190],
        'lokasi'     => ['Lokasi', 190],
        'keahlian'   => ['Keahlian', 190],
        'biografi'   => ['Biografi', 1000],
    ];

    /**
     * Nomor kota pilihan saran Lokasi (SumberLokasi), disimpan di samping teks lokasi; tidak ada bila Lokasi diketik
     * bebas.
     */
    public const LOKASI_ID = 'lokasi_id';

    /** Kunci profil per website. */
    public const NAMA = 'nama';
    public const FOTO = 'foto';

    public const PANJANG_NAMA_MAKS = 190;

    private readonly string $tProfil;
    private readonly string $tUser;

    private bool $tersedia = false;

    public function __construct(
        private readonly Adapter $db,
        NamaTabel $tabel = new NamaTabel(),
    ) {
        $platform      = $db->getPlatform();
        $this->tProfil = $platform->quoteIdentifier($tabel->profil());
        $this->tUser   = $platform->quoteIdentifier($tabel->user());
    }

    /** Apakah tabel profil dan kolom nama/foto di tabel user sudah dibuat oleh perintah pasang. */
    public function tersedia(): bool
    {
        // Hanya hasil "sudah tersedia" yang diingat, karena tabel bisa dibuat sesudah objek ini dibuat.
        if (! $this->tersedia) {
            try {
                $this->db->query("SELECT kunci FROM {$this->tProfil} WHERE 1 = 0", Adapter::QUERY_MODE_EXECUTE);
                $this->db->query("SELECT nama_depan, foto FROM {$this->tUser} WHERE 1 = 0", Adapter::QUERY_MODE_EXECUTE);
                $this->tersedia = true;
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * Isian profil satu user di satu lingkup ('' = Tentang).
     *
     * @return array<string, string> kunci => nilai
     */
    public function ambil(int $userId, string $lingkup = ''): array
    {
        if (! $this->tersedia()) {
            return [];
        }

        $hasil = [];
        foreach ($this->baris("SELECT kunci, nilai FROM {$this->tProfil} WHERE user_id = ? AND lingkup = ?", [$userId, $lingkup]) as $b) {
            $hasil[(string) $b['kunci']] = (string) $b['nilai'];
        }

        return $hasil;
    }

    /**
     * Menyimpan isian; nilai kosong menghapus isian itu. Kunci yang tidak disebut tidak diubah.
     *
     * @param array<string, string> $nilai
     * @return list<string> kunci yang berubah
     */
    public function simpan(int $userId, string $lingkup, array $nilai): array
    {
        $lama    = $this->ambil($userId, $lingkup);
        $berubah = [];
        foreach ($nilai as $kunci => $isi) {
            $isi = trim($isi);
            if (($lama[$kunci] ?? '') === $isi) {
                continue;
            }

            $this->db->query("DELETE FROM {$this->tProfil} WHERE user_id = ? AND lingkup = ? AND kunci = ?", [$userId, $lingkup, $kunci]);
            if ($isi !== '') {
                $this->db->query(
                    "INSERT INTO {$this->tProfil} (user_id, lingkup, kunci, nilai, diubah_pada) VALUES (?, ?, ?, ?, ?)",
                    [$userId, $lingkup, $kunci, $isi, gmdate('Y-m-d H:i:s')],
                );
            }

            $berubah[] = $kunci;
        }

        return $berubah;
    }

    /**
     * Satu isian profil semua user di satu lingkup, misalnya nama tampilan di website untuk daftar user.
     *
     * @return array<int, string> id user => nilai
     */
    public function untukLingkup(string $lingkup, string $kunci): array
    {
        if (! $this->tersedia()) {
            return [];
        }

        $hasil = [];
        foreach ($this->baris("SELECT user_id, nilai FROM {$this->tProfil} WHERE lingkup = ? AND kunci = ?", [$lingkup, $kunci]) as $b) {
            $hasil[(int) $b['user_id']] = (string) $b['nilai'];
        }

        return $hasil;
    }

    /**
     * Kode foto akun semua user yang punya foto.
     *
     * @return array<int, string> id user => kode foto
     */
    public function fotoAkun(): array
    {
        if (! $this->tersedia()) {
            return [];
        }

        $hasil = [];
        foreach ($this->baris("SELECT id, foto FROM {$this->tUser} WHERE foto <> ''", []) as $b) {
            $hasil[(int) $b['id']] = (string) $b['foto'];
        }

        return $hasil;
    }

    /**
     * Pemilik file foto: id user dan lingkupnya ('' = foto akun), atau null bila kode itu tidak dipakai siapa pun.
     *
     * @return array{0: int, 1: string}|null
     */
    public function pemilikFoto(string $kode): ?array
    {
        if (! $this->tersedia() || ! FotoUser::kodeSah($kode)) {
            return null;
        }

        $akun = $this->baris("SELECT id FROM {$this->tUser} WHERE foto = ?", [$kode]);
        if ($akun !== []) {
            return [(int) $akun[0]['id'], ''];
        }

        $website = $this->baris("SELECT user_id, lingkup FROM {$this->tProfil} WHERE kunci = ? AND nilai = ?", [self::FOTO, $kode]);

        return $website === [] ? null : [(int) $website[0]['user_id'], (string) $website[0]['lingkup']];
    }

    /**
     * @param list<mixed> $parameter
     * @return list<array<string, mixed>>
     */
    private function baris(string $sql, array $parameter): array
    {
        $hasil = $this->db->query($sql, $parameter);

        return $hasil instanceof ResultSetInterface ? array_values($hasil->toArray()) : [];
    }
}
