<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

/**
 * Daftar website yang bisa dipilih admin super di dashboard utama untuk memberi akses website kepada user.
 *
 * Paket user tidak tahu website apa saja yang ada; daftarnya milik paket multi-website. Modul yang memasang halaman
 * kelola user di dashboard utama (misalnya dashboard) memberikan implementasinya. Tanpa implementasi, akses website
 * hanya bisa diatur dari dashboard website masing-masing.
 */
interface DaftarWebsite
{
    /**
     * Nama host website terdaftar yang foldernya ada, urut nama.
     *
     * @return list<string>
     */
    public function website(): array;

    /**
     * Batasan domain email yang berlaku di website itu, dibaca dari pengaturan website tersebut
     * (`'user' => ['domain_email' => [...]]`), sama seperti saat website itu sendiri memeriksanya.
     */
    public function batasanDomain(string $host): BatasanDomain;
}
