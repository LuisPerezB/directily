<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Services\Imports\Processors\BranchImportProcessor;
use App\Services\Imports\Processors\ImportProcessor;
use App\Services\Imports\Processors\InventoryImportProcessor;
use App\Services\Imports\Processors\LeadTimeImportProcessor;
use App\Services\Imports\Processors\ProductImportProcessor;
use App\Services\Imports\Processors\PromotionImportProcessor;
use App\Services\Imports\Processors\SalesImportProcessor;
use App\Services\Imports\Processors\SupplierImportProcessor;

final class ImportProcessorFactory
{
    public function for(ImportEntity $entity): ImportProcessor
    {
        return match ($entity) {
            ImportEntity::Sales => app(SalesImportProcessor::class),
            ImportEntity::Products => app(ProductImportProcessor::class),
            ImportEntity::Inventory => app(InventoryImportProcessor::class),
            ImportEntity::LeadTimes => app(LeadTimeImportProcessor::class),
            ImportEntity::Branches => app(BranchImportProcessor::class),
            ImportEntity::Suppliers => app(SupplierImportProcessor::class),
            ImportEntity::Promotions => app(PromotionImportProcessor::class),
        };
    }
}
