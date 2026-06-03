<?php
declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Controller\Adminhtml\License;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\View\Result\PageFactory;

/**
 * Stripe success_url target.
 * Called as: /admin/etechflow_nextdayeligibility/license/activated?session_id=cs_test_xxx&plan=...&domain=...&name=...&email=...
 *
 * Flow per PORTAL_LICENSING_GUIDE.md §4:
 *   1. Read session_id + decrypt stripe_secret_key from config
 *   2. POST JSON to portal /license/activate with {session_id, stripe_secret_key, domain, name, email, plan}
 *   3. Portal verifies Stripe session + mints SP-XXXX, returns it
 *   4. Save license_key to config + flush config cache
 *   5. Render success template with the key
 */
class Activated extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_NextDayEligibility::config';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly Curl $curl,
        private readonly EncryptorInterface $encryptor,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $sessionId = trim((string) $this->getRequest()->getParam('session_id', ''));
        $plan      = trim((string) $this->getRequest()->getParam('plan',      ''));
        $domain    = trim((string) $this->getRequest()->getParam('domain',    ''));
        $name      = trim((string) $this->getRequest()->getParam('name',      ''));
        $email     = trim((string) $this->getRequest()->getParam('email',     ''));

        $page = $this->resultPageFactory->create();
        $page->getConfig()->getTitle()->set('License Activated');

        if ($sessionId === '' || !preg_match('/^cs_[a-zA-Z0-9_]+$/', $sessionId)) {
            $page->getLayout()->getBlock('etechflow.bed.license.activated')
                ->setData('error', 'Missing or invalid Stripe session_id in callback URL.');
            return $page;
        }

        $stripeKey = $this->getStripeSecretKey();
        if ($stripeKey === '') {
            $page->getLayout()->getBlock('etechflow.bed.license.activated')
                ->setData('error', 'Stripe secret key not configured.');
            return $page;
        }

        $portalUrl = $this->getPortalUrl();
        if ($portalUrl === '') {
            $page->getLayout()->getBlock('etechflow.bed.license.activated')
                ->setData('error', 'Portal URL not configured.');
            return $page;
        }

        // Per gotcha A: NEVER rtrim($portalUrl, '/license/validate') — strips .dev/.com.
        // Use str_replace to peel the validate path off if present, leaving the base portal URL.
        $portalBase = str_replace('/license/validate', '', $portalUrl);

        $result = $this->callPortalActivate($portalBase, $sessionId, $stripeKey, $domain, $name, $email, $plan);
        if (!empty($result['error'])) {
            $page->getLayout()->getBlock('etechflow.bed.license.activated')
                ->setData('error', $result['error']);
            return $page;
        }

        $licenseKey = (string) ($result['license_key'] ?? '');
        if ($licenseKey === '') {
            $page->getLayout()->getBlock('etechflow.bed.license.activated')
                ->setData('error', 'Portal did not return a license_key.');
            return $page;
        }

        $this->configWriter->save('etechflow_nextdayeligibility/license/license_key',  $licenseKey);
        $this->configWriter->save('etechflow_nextdayeligibility/license/issued_key',   $licenseKey);
        $this->configWriter->save('etechflow_nextdayeligibility/license/issued_at',    (string) time());
        $this->configWriter->save('etechflow_nextdayeligibility/license/issued_domain', $domain);
        $this->configWriter->save('etechflow_nextdayeligibility/license/issued_plan',   $plan);
        $this->configWriter->save('etechflow_nextdayeligibility/license/stripe_session_id', $sessionId);
        $this->configWriter->save('etechflow_nextdayeligibility/license/revoked',       '0');
        $this->configWriter->save('etechflow_nextdayeligibility/license/ip_blocked',    '0');
        $this->cacheTypeList->cleanType('config');

        $page->getLayout()->getBlock('etechflow.bed.license.activated')
            ->setData('license_key',     $licenseKey)
            ->setData('plan',            $plan)
            ->setData('settings_url',    (string) $this->getUrl('adminhtml/system_config/edit', ['section' => 'etechflow_nextdayeligibility']))
            ->setData('management_url',  (string) $this->getUrl('etechflow_nextdayeligibility/license/gate'));

        return $page;
    }

    private function getStripeSecretKey(): string
    {
        $raw = trim((string) $this->scopeConfig->getValue('etechflow_nextdayeligibility/payment/stripe_secret_key'));
        if ($raw === '') return '';
        if (preg_match('/^\d+:\d+:/', $raw)) {
            return trim($this->encryptor->decrypt($raw));
        }
        return $raw;
    }

    private function getPortalUrl(): string
    {
        $api = trim((string) $this->scopeConfig->getValue('etechflow_nextdayeligibility/license/portal_api_url'));
        if ($api !== '') return $api;
        return trim((string) $this->scopeConfig->getValue('etechflow_nextdayeligibility/license/portal_url'));
    }

    private function callPortalActivate(
        string $portalBase, string $sessionId, string $stripeKey,
        string $domain, string $name, string $email, string $plan
    ): array {
        $url = rtrim($portalBase, '/') . '/license/activate';
        $payload = json_encode([
            'session_id'        => $sessionId,
            'stripe_secret_key' => $stripeKey,
            'domain'            => $domain,
            'name'              => $name,
            'email'             => $email,
            'plan'              => $plan,
            'module'            => 'next-day-eligibility',
        ]);

        try {
            $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setTimeout(15);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('User-Agent', 'ETechFlow-NDE/1.0');
            $this->curl->post($url, $payload);

            $body   = $this->curl->getBody();
            $status = $this->curl->getStatus();
            $data   = json_decode($body, true);
            if ($status !== 200 || !is_array($data)) {
                return ['error' => 'Portal returned HTTP ' . $status . ': ' . substr((string)$body, 0, 300)];
            }
            return $data;
        } catch (\Throwable $e) {
            return ['error' => 'Portal call failed: ' . $e->getMessage()];
        }
    }
}
