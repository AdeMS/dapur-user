<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSetInterface;

/**
 * Riwayat perubahan akun: siapa (pelaku) mengubah apa pada akun siapa (sasaran), di lingkup mana.
 *
 * Berbeda dari RiwayatMasuk yang mencatat login, tabel ini mencatat pengelolaan akun: user dibuat, nama
 * diubah, akses diberikan atau dicabut, peran diubah, dan akun diaktifkan atau dinonaktifkan. Pelaku
 * kosong berarti perubahan dilakukan lewat perintah di terminal. Catatan disimpan selamanya.
 */
final class RiwayatAkun
{
    public const BUAT_USER   = 'buat_user';
    public const UBAH_NAMA   = 'ubah_nama';
    public const BERI_AKSES  = 'beri_akses';
    public const UBAH_PERAN  = 'ubah_peran';
    public const CABUT_AKSES = 'cabut_akses';
    public const AKTIFKAN    = 'aktifkan';
    public const NONAKTIFKAN = 'nonaktifkan';
    public const UNDANG      = 'undang';
    public const UBAH_EMAIL  = 'ubah_email';
    public const TAMBAH_EMAIL  = 'tambah_email';
    public const HAPUS_EMAIL   = 'hapus_email';
    public const EMAIL_UTAMA   = 'email_utama';
    public const EMAIL_WEBSITE = 'email_website';

    /** Label peristiwa untuk tampilan. */
    private const LABEL = [
        self::BUAT_USER   => 'Buat user',
        self::UBAH_NAMA   => 'Ubah nama',
        self::BERI_AKSES  => 'Beri akses',
        self::UBAH_PERAN  => 'Ubah peran',
        self::CABUT_AKSES => 'Cabut akses',
        self::AKTIFKAN    => 'Aktifkan',
        self::NONAKTIFKAN => 'Nonaktifkan',
        self::UNDANG      => 'Undang',
        self::UBAH_EMAIL  => 'Ubah email',
        self::TAMBAH_EMAIL  => 'Tambah email',
        self::HAPUS_EMAIL   => 'Hapus email',
        self::EMAIL_UTAMA   => 'Email utama',
        self::EMAIL_WEBSITE => 'Email website',
    ];

    private const PANJANG_TEKS = 255;

    /** Pesan untuk admin bila tabel riwayat akun belum dibuat, misalnya setelah memperbarui dari 1.1. */
    public const PESAN_BELUM_DIPASANG = 'Tabel riwayat akun belum dibuat. Jalankan "vendor/bin/dapur-user pasang" dari folder inti Dapur.';

    private readonly string $tabel;
    private readonly string $tUser;
    private readonly string $tAkses;
    private readonly string $tEmail;
    private readonly EmailUser $email;

    public function __construct(
        private readonly Adapter $db,
        NamaTabel $tabel = new NamaTabel(),
    ) {
        $platform     = $db->getPlatform();
        $this->tabel  = $platform->quoteIdentifier($tabel->riwayatAkun());
        $this->tUser  = $platform->quoteIdentifier($tabel->user());
        $this->tAkses = $platform->quoteIdentifier($tabel->akses());
        $this->tEmail = $platform->quoteIdentifier($tabel->email());
        $this->email  = new EmailUser($db, $tabel);
    }

    /**
     * @param int|null $pelakuId user yang melakukan perubahan; null bila lewat perintah di terminal
     * @param string   $lingkup  tempat perubahan dilakukan, misalnya 'dashboard' atau domain website
     */
    public function catat(
        string $peristiwa,
        ?int $pelakuId,
        int $sasaranId,
        string $lingkup,
        string $keterangan = '',
        string $ip = '',
        string $perangkat = '',
    ): void {
        $this->db->query(
            "INSERT INTO {$this->tabel} (pelaku_id, sasaran_id, lingkup, peristiwa, keterangan, ip, perangkat, waktu)"
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $pelakuId,
                $sasaranId,
                $lingkup,
                $peristiwa,
                self::potong($keterangan),
                substr($ip, 0, 45),
                self::potong($perangkat),
                gmdate('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Perubahan pada akun satu user, terbaru lebih dulu. Waktu dalam UTC.
     *
     * @return list<array{peristiwa: string, lingkup: string, keterangan: string, ip: string, perangkat: string, waktu: string, pelaku: ?string, sasaran: string}>
     */
    public function untukUser(int $sasaranId, int $jumlah = 20): array
    {
        return $this->baca('r.sasaran_id = ?', [$sasaranId], $jumlah);
    }

    /**
     * Aktivitas akun seorang user, terbaru lebih dulu: perubahan pada akunnya dan perubahan yang ia lakukan pada
     * akun lain. Waktu dalam UTC.
     *
     * @return list<array{peristiwa: string, lingkup: string, keterangan: string, ip: string, perangkat: string, waktu: string, pelaku: ?string, sasaran: string}>
     */
    public function aktivitasUser(int $userId, int $jumlah = 20): array
    {
        return $this->baca('(r.sasaran_id = ? OR r.pelaku_id = ?)', [$userId, $userId], $jumlah);
    }

    /**
     * Perubahan yang dilakukan di satu lingkup, terbaru lebih dulu. Waktu dalam UTC.
     *
     * @return list<array{peristiwa: string, lingkup: string, keterangan: string, ip: string, perangkat: string, waktu: string, pelaku: ?string, sasaran: string}>
     */
    public function untukLingkup(string $lingkup, int $jumlah = 20): array
    {
        return $this->baca('r.lingkup = ?', [$lingkup], $jumlah, true);
    }

    /**
     * Apakah tabel riwayat akun sudah ada. Setelah memperbarui dari 1.1, tabel ini baru ada sesudah perintah
     * pasang dijalankan; halaman yang memerlukannya memakai pemeriksaan ini agar bisa memberi pesan yang jelas.
     */
    public function tersedia(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM {$this->tabel} WHERE 1 = 0", Adapter::QUERY_MODE_EXECUTE);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function label(string $peristiwa): string
    {
        return self::LABEL[$peristiwa] ?? $peristiwa;
    }

    /**
     * @param list<mixed> $parameter
     * @return list<array{peristiwa: string, lingkup: string, keterangan: string, ip: string, perangkat: string, waktu: string, pelaku: ?string, sasaran: string}>
     */
    private function baca(string $syarat, array $parameter, int $jumlah, bool $perLingkup = false): array
    {
        $jumlah = max(1, min(500, $jumlah));

        // Untuk daftar per website: pelaku dan sasaran ditampilkan dengan email yang mereka pakai di website itu,
        // agar email pribadi tidak terlihat oleh rekan di website kantor. User yang tidak (lagi) punya akses ke
        // website itu ditampilkan dengan namanya.
        $perLingkup = $perLingkup && $this->email->tersedia();
        $tambahan   = $perLingkup
            ? ", p.nama AS pelaku_nama, s.nama AS sasaran_nama, ap.user_id AS pelaku_akses, aks.user_id AS sasaran_akses,"
                . " ep.email AS pelaku_email_lingkup, es.email AS sasaran_email_lingkup"
            : '';
        $gabung     = $perLingkup
            ? " LEFT JOIN {$this->tAkses} ap ON ap.user_id = r.pelaku_id AND ap.lingkup = r.lingkup"
                . " LEFT JOIN {$this->tEmail} ep ON ep.id = ap.email_id AND ep.user_id = r.pelaku_id"
                . " LEFT JOIN {$this->tAkses} aks ON aks.user_id = r.sasaran_id AND aks.lingkup = r.lingkup"
                . " LEFT JOIN {$this->tEmail} es ON es.id = aks.email_id AND es.user_id = r.sasaran_id"
            : '';

        $hasil  = $this->db->query(
            "SELECT r.peristiwa, r.lingkup, r.keterangan, r.ip, r.perangkat, r.waktu, p.email AS pelaku, s.email AS sasaran{$tambahan}"
            . " FROM {$this->tabel} r"
            . " LEFT JOIN {$this->tUser} p ON p.id = r.pelaku_id"
            . " JOIN {$this->tUser} s ON s.id = r.sasaran_id{$gabung}"
            . " WHERE {$syarat} ORDER BY r.waktu DESC, r.id DESC LIMIT {$jumlah}",
            $parameter,
        );

        $baris = [];
        foreach ($hasil instanceof ResultSetInterface ? $hasil->toArray() : [] as $b) {
            if ($perLingkup) {
                $b['sasaran'] = $b['sasaran_email_lingkup']
                    ?? ($b['sasaran_akses'] !== null ? $b['sasaran'] : $b['sasaran_nama']);
                if ($b['pelaku'] !== null) {
                    $b['pelaku'] = $b['pelaku_email_lingkup']
                        ?? ($b['pelaku_akses'] !== null ? $b['pelaku'] : $b['pelaku_nama']);
                }
            }

            $baris[] = [
                'peristiwa'  => (string) $b['peristiwa'],
                'lingkup'    => (string) $b['lingkup'],
                'keterangan' => (string) $b['keterangan'],
                'ip'         => (string) $b['ip'],
                'perangkat'  => (string) $b['perangkat'],
                'waktu'      => (string) $b['waktu'],
                'pelaku'     => $b['pelaku'] === null ? null : (string) $b['pelaku'],
                'sasaran'    => (string) $b['sasaran'],
            ];
        }

        return $baris;
    }

    /** Teks bebas (keterangan, user agent) disimpan sebagai UTF-8 tanpa karakter kendali, paling panjang 255 byte. */
    private static function potong(string $teks): string
    {
        $teks = preg_replace('/[\x00-\x1f\x7f]/', '', $teks) ?? '';
        if (function_exists('mb_strcut')) {
            return mb_strcut(mb_scrub($teks, 'UTF-8'), 0, self::PANJANG_TEKS, 'UTF-8');
        }

        return substr($teks, 0, self::PANJANG_TEKS);
    }
}
