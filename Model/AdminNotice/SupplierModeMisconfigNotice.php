<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model\AdminNotice;

use ETechFlow\NextDayEligibility\Model\Config;
use Magento\Framework\Notification\MessageInterface;

/**
 * Admin header banner: warns when supplier-based drop-ship mode is enabled but
 * can't actually do anything because a required input is missing (v1.8.0).
 *
 * Same "silent no-op" class of bug as ShippingMethodMismatchNotice, for the
 * supplier path:
 *
 *   Before: merchant switches Drop-Ship Source to "Supplier-based" (or the
 *           denylist mode) and lists supplier names, but never sets the
 *           Supplier Attribute Pairs. The resolver has no product field to
 *           read, so it matches nothing — every product silently falls back to
 *           plain local stock and the supplier list does nothing. No error
 *           anywhere; it just looks like the feature is broken.
 *
 *   After:  red banner on every admin page naming exactly what's missing and
 *           where to fix it.
 *
 * Shown only when the module is enabled AND a supplier mode is selected AND a
 * required input is missing. Healthy / non-supplier configs get no nag.
 */
class SupplierModeMisconfigNotice implements MessageInterface
{
    private const IDENTITY = 'etechflow_nde_supplier_misconfig_v1';

    public function __construct(
        private readonly Config $config
    ) {
    }

    public function getIdentity(): string
    {
        return self::IDENTITY;
    }

    /**
     * Collect human-readable descriptions of every blocking misconfiguration.
     * Empty array = supplier mode is wired correctly (or isn't in use).
     *
     * @return string[]
     */
    private function collectIssues(): array
    {
        if (!$this->config->isEnabled()) {
            return [];
        }

        $source = $this->config->getDropShipSource();
        $supplierMode = in_array(
            $source,
            [Config::DROP_SHIP_SOURCE_SUPPLIER, Config::DROP_SHIP_SOURCE_SUPPLIER_DENYLIST],
            true
        );
        if (!$supplierMode) {
            return [];
        }

        $issues = [];

        // The critical one: no field mapping means the resolver reads nothing,
        // so the whole supplier list (qualifying or blocked) is inert.
        if (empty($this->config->getSupplierAttributePairs())) {
            $issues[] = 'no <strong>Supplier Attribute Pairs</strong> are configured, so the module has no '
                . 'product field to read the supplier from &mdash; supplier mode is matching nothing and every '
                . 'product falls back to plain local stock';

            return $issues; // nothing else matters until the mapping exists
        }

        // Mapping is present; check the list that this specific mode depends on.
        if ($source === Config::DROP_SHIP_SOURCE_SUPPLIER
            && empty($this->config->getQualifyingSupplierNames())
        ) {
            $issues[] = 'the <strong>Qualifying Supplier Names</strong> list is empty, so no supplier qualifies '
                . 'for next-day delivery at zero local stock';
        }

        // Note: denylist mode with an empty blocked list is a VALID (if pointless)
        // setup — it just means nothing is excluded — so it is deliberately not
        // flagged here.

        return $issues;
    }

    public function isDisplayed(): bool
    {
        return !empty($this->collectIssues());
    }

    public function getText(): string
    {
        $issues = $this->collectIssues();

        return 'ETechFlow Next Day Eligibility &mdash; supplier mode is enabled but misconfigured: '
            . implode('; ', $issues)
            . '. Fix at Stores &rarr; Configuration &rarr; eTechFlow &rarr; Next Day Eligibility &rarr; '
            . 'Drop-Ship Settings, or run <strong>bin/magento etechflow:nde:resync</strong> after correcting it.';
    }

    /**
     * MAJOR severity = red banner, persistent until the config is fixed.
     * It's a silent feature failure, not a system fault.
     */
    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }
}
