<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Observer;

use ETechFlow\NextDayEligibility\Model\EligibilityEvaluator;
use ETechFlow\NextDayEligibility\Model\SupplierDropShipResolver;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;

/**
 * Recompute next_day_eligible across the catalogue when the merchant saves the
 * NDE config section (v1.8.0).
 *
 * Why: eligibility is a stored per-product attribute, so changing the rules
 * (drop-ship source, supplier pairs, qualifying / blocked lists, etc.) does
 * NOT retroactively update products — previously you had to remember to run
 * `bin/magento etechflow:nde:resync` by hand, and forgetting it made the new
 * settings look broken. This wires the resync to the Save Config click.
 *
 * Two layers, so it is both instant AND reliable:
 *
 *   1. A pending-resync flag is set immediately. The hourly cron honours it
 *      with a full, uncapped pass and clears it — the guaranteed backstop for
 *      stores without fastcgi, very large catalogues, or a worker that dies
 *      mid-run.
 *
 *   2. On php-fpm, we also run the resync right after the admin response is
 *      flushed (fastcgi_finish_request), so the change applies within seconds
 *      without the merchant staring at a spinner. If that completes, it clears
 *      the flag so the cron skips the redundant pass.
 *
 * Bound to admin_system_config_changed_section_etechflow_nextdayeligibility, so
 * it only fires when OUR section is saved.
 */
class RecomputeOnConfigChange implements ObserverInterface
{
    /** Flag set on config change; consumed by the hourly cron's full-resync path. */
    public const FLAG_RESYNC_PENDING = 'etechflow_nde_resync_pending';

    public function __construct(
        private readonly EligibilityEvaluator $evaluator,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly SupplierDropShipResolver $supplierResolver,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        // Always mark a resync pending first — the cron is the reliable backstop.
        $this->flagManager->saveFlag(self::FLAG_RESYNC_PENDING, 1);

        // Best-effort instant apply: only when we can return the response before
        // doing the heavy work, so the admin save never hangs.
        if (function_exists('fastcgi_finish_request')) {
            register_shutdown_function([$this, 'runDeferredResync']);
        }
    }

    /**
     * Post-response full resync. Public so it can be used as a shutdown callback.
     *
     * @return void
     */
    public function runDeferredResync(): void
    {
        try {
            // Flush the admin response first so the merchant isn't kept waiting.
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            // This worker is now detached from the response; let it finish.
            @set_time_limit(0);

            $count = $this->resyncAll();
            $this->flagManager->deleteFlag(self::FLAG_RESYNC_PENDING);
            $this->logger->info(
                "ETechFlow_NextDayEligibility: post-save resync applied new config to {$count} product(s)."
            );
        } catch (\Throwable $e) {
            // Leave the flag set so the hourly cron retries the full pass.
            $this->logger->warning(
                'ETechFlow_NextDayEligibility: post-save resync failed; hourly cron will retry.',
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Re-evaluate every simple product. Returns the number processed.
     *
     * @return int
     */
    private function resyncAll(): int
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addFieldToFilter('type_id', ProductType::TYPE_SIMPLE);
        $ids = $collection->getAllIds();

        $count = 0;
        foreach ($ids as $id) {
            $this->evaluator->evaluateById((int) $id);
            // Keep the per-request supplier-resolver cache from growing unbounded
            // across a full-catalogue pass.
            if ((++$count % 500) === 0) {
                $this->supplierResolver->resetCache();
            }
        }
        $this->supplierResolver->resetCache();

        return $count;
    }
}
