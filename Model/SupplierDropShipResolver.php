<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a product qualifies as drop-ship-eligible based on its
 * supplier attributes, configured per-store in admin.
 *
 * Reads the configured `<active_attr>:<name_attr>` pairs (see Config::
 * getSupplierAttributePairs) and the list of qualifying supplier names
 * (Config::getQualifyingSupplierNames). For a given product, walks every
 * pair and returns true if any pair has `active = 1` AND `name` (case-
 * insensitive, trimmed) is in the qualifying list.
 *
 * Module-agnostic by design — no Keystation-specific attribute names or
 * supplier values appear in this file. Everything is data-driven from
 * the admin config so any merchant can plug in their own attribute
 * structure.
 *
 * Failure modes are silent:
 *   - Missing attribute on the product → that pair contributes false
 *   - No pairs configured             → returns false (caller falls back)
 *   - No qualifying names configured  → returns false (supplier mode no-op)
 *
 * Per-request memoization on (productId × storeId) keeps the cost low
 * when called multiple times in a single checkout/admin save chain.
 */
class SupplierDropShipResolver
{
    /** @var array<string, bool> */
    private array $cache = [];

    /**
     * Constructor.
     *
     * @param Config                   $config
     * @param ProductCollectionFactory $productCollectionFactory
     * @param LoggerInterface          $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly LoggerInterface $logger,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @param int      $productId
     * @param int|null $storeId   Used for per-store config scope.
     * @return bool
     */
    public function isDropShipEligible(int $productId, ?int $storeId = null): bool
    {
        $cacheKey = $productId . ':' . ($storeId ?? '_');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $pairs = $this->config->getSupplierAttributePairs($storeId);
        $qualifying = $this->config->getQualifyingSupplierNames($storeId);

        if (empty($pairs) || empty($qualifying)) {
            return $this->cache[$cacheKey] = false;
        }

        // Build a case-insensitive set so the membership check is O(1) per pair.
        $qualifyingSet = [];
        foreach ($qualifying as $name) {
            $qualifyingSet[strtolower(trim($name))] = true;
        }

        // Collect every attribute code we need so we can load them in one query.
        $attrCodes = [];
        foreach ($pairs as $pair) {
            $attrCodes[$pair['active']] = true;
            $attrCodes[$pair['name']]   = true;
        }

        try {
            $product = $this->loadProduct($productId, array_keys($attrCodes));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_NextDayEligibility: supplier resolver failed to load product attributes; treating as not eligible.',
                ['product_id' => $productId, 'exception' => $e->getMessage()]
            );
            return $this->cache[$cacheKey] = false;
        }

        if ($product === null) {
            return $this->cache[$cacheKey] = false;
        }

        $mode = $this->config->getSupplierMatchMode($storeId);

        foreach ($pairs as $pair) {
            $active = $product->getData($pair['active']);
            if (!$active) {
                continue;
            }

            // v1.6.3: support both text + dropdown + multiselect name attributes.
            // resolveSupplierNames() returns 1+ candidate names; we check whether
            // ANY of them matches the qualifying list (for multiselect support).
            $candidates = $this->resolveSupplierNames($product, $pair['name'], $productId);

            if (empty($candidates)) {
                if ($mode === Config::MATCH_FIRST_ACTIVE_WINS) {
                    // First-active-wins: this is the supplier we'd ship from. No
                    // resolvable name → can't confirm next-day-capable → not
                    // eligible. Don't fall through.
                    return $this->cache[$cacheKey] = false;
                }
                continue;
            }

            $isQualifying = false;
            foreach ($candidates as $candidate) {
                $normalised = strtolower(trim($candidate));
                if ($normalised !== '' && isset($qualifyingSet[$normalised])) {
                    $isQualifying = true;
                    break;
                }
            }

            if ($mode === Config::MATCH_FIRST_ACTIVE_WINS) {
                // v1.6.3+ semantics: the first active slot is the supplier we'll
                // actually ship from. Eligibility = is THAT supplier in the
                // qualifying list? Stop iterating regardless.
                return $this->cache[$cacheKey] = $isQualifying;
            }

            // ANY_ACTIVE_QUALIFYING (legacy): keep iterating after a non-qualifying
            // active slot — find any active+qualifying pair.
            if ($isQualifying) {
                return $this->cache[$cacheKey] = true;
            }
        }

        return $this->cache[$cacheKey] = false;
    }

    /**
     * Resolve a name-attribute's value into one or more candidate supplier names.
     *
     * Handles three attribute shapes (v1.6.3):
     *
     *   - Text attribute (free-text supplier name like "Auto remote man")
     *     → returns [$rawValue]
     *   - Single-select dropdown (option ID like 42)
     *     → looks up option label via EAV source → returns [$label]
     *   - Multi-select (comma-separated option IDs like "42,55")
     *     → looks up each label → returns [$label1, $label2, ...]
     *
     * Pre-v1.6.3 this method didn't exist — the resolver had a hard
     * `is_string($name)` guard that bailed on any dropdown attribute.
     * That meant supplier-mode silently never matched on stores using
     * dropdown attributes for supplier names, which is the common case.
     *
     * @return string[] zero or more candidate names; empty = couldn't resolve
     */
    private function resolveSupplierNames(
        \Magento\Catalog\Api\Data\ProductInterface $product,
        string $attrCode,
        int $productId
    ): array {
        $raw = $product->getData($attrCode);

        if ($raw === null || $raw === '' || $raw === false) {
            return [];
        }

        // Path 1: text attribute storing a literal supplier-name string.
        if (is_string($raw) && !is_numeric($raw) && !$this->looksLikeIdList($raw)) {
            return [$raw];
        }

        // Path 2 + 3: dropdown / multiselect — resolve via EAV source.
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $attrCode);
            if ($attribute && $attribute->getId() && $attribute->usesSource()) {
                $source = $attribute->getSource();
                $label  = $source->getOptionText($raw);

                if (is_string($label) && $label !== '') {
                    return [$label];
                }
                if (is_array($label) && !empty($label)) {
                    // Multiselect — getOptionText returns an array of labels
                    return array_values(array_filter(array_map(
                        static fn($v) => is_scalar($v) ? (string) $v : '',
                        $label
                    )));
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'ETechFlow_NextDayEligibility: failed to resolve supplier name from EAV source.',
                [
                    'product_id' => $productId,
                    'attr_code'  => $attrCode,
                    'exception'  => $e->getMessage(),
                ]
            );
        }

        // Fallback: cast to string and hope it matches (it won't if it's still
        // a numeric option id, but at least we logged the EAV lookup failure).
        if (is_scalar($raw)) {
            return [(string) $raw];
        }

        $this->logger->debug(
            'ETechFlow_NextDayEligibility: supplier name attribute returned non-scalar value; skipping pair.',
            ['product_id' => $productId, 'attr_code' => $attrCode, 'value_type' => gettype($raw)]
        );
        return [];
    }

    /**
     * Heuristic: does a string look like a comma-separated list of option IDs
     * (e.g. "42,55,103") rather than a literal supplier name? Used to route
     * multiselect-as-string values through the EAV source path instead of
     * the text path.
     */
    private function looksLikeIdList(string $value): bool
    {
        // Empty or no comma → not a list
        if (!str_contains($value, ',')) {
            return false;
        }
        foreach (explode(',', $value) as $piece) {
            if (!is_numeric(trim($piece))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Reset the per-request cache. Useful for long-running CLI processes
     * (cron tasks, batch reindex) that touch many products and would
     * otherwise hold every result for the lifetime of the process.
     *
     * @return void
     */
    public function resetCache(): void
    {
        $this->cache = [];
    }

    /**
     * Load a single product with only the attributes we need. Returns null
     * when the product no longer exists.
     *
     * @param int      $productId
     * @param string[] $attrCodes
     * @return \Magento\Catalog\Api\Data\ProductInterface|null
     */
    private function loadProduct(int $productId, array $attrCodes)
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter([$productId]);
        foreach ($attrCodes as $code) {
            $collection->addAttributeToSelect($code);
        }
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        return $item->getId() ? $item : null;
    }
}
