<?php

/**
 * REST handlers for the webnic_dns module — the client-facing DNS API, exposing
 * a service's zones and letting its owner manage records (WebNIC DNS v2).
 *
 * The manifest beside this file (webnic_dns_apiroutes.json) is the source of
 * truth: a method here with no manifest entry is unreachable, a `handle`
 * naming a missing method 404s at call time, and each `uuid` must stay stable.
 *
 * Every route is `"auth": true` and re-checks ownership — `auth` only proves a
 * client is calling, not that this client owns the service in the URL.
 */

if (!defined('MAINDIR')) {
    die('Access denied');
}

class webnic_dns_apiroutes
{
    /**
     * The module, connected to the app behind one service (populates
     * $details/account config, unlike HBLoader::LoadModule()).
     *
     * @return webnic_dns
     */
    protected function module($id)
    {
        return UserApi::module((int) $id, 'webnic_dns');
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

    /** GET /service/@id/webnic_dns/zones */
    public function zones($id)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        UserApi::render(['success' => true, 'zones' => $module->listZones()]);
    }

    /** GET /service/@id/webnic_dns/zone/@domain/records */
    public function records($id, $domain)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        UserApi::render(['success' => true, 'records' => $module->getZone((string) $domain)]);
    }

    /** POST /service/@id/webnic_dns/zone/@domain/record */
    public function addRecord($id, $domain)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $data = $this->recordFromParams(UserApi::params());
        if ($data === false) {
            UserApi::render(['success' => false, 'error' => 'type, name and content are required']);
            return;
        }
        $ok = $module->addRecord((string) $domain, $data);
        UserApi::render(['success' => (bool) $ok]);
    }

    /** PUT /service/@id/webnic_dns/zone/@domain/record/@record */
    public function editRecord($id, $domain, $record)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $data = $this->recordFromParams(UserApi::params());
        if ($data === false) {
            UserApi::render(['success' => false, 'error' => 'type, name and content are required']);
            return;
        }
        $ok = $module->editRecord($record, $data, (string) $domain);
        UserApi::render(['success' => (bool) $ok]);
    }

    /** DELETE /service/@id/webnic_dns/zone/@domain/record/@record */
    public function deleteRecord($id, $domain, $record)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $ok = $module->deleteRecord($record, (string) $domain);
        UserApi::render(['success' => (bool) $ok]);
    }

    /** GET /service/@id/webnic_dns/zone/@domain/dnssec */
    public function dnssec($id, $domain)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        UserApi::render(['success' => true, 'dnssec' => $module->getZoneSecurity((string) $domain)]);
    }

    /** GET /service/@id/webnic_dns/zone/@domain/url-forwardings */
    public function urlForwardings($id, $domain)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        UserApi::render(['success' => true, 'forwardings' => $module->getUrlForwardings((string) $domain)]);
    }

    /** POST /service/@id/webnic_dns/zone/@domain/url-forwarding */
    public function addUrlForwarding($id, $domain)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $params = UserApi::params();
        $subdomain = trim((string) ($params['subdomain'] ?? ''));
        $targetUrl = trim((string) ($params['targetUrl'] ?? ''));
        if ($subdomain === '' || $targetUrl === '') {
            UserApi::render(['success' => false, 'error' => 'subdomain and targetUrl are required']);
            return;
        }
        $ok = $module->addUrlForwarding((string) $domain, $subdomain, $targetUrl, !empty($params['overrideConflictingRecord']));
        UserApi::render(['success' => (bool) $ok]);
    }

    /** DELETE /service/@id/webnic_dns/zone/@domain/url-forwarding/@subdomain */
    public function removeUrlForwarding($id, $domain, $subdomain)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $ok = $module->removeUrlForwarding((string) $domain, (string) $subdomain);
        UserApi::render(['success' => (bool) $ok]);
    }

    /** GET /service/@id/webnic_dns/zone/@domain/email-forwardings */
    public function emailForwardings($id, $domain)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        UserApi::render(['success' => true, 'forwardings' => $module->getEmailForwardings((string) $domain)]);
    }

    /** POST /service/@id/webnic_dns/zone/@domain/email-forwarding */
    public function addEmailForwarding($id, $domain)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $params = UserApi::params();
        $user = trim((string) ($params['user'] ?? ''));
        $targetEmail = trim((string) ($params['targetEmail'] ?? ''));
        if ($user === '' || $targetEmail === '') {
            UserApi::render(['success' => false, 'error' => 'user and targetEmail are required']);
            return;
        }
        $ok = $module->addEmailForwarding((string) $domain, $user, $targetEmail, !empty($params['overrideConflictingRecord']));
        UserApi::render(['success' => (bool) $ok]);
    }

    /** DELETE /service/@id/webnic_dns/zone/@domain/email-forwarding/@user */
    public function removeEmailForwarding($id, $domain, $user)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        $ok = $module->removeEmailForwarding((string) $domain, (string) $user);
        UserApi::render(['success' => (bool) $ok]);
    }

    /** GET /service/@id/webnic_dns/zone/@domain/subscription */
    public function subscription($id, $domain)
    {
        $module = $this->module($id);
        if (!$this->owns($module, $id)) {
            UserApi::render(['success' => false, 'error' => 'not your service']);
            return;
        }
        UserApi::render(['success' => true, 'subscription' => $module->getDomainSubscriptionByDomain((string) $domain)]);
    }

    /**
     * Shape a record payload from API params into what addRecord/editRecord
     * expect. Required fields are validated here rather than passed blindly.
     *
     * @return array|false
     */
    protected function recordFromParams($params)
    {
        $type = trim((string) (isset($params['type']) ? $params['type'] : ''));
        $name = trim((string) (isset($params['name']) ? $params['name'] : ''));
        $content = isset($params['content']) ? $params['content'] : '';
        if ($type === '' || $name === '' || $content === '') {
            return false;
        }
        $data = [
            'type' => strtoupper($type),
            'name' => $name,
            'content' => $content,
            'ttl' => isset($params['ttl']) ? (int) $params['ttl'] : 3600,
        ];
        if (isset($params['priority'])) {
            $data['priority'] = (int) $params['priority'];
        }
        return $data;
    }
}
