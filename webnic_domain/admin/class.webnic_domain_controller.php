<?php

/**
 * Admin controller for the webnic_domain registrar module.
 *
 * Routes `?cmd=webnic_domain&action=...` requests to module methods and
 * renders the in-domain-details panels (status / contacts / actions / transfer /
 * certificate / tax lookup).
 *
 * @property webnic_domain $module
 */
class webnic_domain_controller extends HBController
{
    /**
     * Related module object.
     * @var webnic_domain
     */
    public $module = null;

    /**
     * Admin authorization object.
     * @var AdminAuthorization
     */
    public $authorization = null;

    /**
     * Template object.
     * @var Smarty
     */
    public $template = null;

    /** Base path for this module's admin templates. */
    protected function tplPath($file)
    {
        return MAINDIR . 'includes/modules/Domain/webnic_domain/admin/' . $file;
    }

    /** Assign the common domain-scoped template variables. */
    protected function assignCommon($params)
    {
        $this->template->assign('domainid', (int) $params['id']);
        $this->template->assign('modulename', $this->module->getModuleName());
        $this->template->assign('modname', $this->module->getModName());
        $this->template->assign('moduleid', $this->module->getModuleId());
    }

    /** Load a domain record and its per-domain module config. */
    protected function loadDomain($id)
    {
        $this->module->getFromDB($id);
        $this->module->setDomainConfig(HBLoader::LoadModel('Domains')->getDomainModuleConfig($id));
    }

    /**
     * Default admin landing page (account status).
     */
    public function _default($params)
    {
        $modDir = strtolower($this->module->getModuleDirName());
        $this->template->pageTitle = Language::parse($this->module->getModName());
        $this->template->module_template_dir = APPDIR_MODULES . 'Domain' . DS . $modDir . DS . 'admin';
        $this->template->showtpl = 'default';
        $this->template->assign('moduleurl', Utilities::checkSecureURL(HBConfig::getConfig('InstallURL') . 'includes/modules/Domain/' . $modDir . '/admin/'));
        $this->template->assign('modulename', $this->module->getModuleName());
        $this->template->assign('modname', $this->module->getModName());
        $this->template->assign('moduleid', $this->module->getModuleId());
    }

    /**
     * Download a domain certificate PDF.
     */
    public function downloadcertificate($params)
    {
        $id   = (int) $params['id'];
        $lang = $params['lang'] ?? 'eng';

        $this->loadDomain($id);

        $pdf = $this->module->downloadCertificateApi($lang);
        if ($pdf === false) {
            Utilities::redirect('?cmd=domains&action=edit&id=' . $id . '&error=downloadFailed');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="cert_' . $id . '_' . $lang . '.pdf"');
        echo $pdf;
        exit;
    }

    /**
     * Domain-details wrapper (renders the actions panel in the domain edit page).
     */
    public function domaindetails($params)
    {
        $this->template->assign('custom_template', $this->tplPath('domaindetails.tpl'));

        if (Controller::isAjax() && $params['token_valid'] && $params['load']) {
            $this->template->showtpl = $this->tplPath('ajax.domaindetails.tpl');
            $this->loadDomain($params['id']);
            $this->template->assign('info', $this->module->getDomainInfo());
            $this->template->assign('raa_date', Utilities::dateDisplay(date('Y-m-d H:i:s')));
        }
        $this->assignCommon($params);
    }

    /**
     * Domain status panel.
     */
    public function domainstatus($params)
    {
        $this->template->assign('custom_template', $this->tplPath('domaindetails.tpl'));

        if (isset($params['update'])) {
            $this->loadDomain($params['id']);
            $this->module->updateStatus($params);
            Utilities::redirect('?cmd=domains&action=edit&id=' . $params['id']);
        }
        if (Controller::isAjax() && $params['token_valid'] && $params['load']) {
            $this->template->showtpl = $this->tplPath('ajax.domainstatus.tpl');
            $this->loadDomain($params['id']);
            $this->template->assign('info', $this->module->getDomainInfo());
        }
        $this->assignCommon($params);
    }

    /**
     * Domain contacts panel.
     */
    public function domaincontacts($params)
    {
        $this->template->assign('custom_template', $this->tplPath('domaindetails.tpl'));

        if (Controller::isAjax() && $params['token_valid'] && $params['load']) {
            $this->template->showtpl = $this->tplPath('ajax.domaincontacts.tpl');
            $this->loadDomain($params['id']);
            $this->template->assign('contacts', $this->module->getDomainContacts());
        }
        $this->assignCommon($params);
    }

    /**
     * Domain action dispatcher (SyncContact, ChangeEpp, Lock, Unlock, Hold,
     * RemoveHold, SendVerify, Certificate, TaxLookup).
     */
    public function domainaction($params)
    {
        $this->template->assign('custom_template', $this->tplPath('domaindetails.tpl'));

        if (Controller::isAjax() && $params['token_valid'] && $params['load']) {
            $this->template->showtpl = $this->tplPath('ajax.domainactions.tpl');
            $this->loadDomain($params['id']);

            switch ($params['ac'] ?? '') {
                case 'SyncContact':
                    $this->template->assign('message', $this->module->SyncContact() ? 'Contacts synced.' : 'Sync failed.');
                    break;
                case 'ChangeEpp':
                    $this->template->assign('message', $this->module->ChangeEpp());
                    break;
                case 'Unlock':
                    $this->template->assign('message', $this->module->Unlock());
                    break;
                case 'Lock':
                    $this->template->assign('message', $this->module->Lock());
                    break;
                case 'Hold':
                    $this->template->assign('message', $this->module->Hold() ? 'Domain suspended.' : 'Suspend failed.');
                    break;
                case 'RemoveHold':
                    $this->template->assign('message', $this->module->RemoveHold() ? 'Domain un-suspended.' : 'Un-suspend failed.');
                    break;
                case 'SendVerify':
                    $recipient = $this->module->SendVerify();
                    $this->template->assign('recipient', $recipient);
                    $this->template->showtpl = $this->tplPath('ajax.sendverify.tpl');
                    break;
                case 'Certificate':
                    $this->template->assign('domainid', (int) $params['id']);
                    $this->template->showtpl = $this->tplPath('ajax.certificate.tpl');
                    break;
                case 'TaxLookup':
                    $this->handleTaxLookup($params);
                    break;
                default:
                    $this->template->assign('message', 'Unknown action.');
                    break;
            }
        }
        $this->assignCommon($params);
    }

    /**
     * Tax code lookup (Vietnamese MST) rendered into its own ajax pane.
     */
    protected function handleTaxLookup($params)
    {
        $id  = (int) $params['id'];
        $mst = trim($params['mst'] ?? '');

        $this->template->assign('domainid', $id);
        $this->template->assign('taxid', $id);
        $this->template->assign('mst', $mst);

        if ($mst !== '') {
            $url = 'https://tool.bnix.vn/wp-content/themes/blocksy-child/mst/check_mst.php?mst=' . urlencode($mst);
            $ch  = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $json = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($json, true) ?: [];

            // Mask the legal representative's ID number.
            $repId = $data['legal_representative_id'] ?? '';
            $len   = strlen($repId);
            if ($len === 12) {
                $data['legal_representative_id_masked'] = substr($repId, 0, 3) . str_repeat('X', $len - 5) . substr($repId, -2);
            } elseif ($len === 9) {
                $data['legal_representative_id_masked'] = substr($repId, 0, 3) . str_repeat('X', $len - 6) . substr($repId, -3);
            } else {
                $data['legal_representative_id_masked'] = $repId;
            }

            $this->template->assign('result', $data);
        }

        $this->template->showtpl = $this->tplPath('ajax.taxeslookup.tpl');
    }

    /**
     * Domain transfer status panel.
     */
    public function domaintransfer($params)
    {
        $this->template->assign('custom_template', $this->tplPath('domaindetails.tpl'));

        if (Controller::isAjax() && $params['token_valid'] && $params['load']) {
            $this->template->showtpl = $this->tplPath('ajax.domaintransfer.tpl');
            $this->loadDomain($params['id']);
            $this->template->assign('transferinfo', $this->module->TransferAction($params));
            $this->template->assign('raa_date', Utilities::dateDisplay(date('Y-m-d H:i:s')));
        }
        $this->assignCommon($params);
    }
}
