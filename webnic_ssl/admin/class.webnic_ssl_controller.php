<?php

/**
 * WebNIC SSL — admin service management controller.
 *
 * The HostBill SSLModule contract lets a module render its own admin service
 * page: when "haveadmin" is set, HostBill calls accountdetails() and uses the
 * assigned "custom_template" to draw the panel under the service in the admin
 * area (the same mechanism Hosting/thesslstore uses). This controller renders
 * that panel (order status, product, CSR, SAN and the active DCV details) and
 * dispatches the management actions (sync, reissue, renew, change-DCV, resend
 * approver email, download) straight onto the module's existing public methods.
 *
 * Action URLs are "?cmd=webnic_ssl&action=<method>&id=<serviceId>".
 *
 * @property webnic_ssl $module
 *
 * @package   HiTechCloud WebNIC Suite
 * @author    HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @copyright 2026 HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @license   MIT
 * @version   1.1.0
 */
#[\AllowDynamicProperties]
class webnic_ssl_controller extends HBController
{
    /**
     * Related module object.
     * @var webnic_ssl
     */
    public $module = null;

    public function beforeCall($params)
    {
    }

    /** Absolute path to one of this module's admin templates. */
    protected function tplPath($file)
    {
        return __DIR__ . DS . $file;
    }

    /**
     * Connect the module to the service's server and load its account context,
     * so the mutating actions (reissue / renew / sync / ...) operate on the
     * right order. Mirrors the setup Hosting/thesslstore performs.
     *
     * @return bool
     */
    protected function setupModule($service)
    {
        $a = HBLoader::LoadModel('Accounts');
        $data = $a->getAccount($service);
        if (!$data) {
            return false;
        }
        $account_config = $a->getAccountModuleConfig($service);
        $s = HBLoader::LoadModel('Servers');
        $this->module->connect($s->getServerDetails($data['server_id']));
        $this->module->setAccountConfig($account_config);
        $this->module->setAccount($data);
        return true;
    }

    /** Persist the module's current service details back to the account. */
    protected function persist($service)
    {
        $a = HBLoader::LoadModel('Accounts');
        $a->updateExtraDetails((int) $service, $this->module->getDetails());
    }

    /** Redirect back to the admin service page. */
    protected function back($id)
    {
        Utilities::redirect('?cmd=accounts&action=edit&id=' . (int) $id);
    }

    /**
     * Admin service panel (rendered by HostBill under the service row).
     */
    public function accountdetails($params)
    {
        $this->template->assign('custom_template', $this->tplPath('myadminarea.tpl'));

        $cert = [];
        try {
            $cert = (array) $this->module->CertDetails();
        } catch (\Throwable $e) {
            Engine::addError($e->getMessage());
        }

        $status = (string) ($cert[\SSL\STATUS] ?? ($cert['status'] ?? ''));

        // Decode the stored CSR for display (best-effort; no CSR yet is fine).
        $csrData = false;
        try {
            if (!empty($cert[\SSL\CSR] ?? ($cert['csr'] ?? ''))) {
                $csrData = $this->module->decodeStoredCsr();
            }
        } catch (\Throwable $e) {
            // ignore — show the raw CSR only
        }

        // Live DCV details are only meaningful while the order is validating.
        $validation = false;
        $dcvStatus = false;
        if (in_array($status, [\SSL\STATUS\VALIDATION, \SSL\STATUS\PROCESSING], true)) {
            try {
                $validation = $this->module->getValidationDetails();
            } catch (\Throwable $e) {
                Engine::addError($e->getMessage());
            }
            try {
                $dcvStatus = $this->module->getDcvStatus();
            } catch (\Throwable $e) {
                // ignore — dcv status is advisory
            }
        }

        $this->template->assign('cert', $cert);
        $this->template->assign('cert_status', $status);
        $this->template->assign('csr_data', $csrData);
        $this->template->assign('validation', $validation);
        $this->template->assign('dcv_status', $dcvStatus);
        $this->template->assign('serviceid', (int) $params['id']);
    }

    /**
     * Pull the latest order/cert status from WebNIC.
     */
    public function sync($params)
    {
        $id = (int) $params['id'];
        if ($this->setupModule($id)) {
            if ($this->module->getSynchInfo() !== false) {
                Engine::addInfo('WebNIC SSL status synchronised.');
            }
            $this->persist($id);
        }
        $this->back($id);
    }

    /**
     * Reissue the certificate. An optional "csr" POST field replaces the stored
     * CSR before the reissue (SSLModule::CertDetails([...]) is the supported way
     * to set a service detail).
     */
    public function reissue($params)
    {
        $id = (int) $params['id'];
        if ($this->setupModule($id)) {
            if (!empty($params['csr'])) {
                $this->module->CertDetails([\SSL\CSR => trim((string) $params['csr'])]);
            }
            if ($this->module->Reissue()) {
                Engine::addInfo('WebNIC SSL reissue submitted.');
            }
            $this->persist($id);
        }
        $this->back($id);
    }

    /**
     * Renew the certificate (WebNIC issues a new order id for the renewal).
     */
    public function renew($params)
    {
        $id = (int) $params['id'];
        if ($this->setupModule($id)) {
            if ($this->module->Renewal()) {
                Engine::addInfo('WebNIC SSL renewal submitted.');
            }
            $this->persist($id);
        }
        $this->back($id);
    }

    /**
     * Change the active domain-control-validation method (dns / file / email).
     */
    public function changedcv($params)
    {
        $id = (int) $params['id'];
        $method = (string) ($params['method'] ?? '');
        $email = $params['email'] ?? false;
        if ($method === '') {
            Engine::addError('A DCV method is required.');
            return $this->back($id);
        }
        if ($this->setupModule($id)) {
            if ($this->module->CertDCVMethodChange($method, $email)) {
                Engine::addInfo('WebNIC SSL validation method updated.');
            }
            $this->persist($id);
        }
        $this->back($id);
    }

    /**
     * Resend the approver email for every email-validated domain on the order.
     */
    public function resend($params)
    {
        $id = (int) $params['id'];
        if ($this->setupModule($id)) {
            if ($this->module->CertDCVEmailResend()) {
                Engine::addInfo('WebNIC SSL approver email re-sent.');
            }
        }
        $this->back($id);
    }

    /**
     * Download the issued certificate chain as a single PEM bundle.
     */
    public function download($params)
    {
        $id = (int) $params['id'];
        if (!$this->setupModule($id)) {
            return $this->back($id);
        }
        $certs = $this->module->CertDownload();
        if (empty($certs)) {
            Engine::addError('No issued certificate is available to download yet.');
            return $this->back($id);
        }
        $body = implode("\n", (array) $certs) . "\n";
        header('Content-Description: File Transfer');
        header('Content-Type: application/x-pem-file');
        header('Content-Disposition: attachment; filename="webnic_ssl_' . $id . '.pem"');
        header('Content-Transfer-Encoding: binary');
        echo $body;
        exit;
    }
}
