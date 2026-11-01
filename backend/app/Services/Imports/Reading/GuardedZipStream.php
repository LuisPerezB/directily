<?php

declare(strict_types=1);

namespace App\Services\Imports\Reading;

use ZipArchive;

/**
 * Envoltorio de flujo `zip://` que instala ZipReadGuard mientras OpenSpout abre y lee un libro. Solo sirve partes del libro
 * que la guarda está leyendo (las que existen en su directorio ya verificado) y reporta cada lectura a la guarda, que corta la
 * lectura al pasar de los presupuestos. Lee con ZipArchive::getStream, que no depende de los envoltorios registrados.
 *
 * Contrato de streams de usuario de PHP: los métodos se llaman desde libxml (XMLReader::open('zip://…')).
 *
 * @internal
 */
final class GuardedZipStream
{
    /** @var resource|null requerido por PHP para los envoltorios de usuario */
    public $context;

    /** @var resource|null */
    private $handle = null;

    private ?ZipArchive $zip = null;

    private ?ZipReadGuard $guard = null;

    private int $declared = 0;

    private bool $sheet = false;

    private int $read = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $guard = ZipReadGuard::installed();
        $hash = strpos($path, '#');
        if ($guard === null || ! str_starts_with($path, 'zip://') || $hash === false || str_contains($mode, 'w') || str_contains($mode, 'a') || str_contains($mode, '+')) {
            return false;
        }
        $file = substr($path, 6, $hash - 6);
        $entry = substr($path, $hash + 1);
        $info = $guard->authorize($file, $entry);
        if ($info === null) {
            return false;
        }
        $zip = new ZipArchive;
        if ($zip->open($guard->path(), ZipArchive::RDONLY) !== true) {
            return false;
        }
        $handle = $zip->getStream($entry);
        if (! is_resource($handle)) {
            $zip->close();

            return false;
        }
        [$this->zip, $this->handle, $this->guard, $this->declared, $this->sheet] = [$zip, $handle, $guard, $info['declared'], $info['sheet']];

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->handle === null || $this->guard === null) {
            return false;
        }
        $chunk = fread($this->handle, $count);
        if ($chunk === false) {
            return false;
        }
        $this->read += strlen($chunk);
        $this->guard->onRead($this->read, $this->declared, $this->sheet);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->handle === null || feof($this->handle);
    }

    public function stream_close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->zip?->close();
        $this->handle = null;
        $this->zip = null;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => $this->declared, 'mode' => 0100444];
    }

    /** @return array<string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        $guard = ZipReadGuard::installed();
        $hash = strpos($path, '#');
        if ($guard === null || ! str_starts_with($path, 'zip://') || $hash === false) {
            return false;
        }
        $info = $guard->lookup(substr($path, 6, $hash - 6), substr($path, $hash + 1));

        return $info === null ? false : ['size' => $info['declared'], 'mode' => 0100444];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }
}
