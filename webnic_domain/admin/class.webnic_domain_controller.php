<?php

/**
 * Admin controller for the webnic_domain registrar module.
 *
 * Routes `?cmd=webnic_domain&action=...` requests to module methods and
 * renders the in-domain-details panels (status / contacts / actions / transfer /
 * certificate / tax lookup).
 *
 * @property webnic_domain $module
 *
 * @package   HiTechCloud WebNIC Suite
 * @author    HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @copyright 2026 HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @license   MIT
 * @version   1.2026-11-02.2
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
     * RemoveHold, SendVerify, Certificate).
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
                case 'Restore':
                    $this->template->assign('message', $this->module->RestoreDomain() ? 'Domain restoration submitted.' : 'Restore failed.');
                    break;
                default:
                    $this->template->assign('message', 'Unknown action.');
                    break;
            }
        }
        $this->assignCommon($params);
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
            $this->template->assign('transfertype', $this->module->queryTransferType());
            $this->template->assign('raa_date', Utilities::dateDisplay(date('Y-m-d H:i:s')));
        }
        $this->assignCommon($params);
    }

    /**
     * DNSSEC management panel (view/update/delete DS records).
     */
    public function dnssec($params)
    {
        $this->template->assign('custom_template', $this->tplPath('domaindetails.tpl'));

        if (Controller::isAjax() && $params['token_valid'] && $params['load']) {
            $this->loadDomain($params['id']);

            $do = $params['do'] ?? '';
            if ($do === 'update') {
                $dsDatas = [];
                foreach ((array) ($params['keyTag'] ?? []) as $i => $keyTag) {
                    if ($keyTag === '') {
                        continue;
                    }
                    $dsDatas[] = [
                        'keyTag'     => $keyTag,
                        'algorithm'  => $params['algorithm'][$i] ?? '',
                        'digestType' => $params['digestType'][$i] ?? '',
                        'digest'     => $params['digest'][$i] ?? '',
                    ];
                }
                $this->module->updateDnssec($dsDatas);
            } elseif ($do === 'delete') {
                $this->module->deleteDnssec();
            }

            $this->template->showtpl = $this->tplPath('ajax.dnssec.tpl');
            $this->template->assign('domainid', (int) $params['id']);
            $this->template->assign('supported', $this->module->checkDnssecSupported());
            $this->template->assign('dsDatas', $this->module->getDnssecInfo());
        }
        $this->assignCommon($params);
    }

    /**
     * Universal WHOIS lookup panel.
     */
    public function whoislookup($params)
    {
        $this->template->assign('custom_template', $this->tplPath('domaindetails.tpl'));

        if (Controller::isAjax() && $params['token_valid'] && $params['load']) {
            $this->loadDomain($params['id']);
            $this->template->showtpl = $this->tplPath('ajax.whois.tpl');
            $this->template->assign('whois', $this->module->getUniversalWhois());
        }
        $this->assignCommon($params);
    }

    /**
     * Transfer-away status panel (registrar + reseller), with approve/reject.
     */
    public function transferaway($params)
    {
        $this->template->assign('custom_template', $this->tplPath('domaindetails.tpl'));

        if (Controller::isAjax() && $params['token_valid'] && $params['load']) {
            $this->loadDomain($params['id']);

            if (($params['do'] ?? '') === 'update') {
                $scope   = $params['scope'] ?? 'registrar';
                $recid   = $params['recid'] ?? '';
                $type    = $params['approveType'] ?? 'registrant';
                $approve = ($params['approve'] ?? '') === '1';
                $remarks = $params['approveRemarks'] ?? '';
                if ($scope === 'reseller') {
                    $this->module->updateResellerTransferAwayStatus($recid, $type, $approve, $remarks);
                } else {
                    $this->module->updateRegistrarTransferAwayStatus($recid, $type, $approve, $remarks);
                }
            }

            $domainName = $this->module->apiDomainName();
            $this->template->showtpl = $this->tplPath('ajax.transferaway.tpl');
            $this->template->assign('domainid', (int) $params['id']);
            $this->template->assign('domainname', $domainName);
            $this->template->assign('registrar', $this->module->getRegistrarTransferAwayStatus($domainName));
            $this->template->assign('reseller', $this->module->getResellerTransferAwayStatus($domainName));
        }
        $this->assignCommon($params);
    }

    /**
     * Standalone admin tool: registrant account create / search / reassign.
     */
    public function registrant($params)
    {
        $this->prepareStandalonePage('registrant.tpl');

        if (($params['do'] ?? '') === 'create') {
            $data = $this->module->createRegistrantAccount($params['username'] ?? '');
            $this->template->assign('created', $data);
        } elseif (($params['do'] ?? '') === 'search') {
            $this->template->assign('results', $this->module->listRegistrantAccounts($params['keyword'] ?? ''));
            $this->template->assign('keyword', $params['keyword'] ?? '');
        } elseif (($params['do'] ?? '') === 'reassign') {
            $ok = $this->module->reassignRegistrantAccount($params['domainName'] ?? '', $params['registrantUsername'] ?? '');
            $this->template->assign('reassigned', $ok);
        }
    }

    /**
     * Standalone admin tool: list domains linked to a nameserver/host.
     */
    public function hostdomains($params)
    {
        $this->prepareStandalonePage('hostdomains.tpl');

        if (!empty($params['nameserver'])) {
            $page     = (int) ($params['page'] ?? 1);
            $pageSize = (int) ($params['pageSize'] ?? 50);
            $this->template->assign('nameserver', $params['nameserver']);
            $this->template->assign('items', $this->module->getHostLinkedDomains($params['nameserver'], $page, $pageSize));
        }
    }

    /**
     * Standalone admin tool: bulk operations + batch report lookup.
     */
    public function bulk($params)
    {
        $this->prepareStandalonePage('bulk.tpl');

        $do = $params['do'] ?? '';
        if ($do === 'register' && !empty($params['registrations'])) {
            $list = json_decode((string) $params['registrations'], true);
            if (is_array($list)) {
                $this->template->assign('taskId', $this->module->bulkRegister($list));
            } else {
                $this->template->assign('taskId', false);
            }
        } elseif ($do === 'renew' && !empty($params['renewals'])) {
            $list = [];
            foreach (preg_split('/\r\n|\r|\n/', (string) $params['renewals']) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = preg_split('/[\s,]+/', $line);
                $list[] = ['domainName' => $parts[0], 'term' => (int) ($parts[1] ?? 1)];
            }
            $this->template->assign('taskId', $this->module->bulkRenew($list));
        } elseif ($do === 'report' && !empty($params['taskId'])) {
            $this->template->assign('overview', $this->module->getBulkReportOverview($params['taskId']));
            $this->template->assign('details', $this->module->getBulkReportDetails($params['taskId']));
            $this->template->assign('taskId', $params['taskId']);
        } elseif ($do === 'replace' && !empty($params['domains'])) {
            $domains = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $params['domains'])));
            $map = [];
            foreach (['registrant', 'administrator', 'technical', 'billing'] as $t) {
                if (!empty($params[$t])) {
                    $map[$t] = $params[$t];
                }
            }
            $this->template->assign('jobOrderId', $this->module->bulkReplaceContact($domains, $map));
        }
    }

    /**
     * Shared setup for a standalone (non-domain-scoped) admin page.
     */
    protected function prepareStandalonePage($file)
    {
        $modDir = strtolower($this->module->getModuleDirName());
        $this->template->pageTitle = Language::parse($this->module->getModName());
        $this->template->module_template_dir = APPDIR_MODULES . 'Domain' . DS . $modDir . DS . 'admin';
        $this->template->showtpl = $this->tplPath($file);
        $this->template->assign('moduleurl', Utilities::checkSecureURL(HBConfig::getConfig('InstallURL') . 'includes/modules/Domain/' . $modDir . '/admin/'));
        $this->template->assign('modulename', $this->module->getModuleName());
        $this->template->assign('modname', $this->module->getModName());
        $this->template->assign('moduleid', $this->module->getModuleId());
    }
}
