<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\FotoUser;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RequiresPhpExtension('gd')]
final class FotoUserTest extends TestCase
{
    private string $folder;
    private FotoUser $foto;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/dapur-foto-uji-' . bin2hex(random_bytes(4));
        $this->foto   = new FotoUser($this->folder . '/upload/avatar');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . '/upload/avatar/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->folder . '/upload/avatar');
        @rmdir($this->folder . '/upload');
        foreach (glob($this->folder . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->folder);
    }

    /** Gambar uji berukuran lebar x tinggi; kiri merah, kanan biru. */
    public static function gambar(string $file, int $lebar, int $tinggi, string $format = 'png'): string
    {
        $g = imagecreatetruecolor($lebar, $tinggi);
        imagefilledrectangle($g, 0, 0, intdiv($lebar, 2), $tinggi, (int) imagecolorallocate($g, 255, 0, 0));
        imagefilledrectangle($g, intdiv($lebar, 2) + 1, 0, $lebar, $tinggi, (int) imagecolorallocate($g, 0, 0, 255));
        @mkdir(dirname($file), 0777, true);
        match ($format) {
            'jpeg' => imagejpeg($g, $file),
            'webp' => imagewebp($g, $file),
            'gif'  => imagegif($g, $file),
            default => imagepng($g, $file),
        };
        imagedestroy($g);

        return $file;
    }

    public function testFotoDibuatUlangPersegiWebp(): void
    {
        foreach (['png', 'jpeg', 'webp'] as $format) {
            $kode = $this->foto->simpan(self::gambar($this->folder . '/asli.' . $format, 600, 300, $format));
            self::assertTrue(FotoUser::kodeSah($kode));
            $file = $this->foto->file($kode);
            self::assertNotNull($file);
            $info = getimagesize($file);
            self::assertSame([256, 256, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        }

        self::assertSame('2770', substr(sprintf('%o', fileperms($this->foto->folder)), -4), 'Folder dibuat dengan setgid dan tanpa akses umum.');
    }

    public function testHapus(): void
    {
        $kode = $this->foto->simpan(self::gambar($this->folder . '/asli.png', 50, 50));
        $this->foto->hapus($kode);
        self::assertNull($this->foto->file($kode));
        $this->foto->hapus('../../etc/passwd');
        self::assertNull($this->foto->file('../../etc/passwd'));
    }

    public function testFormatLainDitolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JPG, PNG, atau WebP');
        $this->foto->simpan(self::gambar($this->folder . '/asli.gif', 50, 50, 'gif'));
    }

    public function testBukanGambarDitolak(): void
    {
        @mkdir($this->folder, 0777, true);
        file_put_contents($this->folder . '/palsu.png', '<?php echo "bukan gambar";');
        $this->expectException(RuntimeException::class);
        $this->foto->simpan($this->folder . '/palsu.png');
    }

    public function testFileTerlaluBesarDitolak(): void
    {
        @mkdir($this->folder, 0777, true);
        $file = self::gambar($this->folder . '/besar.png', 20, 20);
        file_put_contents($file, str_repeat("\0", FotoUser::UKURAN_MAKS), FILE_APPEND);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2 MB');
        $this->foto->simpan($file);
    }
}
