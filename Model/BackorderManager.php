<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model;

use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Psr\Log\LoggerInterface;

/**
 * Updates a product's Backorders setting on its stock item.
 *
 * Called by UpdateOnProductSave when Drop-Ship Eligible changes:
 *  - Drop-Ship turned ON  → set Backorders to "Allow Qty Below 0" (value 2)
 *                            so the product is purchasable when local stock is zero
 *                            (supplier still ships, so customer can buy)
 *  - Drop-Ship turned OFF → set Backorders to "Use Config" (value 0)
 *                            reverts to whatever the store-wide default is
 *
 * Behind a config toggle: Stores → Configuration → eTechFlow → Next Day Eligibility →
 *   Drop-Ship Exception → "Auto-Enable Backorders for Drop-Ship Products"
 * (default: Yes — most merchant-friendly behaviour out of the box).
 *
 * Why this exists: without it, the Drop-Ship Eligible flag only changed the
 * "Next Day Eligible" badge but didn't change Magento's stock check. Customers
 * saw a "Next Day Eligible" badge but couldn't add OOS drop-ship products to
 * cart — a confusing UX. This module fixes that by aligning Magento's stock
 * behaviour with the merchant's drop-ship intent.
 */
class BackorderManager
{
    /** Magento's "Allow Qty Below 0" — product remains sellable when stock hits zero. */
    public const BACKORDERS_ALLOW_QTY_BELOW_ZERO = 2;

    /** Magento's "Use Config" — fall back to the store-wide default Backorders setting. */
    public const BACKORDERS_USE_CONFIG = 0;

    /**
     * Constructor.
     *
     * @param StockRegistryInterface       $stockRegistry
     * @param StockItemRepositoryInterface $stockItemRepository
     * @param LoggerInterface              $logger
     */
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StockItemRepositoryInterface $stockItemRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Set the Backorders flag on a product's stock item to match its Drop-Ship Eligible state.
     *
     * @param int  $productId
     * @param bool $dropShipEligible When true, allow backorders. When false, revert to use-config.
     * @return void
     */
    public function syncBackordersWithDropShip(int $productId, bool $dropShipEligible): void
    {
        try {
            $stockItem = $this->stockRegistry->getStockItem($productId);
            if (!$stockItem || !$stockItem->getItemId()) {
                return;
            }

            $desired = $dropShipEligible
                ? self::BACKORDERS_ALLOW_QTY_BELOW_ZERO
                : self::BACKORDERS_USE_CONFIG;

            $current = (int) $stockItem->getBackorders();

            // v1.7.2 fix: detect whether we actually need to write something.
            // We write when EITHER the backorders flag needs flipping OR (drop-ship is
            // on AND is_in_stock is currently 0). The latter case is the one that the
            // pre-patch code missed — a Magento admin save with qty=0 + backorders=No
            // had already flipped is_in_stock to 0 before this observer ran, leaving
            // drop-ship products silently unsalable despite backorders=Allow.
            $needsBackordersWrite  = ($current !== $desired);
            $needsStockStatusWrite = $dropShipEligible && !$stockItem->getIsInStock();

            if (!$needsBackordersWrite && !$needsStockStatusWrite) {
                return;
            }

            $stockItem->setBackorders($desired);

            // Also flip "Use Config" so our explicit value isn't overridden by store defaults
            $stockItem->setUseConfigBackorders($dropShipEligible ? false : true);

            // v1.7.2 fix: when enabling backorders for a drop-ship product, also force
            // is_in_stock=1. Without this, Magento's stock-sync rule (qty=0 +
            // manage_stock=Yes + backorders=No at the moment of admin save) sets
            // is_in_stock=0 *before* this observer runs, and the user-facing result is
            // "drop-ship product with backorders=Allow but still shown as Out of Stock"
            // — silently breaking Test 3a's promised "Add to Cart button works".
            // When drop-ship is turned OFF we deliberately leave is_in_stock alone so
            // Magento's standard rules can govern (otherwise we'd erroneously force OOS
            // products back into stock).
            if ($dropShipEligible) {
                $stockItem->setIsInStock(true);
            }

            $this->stockItemRepository->save($stockItem);
        } catch (\Exception $e) {
            // Never crash the product save chain over a backorder update — log and continue.
            // The merchant can still manually configure backorders if this auto-sync fails.
            $this->logger->warning(
                'ETechFlow_NextDayEligibility: Could not auto-sync backorders for drop-ship product.',
                [
                    'product_id' => $productId,
                    'desired_drop_ship' => $dropShipEligible,
                    'exception' => $e->getMessage(),
                ]
            );
        }
    }
}
