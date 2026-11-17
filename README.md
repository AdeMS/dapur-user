# Dapur AdeMS User

Modul user untuk [Dapur AdeMS](https://github.com/AdeMS/dapur): akun, kata sandi, login dan keluar, halaman profil,
kelola user, hak akses dan peran per lingkup, pembatasan percobaan login, riwayat login, riwayat akun, serta email
undangan, lupa kata sandi, dan ganti email.

Paket ini tidak membuat halaman sendiri. Modul yang memerlukan login, misalnya
[adems/dapur-dashboard](https://github.com/AdeMS/dapur-dashboard), memasang halaman masuk, keluar, profil, dan
kelola user dari paket ini pada alamatnya sendiri. Satu akun user bisa diberi akses ke beberapa lingkup, misalnya dashboard utama dan
beberapa website.

## Kebutuhan

- Dapur AdeMS 2.6 atau lebih baru
- MariaDB atau MySQL yang sudah diatur di `config/autoload/database.local.php` (SQLite juga bisa dipakai)
- [adems/dapur-multiwebsite](https://github.com/AdeMS/dapur-multiwebsite) 1.1 atau lebih baru, bila user dipakai
  per website
- [adems/dapur-email](https://github.com/AdeMS/dapur-email) 1.0 (ikut terpasang otomatis); fitur email aktif
  setelah pengiriman email diatur

## Pemasangan

Paket ini biasanya ikut terpasang sebagai kebutuhan modul lain. Untuk memasangnya sendiri, tulis di
`composer.local.json`, lalu jalankan `composer update`:

```json
{
    "require": {
        "adems/dapur-user": "^2.1"
    }
}
```

Bila tabel perlu awalan nama (misalnya karena database dipakai bersama aplikasi lain), atur `awalan_tabel` lebih
dulu (lihat [Pengaturan](#pengaturan)). Lalu buat tabel dan admin pertama dari folder inti. Admin yang dibuat di
inti menjadi **admin super**. Kata sandi diketik tanpa terlihat; minimal 12 karakter untuk admin dan 8 karakter
untuk anggota:

```bash
vendor/bin/dapur-user pasang
vendor/bin/dapur-user buat-admin admin@contoh.com --nama="Nama Lengkap"
```

### Memperbarui dari 2.0 ke 2.1

Versi 2.1 menambah email undangan, lupa kata sandi, dan ganti email, beserta tabel `user_token`. Jalankan dari
folder inti:

```bash
composer update
vendor/bin/dapur-user pasang
```

lalu hapus cache konfigurasi. Paket [adems/dapur-email](https://github.com/AdeMS/dapur-email) ikut terpasang.
Selama pengiriman email belum diatur, semuanya berjalan seperti 2.0: user baru mendapat kata sandi sementara, dan
tautan "Lupa kata sandi?" tidak tampil. Untuk mengaktifkan email, ikuti [Email](#email).

Ubah nama, ganti kata sandi, aktivitas, dan riwayat masuk pindah dari Profil ke halaman baru
[Pengaturan & Privasi](#pengaturan--privasi); Profil kini hanya untuk dibaca.
Modul yang memasang halaman user sendiri perlu menambah route untuk `PabrikLayanan::pengaturan()`; template yang
menimpa `user::profil-isi` perlu disesuaikan.

### Memperbarui dari 1.x ke 2.0

Versi 2.0 mengganti peran `admin` dengan **admin super** dan **admin web** (lihat [Peran](#peran)), dan menambah
tabel `user_pengaturan`. Jalankan dari folder inti:

```bash
composer update
vendor/bin/dapur-user pasang
```

lalu hapus cache konfigurasi. `pasang` membuat tabel yang belum ada dan mengubah peran lama: `admin` di dashboard
utama menjadi `admin_super`, `admin` di website menjadi `admin_web`. Sebelum `pasang` dijalankan, peran lama sudah
dibaca dengan arti yang sama, jadi login tetap berjalan. User tidak perlu login ulang.

Perubahan untuk kode yang memakai paket ini:

- Konstanta `DaftarUser::PERAN_ADMIN` dan `DaftarUser::PERAN` diganti `PERAN_ADMIN_SUPER`, `PERAN_ADMIN_WEB`,
  `PERAN_UTAMA`, dan `PERAN_WEBSITE`. Untuk memeriksa "admin mana pun", pakai `DaftarUser::peranAdmin($peran)`.
- `DaftarUser::jumlahAdmin()` diganti `jumlahAdminSuper()`.
- Atribut request `user.peran` berisi `admin_super`, `admin_web`, atau `anggota`. Admin super mendapat
  `admin_super` di website mana pun.
- Template `user::kelola-user-isi` menerima `peranTersedia` sebagai `nilai => label`, dan setiap baris user
  memiliki `labelPeran` serta `otomatis`.

Dari 1.0, lakukan juga langkah di bawah.

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
        'alamat_website' => 'https://contoh.com', // alamat untuk tautan di email; bawaan dari request
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
| `user_pengaturan` | `xyz_user_pengaturan` | Pengaturan kecil per user |
| `user_token` | `xyz_user_token` | Token sekali pakai untuk tautan email (hanya hash-nya) |

Awalan hanya boleh berisi huruf, angka, dan garis bawah, paling panjang 32 karakter. Perintah `dapur-user`
membaca pengaturan yang sama, jadi atur awalan sebelum menjalankan `pasang`.

## Perintah

```bash
vendor/bin/dapur-user pasang                          # membuat tabel yang belum ada dan memperbarui peran lama, aman diulang
vendor/bin/dapur-user buat-admin <email>              # membuat admin super (inti) atau admin web (--website)
vendor/bin/dapur-user ganti-sandi <email>             # mengganti kata sandi
vendor/bin/dapur-user riwayat <email> [--jumlah=20]   # menampilkan riwayat login user
vendor/bin/dapur-user daftar-akses [<email>]          # user di lingkup ini, atau semua akses satu user
vendor/bin/dapur-user cabut-akses <email>             # mencabut akses user dari lingkup ini
vendor/bin/dapur-user riwayat-akun [<email>]          # perubahan akun di lingkup ini, atau pada satu user
```

Dengan paket multi-website, tambahkan `--website=<domain>` untuk website tertentu. Lingkup akses yang diberikan
`buat-admin` adalah `dashboard` di inti (peran admin super), atau nama domain website (peran admin web).

`pasang` melaporkan setiap tabel: `dibuat` atau `sudah ada`. Tabel yang sudah ada beserta isinya tidak diubah;
perintah ini tidak pernah menghapus tabel.

`cabut-akses` hanya mencabut akses ke lingkup yang dituju; akun user dan aksesnya ke lingkup lain tetap ada.
Admin super terakhir tidak bisa dicabut, agar Dapur tidak terkunci. Akses otomatis admin super ke website hanya
bisa dicabut dari inti. Bila dijalankan di terminal,
perintah ini meminta konfirmasi lebih dulu.

Kata sandi yang diganti lewat `ganti-sandi` atau `buat-admin` dicatat di riwayat, dan session user tersebut di
semua perangkat berakhir.

Bila email pada `buat-admin` sudah terdaftar, perintah memastikan user itu menjadi admin di lingkup yang dituju,
lalu menawarkan ganti kata sandi dengan jawaban bawaan "tidak". Tawaran ini hanya muncul bila perintah dijalankan
di terminal; dari skrip, kata sandi tidak diubah.

## Profil

Halaman profil berada di `<alamat dasar>/profil`, misalnya `/profil` di dashboard utama atau `/dashboard/profil`
di dashboard website. Tautannya ada di submenu avatar. Halaman ini hanya untuk dibaca: kartu user (avatar, nama,
email, peran, status) dengan tombol **Pengaturan akun**.

## Pengaturan & Privasi

Halaman `<alamat dasar>/pengaturan` (tautan "Pengaturan & Privasi" di submenu avatar) berisi tiga tab, yang bisa
dibuka langsung lewat `?tab=akun|aktivitas|riwayat`:

- **Akun Saya**: mengubah nama (tombol Simpan dan Batal), serta dua tombol yang membuka popup:
  - **Ubah** di samping email, bila pengiriman email aktif (lihat [Email](#email));
  - **Atur kata sandi baru**. Kata sandi sekarang wajib diisi, dan salah ketik dihitung seperti login gagal.
    Setelah berhasil, session di perangkat lain berakhir, sedangkan perangkat yang dipakai tetap login.

  Bila isian di popup salah, halaman dimuat ulang dengan popup itu terbuka bersama pesannya.
- **Aktivitas**: 20 perubahan akun terakhir, baik perubahan pada akun user itu maupun perubahan yang ia lakukan
  pada akun lain. Email admin yang mengubah akunnya tidak ditampilkan (cukup "Admin").
- **Riwayat masuk**: 20 catatan login terakhir, dengan perangkat yang diringkas (misalnya `Edge 157 · Windows`).
  Teks user agent lengkap muncul saat kursor diarahkan ke nama perangkat.

Aktivitas dan Riwayat masuk punya tombol **Tampilkan lebih banyak** bila masih ada catatan lain: setiap klik
menambah 20 baris (`?tab=riwayat&jumlah=40`, dan seterusnya, paling banyak 480) dan melompat ke baris baru pertama.
Catatan yang lebih lama bisa dilihat dengan perintah `riwayat` dan `riwayat-akun`.

Tautan lama ke tab profil (`/profil?tab=sandi` dan sebagainya) dialihkan ke tab yang sesuai di halaman ini.

## Peran

Setiap akses ke sebuah lingkup punya peran:

| Peran | Nilai | Bisa |
|---|---|---|
| Admin super | `admin_super` | Admin di dashboard utama, dan otomatis admin di **semua** website tanpa perlu diberi akses satu per satu. Hanya ada di lingkup `dashboard`. |
| Admin web | `admin_web` | Login, memakai halaman, dan mengelola user di website yang aksesnya diberikan. Hanya ada di lingkup website. |
| Anggota | `anggota` | Login dan memakai halaman di lingkup itu, tanpa mengelola user. |

Kata sandi admin super dan admin web minimal 12 karakter; anggota minimal 8 karakter.

Peran berlaku per lingkup: satu user bisa menjadi admin web di satu website dan anggota di website lain. Peran
user yang sedang login tersedia sebagai atribut request `WajibMasukMiddleware::ATRIBUT_PERAN`. Admin super pertama
dibuat lewat perintah `buat-admin` dari folder inti; user berikutnya bisa ditambahkan dari halaman kelola user.

Nama "admin web" dipilih agar tidak tertukar dengan "admin" sebagai staf administrasi sebuah perusahaan atau
organisasi.

## Kelola user

Halaman kelola user berada di `<alamat dasar>/user`, misalnya `/user` di dashboard utama atau `/dashboard/user`
di dashboard website, dan hanya bisa dibuka admin. Di halaman ini admin bisa:

- **menambah user.** Bila email sudah terdaftar, user itu cukup diberi akses ke lingkup ini. Bila belum, akunnya
  dibuat, lalu:
  - bila pengiriman email aktif, user menerima **undangan** berisi tautan untuk membuat kata sandinya sendiri;
  - bila email mati atau undangan gagal terkirim, kata sandi sementara ditampilkan **sekali** kepada admin,
    misalnya `k7mq-x3vt-9wcr-hp4n`. Admin menyampaikannya sendiri kepada user, lalu user menggantinya di Profil.
- **mengirim ulang undangan** kepada user yang belum pernah login, misalnya karena tautannya sudah kedaluwarsa.
- **mengubah peran**: antara admin super dan anggota di dashboard utama, atau antara admin web dan anggota di
  website.
- **mencabut akses** dari lingkup ini. Akun user tidak dihapus, dan aksesnya ke lingkup lain tetap ada.
- **mengaktifkan atau menonaktifkan akun**, hanya di dashboard utama. Akun nonaktif tidak bisa login di lingkup
  mana pun, dan session yang sedang berjalan langsung berakhir. Karena berlaku di semua website, admin website
  tidak bisa melakukannya.
- melihat **aktivitas terakhir** di lingkup ini.

Admin tidak bisa mengubah peran, mencabut akses, atau menonaktifkan dirinya sendiri, dan admin super terakhir
tidak bisa diturunkan, dicabut, atau dinonaktifkan, agar Dapur tidak terkunci. Hanya user yang punya akses ke
lingkup ini yang bisa diubah dari halamannya. Di website, admin super ikut terdaftar dengan keterangan "berlaku
di semua website" dan hanya bisa diubah dari dashboard utama, jadi admin web tidak bisa mengubahnya.

## Email

Bila pengiriman email diatur di [adems/dapur-email](https://github.com/AdeMS/dapur-email#pengaturan) (`'cara' =>
'smtp'`), tiga fitur ini aktif:

| Fitur | Tautan berlaku | Keterangan |
|---|---|---|
| **Undangan** user baru | 48 jam | User membuat kata sandinya sendiri, jadi admin tidak pernah tahu kata sandinya |
| **Lupa kata sandi** | 1 jam | Tautan "Lupa kata sandi?" di halaman masuk |
| **Ubah email** di Profil | 24 jam | Tautan konfirmasi dikirim ke email baru; email lama menerima pemberitahuan |

Halaman-halamannya berada di bawah alamat dasar pintu: `<alamat dasar>/lupa-sandi`, `/atur-sandi` (membuat kata
sandi dari undangan atau lupa kata sandi), dan `/verifikasi-email`.

Cara kerja tautan:

- Token di tautan dibuat acak (32 byte). Database hanya menyimpan hash SHA-256-nya, jadi orang yang bisa membaca
  database tidak bisa memakainya.
- Setiap token hanya bisa dipakai sekali, punya batas waktu, dan token baru membatalkan token lama sejenis.
- Membuka tautan hanya menampilkan formulir atau tombol konfirmasi. Token baru dipakai saat formulir dikirim, agar
  pemindai tautan di aplikasi email (yang membuka tautan secara otomatis) tidak menghabiskannya.
- Halaman lupa kata sandi selalu memberi jawaban yang sama, terdaftar atau tidak, agar orang lain tidak bisa
  menebak email mana yang punya akun. Permintaannya dibatasi seperti login gagal.
- Setelah kata sandi dibuat lewat tautan, semua session lama user itu berakhir.

Tautan di email harus berisi alamat lengkap. Bawaannya diambil dari request yang sedang berjalan; dengan
multi-website, hanya host yang terdaftar yang dilayani. Untuk memastikan tautan selalu menunjuk ke alamat yang
benar, atur `'user' => ['alamat_website' => 'https://contoh.com']`.

Email yang dikirim memakai template `user::email-undangan`, `user::email-lupa-sandi`, `user::email-verifikasi`,
dan `user::email-pemberitahuan` (masing-masing dengan versi `-teks`), yang bisa ditimpa seperti template lain.

## Riwayat akun

Setiap perubahan akun dicatat bersama pelaku, user sasaran, lingkup tempat perubahan dilakukan, keterangan
(misalnya `anggota → admin_web`), IP, dan perangkat: user dibuat, nama diubah, akses diberikan atau dicabut, peran
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
'laporan.pengaturan'  => PabrikLayanan::pengaturan($container, $pintu),
'laporan.user'        => PabrikLayanan::kelolaUser($container, $pintu),
'laporan.lupa_sandi'  => PabrikLayanan::lupaSandi($container, $pintu),
'laporan.atur_sandi'  => PabrikLayanan::aturSandi($container, $pintu),
'laporan.verifikasi'  => PabrikLayanan::verifikasiEmail($container, $pintu),

// Route:
$app->route('/laporan/masuk', [MulaiSesiMiddleware::class, 'laporan.masuk'], ['GET', 'POST']);
$app->post('/laporan/keluar', [MulaiSesiMiddleware::class, 'laporan.wajib_masuk', 'laporan.keluar']);
$app->route('/laporan/profil', [MulaiSesiMiddleware::class, 'laporan.wajib_masuk', 'laporan.profil'], ['GET', 'POST']);
$app->route('/laporan/pengaturan', [MulaiSesiMiddleware::class, 'laporan.wajib_masuk', 'laporan.pengaturan'], ['GET', 'POST']);
$app->route('/laporan/user', [MulaiSesiMiddleware::class, 'laporan.wajib_masuk', 'laporan.user'], ['GET', 'POST']);
// Halaman tautan email, tanpa login:
$app->route('/laporan/lupa-sandi', [MulaiSesiMiddleware::class, 'laporan.lupa_sandi'], ['GET', 'POST']);
$app->route('/laporan/atur-sandi', [MulaiSesiMiddleware::class, 'laporan.atur_sandi'], ['GET', 'POST']);
$app->route('/laporan/verifikasi-email', [MulaiSesiMiddleware::class, 'laporan.verifikasi'], ['GET', 'POST']);
$app->get('/laporan', [MulaiSesiMiddleware::class, 'laporan.wajib_masuk', LaporanHandler::class]);
```

Sertakan isi `AdeMS\Dapur\User\ConfigProvider` di ConfigProvider modul tersebut. Setelah login, user tersedia
sebagai atribut request `AdeMS\Dapur\User\User::class`.

Template `user::menu-user` mengisi placeholder `menu-kanan` di navbar dengan avatar inisial user beserta
submenu nama, email, Profil, Pengaturan & Privasi, dan tombol keluar. Template `user::masuk` adalah halaman login,
dan `user::profil` adalah halaman profil yang berisi menu user serta `user::profil-isi`. Halaman Pengaturan &
Privasi dan kelola user tersusun dengan cara yang sama: `user::pengaturan` + `user::pengaturan-isi`, dan
`user::kelola-user` + `user::kelola-user-isi`. Semuanya bisa ditimpa lewat
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
- Tautan email memakai token sekali pakai yang disimpan sebagai hash, dengan batas waktu (lihat [Email](#email)).

## Uji

```bash
composer test
```

## Lisensi

BSD-3-Clause. Lihat [LICENSE.md](LICENSE.md).
