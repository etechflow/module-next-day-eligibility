<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * v1.6.3 backward-compatibility patch.
 *
 * Pre-v1.6.3 the SupplierDropShipResolver ran in `any_active_qualifying` mode
 * unconditionally — iterate every active slot, return true if any matched the
 * qualifying list. v1.6.3 introduces a config switch and defaults NEW installs
 * to `first_active_wins` (the more honest semantics for real-world fulfillment).
 *
 * To avoid silently changing existing merchants' eligibility logic on upgrade,
 * this patch detects "we're upgrading from a pre-1.6.3 version" and pins the
 * config to `any_active_qualifying`. The merchant can flip it to first-active-
 * wins from the admin when they're ready.
 *
 * Detection: query `setup_module.data_version` for ETechFlow_NextDayEligibility
 * BEFORE the upgrade completes. Magento runs data patches before bumping the
 * module's data_version, so:
 *
 *   - data_version exists (any value)  →  upgrade from a prior NDE install →
 *                                          pin to legacy mode
 *   - data_version missing / empty     →  fresh install → leave config.xml
 *                                          default of first_active_wins
 *
 * If the merchant has already explicitly set the value (somehow — unlikely
 * given the field didn't exist before this release), respect it.
 *
 * @see \ETechFlow\NextDayEligibility\Model\Config::MATCH_ANY_ACTIVE_QUALIFYING
 * @see \ETechFlow\NextDayEligibility\Model\Config::MATCH_FIRST_ACTIVE_WINS
 */
class SetSupplierMatchModeLegacyForUpgrades implements DataPatchInterface
{
    private const CONFIG_PATH = 'etechflow_nextdayeligibility/drop_ship/supplier_match_mode';
    private const MODULE_NAME = 'ETechFlow_NextDayEligibility';
    private const LEGACY_MODE = 'any_active_qualifying';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $setupTable = $this->moduleDataSetup->getTable('setup_module');

        // Detect: is this a fresh install or an upgrade?
        $existingVersion = $connection->fetchOne(
            $connection->select()
                ->from($setupTable, 'data_version')
                ->where('module = ?', self::MODULE_NAME)
        );

        if (!$existingVersion) {
            // Fresh install — leave the config.xml default in place (first_active_wins).
            return $this;
        }

        // Upgrade path. If the merchant has already explicitly set a value,
        // respect it.
        $currentValue = $this->scopeConfig->getValue(self::CONFIG_PATH);
        if ($currentValue !== null && $currentValue !== '') {
            return $this;
        }

        // Pin to legacy semantics so storefront behaviour doesn't silently change.
        $this->configWriter->save(self::CONFIG_PATH, self::LEGACY_MODE);

        return $this;
    }

    public function getAliases(): array
    {
        return [];
    }

    public static function getDependencies(): array
    {
        return [];
    }
}
