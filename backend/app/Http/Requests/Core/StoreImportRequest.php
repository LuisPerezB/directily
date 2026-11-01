<?php

declare(strict_types=1);

namespace App\Http\Requests\Core;

use App\Services\Imports\ImportEntity;
use App\Services\Imports\Reading\ReaderFactory;
use App\Services\Imports\Reading\UnsafeSpreadsheetException;
use App\Services\Imports\Reading\ZipArchiveInspector;
use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

final class StoreImportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) config('predictily.imports.max_file_size_kb', 20480), 'extensions:csv,txt,tsv,xlsx'],
            'entity_type' => ['required', Rule::in(ImportEntity::values())],
            'data_source_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('data_sources')],
        ];
    }

    /**
     * Endurecimiento de cargas (ADR-V25-15): un XLSX es un ZIP y se inspecciona ANTES de guardarlo y de analizarlo (nº de entradas,
     * tamaño descomprimido, razón de compresión, archivos anidados y, sin fiarse del directorio central, los bytes REALMENTE
     * descomprimidos de cada parte). Los metadatos cuestan milisegundos y el recuento real ≈ 0,1 s para el histórico real; un libro que
     * no cumple se rechaza con 413 (tamaño descomprimido/razón) o 422 y un código estable `xlsx_*`, sin crear importación ni guardar el archivo.
     *
     * @throws UnsafeSpreadsheetException
     */
    protected function passedValidation(): void
    {
        $file = $this->file('file');
        if ($file instanceof UploadedFile && ReaderFactory::formatFromExtension($file->getClientOriginalExtension()) === 'xlsx') {
            ZipArchiveInspector::fromConfig()->inspect((string) $file->getRealPath());
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.max' => 'El archivo supera el tamaño máximo permitido (20 MB).',
            'file.extensions' => 'Formato no soportado. Use CSV o XLSX.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['file' => 'archivo', 'entity_type' => 'tipo de datos'];
    }
}
