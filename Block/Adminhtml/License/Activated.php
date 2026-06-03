<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Block\Adminhtml\License;

use Magento\Backend\Block\Template;

/**
 * Block backing license/activated.phtml.
 * Data set by Activated controller:
 *   license_key    : the SP-XXXX key returned by the portal
 *   plan           : plan slug (e.g. nde_starter)
 *   settings_url   : link back to Stores → Config → eTechFlow → Next Day Eligibility
 *   management_url : link back to the gate/landing
 *   error          : present only when activation failed
 */
class Activated extends Template
{
    public function getLicenseKey(): string
    {
        return (string) $this->getData('license_key');
    }

    public function getPlan(): string
    {
        return (string) $this->getData('plan');
    }

    public function getError(): string
    {
        return (string) $this->getData('error');
    }

    public function hasError(): bool
    {
        return $this->getError() !== '';
    }

    public function getSettingsUrl(): string
    {
        $url = (string) $this->getData('settings_url');
        if ($url === '') {
            $url = (string) $this->getUrl('adminhtml/system_config/edit', ['section' => 'etechflow_nextdayeligibility']);
        }
        return $url;
    }

    public function getManagementUrl(): string
    {
        $url = (string) $this->getData('management_url');
        if ($url === '') {
            $url = (string) $this->getUrl('etechflow_nextdayeligibility/license/gate');
        }
        return $url;
    }
}
