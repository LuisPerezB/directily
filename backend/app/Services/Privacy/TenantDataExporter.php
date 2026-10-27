<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Tenant;
use App\Models\TenantExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Genera un ZIP con CSVs de todos los datos de la empresa (portabilidad, LOPDP).
 * Los nombres de clientes se exportan descifrados porque pertenecen a la empresa titular;
 * las cédulas/RUC nunca se almacenaron en claro y solo existe su huella.
 */
final class TenantDataExporter
{
    /** tabla => columnas exportadas (sin secretos ni credenciales). */
    private const TABLES = [
        'branches' => ['id', 'code', 'name', 'province', 'canton', 'commercial_zone', 'address', 'is_active', 'created_at'],
        'product_categories' => ['id', 'parent_id', 'code', 'name'],
        'products' => ['id', 'sku', 'name', 'category_id', 'unit', 'brand', 'current_price', 'current_cost', 'is_active', 'created_at'],
        'customer_segments' => ['id', 'code', 'name'],
        'customers' => ['id', 'external_code', 'segment_id', 'customer_type', 'province', 'canton', 'commercial_zone', 'created_at'],
        'suppliers' => ['id', 'code', 'name', 'tax_id', 'country', 'default_lead_time_days'],
        'sales' => ['id', 'branch_id', 'document_type', 'document_number', 'status', 'issued_at', 'sale_date', 'customer_id', 'channel', 'currency', 'subtotal', 'discount_total', 'tax_total', 'total'],
        'sale_items' => ['id', 'sale_id', 'line_number', 'product_id', 'branch_id', 'sale_date', 'quantity', 'unit_price', 'discount_amount', 'net_amount', 'tax_amount', 'unit_cost', 'is_return'],
        'inventory_snapshots' => ['id', 'branch_id', 'product_id', 'snapshot_date', 'on_hand', 'reserved', 'in_transit', 'unit_cost'],
        'supplier_lead_times' => ['id', 'supplier_id', 'product_id', 'branch_id', 'lead_time_days', 'lead_time_std_days', 'source'],
        'promotions' => ['id', 'name', 'promo_type', 'discount_pct', 'starts_on', 'ends_on', 'branch_id', 'product_id', 'category_id'],
        'product_price_history' => ['id', 'product_id', 'branch_id', 'price', 'currency', 'valid_from', 'valid_to', 'source'],
        'purchase_cost_history' => ['id', 'product_id', 'supplier_id', 'unit_cost', 'currency', 'valid_from', 'source'],
        'import_jobs' => ['id', 'entity_type', 'original_filename', 'file_hash', 'status', 'created_at', 'finished_at'],
        'forecast_runs' => ['id', 'version', 'run_type', 'status', 'horizon_days', 'cutoff_date', 'feature_set_version', 'is_current', 'created_at', 'completed_at'],
        'recommendations' => ['id', 'forecast_run_id', 'branch_id', 'product_id', 'type', 'suggested_qty', 'risk_level', 'status', 'created_at'],
        'audit_logs' => ['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'created_at'],
    ];

    public function export(TenantExport $export): void
    {
        $tenant = Tenant::query()->findOrFail($export->tenant_id);
        $disk = Storage::disk((string) config('predictily.imports.disk', 'local'));
        $relative = sprintf('tenants/%d/exports/export-%d-%s.zip', $tenant->id, $export->id, now()->format('YmdHis'));
        $disk->makeDirectory(dirname($relative));
        $zipPath = $disk->path($relative);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo de exportación.');
        }
        $tmpFiles = [];
        $summary = [];
        try {
            foreach (self::TABLES as $table => $columns) {
                $tmp = tempnam(sys_get_temp_dir(), 'pexp');
                $tmpFiles[] = $tmp;
                $out = fopen($tmp, 'wb');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $columns, ',', '"', '');
                $count = 0;
                DB::table($table)->where('tenant_id', $tenant->id)->select($columns)->orderBy('id')
                    ->chunkById(5000, function ($rows) use ($out, &$count): void {
                        foreach ($rows as $row) {
                            fputcsv($out, array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, (array) $row), ',', '"', '');
                            $count++;
                        }
                    });
                fclose($out);
                $zip->addFile($tmp, $table.'.csv');
                $summary[$table] = $count;
            }
            // Nombres de clientes (cifrados en reposo) en archivo separado.
            $tmp = tempnam(sys_get_temp_dir(), 'pexp');
            $tmpFiles[] = $tmp;
            $out = fopen($tmp, 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['customer_id', 'name'], ',', '"', '');
            DB::table('customers')->where('tenant_id', $tenant->id)->whereNotNull('name_encrypted')->orderBy('id')
                ->chunkById(2000, function ($rows) use ($out): void {
                    foreach ($rows as $row) {
                        try {
                            $name = decrypt($row->name_encrypted, false);
                        } catch (\Throwable) {
                            $name = null;
                        }
                        fputcsv($out, [$row->id, $name], ',', '"', '');
                    }
                });
            fclose($out);
            $zip->addFile($tmp, 'customer_names.csv');
            $zip->addFromString('LEEME.txt', implode("\n", [
                'Exportación de datos de la empresa '.$tenant->name,
                'Generada: '.now($tenant->timezone)->toIso8601String(),
                'Contenido: un CSV por tabla (UTF-8). Las cédulas/RUC de clientes no se almacenan en claro en Predictily; solo existe una huella irreversible.',
            ]));
            $zip->addFromString('tenant.json', json_encode([
                'uuid' => $tenant->uuid, 'name' => $tenant->name, 'legal_name' => $tenant->legal_name, 'tax_id' => $tenant->tax_id,
                'sector' => $tenant->sector, 'settings' => $tenant->effectiveSettings(), 'allow_shared_training' => $tenant->allow_shared_training,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $zip->close();
        } finally {
            foreach ($tmpFiles as $file) {
                @unlink($file);
            }
        }

        $export->forceFill([
            'status' => 'completed',
            'path' => $relative,
            'file_size' => filesize($zipPath) ?: null,
            'file_hash' => hash_file('sha256', $zipPath) ?: null,
            'summary' => $summary,
            'expires_at' => now()->addDays((int) config('predictily.privacy.export_retention_days', 7)),
        ])->save();
    }
}
