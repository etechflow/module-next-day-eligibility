<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model\Source;

use ETechFlow\NextDayEligibility\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the supplier_match_mode admin dropdown (v1.6.3).
 *
 * @see Config::MATCH_FIRST_ACTIVE_WINS
 * @see Config::MATCH_ANY_ACTIVE_QUALIFYING
 */
class SupplierMatchMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::MATCH_FIRST_ACTIVE_WINS,
                'label' => __('First active wins (recommended)'),
            ],
            [
                'value' => Config::MATCH_ANY_ACTIVE_QUALIFYING,
                'label' => __('Any active qualifying (legacy)'),
            ],
        ];
    }
}
