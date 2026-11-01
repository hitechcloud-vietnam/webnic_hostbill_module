<?php

/**
 * WebNIC Domain — reseller account balance widget.
 *
 * A small admin/client-area domain widget that reports the WebNIC reseller
 * account balance, for parity with the SSL/DNS admin tooling. It follows the
 * same DomainsWidget contract the nhanhoa_domain widgets use: it renders
 * `webnic_domain_balance.tpl` and only calls the registrar on its own
 * token-checked submit (never on a plain page render).
 *
 * @package   HiTechCloud WebNIC Suite
 * @author    HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @copyright 2026 HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @license   MIT
 * @version   1.2026-11-02.2
 */

if (!defined('MAINDIR')) {
    die('Access denied');
}

#[\AllowDynamicProperties]
class Widget_webnic_domain_balance extends DomainsWidget
{
    /** Only attach to the webnic_domain module. */
    const MODULE_FILE = 'class.webnic_domain.php';

    /** @var string */
    protected $widgetfullname = 'WebNIC Account Balance';

    /** @var string */
    protected $description = 'WebNIC reseller account balance.';

    /**
     * OPTION_UNIQUE | OPTION_DEFAULT_ON = 1 + 2.
     * @var array
     */
    protected $info = [
        'appendtpl'  => false,
        'replacetpl' => false,
        'options'    => 3,
    ];

    /** The `widgetdo` value this widget answers to. */
    protected $action = 'webnicDomainBalance';

    /**
     * Assign the balance rows only on this widget's own token-checked submit.
     */
    public function controller($service, &$module, &$smarty, &$params)
    {
        $smarty->assign('webnic_domain_balance_rows', null);
        $smarty->assign('webnic_domain_balance_error', null);

        if (!method_exists($module, 'getModuleFilename') || $module->getModuleFilename() !== self::MODULE_FILE) {
            return;
        }

        $submitted = isset($params['widgetdo'])
            && $params['widgetdo'] === $this->action
            && !empty($params['token_valid']);
        if (!$submitted) {
            return;
        }

        $resp = $module->getApi('reseller/v2/balance');
        if (!is_array($resp) || (string) ($resp['code'] ?? '') !== '1000') {
            $smarty->assign('webnic_domain_balance_error', 'Unable to read the WebNIC account balance.');
            return;
        }

        $rows = [];
        foreach ((array) ($resp['data']['balance'] ?? []) as $bal) {
            $currency = (string) ($bal['currency'] ?? '');
            $rows[$currency !== '' ? $currency : '?'] = number_format((float) ($bal['amount'] ?? 0), 2) . ' ' . $currency;
        }
        if (empty($rows)) {
            $smarty->assign('webnic_domain_balance_error', 'Connected, but no balance information was returned.');
            return;
        }
        $smarty->assign('webnic_domain_balance_rows', $rows);
    }
}
