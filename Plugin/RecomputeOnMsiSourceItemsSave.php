<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Plugin;

use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\EligibilityEvaluator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Recompute NDE eligibility after Magento MSI source-items save (v1.6.0).
 *
 * NDE's original observers (in etc/events.xml) only catch two events:
 *
 *   - cataloginventory_stock_item_save_after  →  fires on direct legacy
 *                                                stock_item saves
 *   - catalog_product_save_after              →  fires on product entity save
 *
 * On Magento 2.3+ with MSI installed, most stock-changing flows go through
 * Magento\InventoryApi\Api\SourceItemsSaveInterface, which does NOT trigger
 * cataloginventory_stock_item_save_after. The legacy stock_item table is
 * updated later by MSI's sync indexer, but that sync runs without firing
 * a save event our observer can listen to.
 *
 * Concrete flows that silently bypassed the recompute pre-v1.6.0:
 *
 *   - Order shipment → MSI reservation collapse → indexer-only update
 *   - Refund / credit memo restocking
 *   - Source-items save via REST/SOAP/GraphQL API
 *   - Admin "Manage Stock" screen on a single source
 *   - Bulk import that pushes to MSI source-items endpoint
 *
 * Symptom: products with stock that has dropped to 0 still display
 * `next_day_eligible = 1` until something else (a product save, a stock-item
 * direct save, the verify CLI as a side effect) happens to trigger a
 * recompute.
 *
 * Fix: this plugin runs AFTER SourceItemsSaveInterface::execute, resolves
 * the affected SKUs to product IDs, and calls EligibilityEvaluator on each.
 *
 * Soft-installed: if MSI isn't on the system (rare on modern Magento, but
 * possible on stripped builds), the di.xml `<type>` declaration targets a
 * non-existent interface and Magento silently ignores it — the plugin code
 * itself never runs.
 *
 * @see EligibilityEvaluator::evaluateById()
 */
class RecomputeOnMsiSourceItemsSave
{
    public function __construct(
        private readonly Config $config,
        private readonly EligibilityEvaluator $evaluator,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param object $subject       Magento\InventoryApi\Api\SourceItemsSaveInterface (typed loose to keep
     *                              this file installable on non-MSI builds)
     * @param mixed  $result        The return value of SourceItemsSaveInterface::execute()
     * @param array  $sourceItems   The source items that were saved
     * @return mixed
     */
    public function afterExecute($subject, $result, array $sourceItems = [])
    {
        if (!$this->config->isEnabled()) {
            return $result;
        }

        if (empty($sourceItems)) {
            return $result;
        }

        // De-dupe SKUs across the saved batch — a single product can have
        // multiple source items in one save call (one per warehouse).
        $skus = [];
        foreach ($sourceItems as $item) {
            try {
                $sku = method_exists($item, 'getSku') ? (string) $item->getSku() : '';
            } catch (\Throwable $e) {
                continue;
            }
            if ($sku !== '') {
                $skus[$sku] = true;
            }
        }

        foreach (array_keys($skus) as $sku) {
            try {
                $product = $this->productRepository->get($sku);
                $productId = (int) $product->getId();
                if ($productId > 0) {
                    $this->evaluator->evaluateById($productId);
                }
            } catch (NoSuchEntityException $e) {
                // SKU saved as source-item but no matching catalog product — skip silently
                continue;
            } catch (\Throwable $e) {
                $this->logger->error(
                    'ETechFlow_NextDayEligibility: Error recomputing eligibility after MSI source-items save.',
                    ['sku' => $sku, 'exception' => $e->getMessage()]
                );
            }
        }

        return $result;
    }
}
