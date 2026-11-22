<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use RuntimeException;

/**
 * Foto profil user: menerima JPG, PNG, atau WebP paling besar 2 MB, lalu membuatnya ulang dengan GD menjadi gambar
 * persegi 256 piksel berformat WebP. Karena gambar dibuat ulang, metadata seperti EXIF dan lokasi GPS ikut terbuang,
 * dan isi selain gambar tidak pernah disimpan.
 *
 * File disimpan di luar folder public (bawaan data/upload/avatar di folder inti), dengan nama kode acak 32 huruf
 * heksadesimal, dan disajikan lewat FotoHandler hanya kepada user yang login. Foto baru selalu mendapat kode baru,
 * sehingga browser boleh menyimpan foto lama selamanya.
 */
final class FotoUser
{
    public const UKURAN_MAKS   = 2 * 1024 * 1024;
    public const SISI          = 256;
    /** Batas jumlah piksel gambar asli, agar memori PHP cukup saat gambar dibuka (sekitar 12 megapiksel). */
    public const PIKSEL_MAKS   = 12_000_000;
    public const JENIS_DITERIMA = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        public readonly string $folder,
    ) {
    }

    public static function kodeSah(mixed $kode): bool
    {
        return is_string($kode) && preg_match('/^[a-f0-9]{32}$/', $kode) === 1;
    }

    /** Apakah fungsi gambar PHP (GD dengan WebP) tersedia. */
    public static function didukung(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagewebp') && function_exists('finfo_open');
    }

    /** Apakah folder foto ada (atau bisa dibuat) dan bisa ditulis. */
    public function folderSiap(): bool
    {
        if (! is_dir($this->folder)) {
            // Folder yang belum ada dibuat satu per satu (misalnya data/upload lalu avatar) dengan izin 2770: grup web
            // server bisa menulis, setgid menjaga grupnya, dan akun lain tidak bisa melihat isinya. mkdir() tidak selalu
            // memasang setgid, jadi izinnya diatur lagi dengan chmod().
            $baru = [];
            for ($f = $this->folder; ! is_dir($f) && $f !== dirname($f); $f = dirname($f)) {
                $baru[] = $f;
            }

            foreach (array_reverse($baru) as $f) {
                if (@mkdir($f) || is_dir($f)) {
                    @chmod($f, 02770);
                }
            }
        }

        return is_dir($this->folder) && is_writable($this->folder);
    }

    public function file(string $kode): ?string
    {
        if (! self::kodeSah($kode)) {
            return null;
        }

        $file = $this->folder . '/' . $kode . '.webp';

        return is_file($file) ? $file : null;
    }

    /**
     * Membuat foto dari file unggahan.
     *
     * @return string kode foto baru
     * @throws RuntimeException dengan pesan yang bisa ditampilkan kepada user
     */
    public function simpan(string $fileUnggahan): string
    {
        if (! self::didukung()) {
            throw new RuntimeException('Server ini belum bisa mengolah gambar (ekstensi PHP GD dengan WebP belum aktif).');
        }

        $ukuran = @filesize($fileUnggahan);
        if ($ukuran === false || $ukuran === 0) {
            throw new RuntimeException('File foto kosong atau tidak terbaca.');
        }

        if ($ukuran > self::UKURAN_MAKS) {
            throw new RuntimeException('Foto terlalu besar; paling besar 2 MB.');
        }

        $jenis = (new \finfo(FILEINFO_MIME_TYPE))->file($fileUnggahan);
        if (! in_array($jenis, self::JENIS_DITERIMA, true)) {
            throw new RuntimeException('Foto harus berformat JPG, PNG, atau WebP.');
        }

        $info = @getimagesize($fileUnggahan);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new RuntimeException('File itu bukan gambar yang bisa dibaca.');
        }

        if ($info[0] * $info[1] > self::PIKSEL_MAKS) {
            throw new RuntimeException('Ukuran gambar terlalu besar; paling banyak sekitar 12 megapiksel.');
        }

        if (! $this->folderSiap()) {
            throw new RuntimeException('Folder foto belum bisa ditulis oleh web server. Hubungi admin website.');
        }

        $asli = @imagecreatefromstring((string) file_get_contents($fileUnggahan));
        if ($asli === false) {
            throw new RuntimeException('File itu bukan gambar yang bisa dibaca.');
        }

        $asli  = $this->putarSesuaiExif($asli, $fileUnggahan, $jenis);
        $hasil = $this->potongPersegi($asli);
        imagedestroy($asli);

        $kode = bin2hex(random_bytes(16));
        $file = $this->folder . '/' . $kode . '.webp';
        $sementara = $file . '.tmp';
        $berhasil  = imagewebp($hasil, $sementara, 85);
        imagedestroy($hasil);
        if (! $berhasil || ! @rename($sementara, $file)) {
            @unlink($sementara);
            throw new RuntimeException('Foto gagal disimpan. Coba lagi.');
        }

        @chmod($file, 0660);

        return $kode;
    }

    /** Menghapus file foto; kode yang tidak sah atau file yang tidak ada diabaikan. */
    public function hapus(string $kode): void
    {
        $file = $this->file($kode);
        if ($file !== null) {
            @unlink($file);
        }
    }

    /** Foto dari kamera ponsel sering disimpan miring dengan catatan arah di EXIF; diluruskan sebelum EXIF dibuang. */
    private function putarSesuaiExif(\GdImage $gambar, string $file, string $jenis): \GdImage
    {
        if ($jenis !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $gambar;
        }

        $exif  = @exif_read_data($file);
        $arah  = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $sudut = match ($arah) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($sudut !== 0) {
            $putar = imagerotate($gambar, $sudut, 0);
            if ($putar !== false) {
                imagedestroy($gambar);
                $gambar = $putar;
            }
        }

        if (in_array($arah, [2, 4, 5, 7], true)) {
            imageflip($gambar, IMG_FLIP_HORIZONTAL);
        }

        return $gambar;
    }

    /** Bagian tengah gambar yang berbentuk persegi, diperkecil menjadi SISI x SISI piksel. */
    private function potongPersegi(\GdImage $gambar): \GdImage
    {
        $lebar  = imagesx($gambar);
        $tinggi = imagesy($gambar);
        $sisi   = min($lebar, $tinggi);

        $hasil = imagecreatetruecolor(self::SISI, self::SISI);
        // Latar transparan untuk PNG/WebP bertransparansi.
        imagealphablending($hasil, false);
        imagesavealpha($hasil, true);
        imagefill($hasil, 0, 0, imagecolorallocatealpha($hasil, 0, 0, 0, 127));

        imagecopyresampled(
            $hasil,
            $gambar,
            0,
            0,
            intdiv($lebar - $sisi, 2),
            intdiv($tinggi - $sisi, 2),
            self::SISI,
            self::SISI,
            $sisi,
            $sisi,
        );

        return $hasil;
    }
}
