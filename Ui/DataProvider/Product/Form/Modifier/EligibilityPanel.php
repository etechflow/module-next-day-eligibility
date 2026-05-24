<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Ui\DataProvider\Product\Form\Modifier;

use ETechFlow\NextDayEligibility\Service\EligibilityExplainer;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Escaper;
use Magento\Framework\Stdlib\ArrayManager;

/**
 * Injects a "Next Day Eligibility — Why?" panel into the product edit page.
 * Shows the merchant the live eligibility verdict + the rules that produced it.
 *
 * Renders as an HTML container inside a collapsible fieldset, positioned next
 * to the existing eTechFlow Shipping attribute group.
 */
class EligibilityPanel extends AbstractModifier
{
    private const FIELDSET_NAME = 'etechflow_nde_eligibility_panel';
    private const FIELD_NAME    = 'etechflow_nde_eligibility_html';

    public function __construct(
        private readonly LocatorInterface $locator,
        private readonly EligibilityExplainer $explainer,
        private readonly Escaper $escaper,
        private readonly ArrayManager $arrayManager
    ) {
    }

    public function modifyData(array $data): array
    {
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        $product = $this->locator->getProduct();
        if (!$product) {
            return $meta;
        }

        $explanation = $this->explainer->explain($product);
        $html = $this->renderHtml($explanation);

        $meta[self::FIELDSET_NAME] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'label'         => __('Next Day Eligibility — Why?'),
                        'collapsible'   => true,
                        'opened'        => true,
                        'componentType' => 'fieldset',
                        'sortOrder'     => 35,
                        'dataScope'     => '',
                    ],
                ],
            ],
            'children' => [
                self::FIELD_NAME => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'formElement'      => 'container',
                                'componentType'    => 'container',
                                'component'        => 'Magento_Ui/js/form/components/html',
                                'additionalClasses' => 'etechflow-nde-panel',
                                'content'          => $html,
                                'sortOrder'        => 10,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $meta;
    }

    private function renderHtml(array $explanation): string
    {
        $eligible = $explanation['eligible'] ?? false;
        $headline = (string) ($explanation['headline'] ?? '');
        $reasons  = $explanation['reasons'] ?? [];
        $notes    = $explanation['notes'] ?? [];

        $bg     = $eligible ? '#e8f5e9' : '#fdecea';
        $border = $eligible ? '#43a047' : '#d32f2f';
        $icon   = $eligible ? '✅' : '❌';
        $titleColor = $eligible ? '#1b5e20' : '#b71c1c';

        $html = sprintf(
            '<div style="background:%s;border-left:4px solid %s;padding:16px 18px;border-radius:4px;margin:8px 0;font-family:inherit;">',
            $bg,
            $border
        );

        $html .= sprintf(
            '<div style="font-size:14px;font-weight:600;color:%s;margin-bottom:10px;">%s %s</div>',
            $titleColor,
            $icon,
            $this->escaper->escapeHtml($headline)
        );

        if (!empty($reasons)) {
            $html .= '<div style="font-size:12px;color:#333;margin-bottom:8px;"><strong>How we got this:</strong></div>';
            $html .= '<ul style="font-size:12px;color:#333;margin:0 0 12px 1.4em;padding:0;line-height:1.6;">';
            foreach ($reasons as $reason) {
                $html .= '<li>' . $this->escaper->escapeHtml((string) $reason) . '</li>';
            }
            $html .= '</ul>';
        }

        if (!empty($notes)) {
            $html .= '<div style="font-size:12px;color:#555;background:#fff8e1;border-left:3px solid #ffa000;padding:8px 12px;margin-top:8px;border-radius:3px;">';
            $html .= '<strong>What to do:</strong>';
            $html .= '<ul style="margin:4px 0 0 1.4em;padding:0;line-height:1.5;">';
            foreach ($notes as $note) {
                $html .= '<li>' . $this->escaper->escapeHtml((string) $note) . '</li>';
            }
            $html .= '</ul></div>';
        }

        $html .= '<div style="font-size:11px;color:#888;margin-top:10px;font-style:italic;">';
        $html .= $this->escaper->escapeHtml(
            'Save the product to refresh this panel after changes.'
        );
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }
}
