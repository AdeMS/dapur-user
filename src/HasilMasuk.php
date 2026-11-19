<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

final class HasilMasuk
{
    public const BERHASIL = 'berhasil';
    public const GAGAL    = 'gagal';
    public const DIBATASI = 'dibatasi';

    /** Email dan kata sandi benar, tetapi website ini mewajibkan email dengan domain tertentu. */
    public const DOMAIN = 'domain';

    public function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
    ) {
    }
}
