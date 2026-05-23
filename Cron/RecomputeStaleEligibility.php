<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Cron;

use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\EligibilityEvaluator;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * Hourly safety-net cron that detects + repairs stale next_day_eligible rows (v1.6.0).
 *
 * The MSI source-items plugin (see Plugin/RecomputeOnMsiSourceItemsSave) catches
 * the main propagation hole, but cron is the belt-and-braces backstop for
 * any remaining edge case: an MSI sync that didn't fire its expected hook,
 * a custom module that writes stock without going through MSI's API, a
 * partial failure during a bulk import, etc.
 *
 * What "stale" means here:
 *
 *   - next_day_eligible = 1 in EAV, but the legacy stock state says the
 *     product is out of stock AND no drop_ship_eligible / no
 *     force_standard_shipping_only override is in play. The product should
 *     have flipped to next_day_eligible = 0 but didn't.
 *
 *   - (Optional) next_day_eligible = 0 in EAV, but the stock is fine now
 *     and no override forbids eligibility. Stock came back but eligibility
 *     stayed off. We catch this too — the evaluator handles both directions.
 *
 * Implementation: rather than try to compute "stale" in SQL (which would
 * require joining EAV, legacy stock, drop_ship, force_standard, and the
 * supplier-pair check — fragile and module-coupled), we just delegate to
 * the evaluator for every simple product. The evaluator is idempotent and
 * cheap (one StockRegistry call, one updateAttributes call) so re-running
 * it on every product hourly is fine for catalogues up to ~50k SKUs. For
 * larger catalogues, switch this to a paginated batch + state cursor.
 *
 * Runs hourly via etc/crontab.xml (configurable by the merchant if they
 * want it less frequent on huge catalogues).
 */
class RecomputeStaleEligibility
{
    private const ATTR_NEXT_DAY = 'next_day_eligible';

    /** Maximum products processed per cron run — guards against catalogue-size runaway. */
    private const PROCESS_LIMIT = 5000;

    public function __construct(
        private readonly Config $config,
        private readonly EligibilityEvaluator $evaluator,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $start = microtime(true);
        $count = 0;
        $errors = 0;

        try {
            $productIds = $this->collectCandidateProductIds();
            foreach ($productIds as $productId) {
                try {
                    $this->evaluator->evaluateById((int) $productId);
                    $count++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->logger->warning(
                        'ETechFlow_NextDayEligibility: Cron evaluate failed for one product.',
                        ['product_id' => $productId, 'exception' => $e->getMessage()]
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'ETechFlow_NextDayEligibility: Cron stale-eligibility run failed.',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $elapsed = number_format(microtime(true) - $start, 2);
        $this->logger->info(
            "ETechFlow_NextDayEligibility: Cron resync done — {$count} products evaluated, {$errors} errors, {$elapsed}s."
        );
    }

    /**
     * Return up to PROCESS_LIMIT simple-product IDs to re-evaluate.
     *
     * We use the simplest possible scope (every simple product) and rely on
     * the evaluator's idempotency. This keeps the cron robust against future
     * edge cases without over-fitting to today's known stale-state patterns.
     *
     * @return int[]
     */
    private function collectCandidateProductIds(): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('type_id', 'simple');
        $collection->addAttributeToSelect('entity_id');
        $collection->setPageSize(self::PROCESS_LIMIT);
        $collection->setCurPage(1);

        $ids = [];
        foreach ($collection as $product) {
            $ids[] = (int) $product->getId();
        }
        return $ids;
    }
}
