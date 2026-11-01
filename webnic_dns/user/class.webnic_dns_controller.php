<?php
/**
 * HostBill Module Component
 * Developed & Optimized for PHP 8.1 - 8.5 compatibility
 *
 * Client-side controller for the webnic_dns DNS module. Mirrors the cloudns
 * user controller: it inherits the generic DNS client area (zone list, record
 * table, add/edit/delete) from DNSClient_Controller.
 *
 * Adds a client-facing URL/email forwarding panel so a service owner can manage
 * forwarding on their own zones (the full forwarding surface is also exposed
 * through the User API routes beside this module).
 */
require_once APPDIR_LIBS . "dnsfunctions" . DS . "class.dnscontroller.php";
#[\AllowDynamicProperties]
class webnic_dns_controller extends DNSClient_Controller
{
    /**
     * @var webnic_dns
     */
    public $module;

    public function beforeCall($params)
    {
        $this->domain_indent = DnsManage::COLUMN_RELATED_ID;
        parent::beforeCall($params);
    }

    public function accountdetails($params)
    {
        parent::accountdetails($params);
    }

    /**
     * Client URL/email forwarding management for a zone they own.
     * Resolves the zone through the module (which is scoped to this service),
     * handles add/remove, and assigns the current forwarding lists.
     */
    public function forwarding($params)
    {
        $domain = (string) ($params["domain"] ?? $params["zone"] ?? "");
        if (!empty($params["webnic_action"]) && $domain !== "") {
            switch ((string) $params["webnic_action"]) {
                case "url_fwd_add":
                    $this->module->addUrlForwarding($domain, (string) ($params["subdomain"] ?? ""), (string) ($params["targetUrl"] ?? ""), !empty($params["override"]));
                    break;
                case "url_fwd_remove":
                    $this->module->removeUrlForwarding($domain, (string) ($params["subdomain"] ?? ""));
                    break;
                case "email_fwd_add":
                    $this->module->addEmailForwarding($domain, (string) ($params["user"] ?? ""), (string) ($params["targetEmail"] ?? ""), !empty($params["override"]));
                    break;
                case "email_fwd_remove":
                    $this->module->removeEmailForwarding($domain, (string) ($params["user"] ?? ""));
                    break;
            }
        }
        if ($domain !== "") {
            $this->template->assign("url_forwardings", $this->module->getUrlForwardings($domain));
            $this->template->assign("email_forwardings", $this->module->getEmailForwardings($domain));
        }
        $this->template->assign("zone", $domain);
        return true;
    }
}

?>
