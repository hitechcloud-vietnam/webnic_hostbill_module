<?php
/**
 * HostBill Module Component
 * Developed & Optimized for PHP 8.1 - 8.5 compatibility
 *
 * Admin-side controller for the webnic_dns DNS module. Mirrors the cloudns
 * admin controller: connects the module to the configured app (server) before
 * each call and wires the product/app configuration templates plus the shared
 * DNS Templates tab.
 *
 * On top of the standard DNS product config it surfaces the extended WebNIC DNS
 * v2 admin tooling as product-config tabs (admin-only):
 *   - WebNIC NS & Whitelabel : whitelabel nameserver get/save/remove +
 *                              basic/subscription default nameservers.
 *   - WebNIC Statistics      : partner zone statistics + premium usage.
 *   - WebNIC Record Templates: subscription record template CRUD.
 *   - WebNIC Subscriptions   : per-zone premium subscribe/upgrade/renew/
 *                              auto-renew/unsubscribe, partner-subscription and
 *                              URL/email forwarding tools.
 * All mutating actions are dispatched through handleWebnicAction() on POST.
 */
require_once APPDIR_LIBS . "dnsfunctions" . DS . "class.dnstemplates_controller.php";
#[\AllowDynamicProperties]
class webnic_dns_controller extends DNSTemplates_Controller
{
    /**
     * @var webnic_dns
     */
    public $module;

    public function beforeCall($params)
    {
        if (isset($params["account"]["server_id"])) {
            $s = HBLoader::LoadModel("Servers");
            $this->module->connect($s->getServerDetails($params["account"]["server_id"]));
        }
        parent::beforeCall($params);
    }

    public function appdetails($params)
    {
        parent::productdetails($params);
        $this->template->assign("custom_template", APPDIR_MODULES . $this->module->getModuleType() . DS . strtolower($this->module->getModuleName()) . DS . "templates" . DS . "appconfig.tpl");
        return true;
    }

    public function productdetails($params)
    {
        $this->connectFromParams($params);
        parent::productdetails($params);

        // Admin action dispatch (whitelabel / templates / subscriptions /
        // forwarding). POST only; product option saves fall through untouched.
        if (!empty($params["webnic_action"])) {
            $this->handleWebnicAction($params);
        }

        $this->assignPanelData();

        $this->template->assign("customconfig", APPDIR_MODULES . $this->module->getModuleType() . DS . strtolower($this->module->getModuleName()) . DS . "templates" . DS . "productconfig.tpl");
        $tplDir = APPDIR_MODULES . $this->module->getModuleType() . DS . strtolower($this->module->getModuleName()) . DS . "admin" . DS;
        $this->template->assign("extra_tabs", [
            "DNS Templates" => MAINDIR . "includes/types/_common/dns_templates.tpl",
            "WebNIC Whitelabel NS" => $tplDir . "whitelabel.tpl",
            "WebNIC Statistics" => $tplDir . "statistics.tpl",
            "WebNIC Record Templates" => $tplDir . "record_templates.tpl",
            "WebNIC Subscriptions" => $tplDir . "subscriptions.tpl",
        ]);
        return true;
    }

    /**
     * Connect the module to the server behind the product (accepts server_id,
     * a server[] list, or the account's server_id).
     */
    protected function connectFromParams($params)
    {
        $s = HBLoader::LoadModel("Servers");
        if (isset($params["server_id"]) && is_numeric($params["server_id"])) {
            $this->module->connect($s->getServerDetails($params["server_id"]));
            return;
        }
        if (!empty($params["server"]) && is_array($params["server"])) {
            foreach ($params["server"] as $server) {
                if (is_numeric($server)) {
                    $this->module->connect($s->getServerDetails($server));
                    return;
                }
            }
        }
        if (isset($params["account"]["server_id"])) {
            $this->module->connect($s->getServerDetails($params["account"]["server_id"]));
        }
    }

    /**
     * Fetch the live data the admin panels display. Guarded so a product-config
     * page still renders when credentials are absent/invalid.
     */
    protected function assignPanelData()
    {
        $whitelabel = false;
        $basicNs = [];
        $subNs = [];
        $stats = false;
        $premiumStats = [];
        $templates = [];
        $subscriptions = [];
        try {
            $whitelabel = $this->module->getWhitelabelNameservers();
            $basicNs = $this->module->getBasicRecordNameservers();
            $subNs = $this->module->getSubscriptionRecordNameservers();
            $stats = $this->module->getZoneStatistics();
            $premiumStats = $this->module->getPremiumSubscriptionStatistics();
            $templates = $this->module->listRecordTemplates();
            $subscriptions = $this->module->listDomainSubscriptions();
        } catch (\Throwable $e) {
            HBDebug::debug("webnic_dns panel load failed", ["err" => $e->getMessage()]);
        }
        $this->template->assign("wn_whitelabel", $whitelabel);
        $this->template->assign("wn_basic_ns", $basicNs);
        $this->template->assign("wn_sub_ns", $subNs);
        $this->template->assign("wn_stats", $stats);
        $this->template->assign("wn_premium_stats", $premiumStats);
        $this->template->assign("wn_templates", $templates);
        $this->template->assign("wn_subscriptions", $subscriptions);
    }

    /**
     * Dispatch an admin mutation. Each case maps straight onto a module method
     * (and thus a WebNIC DNS v2 endpoint). Best-effort: the module surfaces its
     * own addInfo()/addError() notices.
     */
    protected function handleWebnicAction($params)
    {
        $action = (string) $params["webnic_action"];
        $zone = (string) ($params["wn_zone"] ?? "");
        $sid = (string) ($params["wn_subscription_id"] ?? "");
        switch ($action) {
            /* Whitelabel nameservers */
            case "whitelabel_save":
                $this->module->saveWhitelabelNameservers($params["wn_nameservers"] ?? "", (string) ($params["wn_admin_email"] ?? ""));
                break;
            case "whitelabel_remove":
                $this->module->removeWhitelabelNameservers();
                break;

            /* Record templates */
            case "template_create":
                $this->module->createRecordTemplate((string) ($params["wn_template_name"] ?? ""), !empty($params["wn_default_template"]));
                break;
            case "template_update":
                $this->module->updateRecordTemplate((string) ($params["wn_template_id"] ?? ""), (string) ($params["wn_template_name"] ?? ""), !empty($params["wn_default_template"]));
                break;
            case "template_delete":
                $this->module->deleteRecordTemplate((string) ($params["wn_template_id"] ?? ""));
                break;
            case "template_add_record":
                $this->module->addRecordToTemplate((string) ($params["wn_template_id"] ?? ""), [
                    "type" => $params["wn_rec_type"] ?? "",
                    "name" => $params["wn_rec_name"] ?? "",
                    "ttl" => $params["wn_rec_ttl"] ?? 3600,
                    "content" => $params["wn_rec_value"] ?? "",
                ]);
                break;
            case "template_remove_record":
                $this->module->removeRecordFromTemplate((string) ($params["wn_template_id"] ?? ""), [
                    "type" => $params["wn_rec_type"] ?? "",
                    "name" => $params["wn_rec_name"] ?? "",
                    "content" => $params["wn_rec_value"] ?? "",
                ]);
                break;

            /* Per-zone premium subscription management */
            case "sub_upgrade":
                $this->module->upgradeToPremium($zone, (int) ($params["wn_term"] ?? 12), !empty($params["wn_auto_renew"]));
                break;
            case "sub_subscribe":
                $this->module->subscribeDomain($zone, (string) ($params["wn_product"] ?? "premium_ns_georoute"), (int) ($params["wn_term"] ?? 12), !empty($params["wn_auto_renew"]));
                break;
            case "sub_renew":
                $this->module->renewDomainSubscription($sid, (int) ($params["wn_term"] ?? 12));
                break;
            case "sub_autorenew_enable":
                $this->module->enableAutoRenewal($sid);
                break;
            case "sub_autorenew_disable":
                $this->module->disableAutoRenewal($sid);
                break;
            case "sub_unsubscribe":
                $this->module->unsubscribeDomain($sid);
                break;
            case "partner_add":
                $this->module->addZoneToPartnerSubscription($zone);
                break;
            case "partner_remove":
                $this->module->removeZoneFromPartnerSubscription($zone);
                break;
            case "ns_subscription_add":
                $this->module->addZoneToNsSubscription($zone, (string) ($params["wn_product"] ?? "basic_ns"));
                break;

            /* Zone forwarding */
            case "url_fwd_add":
                $this->module->addUrlForwarding($zone, (string) ($params["wn_subdomain"] ?? ""), (string) ($params["wn_target_url"] ?? ""), !empty($params["wn_override"]));
                break;
            case "url_fwd_remove":
                $this->module->removeUrlForwarding($zone, (string) ($params["wn_subdomain"] ?? ""));
                break;
            case "email_fwd_add":
                $this->module->addEmailForwarding($zone, (string) ($params["wn_user"] ?? ""), (string) ($params["wn_target_email"] ?? ""), !empty($params["wn_override"]));
                break;
            case "email_fwd_remove":
                $this->module->removeEmailForwarding($zone, (string) ($params["wn_user"] ?? ""));
                break;
        }
    }
}

?>
