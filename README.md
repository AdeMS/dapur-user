# Dapur AdeMS User

Modul user untuk [Dapur AdeMS](https://github.com/AdeMS/dapur): akun, kata sandi, login dan keluar, halaman profil,
kelola user, hak akses dan peran per lingkup, pembatasan percobaan login, riwayat login, dan riwayat akun.

Paket ini tidak membuat halaman sendiri. Modul yang memerlukan login, misalnya
[adems/dapur-dashboard](https://github.com/AdeMS/dapur-dashboard), memasang halaman masuk, keluar, profil, dan
kelola user dari paket ini pada alamatnya sendiri. Satu akun user bisa diberi akses ke beberapa lingkup, misalnya dashboard utama dan
beberapa website.

## Kebutuhan

- Dapur AdeMS 2.6 atau lebih baru
- MariaDB atau MySQL yang sudah diatur di `config/autoload/database.local.php` (SQLite juga bisa dipakai)
- [adems/dapur-multiwebsite](https://github.com/AdeMS/dapur-multiwebsite) 1.1 atau lebih baru, bila user dipakai
  per website

## Pemasangan

Paket ini biasanya ikut terpasang sebagai kebutuhan modul lain. Untuk memasangnya sendiri, tulis di
`composer.local.json`, lalu jalankan `composer update`:

```json
{
    "require": {
        "adems/dapur-user": "^1.2"
    }
}
```

Bila tabel perlu awalan nama (misalnya karena database dipakai bersama aplikasi lain), atur `awalan_tabel` lebih
dulu (lihat [Pengaturan](#pengaturan)). Lalu buat tabel dan admin pertama dari folder inti. Kata sandi diketik
tanpa terlihat; minimal 12 karakter untuk admin dan 8 karakter untuk user lain:

```bash
vendor/bin/dapur-user pasang
vendor/bin/dapur-user buat-admin admin@contoh.com --nama="Nama Lengkap"
```

### Memperbarui dari 1.1

Versi 1.2 menambah tabel riwayat akun. Jalankan dari folder inti:

```bash
composer update
vendor/bin/dapur-user pasang
```

lalu hapus cache konfigurasi. `pasang` hanya membuat tabel `user_riwayat_akun`; tabel lain beserta isinya tidak
diubah. Sebelum `pasang` dijalankan, halaman kelola user dan perubahan nama di Profil akan gagal. User tidak perlu
login ulang.

### Memperbarui dari 1.0

Ikuti langkah dari 1.1 di atas. Selain itu, bila template `user::menu-user` ditimpa sendiri, tambahkan tautan ke
`$this->alamatProfil`.

## Pengaturan

Tulis di `config/autoload/*.php` milik inti. Pengaturan berlaku juga untuk semua website, kecuali website
tersebut menimpanya di `config/autoload/*.php` miliknya sendiri:

```php
return [
    'user' => [
        'awalan_tabel' => 'xyz_', // awalan nama tabel, bawaan tanpa awalan
        'riwayat_hari' => 180,    // lama riwayat login disimpan; 0 = tidak pernah dihapus
        'zona_waktu'   => 'Asia/Jakarta', // zona waktu tampilan, bawaan zona waktu PHP
    ],
];
```

### Nama tabel

| Tanpa `awalan_tabel` | Dengan `'awalan_tabel' => 'xyz_'` | Isi |
|---|---|---|
| `user` | `xyz_user` | Akun user |
| `user_akses` | `xyz_user_akses` | Hak akses per lingkup |
| `user_percobaan_masuk` | `xyz_user_percobaan_masuk` | Login gagal 15 menit terakhir, untuk pembatasan |
| `user_riwayat_masuk` | `xyz_user_riwayat_masuk` | Riwayat login |
| `user_riwayat_akun` | `xyz_user_riwayat_akun` | Riwayat perubahan akun |

Awalan hanya boleh berisi huruf, angka, dan garis bawah, paling panjang 32 karakter. Perintah `dapur-user`
membaca pengaturan yang sama, jadi atur awalan sebelum menjalankan `pasang`.

## Perintah

```bash
vendor/bin/dapur-user pasang                          # membuat tabel yang belum ada, aman diulang
vendor/bin/dapur-user buat-admin <email>              # membuat admin, atau menambah akses user yang sudah ada
vendor/bin/dapur-user ganti-sandi <email>             # mengganti kata sandi
vendor/bin/dapur-user riwayat <email> [--jumlah=20]   # menampilkan riwayat login user
vendor/bin/dapur-user daftar-akses [<email>]          # user di lingkup ini, atau semua akses satu user
vendor/bin/dapur-user cabut-akses <email>             # mencabut akses user dari lingkup ini
vendor/bin/dapur-user riwayat-akun [<email>]          # perubahan akun di lingkup ini, atau pada satu user
```

Dengan paket multi-website, tambahkan `--website=<domain>` untuk website tertentu. Lingkup akses yang diberikan
`buat-admin` adalah `dashboard` di inti, atau nama domain website.

`pasang` melaporkan setiap tabel: `dibuat` atau `sudah ada`. Tabel yang sudah ada beserta isinya tidak diubah;
perintah ini tidak pernah menghapus tabel.

`cabut-akses` hanya mencabut akses ke lingkup yang dituju; akun user dan aksesnya ke lingkup lain tetap ada.
Admin terakhir sebuah lingkup tidak bisa dicabut, agar lingkup itu tidak terkunci. Bila dijalankan di terminal,
perintah ini meminta konfirmasi lebih dulu.

Kata sandi yang diganti lewat `ganti-sandi` atau `buat-admin` dicatat di riwayat, dan session user tersebut di
semua perangkat berakhir.

Bila email pada `buat-admin` sudah terdaftar, perintah memastikan user itu punya akses ke lingkup yang dituju,
lalu menawarkan ganti kata sandi dengan jawaban bawaan "tidak". Tawaran ini hanya muncul bila perintah dijalankan
di terminal; dari skrip, kata sandi tidak diubah.

## Profil

Halaman profil berada di `<alamat dasar>/profil`, misalnya `/profil` di dashboard utama atau `/dashboard/profil`
di dashboard website. Tautannya ada di submenu avatar. Di halaman ini user bisa:

- mengubah nama. Email dipakai untuk login dan belum bisa diubah.
- mengganti kata sandi. Kata sandi sekarang wajib diisi, dan salah ketik dihitung seperti login gagal. Setelah
  berhasil, session di perangkat lain berakhir, sedangkan perangkat yang dipakai tetap login.
- melihat 20 catatan riwayat login terakhir, dengan perangkat yang diringkas (misalnya `Edge 157 · Windows`).
  Teks user agent lengkap muncul saat kursor diarahkan ke nama perangkat.

## Peran

Setiap akses ke sebuah lingkup punya peran:

| Peran | Bisa |
|---|---|
| `admin` | Login, memakai halaman di lingkup itu, dan mengelola user di sana. Kata sandi minimal 12 karakter. |
| `anggota` | Login dan memakai halaman di lingkup itu, tanpa mengelola user. Kata sandi minimal 8 karakter. |

Peran berlaku per lingkup: satu user bisa menjadi admin di satu website dan anggota di website lain. Peran user
yang sedang login tersedia sebagai atribut request `WajibMasukMiddleware::ATRIBUT_PERAN`. Admin pertama dibuat
lewat perintah `buat-admin`; user berikutnya bisa ditambahkan dari halaman kelola user.

## Kelola user

Halaman kelola user berada di `<alamat dasar>/user`, misalnya `/user` di dashboard utama atau `/dashboard/user`
di dashboard website, dan hanya bisa dibuka admin. Di halaman ini admin bisa:

- **menambah user.** Bila email sudah terdaftar, user itu cukup diberi akses ke lingkup ini. Bila belum, akunnya
  dibuat dengan kata sandi sementara yang ditampilkan **sekali** kepada admin, misalnya `k7mq-x3vt-9wcr-hp4n`.
  Admin menyampaikannya sendiri kepada user, lalu user menggantinya di halaman Profil.
- **mengubah peran** antara admin dan anggota.
- **mencabut akses** dari lingkup ini. Akun user tidak dihapus, dan aksesnya ke lingkup lain tetap ada.
- **mengaktifkan atau menonaktifkan akun**, hanya di dashboard utama. Akun nonaktif tidak bisa login di lingkup
  mana pun, dan session yang sedang berjalan langsung berakhir. Karena berlaku di semua website, admin website
  tidak bisa melakukannya.
- melihat **aktivitas terakhir** di lingkup ini.

Admin tidak bisa mengubah peran, mencabut akses, atau menonaktifkan dirinya sendiri, dan admin terakhir sebuah
lingkup tidak bisa diturunkan atau dicabut, agar lingkup itu tidak terkunci. Hanya user yang punya akses ke
lingkup ini yang bisa diubah dari halamannya.

## Riwayat akun

Setiap perubahan akun dicatat bersama pelaku, user sasaran, lingkup tempat perubahan dilakukan, keterangan
(misalnya `anggota → admin`), IP, dan perangkat: user dibuat, nama diubah, akses diberikan atau dicabut, peran
diubah, serta akun diaktifkan atau dinonaktifkan. Perubahan lewat perintah di terminal dicatat tanpa pelaku.

Riwayat akun disimpan selamanya. Lihat dengan `vendor/bin/dapur-user riwayat-akun`, atau di bagian aktivitas
terakhir pada halaman kelola user.

## Riwayat login

Setiap login berhasil, login gagal, keluar, dan ganti kata sandi dicatat bersama lingkup, IP, perangkat (user agent browser), dan
waktunya. Waktu disimpan dalam UTC; perintah `riwayat` menampilkannya menurut `zona_waktu` (bawaan: zona waktu
PHP).

- Login gagal dengan email yang tidak terdaftar dicatat tanpa user. Email yang diketik tidak pernah disimpan,
  karena orang kadang salah mengetik kata sandi di kolom email.
- Percobaan yang ditolak karena terlalu sering tidak dicatat, agar riwayat tidak bisa dibanjiri.
- Catatan yang lebih tua dari `riwayat_hari` dihapus otomatis saat ada login berhasil.

Tabel `user_percobaan_masuk` berbeda tujuannya: tabel itu hanya menghitung login gagal untuk pembatasan, dan
isinya dihapus setelah login berhasil.

## Memakai login di modul lain

Buat `Pintu` (lingkup akses, alamat dasar, batas diam, layout), lalu buat middleware dan handler lewat
`PabrikLayanan`. Contoh factory di modul sendiri:

```php
use AdeMS\Dapur\User\Middleware\MulaiSesiMiddleware;
use AdeMS\Dapur\User\PabrikLayanan;
use AdeMS\Dapur\User\Pintu;

$pintu = new Pintu('laporan', '/laporan');

// Layanan bernama milik modul, didaftarkan sebagai factory:
'laporan.wajib_masuk' => PabrikLayanan::wajibMasuk($container, $pintu),
'laporan.masuk'       => PabrikLayanan::masuk($container, $pintu),
'laporan.keluar'      => PabrikLayanan::keluar($container, $pintu),
'laporan.profil'      => PabrikLayanan::profil($container, $pintu),
'laporan.user'        => PabrikLayanan::kelolaUser($container, $pintu),

// Route:
$app->route('/laporan/masuk', [MulaiSesiMiddleware::class, 'laporan.masuk'], ['GET', 'POST']);
$app->post('/laporan/keluar', [MulaiSesiMiddleware::class, 'laporan.wajib_masuk', 'laporan.keluar']);
$app->route('/laporan/profil', [MulaiSesiMiddleware::class, 'laporan.wajib_masuk', 'laporan.profil'], ['GET', 'POST']);
$app->route('/laporan/user', [MulaiSesiMiddleware::class, 'laporan.wajib_masuk', 'laporan.user'], ['GET', 'POST']);
$app->get('/laporan', [MulaiSesiMiddleware::class, 'laporan.wajib_masuk', LaporanHandler::class]);
```

Sertakan isi `AdeMS\Dapur\User\ConfigProvider` di ConfigProvider modul tersebut. Setelah login, user tersedia
sebagai atribut request `AdeMS\Dapur\User\User::class`.

Template `user::menu-user` mengisi placeholder `menu-kanan` di navbar dengan avatar inisial user beserta
submenu nama, email, Profil, dan tombol keluar. Template `user::masuk` adalah halaman login, dan `user::profil`
adalah halaman profil yang berisi menu user serta `user::profil-isi`. Halaman kelola user tersusun dengan cara yang
sama: `user::kelola-user` dan `user::kelola-user-isi`. Semuanya bisa ditimpa lewat
`'templates' => ['paths' => ['user' => [...]]]`.

Agar halaman profil memakai menu modul sendiri, berikan penyaji halaman (kelas yang menerapkan
`AdeMS\Dapur\User\PenyajiHalaman`) dan template sendiri yang memanggil `$this->partial('user::profil-isi', ...)`:

```php
'laporan.profil' => PabrikLayanan::profil($container, $pintu, $penyajiLaporan, 'laporan::profil'),
```

Dashboard memakai cara ini untuk menampilkan menu dashboard di halaman profil dan kelola user.
`PabrikLayanan::kelolaUser()` menerima argumen kelima `$kelolaAkun`; isi `true` hanya untuk pintu utama, karena
aktif dan nonaktif berlaku di semua lingkup.

## Keamanan

- Kata sandi disimpan sebagai hash bcrypt.
- Cookie session memakai HttpOnly dan SameSite=Lax, ditambah Secure pada HTTPS. ID session diganti saat login.
- Semua form memakai token CSRF. Keluar hanya bisa lewat POST.
- Login gagal dibatasi per akun dan per IP selama 15 menit.
- Status, hak akses, dan sidik jari kata sandi user diperiksa ulang pada setiap request, sehingga session lain
  langsung berakhir setelah kata sandi diganti. Yang disimpan di session hanya hash dari hash kata sandi.
- Setelah login, user diarahkan kembali ke halaman yang diminta sebelumnya. Alamat itu disimpan di session,
  bukan di URL, dan hanya alamat di dalam pintu yang sama yang diterima.
- Session disimpan di `data/cache/session/`, jadi folder `data/cache/` harus bisa ditulis web server.

## Uji

```bash
composer test
```

## Lisensi

BSD-3-Clause. Lihat [LICENSE.md](LICENSE.md).
