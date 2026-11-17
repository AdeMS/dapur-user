<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\NamaTabel;
use AdeMS\Dapur\User\Skema;
use Laminas\Db\Adapter\Adapter;
use Mezzio\Template\TemplatePath;
use Mezzio\Template\TemplateRendererInterface;

/** Sesi di memori, untuk uji. */
final class SesiLarik implements Sesi
{
    /** @var array<string, mixed> */
    public array $isi = [];
    public int $gantiId = 0;
    public bool $hancur = false;

    public function ambil(string $kunci): mixed
    {
        return $this->isi[$kunci] ?? null;
    }

    public function simpan(string $kunci, mixed $nilai): void
    {
        $this->isi[$kunci] = $nilai;
    }

    public function hapus(string $kunci): void
    {
        unset($this->isi[$kunci]);
    }

    public function gantiId(): void
    {
        $this->gantiId++;
    }

    public function hancurkan(): void
    {
        $this->isi    = [];
        $this->hancur = true;
    }
}

final class DatabaseUji
{
    public static function baru(NamaTabel $tabel = new NamaTabel()): Adapter
    {
        $db = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
        foreach (Skema::perintah('SQLite', $tabel) as $sql) {
            $db->query($sql, Adapter::QUERY_MODE_EXECUTE);
        }

        return $db;
    }
}

/** Renderer tiruan yang mengingat data template terakhir. */
final class RendererUji implements TemplateRendererInterface
{
    /** @var array<string, mixed> */
    public array $data = [];

    /** Nama template terakhir. */
    public string $template = '';

    public function render(string $name, $params = []): string
    {
        $this->data     = (array) $params;
        $this->template = $name;

        return $name;
    }

    public function addPath(string $path, ?string $namespace = null): void
    {
    }

    /** @return TemplatePath[] */
    public function getPaths(): array
    {
        return [];
    }

    public function addDefaultParam(string $templateName, string $param, mixed $value): void
    {
    }
}
