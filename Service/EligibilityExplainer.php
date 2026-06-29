<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Service;

use ETechFlow\NextDayEligibility\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Eav\Model\Config as EavConfig;

/**
 * Plain-English explanation of why a product is/isn't next-day eligible.
 * Powers the live status panel on the product edit page.
 *
 * IMPORTANT: this MUST mirror EligibilityEvaluator::isStockEligible() exactly,
 * in the same precedence order, or the admin panel lies about what the
 * storefront badge will actually show. The shared precedence is:
 *
 *   1. force_standard_shipping_only = 1  => ALWAYS ineligible (merchant override).
 *   2. drop_ship_eligible          = 1  => ALWAYS eligible (manual override).
 *   3. supplier mode + supplier qualifies => eligible.
 *   4. stock fallback: in stock AND qty > 0 => eligible, else not.
 */
class EligibilityExplainer
{
    public function __construct(
        private readonly Config $config,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @return array{eligible:bool, headline:string, reasons:string[], notes:string[]}
     */
    public function explain(ProductInterface $product, ?int $storeId = null): array
    {
        if (!$this->config->isEnabled($storeId)) {
            return [
                'eligible'   => false,
                'headline'   => 'Module is currently disabled.',
                'reasons'    => [],
                'notes'      => ['Turn it on under Stores → Configuration → eTechFlow → Next Day Eligibility.'],
            ];
        }

        $productId = (int) $product->getId();
        if ($productId === 0) {
            return [
                'eligible' => false,
                'headline' => 'Save the product first — eligibility is calculated after save.',
                'reasons'  => [],
                'notes'    => [],
            ];
        }

        $source = $this->config->getDropShipSource($storeId);
        $manualFlag = (bool) $product->getData('drop_ship_eligible');
        $forceStandard = (bool) $product->getData('force_standard_shipping_only');
        $stockItem = $this->stockRegistry->getStockItem($productId);
        $qty = (float) $stockItem->getQty();
        $isInStock = (bool) $stockItem->getIsInStock();
        $backordersAllowed = (int) $stockItem->getBackorders() > 0;
        // Stock fallback rule — identical to EligibilityEvaluator precedence 4.
        $stockEligible = $isInStock && $qty > 0;

        $reasons = [];
        $notes = [];

        $reasons[] = sprintf(
            'Stock: %s (qty: %s%s)',
            $isInStock ? 'in stock' : 'out of stock',
            $qty,
            $backordersAllowed ? ', backorders allowed' : ''
        );

        // Precedence 1: merchant override wins over everything.
        if ($forceStandard) {
            $reasons[] = 'Force Standard Shipping Only: Yes → always ineligible (merchant override)';
            return [
                'eligible' => false,
                'headline' => 'This product is NOT next-day eligible (Force Standard Shipping Only is set).',
                'reasons'  => $reasons,
                'notes'    => ['Untick "Force Standard Shipping Only" to allow next-day eligibility for this product.'],
            ];
        }

        // Precedence 2: manual drop-ship flag.
        if ($manualFlag) {
            $reasons[] = 'Drop-Ship Eligible (manual flag): Yes → always counts as next-day-able';
            return [
                'eligible' => true,
                'headline' => 'This product IS next-day eligible (manual drop-ship override).',
                'reasons'  => $reasons,
                'notes'    => $notes,
            ];
        }

        $reasons[] = 'Drop-Ship Eligible (manual flag): No';

        // Precedence 3: supplier mode (also handles the precedence-4 stock fallback internally).
        if ($source === Config::DROP_SHIP_SOURCE_SUPPLIER) {
            return $this->explainSupplierMode($product, $storeId, $reasons, $notes, $isInStock, $qty, $stockEligible);
        }

        // Precedence 3b: deny-list mode — eligible UNLESS denylisted supplier AND out of stock.
        if ($source === Config::DROP_SHIP_SOURCE_DENYLIST) {
            return $this->explainDenylistMode($product, $storeId, $reasons, $notes, $isInStock, $qty, $stockEligible);
        }

        // Precedence 4 (flag-only mode): real stock state — in stock AND qty > 0.
        $eligible = $stockEligible;
        $headline = $eligible
            ? 'This product IS next-day eligible (in stock with available quantity).'
            : 'This product is NOT next-day eligible (no available stock and no drop-ship override).';

        if (!$eligible) {
            $notes[] = 'Fix options: (1) restock so quantity is above zero, or (2) tick "Drop-Ship Eligible" if the supplier ships it directly.';
            $notes = array_merge($notes, $this->zeroQtyInStockNote($isInStock, $qty));
        }

        return [
            'eligible' => $eligible,
            'headline' => $headline,
            'reasons'  => $reasons,
            'notes'    => $notes,
        ];
    }

    /**
     * Deny-list mode explanation. Mirrors EligibilityEvaluator precedence 3b +
     * SupplierDropShipResolver::isDenylisted(): every product is eligible
     * EXCEPT a denylisted supplier that is also out of stock.
     *
     * @param string[] $reasons
     * @param string[] $notes
     * @return array{eligible:bool, headline:string, reasons:string[], notes:string[]}
     */
    private function explainDenylistMode(
        ProductInterface $product,
        ?int $storeId,
        array $reasons,
        array $notes,
        bool $isInStock,
        float $qty,
        bool $stockEligible
    ): array {
        $pairs = $this->config->getSupplierAttributePairs($storeId);
        $denylist = $this->config->getDenylistSupplierNames($storeId);
        $mode = $this->config->getSupplierMatchMode($storeId);

        $reasons[] = 'Drop-Ship Source: Supplier deny-list mode';

        if (empty($denylist)) {
            $reasons[] = 'Deny list is empty → nothing is denied.';
            return [
                'eligible' => true,
                'headline' => 'This product IS next-day eligible (deny list is empty — everything qualifies).',
                'reasons'  => $reasons,
                'notes'    => $notes,
            ];
        }

        $denySet = [];
        foreach ($denylist as $name) {
            $denySet[strtolower(trim($name))] = $name;
        }

        // Resolve the product's supplier name(s) and decide if denylisted,
        // honouring first-active-wins vs any-active semantics.
        $denylisted = false;
        $firstActiveName = null;
        foreach ($pairs as $pair) {
            $active = (bool) $product->getData($pair['active']);
            if (!$active) {
                continue;
            }
            $names = $this->resolveSupplierNames($product, $pair['name']);
            if ($firstActiveName === null) {
                $firstActiveName = $names ? implode(', ', $names) : '(no name set)';
            }
            $thisDenied = false;
            foreach ($names as $candidate) {
                if (isset($denySet[strtolower(trim($candidate))])) {
                    $thisDenied = true;
                    break;
                }
            }
            if ($mode === Config::MATCH_FIRST_ACTIVE_WINS) {
                $denylisted = $thisDenied;
                break;
            }
            if ($thisDenied) {
                $denylisted = true;
                break;
            }
        }

        $reasons[] = $firstActiveName === null
            ? 'Supplier: none set → not on deny list.'
            : sprintf('Supplier: %s → %s.', $firstActiveName, $denylisted ? 'ON the deny list' : 'not on the deny list');

        if (!$denylisted) {
            return [
                'eligible' => true,
                'headline' => 'This product IS next-day eligible (supplier is not on the deny list).',
                'reasons'  => $reasons,
                'notes'    => $notes,
            ];
        }

        // Denylisted supplier → only eligible if in stock with quantity.
        $reasons[] = sprintf(
            'Denylisted supplier → eligible only with stock: %s.',
            $stockEligible ? 'in stock with quantity → ELIGIBLE' : 'no available quantity → not eligible'
        );

        if ($stockEligible) {
            return [
                'eligible' => true,
                'headline' => 'This product IS next-day eligible (denylisted supplier, but in stock).',
                'reasons'  => $reasons,
                'notes'    => $notes,
            ];
        }

        $notes[] = 'To make eligible: restock so quantity is above zero, remove this supplier from the deny list, or tick "Drop-Ship Eligible".';
        $notes = array_merge($notes, $this->zeroQtyInStockNote($isInStock, $qty));

        return [
            'eligible' => false,
            'headline' => 'This product is NOT next-day eligible (denylisted supplier and out of stock).',
            'reasons'  => $reasons,
            'notes'    => $notes,
        ];
    }

    private function explainSupplierMode(
        ProductInterface $product,
        ?int $storeId,
        array $reasons,
        array $notes,
        bool $isInStock,
        float $qty,
        bool $stockEligible
    ): array {
        $pairs = $this->config->getSupplierAttributePairs($storeId);
        $qualifying = $this->config->getQualifyingSupplierNames($storeId);
        $mode = $this->config->getSupplierMatchMode($storeId);
        $modeLabel = $mode === Config::MATCH_FIRST_ACTIVE_WINS
            ? 'First active wins'
            : 'Any active qualifying';

        $reasons[] = sprintf('Drop-Ship Source: Supplier mode (match: %s)', $modeLabel);

        // Misconfiguration: supplier mode on but nothing to match against.
        // The evaluator's resolver returns false here and falls through to the
        // stock check — so we must do the same, not hard-fail to ineligible.
        if (empty($pairs) || empty($qualifying)) {
            $reasons[] = empty($pairs)
                ? 'No supplier attribute pairs configured → supplier match cannot run.'
                : 'No qualifying suppliers listed → supplier match cannot run.';
            $notes[] = empty($pairs)
                ? 'Configure "Supplier Attribute Pairs" under Drop-Ship Exception in the module settings.'
                : 'Add the supplier names that ship next-day in "Qualifying Supplier Names".';
            return $this->stockFallbackResult($reasons, $notes, $isInStock, $qty, $stockEligible);
        }

        $qualifyingSet = [];
        foreach ($qualifying as $name) {
            $qualifyingSet[strtolower(trim($name))] = $name;
        }

        $slotLines = [];
        $firstActiveSlot = null;
        $firstActiveQualifies = null;
        $anyActiveQualifying = false;
        $anyActive = false;
        $orphanedActiveSlots = [];   // active=1 but no supplier name set

        foreach ($pairs as $idx => $pair) {
            $active = (bool) $product->getData($pair['active']);
            $names = $this->resolveSupplierNames($product, $pair['name']);
            $hasName = !empty($names);
            $nameDisplay = $hasName ? implode(', ', $names) : '(no name set)';

            $qualifies = false;
            foreach ($names as $candidate) {
                if (isset($qualifyingSet[strtolower(trim($candidate))])) {
                    $qualifies = true;
                    break;
                }
            }

            $marker = $active ? '✓ active' : '○ inactive';
            $qualifierTag = '';
            if ($active) {
                if (!$hasName) {
                    $qualifierTag = ' — ⚠️ active but no supplier name set';
                    $orphanedActiveSlots[] = $idx + 1;
                } else {
                    $qualifierTag = $qualifies ? ' — qualifies for next-day' : ' — NOT in qualifying list';
                }
            }

            $slotLines[] = sprintf(
                'Slot %d (%s / %s): %s [%s]%s',
                $idx + 1,
                $pair['active'],
                $pair['name'],
                $nameDisplay,
                $marker,
                $qualifierTag
            );

            if ($active) {
                $anyActive = true;
                if ($firstActiveSlot === null) {
                    $firstActiveSlot = $idx + 1;
                    $firstActiveQualifies = $qualifies;
                }
                if ($qualifies) {
                    $anyActiveQualifying = true;
                }
            }
        }

        $reasons = array_merge($reasons, $slotLines);

        if (!empty($orphanedActiveSlots)) {
            $slotsList = implode(', ', array_map(static fn($i) => "slot {$i}", $orphanedActiveSlots));
            $notes[] = sprintf(
                'Data inconsistency: %s ticked active but no supplier name is set. Either pick a supplier name for %s, or untick the active flag.',
                $slotsList,
                count($orphanedActiveSlots) === 1 ? 'it' : 'them'
            );
        }

        // Determine supplier qualification (precedence 3) — mirrors the resolver.
        $supplierQualifies = false;
        if (!$anyActive) {
            $reasons[] = 'No supplier slot is active on this product → does not qualify via supplier.';
        } elseif ($mode === Config::MATCH_FIRST_ACTIVE_WINS) {
            if ($firstActiveQualifies) {
                $supplierQualifies = true;
                $reasons[] = sprintf('Slot %d is the first active supplier and it\'s in the qualifying list → qualifies via supplier.', $firstActiveSlot);
            } else {
                $reasons[] = sprintf('Slot %d is the first active supplier but it\'s NOT in the qualifying list → does not qualify via supplier.', $firstActiveSlot);
                $notes[] = sprintf('To qualify via supplier: change which supplier is at slot %d, or add this supplier name to the qualifying list.', $firstActiveSlot);
            }
        } else {
            if ($anyActiveQualifying) {
                $supplierQualifies = true;
                $reasons[] = 'At least one active supplier is in the qualifying list → qualifies via supplier.';
            } else {
                $reasons[] = 'No active supplier is in the qualifying list → does not qualify via supplier.';
                $notes[] = 'To qualify via supplier: tick a slot whose supplier appears in your qualifying list, OR add one of the active suppliers to the qualifying list.';
            }
        }

        if ($supplierQualifies) {
            return [
                'eligible' => true,
                'headline' => 'This product IS next-day eligible (qualifying supplier ships next-day).',
                'reasons'  => $reasons,
                'notes'    => $notes,
            ];
        }

        // Precedence 4: supplier didn't qualify → fall back to real stock state.
        return $this->stockFallbackResult($reasons, $notes, $isInStock, $qty, $stockEligible);
    }

    /**
     * Shared precedence-4 stock fallback used by both flag and supplier modes
     * once the higher-precedence overrides have been ruled out.
     *
     * @param string[] $reasons
     * @param string[] $notes
     * @return array{eligible:bool, headline:string, reasons:string[], notes:string[]}
     */
    private function stockFallbackResult(
        array $reasons,
        array $notes,
        bool $isInStock,
        float $qty,
        bool $stockEligible
    ): array {
        $reasons[] = sprintf(
            'Stock fallback: %s.',
            $stockEligible
                ? 'in stock with quantity above zero → ELIGIBLE'
                : 'no available quantity → not eligible'
        );

        if ($stockEligible) {
            return [
                'eligible' => true,
                'headline' => 'This product IS next-day eligible (in stock with available quantity).',
                'reasons'  => $reasons,
                'notes'    => $notes,
            ];
        }

        $notes[] = 'To make eligible: restock so quantity is above zero, add this product\'s supplier to the qualifying list, or tick "Drop-Ship Eligible".';
        $notes = array_merge($notes, $this->zeroQtyInStockNote($isInStock, $qty));

        return [
            'eligible' => false,
            'headline' => 'This product is NOT next-day eligible.',
            'reasons'  => $reasons,
            'notes'    => $notes,
        ];
    }

    /**
     * Warn about the in-stock-but-zero-qty trap (Manage Stock off): the legacy
     * is_in_stock flag reads "in stock" while real quantity is 0, which is the
     * #1 source of "why isn't my badge showing?" confusion.
     *
     * @return string[]
     */
    private function zeroQtyInStockNote(bool $isInStock, float $qty): array
    {
        if ($isInStock && $qty <= 0) {
            return ['Note: this product shows "in stock" but quantity is 0 (Manage Stock is likely off). Next-day eligibility still requires real quantity above zero.'];
        }
        return [];
    }

    /**
     * Mirror SupplierDropShipResolver::resolveSupplierNames for explainer.
     * Returns 1+ name candidates from a name-attribute that could be text /
     * dropdown / multiselect.
     *
     * @return string[]
     */
    private function resolveSupplierNames(ProductInterface $product, string $attrCode): array
    {
        $raw = $product->getData($attrCode);
        if ($raw === null || $raw === '' || $raw === false) {
            return [];
        }

        // Path 1: scalar text or numeric option id
        if (is_string($raw) && !ctype_digit($raw) && !preg_match('/^\d+(,\d+)+$/', $raw)) {
            return [trim($raw)];
        }

        // Path 2 / 3: dropdown (id) or multiselect (csv ids)
        try {
            $attr = $this->eavConfig->getAttribute('catalog_product', $attrCode);
            if (!$attr || !$attr->getId()) {
                return is_string($raw) ? [trim($raw)] : [];
            }
            $source = $attr->getSource();
            $ids = is_array($raw) ? $raw : explode(',', (string) $raw);
            $labels = [];
            foreach ($ids as $id) {
                $id = trim((string) $id);
                if ($id === '') {
                    continue;
                }
                $label = $source ? (string) $source->getOptionText($id) : '';
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
            return $labels;
        } catch (\Throwable $e) {
            return is_string($raw) ? [trim($raw)] : [];
        }
    }
}
