<?php

/**
 * REST handlers for the webnic_ssl module — the client-facing SSL API,
 * exposing read-only certificate status, the product's supported validation
 * methods, and download of the issued certificate.
 *
 * The manifest beside this file (webnic_ssl_apiroutes.json) is the source of
 * truth: a method here with no manifest entry is unreachable, a `handle`
 * naming a missing method 404s at call time, and each `uuid` must stay stable.
 *
 * Every route is `"auth": true` and re-checks ownership — `auth` only proves a
 * client is calling, not that this client owns the service in the URL. The
 * surface stays safe for self-service: certificate status/download, DCV details
 * and DCV self-service (change method, resend approver, CSR generate/decode).
 * No cancel/terminate is exposed here.
 *
 * @package   HiTechCloud WebNIC Suite
 * @author    HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @copyright 2026 HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @license   MIT
 * @version   1.1.0
 */

if (!defined('MAINDIR')) {
    die('Access denied');
}

class webnic_ssl_apiroutes
{
    /**
     * The module, connected to the app behind one service.
     *
     * @return webnic_ssl
     */
    protected function module($id)
    {
        return UserApi::module((int) $id, 'webnic_ssl');
    }

    /**
     * auth:true proves a client is calling; it does not prove this client owns
     * this service (the service id is caller input). Admin-issued credentials
     * return no clientId() and fall through to false.
     */
    protected function owns($module, $id)
    {
        return $module->serviceBelongsToClient((int) $id, (int) UserApi::clientId());
    }

    /** GET /service/@id/webnic_ssl/status */
    public function status($id)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $sync = $module->getSynchInfo();
        if ($sync === false) {
            UserApi::render(['success' => false, 'error' => 'the certificate status could not be read']);
            return;
        }
        UserApi::render([
            'success'  => true,
            'next_due' => isset($sync['next_due']) ? $sync['next_due'] : null,
        ]);
    }

    /** GET /service/@id/webnic_ssl/dcv-methods */
    public function dcvMethods($id)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $methods = [];
        foreach ((array) $module->listDCVMethod() as $row) {
            if (is_array($row) && isset($row[0], $row[1])) {
                $methods[] = ['value' => $row[0], 'name' => $row[1]];
            }
        }
        UserApi::render(['success' => true, 'dcv_methods' => $methods]);
    }

    /** GET /service/@id/webnic_ssl/certificate */
    public function certificate($id)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $cert = $module->CertDownload();
        if (empty($cert)) {
            UserApi::render(['success' => false, 'error' => 'no issued certificate is available yet']);
            return;
        }
        UserApi::render(['success' => true, 'certificate' => $cert]);
    }

    /** GET /service/@id/webnic_ssl/auth-info — per-method DCV details (DNS/HTTP/email). */
    public function authInfo($id)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        try {
            UserApi::render(['success' => true, 'validation' => $module->getValidationDetails()]);
        } catch (Exception $e) {
            UserApi::render(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /** GET /service/@id/webnic_ssl/dcv-status — current domain-validation status. */
    public function dcvStatus($id)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        try {
            UserApi::render(['success' => true, 'dcv_status' => $module->getDcvStatus()]);
        } catch (Exception $e) {
            UserApi::render(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST /service/@id/webnic_ssl/change-dcv-method — switch DCV method (dns/file/email). */
    public function changeDcvMethod($id)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $params = (array) UserApi::params();
        $method = isset($params['method']) ? (string) $params['method'] : '';
        if ($method === '') {
            UserApi::render(['success' => false, 'error' => 'method is required']);
            return;
        }
        $email = isset($params['email']) ? $params['email'] : false;
        $ok = $module->CertDCVMethodChange($method, $email);
        UserApi::render(['success' => (bool) $ok]);
    }

    /** POST /service/@id/webnic_ssl/resend-approver-email — resend the DCV approver email. */
    public function resendApproverEmail($id)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $ok = $module->CertDCVEmailResend();
        UserApi::render(['success' => (bool) $ok]);
    }

    /** GET /service/@id/webnic_ssl/csr — decode the CSR stored on the service. */
    public function decodeCsr($id)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        try {
            UserApi::render(['success' => true, 'csr' => $module->decodeStoredCsr()]);
        } catch (Exception $e) {
            UserApi::render(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST /service/@id/webnic_ssl/generate-csr — generate a CSR + key for the service. */
    public function generateCsr($id)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $params = (array) UserApi::params();
        $overrides = [];
        foreach (['commonName', 'country', 'state', 'locality', 'organizationName', 'keySize', 'sanfield'] as $field) {
            if (isset($params[$field])) {
                $overrides[$field] = $params[$field];
            }
        }
        try {
            UserApi::render(['success' => true, 'csr' => $module->generateCsrForService($overrides)]);
        } catch (Exception $e) {
            UserApi::render(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
