<?php
/**
 * HostBill Module Component
 * Developed & Optimized for PHP 8.1 - 8.5 compatibility
 *
 * WebNIC DNS module for HostBill.
 *
 * Greenfield DNSModule built on the WebNIC DNS v2 REST API (base path
 * `/dns/v2`, shared with the other WebNIC modules). Modelled on the cloudns
 * DNS module: it implements the full DNSModule surface plus ReverseDNS and
 * DNSSECModule, with admin + user controllers, templates and User API routes.
 *
 * Credentials and environment come from the connected app (Settings -> Apps):
 *   - Username / Password  -> WebNIC reseller API user
 *   - OTE Environment      -> TEST (oteapi.webnic.cc) vs LIVE (api.webnic.cc)
 * Token auth matches the WebNIC pattern: POST reseller/v2/api-user/token,
 * cache the bearer via HBCache (key "webnic_dns"), refresh+retry once on 401.
 *
 * Basic vs Premium DNS:
 *   WebNIC exposes free "basic" DNS (bundled with a domain) and paid
 *   "premium"/subscription DNS. The record endpoints differ between the two.
 *   The tier is chosen per zone from the product/app "DNS tier" setting:
 *     - basic   -> always use the basic record endpoints
 *     - premium -> always use the subscription record endpoints
 *     - auto    -> detect: look the zone up with
 *                  subscription/standalone-domain/get-by-domain, and treat it
 *                  as premium when a subscription is returned (the zone-records
 *                  response also reports `sourceFrom` = basic|subscription).
 *
 * @package   HiTechCloud WebNIC Suite
 * @author    HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @copyright 2026 HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @license   MIT
 * @version   1.1.0
 */
#[\AllowDynamicProperties]
class webnic_dns extends DNSModule implements ReverseDNS, DNSSECModule
{
    /**
     * Capability flags. haveapi exposes this module's REST routes
     * (api/class.webnic_dns_apiroutes.php) through the User API plugin.
     * @var array
     */
    protected $info = ["haveuser" => true, "haveadmin" => true, "havetpl" => true, "haveapi" => true];

    /**
     * Module version. Increase on every method change.
     * @var string
     */
    protected $version = "1.1.0";

    protected $_repository = "hosting_webnic_dns";

    /**
     * Module name, visible in admin portal.
     * @var string
     */
    protected $modname = "WebNIC DNS";

    /**
     * Module description, visible in admin portal.
     * @var string
     */
    protected $description = "WebNIC DNS v2 module for HostBill (basic + premium/subscription DNS)";

    /**
     * Connected instance of PDO object.
     * @var PDO
     */
    protected $db;

    /**
     * Product-level options (Products & Services -> Product -> Connect with Module).
     * @var array
     */
    protected $options = [
        "maxdomain" => ["name" => "maxdomain", "value" => false, "type" => "input", "variable" => "domainnumber", "description" => ""],
        "dns_template" => ["name" => "dns_template", "value" => false, "type" => "input"],
        "dns_tier" => ["name" => "dns_tier", "value" => false, "type" => "input"],
        "auto_subscribe" => ["name" => "auto_subscribe", "value" => false, "type" => "check", "default" => false],
        "ns1" => ["name" => "ns1", "value" => false, "type" => "input"],
        "ns2" => ["name" => "ns2", "value" => false, "type" => "input"],
        "ns3" => ["name" => "ns3", "value" => false, "type" => "input"],
        "ns4" => ["name" => "ns4", "value" => false, "type" => "input"],
        "soaemailclient" => ["name" => "soaemailclient", "value" => true, "default" => true, "type" => "check"],
        "soaemaildefault" => ["name" => "soaemaildefault", "value" => false, "type" => "input"],
        "soattldefault" => ["name" => "soattldefault", "value" => "3600", "default" => "3600", "type" => "input"],
        "hide_billing" => ["name" => "hide_billing", "value" => false, "type" => "check", "default" => false],
        "hide_zone_management" => ["name" => "hide_zone_management", "value" => false, "type" => false, "default" => false],
    ];

    /**
     * Per-service stored details. option1 persists the premium subscription id
     * (if any) so the service can be renewed / unsubscribed later.
     * @var array
     */
    protected $details = [
        "option1" => ["name" => "subscription_id", "value" => false, "type" => "input", "default" => false],
    ];

    protected $serverFields;
    protected $serverFieldsDescription;

    /**
     * Data passed in via connect().
     * @var array
     * @see connect()
     */
    private $connect_data;

    /**
     * WebNIC API hosts.
     * @var array
     */
    protected $hosts = ["live" => "https://api.webnic.cc/", "test" => "https://oteapi.webnic.cc/"];

    /**
     * WebNIC token endpoints.
     * @var array
     */
    protected $tokenEndpoints = [
        "live" => "https://api.webnic.cc/reseller/v2/api-user/token",
        "test" => "https://oteapi.webnic.cc/reseller/v2/api-user/token",
    ];

    /**
     * WebNIC DNS v2 base path prefix (relative to the API host).
     * @var string
     */
    protected $dns_base = "dns/v2/";

    /**
     * Cached bearer token for the current request lifecycle.
     * @var string
     */
    protected $token;

    /**
     * Supported record types (fallback; getSupportedRecords() refreshes live).
     * @var array
     */
    protected $supported_records = ["A", "AAAA", "CNAME", "MX", "TXT", "NS", "SRV", "CAA"];

    protected $name_servers = [];

    /**
     * Per-zone premium detection cache.
     * @var array
     */
    protected $premium_cache = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function connect($connect)
    {
        $this->connect_data = $connect;
    }

    public function install()
    {
        $this->upgrade("1.0");
    }

    public function upgrade($old)
    {
        if (version_compare($old, "1.0.0", "<")) {
            LangEdit::addTranslation("dnssec_not_available", "DNSSEC is not available for this zone", "clientarea", "client");
            LangEdit::addTranslation("dnssec_has_rectified", "DNSSEC for this domain has been rectified", "clientarea", "client");
        }
    }

    /* ------------------------------------------------------------------ */
    /* Config / credential accessors                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Read a server custom field (saved via name="custom[...]" in appconfig.tpl).
     * Checks the several shapes HostBill may hand back for a connected app.
     */
    protected function getCustom($key, $default = null)
    {
        if (isset($this->connect_data["custom"][$key])) {
            return $this->connect_data["custom"][$key];
        }
        if (isset($this->srv_data["custom"][$key])) {
            return $this->srv_data["custom"][$key];
        }
        if (isset($this->connect_data[$key])) {
            return $this->connect_data[$key];
        }
        return $default;
    }

    /**
     * Read a product option value.
     */
    protected function getOption($key, $default = null)
    {
        if (isset($this->options[$key]["value"]) && $this->options[$key]["value"] !== false && $this->options[$key]["value"] !== "") {
            return $this->options[$key]["value"];
        }
        return $default;
    }

    protected function isLive()
    {
        return (string) $this->getCustom("ote") !== "1";
    }

    /**
     * Configured WebNIC connect market for this app: 'vn' (Vietnam) or 'global'.
     * Credentials are shared across markets (same reseller API user); the market
     * only gates market-specific behaviour. DNS has little VN-specific logic
     * today, so the switch is largely cosmetic for now, but it keeps the whole
     * WebNIC suite (domain / ssl / dns) consistent and gives future VN-only
     * tweaks a single place to branch. Defaults to 'vn' when absent.
     */
    protected function getMarket()
    {
        $market = strtolower(trim((string) $this->getCustom("market")));
        return $market === "global" ? "global" : "vn";
    }

    /** True when the configured market is Vietnam (VN). */
    protected function isVN()
    {
        return $this->getMarket() === "vn";
    }

    protected function baseUrl()
    {
        return ($this->isLive() ? $this->hosts["live"] : $this->hosts["test"]) . $this->dns_base;
    }

    /**
     * Effective DNS tier for this zone: basic | premium | auto.
     * Product option overrides the app-level custom field.
     */
    protected function tier()
    {
        $tier = $this->getOption("dns_tier");
        if (empty($tier)) {
            $tier = $this->getCustom("dns_tier");
        }
        $tier = strtolower((string) $tier);
        if (!in_array($tier, ["basic", "premium", "auto"], true)) {
            $tier = "auto";
        }
        return $tier;
    }

    /**
     * Premium product used when subscribing a zone to premium nameservers.
     */
    protected function premiumProduct()
    {
        $p = (string) $this->getCustom("premium_product");
        if (!in_array($p, ["premium_ns_partner", "premium_ns_georoute"], true)) {
            $p = "premium_ns_partner";
        }
        return $p;
    }

    /**
     * Decide whether a zone is served by premium/subscription DNS.
     * basic/premium tiers are definitive; auto detects via the subscription
     * lookup (falling back to basic when none is found).
     */
    protected function isPremium($zone)
    {
        $tier = $this->tier();
        if ($tier === "basic") {
            return false;
        }
        if ($tier === "premium") {
            return true;
        }
        if (isset($this->premium_cache[$zone])) {
            return $this->premium_cache[$zone];
        }
        $premium = false;
        $resp = $this->request("GET", "subscription/standalone-domain/get-by-domain?domainName=" . rawurlencode($zone), [], false);
        if ($this->ok($resp) && !empty($resp["data"]["subscriptionId"])) {
            $premium = true;
        }
        $this->premium_cache[$zone] = $premium;
        return $premium;
    }

    /* ------------------------------------------------------------------ */
    /* HTTP / token plumbing                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Obtain (and cache) a WebNIC bearer token. $force bypasses the cache.
     */
    protected function access_token($force = false)
    {
        if (!$force) {
            $cached = HBCache::get("webnic_dns");
            if ($cached) {
                $this->token = $cached;
                return true;
            }
        }
        $endpoint = $this->isLive() ? $this->tokenEndpoints["live"] : $this->tokenEndpoints["test"];
        $payload = [
            "username" => $this->connect_data["username"] ?? "",
            "password" => $this->connect_data["password"] ?? "",
        ];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 90);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        $out = curl_exec($curl);
        curl_close($curl);
        $resp = json_decode($out, true);
        HBDebug::debug("WebNIC DNS token", ["resp" => $resp]);
        if (is_array($resp) && (string) ($resp["code"] ?? "") === "1000" && !empty($resp["data"]["access_token"])) {
            $this->token = $resp["data"]["access_token"];
            $ttl = isset($resp["data"]["expires_in"]) ? (int) $resp["data"]["expires_in"] : 3600;
            HBCache::set("webnic_dns", $this->token, $ttl);
            return true;
        }
        $this->addError("WebNIC authentication failed: " . $this->errMsg($resp));
        return false;
    }

    /**
     * Perform a WebNIC DNS v2 request. Refreshes the token and retries once on
     * an auth failure. Returns the decoded response array, or false on hard
     * failure. When $show_error is false the standard error envelope is not
     * surfaced to the admin/client (used for probes).
     */
    protected function request($method, $path, $params = [], $show_error = true, $retry = true)
    {
        if (!$this->token && !$this->access_token()) {
            return false;
        }
        $url = $this->baseUrl() . ltrim($path, "/");
        $method = strtoupper($method);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 90);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Accept: application/json",
            "Authorization: Bearer " . $this->token,
        ]);
        if (in_array($method, ["POST", "PUT", "PATCH"], true)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode((object) $params));
        }
        $out = curl_exec($curl);
        $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);
        curl_close($curl);
        if ($out === false) {
            if ($show_error) {
                $this->addError("WebNIC request error: " . ucwords($err));
            }
            return false;
        }
        $resp = json_decode($out, true);
        HBDebug::debug("WebNIC DNS response: " . $method . " " . $path, ["http" => $http, "resp" => $resp, "params" => $params]);
        if (($http === 401 || $http === 403) && $retry) {
            if ($this->access_token(true)) {
                return $this->request($method, $path, $params, $show_error, false);
            }
        }
        if (is_array($resp) && (string) ($resp["code"] ?? "") !== "1000" && $show_error) {
            $this->addError("WebNIC error: " . $this->errMsg($resp));
        }
        return is_array($resp) ? $resp : false;
    }

    protected function ok($resp)
    {
        return is_array($resp) && (string) ($resp["code"] ?? "") === "1000";
    }

    protected function errMsg($resp)
    {
        if (!is_array($resp)) {
            return "no/invalid response";
        }
        $msg = $resp["message"] ?? "unknown error";
        if (!empty($resp["error"]["subCode"]) || !empty($resp["error"]["message"])) {
            $msg .= " (" . trim(($resp["error"]["subCode"] ?? "") . " " . ($resp["error"]["message"] ?? "")) . ")";
        }
        return $msg;
    }

    /* ------------------------------------------------------------------ */
    /* Lifecycle                                                           */
    /* ------------------------------------------------------------------ */

    public function testConnection()
    {
        return $this->access_token(true) ? true : false;
    }

    public function Create()
    {
        return true;
    }

    public function Terminate()
    {
        $sub = $this->details["option1"]["value"];
        if (empty($sub)) {
            $sub = $this->account_details["extra_details"]["option1"] ?? false;
        }
        if (!empty($sub)) {
            $this->request("DELETE", "subscription/standalone-domain/" . rawurlencode($sub), [], false);
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Zones                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Create a zone: subscribe it to WebNIC nameservers at the chosen tier.
     * POST dns/v2/zone/{zone}/nameserver-subscription.
     */
    public function addZone($domain, $ip = false)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            $this->addError("Domain name is empty");
            return false;
        }
        $premium = $this->tier() === "premium" || ($this->tier() === "auto" && $this->getOption("auto_subscribe"));
        $product = $premium ? $this->premiumProduct() : "basic_ns";
        $resp = $this->request("POST", "zone/" . rawurlencode($domain) . "/nameserver-subscription", ["subscriptionProduct" => $product]);
        if (!$this->ok($resp)) {
            return false;
        }
        if (!empty($resp["data"]["subscriptionId"])) {
            $this->details["option1"]["value"] = $resp["data"]["subscriptionId"];
        }
        $this->addInfo("WebNIC zone created: " . $domain);
        return true;
    }

    /**
     * Delete a zone. DELETE dns/v2/zone/{zone}.
     */
    public function deleteZone($domain)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return false;
        }
        $resp = $this->request("DELETE", "zone/" . rawurlencode($domain));
        if ($this->ok($resp)) {
            $this->addInfo("WebNIC zone deleted: " . $domain);
            return true;
        }
        return false;
    }

    /**
     * Zones belonging to this service, cross-referenced with HostBill's DNS
     * domains. GET dns/v2/zones.
     */
    public function listZones()
    {
        $zones = [];
        if (empty($this->account_details["id"])) {
            return $zones;
        }
        $in = [];
        $userzones = HBLoader::LoadComponent("DnsManage")->listAccountDomains($this->account_details["id"]);
        foreach ($userzones as $zone) {
            $in[(string) $zone[DnsManage::COLUMN_DOMAIN_NAME]] = $zone[DnsManage::COLUMN_RELATED_ID];
        }
        $resp = $this->request("GET", "zones?limit=100", [], false);
        if ($this->ok($resp) && is_array($resp["data"])) {
            foreach ($resp["data"] as $z) {
                $name = (string) ($z["zone"] ?? "");
                if ($name !== "" && isset($in[$name])) {
                    $zones[] = ["id" => $in[$name], "domain" => $name];
                }
            }
        }
        return $zones;
    }

    /**
     * All WebNIC zones for import. GET dns/v2/zones.
     */
    public function importZones()
    {
        $zones = [];
        $resp = $this->request("GET", "zones?limit=100", [], false);
        if ($this->ok($resp) && is_array($resp["data"])) {
            foreach ($resp["data"] as $z) {
                $name = (string) ($z["zone"] ?? "");
                if ($name !== "") {
                    $zones[$name] = $name;
                }
            }
        }
        return $zones;
    }

    /**
     * Reverse (.arpa) zones. GET dns/v2/zones filtered to *.arpa.
     */
    public function getArpaZones()
    {
        $zones = [];
        $resp = $this->request("GET", "zones?limit=100", [], false);
        if ($this->ok($resp) && is_array($resp["data"])) {
            foreach ($resp["data"] as $z) {
                $name = (string) ($z["zone"] ?? "");
                if ($name !== "" && substr($name, -5) === ".arpa") {
                    $zones[$name] = $name;
                }
            }
        }
        return $zones;
    }

    /* ------------------------------------------------------------------ */
    /* Records                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Read a zone's records, normalized to HostBill's record array shape.
     * GET dns/v2/zone/{zone}/records. Each WebNIC rdata value becomes one
     * HostBill record with a synthesized opaque id (type/host/value/ttl).
     */
    public function getZone($domain)
    {
        $records = [];
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return $records;
        }
        $resp = $this->request("GET", "zone/" . rawurlencode($domain) . "/records");
        if (!$this->ok($resp) || empty($resp["data"]["records"])) {
            return $records;
        }
        foreach ($resp["data"]["records"] as $rec) {
            $type = (string) ($rec["type"] ?? "");
            $host = (string) ($rec["name"] ?? "");
            $ttl = (int) ($rec["ttl"] ?? 3600);
            $rdatas = isset($rec["rdatas"]) && is_array($rec["rdatas"]) ? $rec["rdatas"] : [];
            foreach ($rdatas as $rd) {
                $value = (string) ($rd["value"] ?? "");
                $norm = $this->normalizeOut($type, $value);
                $records[] = [
                    "id" => $this->makeId($type, $host, $value, $ttl),
                    "type" => $type,
                    "ttl" => (string) $ttl,
                    "name" => $this->fromHost($host, $domain),
                    "priority" => $norm["priority"],
                    "content" => $norm["content"],
                ];
            }
        }
        return $records;
    }

    /**
     * Add a record: append the new value to the (host,type) rdata set and save.
     */
    public function addRecord($domain, $data = [])
    {
        if (empty($data) || empty($domain)) {
            return false;
        }
        $domain = $this->getDomainName($domain);
        $host = $this->toHost($data["name"] ?? "", $domain);
        $type = strtoupper((string) ($data["type"] ?? ""));
        $value = $this->buildValue($type, $data);
        $ttl = $this->fixTtl($data["ttl"] ?? 3600);
        if ($type === "" || $value === "") {
            $this->addError("Record type and value are required");
            return false;
        }
        $values = $this->getValues($domain, $host, $type);
        if (!in_array($value, $values, true)) {
            $values[] = $value;
        }
        return $this->saveSet($domain, $host, $type, $ttl, $values);
    }

    /**
     * Edit a record = remove the old value then add the new one. Handles
     * type/name changes transparently (no record ids exist in WebNIC).
     */
    public function editRecord($record, $data = [], $domain = false)
    {
        if (empty($data) || empty($domain) || empty($record)) {
            return false;
        }
        $domain = $this->getDomainName($domain);
        $old = $this->parseId($record);
        if ($old) {
            $this->removeValue($domain, $old["host"], $old["type"], $old["value"]);
        }
        return $this->addRecord($domain, $data);
    }

    /**
     * Delete one record (by its synthesized id).
     */
    public function deleteRecord($record, $domain = false)
    {
        if (empty($record) || empty($domain)) {
            return false;
        }
        $domain = $this->getDomainName($domain);
        $parsed = $this->parseId($record);
        if (!$parsed) {
            return false;
        }
        return $this->removeValue($domain, $parsed["host"], $parsed["type"], $parsed["value"]);
    }

    /**
     * Supported record types. GET dns/v2/zone/record-types, with a static
     * fallback.
     */
    public function getSupportedRecords()
    {
        $resp = $this->request("GET", "zone/record-types", [], false);
        if ($this->ok($resp) && is_array($resp["data"]) && !empty($resp["data"])) {
            $this->supported_records = array_values(array_map("strval", $resp["data"]));
        }
        return $this->supported_records;
    }

    /**
     * Default nameservers: configured list, else WebNIC basic/premium NS.
     * GET dns/v2/zone/basic/record/nameservers or
     * GET dns/v2/zone/subscription/record/nameservers.
     */
    public function getDefaultNameservers()
    {
        if (!empty($this->name_servers)) {
            return $this->name_servers;
        }
        $nameservers = [];
        $configured = (string) $this->getCustom("default_ns");
        if ($configured !== "") {
            foreach (preg_split('/[\s,]+/', $configured) as $ns) {
                $ns = trim($ns);
                if ($ns !== "") {
                    $nameservers[] = ["name" => $ns, "ip" => ""];
                }
            }
        }
        if (empty($nameservers)) {
            $list = $this->tier() === "premium" ? $this->getSubscriptionRecordNameservers() : $this->getBasicRecordNameservers();
            foreach ($list as $ns) {
                $nameservers[] = ["name" => (string) $ns, "ip" => ""];
            }
        }
        $this->name_servers = $nameservers;
        return $nameservers;
    }

    /**
     * Basic (complimentary) DNS record nameservers.
     * GET dns/v2/zone/basic/record/nameservers -> string[].
     */
    public function getBasicRecordNameservers()
    {
        $resp = $this->request("GET", "zone/basic/record/nameservers", [], false);
        if ($this->ok($resp) && is_array($resp["data"] ?? null)) {
            return array_values(array_map("strval", $resp["data"]));
        }
        return [];
    }

    /**
     * Premium subscription DNS record nameservers (whitelabel value for premium
     * subscribers). GET dns/v2/zone/subscription/record/nameservers -> string[].
     */
    public function getSubscriptionRecordNameservers()
    {
        $resp = $this->request("GET", "zone/subscription/record/nameservers", [], false);
        if ($this->ok($resp) && is_array($resp["data"] ?? null)) {
            return array_values(array_map("strval", $resp["data"]));
        }
        return [];
    }

    public function getDomainLimit()
    {
        return $this->options["maxdomain"]["value"];
    }

    /**
     * Unified record delete that works for BOTH basic and premium/subscription
     * zones (WebNIC routes it internally by the zone's tier), removing every
     * rdata value for the (type,name) pair in one call.
     * DELETE dns/v2/zone/{zone}/record?type=...&name=...
     * Complements removeValue(), which targets a single rdata value per call.
     */
    public function deleteZoneRecordUnified($domain, $type, $name = "")
    {
        $domain = $this->getDomainName($domain);
        $type = strtoupper(trim((string) $type));
        if (empty($domain) || $type === "") {
            $this->addError("Zone and record type are required");
            return false;
        }
        $sendName = ($name === "" || $name === "@") ? "" : (string) $name;
        $q = "zone/" . rawurlencode($domain) . "/record?type=" . rawurlencode($type) . "&name=" . rawurlencode($sendName);
        $resp = $this->request("DELETE", $q);
        if ($this->ok($resp)) {
            $this->addInfo("Record deleted: " . $type . " " . ($sendName === "" ? "@" : $sendName));
            return true;
        }
        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Record helpers (basic vs premium routing)                           */
    /* ------------------------------------------------------------------ */

    /**
     * Current rdata values for a (host,type) pair in a zone.
     */
    protected function getValues($domain, $host, $type)
    {
        $values = [];
        $resp = $this->request("GET", "zone/" . rawurlencode($domain) . "/records", [], false);
        if ($this->ok($resp) && !empty($resp["data"]["records"])) {
            foreach ($resp["data"]["records"] as $rec) {
                if ((string) ($rec["type"] ?? "") !== $type) {
                    continue;
                }
                if ((string) ($rec["name"] ?? "") !== (string) $host) {
                    continue;
                }
                foreach (($rec["rdatas"] ?? []) as $rd) {
                    $values[] = (string) ($rd["value"] ?? "");
                }
            }
        }
        return $values;
    }

    /**
     * Save the complete rdata set for a (host,type) pair. Routes to the basic
     * save endpoint or the premium "replace" endpoint by zone tier.
     *   basic   -> POST dns/v2/zone/{zone}/record
     *   premium -> POST dns/v2/zone/{zone}/subscription/record/replace
     */
    protected function saveSet($domain, $host, $type, $ttl, array $values)
    {
        $rdatas = [];
        foreach (array_values(array_unique($values)) as $v) {
            if ($v !== "") {
                $rdatas[] = ["value" => $v];
            }
        }
        $body = [
            "name" => $host === "" ? "@" : $host,
            "type" => $type,
            "ttl" => (int) $ttl,
            "rdatas" => $rdatas,
        ];
        if ($this->isPremium($domain)) {
            $resp = $this->request("POST", "zone/" . rawurlencode($domain) . "/subscription/record/replace", $body);
        } else {
            $resp = $this->request("POST", "zone/" . rawurlencode($domain) . "/record", $body);
        }
        if ($this->ok($resp)) {
            $this->addInfo("Record saved: " . $type . " " . ($host === "" ? "@" : $host));
            return true;
        }
        return false;
    }

    /**
     * Remove a single rdata value from a (host,type) pair.
     *   premium -> DELETE dns/v2/zone/{zone}/subscription/record?type&name&value
     *   basic   -> re-save the remaining values, or DELETE the whole set when
     *              this was the last value
     *              (DELETE dns/v2/zone/{zone}/basic/record?type&name).
     */
    protected function removeValue($domain, $host, $type, $value)
    {
        $sendHost = $host === "" ? "@" : $host;
        if ($this->isPremium($domain)) {
            $q = "zone/" . rawurlencode($domain) . "/subscription/record?type=" . rawurlencode($type)
                . "&name=" . rawurlencode($sendHost) . "&value=" . rawurlencode($value);
            $resp = $this->request("DELETE", $q);
            if ($this->ok($resp)) {
                $this->addInfo("Record deleted: " . $type . " " . $sendHost);
                return true;
            }
            return false;
        }
        // Basic: preserve sibling values by re-saving the remainder.
        $remaining = [];
        foreach ($this->getValues($domain, $host, $type) as $v) {
            if ($v !== $value) {
                $remaining[] = $v;
            }
        }
        if (!empty($remaining)) {
            return $this->saveSet($domain, $host, $type, $this->fixTtl(3600), $remaining);
        }
        $q = "zone/" . rawurlencode($domain) . "/basic/record?type=" . rawurlencode($type) . "&name=" . rawurlencode($sendHost);
        $resp = $this->request("DELETE", $q);
        if ($this->ok($resp) || (is_array($resp) && !empty($resp["data"]["removed"]))) {
            $this->addInfo("Record deleted: " . $type . " " . $sendHost);
            return true;
        }
        return false;
    }

    /**
     * Build a WebNIC rdata value string from HostBill record data.
     */
    protected function buildValue($type, $data)
    {
        $content = $data["content"] ?? "";
        $priority = isset($data["priority"]) ? (int) $data["priority"] : 0;
        if ($type === "MX") {
            $target = is_array($content) ? ($content[0] ?? "") : $content;
            return trim($priority . " " . $target);
        }
        if ($type === "SRV") {
            // HostBill SRV content = [weight, port, target]; priority separate.
            if (is_array($content)) {
                $weight = $content[0] ?? 0;
                $port = $content[1] ?? 0;
                $target = $content[2] ?? "";
                return trim($priority . " " . $weight . " " . $port . " " . $target);
            }
            return trim($priority . " " . $content);
        }
        if ($type === "CAA") {
            if (is_array($content)) {
                $flag = $content[0] ?? 0;
                $tag = $content[1] ?? "issue";
                $val = $content[2] ?? "";
                return trim($flag . " " . $tag . ' "' . trim((string) $val, '"') . '"');
            }
            return (string) $content;
        }
        if (is_array($content)) {
            return (string) ($content[0] ?? "");
        }
        return (string) $content;
    }

    /**
     * Split a WebNIC rdata value back into HostBill's {priority, content}.
     */
    protected function normalizeOut($type, $value)
    {
        if ($type === "MX") {
            $parts = preg_split('/\s+/', trim($value), 2);
            if (count($parts) === 2 && is_numeric($parts[0])) {
                return ["priority" => (int) $parts[0], "content" => $parts[1]];
            }
            return ["priority" => 0, "content" => $value];
        }
        if ($type === "SRV") {
            $parts = preg_split('/\s+/', trim($value));
            if (count($parts) >= 4) {
                return ["priority" => (int) $parts[0], "content" => [$parts[1], $parts[2], $parts[3]]];
            }
            return ["priority" => 0, "content" => $value];
        }
        return ["priority" => 0, "content" => $value];
    }

    /**
     * Synthesize an opaque record id (WebNIC records have no id of their own).
     */
    protected function makeId($type, $host, $value, $ttl)
    {
        return base64_encode(json_encode(["type" => $type, "host" => $host, "value" => $value, "ttl" => (int) $ttl]));
    }

    /**
     * Parse a synthesized record id. Returns false when unrecognized.
     */
    protected function parseId($id)
    {
        $decoded = json_decode(base64_decode((string) $id, true), true);
        if (!is_array($decoded) || !isset($decoded["type"])) {
            return false;
        }
        return [
            "type" => (string) $decoded["type"],
            "host" => (string) ($decoded["host"] ?? ""),
            "value" => (string) ($decoded["value"] ?? ""),
            "ttl" => (int) ($decoded["ttl"] ?? 3600),
        ];
    }

    /**
     * FQDN record name -> WebNIC relative host ("@" for the apex).
     */
    protected function toHost($name, $domain)
    {
        $name = rtrim((string) $name, ".");
        $domain = rtrim((string) $domain, ".");
        if ($name === "" || $name === "@" || strcasecmp($name, $domain) === 0) {
            return "@";
        }
        if (substr($name, -strlen("." . $domain)) === "." . $domain) {
            $name = substr($name, 0, -strlen("." . $domain));
        }
        return $name === "" ? "@" : $name;
    }

    /**
     * WebNIC relative host -> FQDN record name for HostBill display.
     */
    protected function fromHost($host, $domain)
    {
        $host = (string) $host;
        if ($host === "" || $host === "@") {
            return $domain;
        }
        return $host . "." . $domain;
    }

    /* ------------------------------------------------------------------ */
    /* DNSSEC (DNSSECModule)                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Read DNSSEC state + DS/DNSKEY for a zone.
     *   GET dns/v2/zone/{zone}/subscription/dnssec/info
     *   GET dns/v2/zone/{zone}/subscription/dnssec/ds
     *   GET dns/v2/zone/{zone}/subscription/dnssec/dnskey
     */
    public function getZoneSecurity($domain)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return false;
        }
        $info = $this->request("GET", "zone/" . rawurlencode($domain) . "/subscription/dnssec/info", [], false);
        if (!$this->ok($info) || empty($info["data"]["enabled"])) {
            return [];
        }
        $dnssec = [];
        $ds = $this->request("GET", "zone/" . rawurlencode($domain) . "/subscription/dnssec/ds", [], false);
        $key_tag = "";
        if ($this->ok($ds) && !empty($ds["data"])) {
            $d = $ds["data"];
            $key_tag = (string) ($d["keyTag"] ?? "");
            $dnssec["ds"][] = [
                "key_tag" => $key_tag,
                "algorithm" => (string) ($d["algorithm"] ?? ""),
                "digest_type" => (string) ($d["digestType"] ?? ""),
                "digest" => (string) ($d["digest"] ?? ""),
            ];
        }
        $dnskey = $this->request("GET", "zone/" . rawurlencode($domain) . "/subscription/dnssec/dnskey", [], false);
        if ($this->ok($dnskey) && !empty($dnskey["data"]["rdatas"])) {
            foreach ($dnskey["data"]["rdatas"] as $i => $rd) {
                // DNSKEY rdata value: "<flags> <protocol> <algorithm> <public key>"
                $parts = preg_split('/\s+/', trim((string) ($rd["value"] ?? "")));
                $flags = $parts[0] ?? "";
                $alg = $parts[2] ?? "";
                $pub = $parts[3] ?? "";
                $dnssec["keys"][] = [
                    "flag" => (string) $flags === "257" ? "KSK" : "ZSK",
                    "algorithm" => $alg,
                    "id_tag" => $i,
                    "key_tag" => $key_tag,
                    "public_key" => $pub,
                ];
            }
        }
        return $dnssec;
    }

    /**
     * Enable DNSSEC. PUT dns/v2/zone/{zone}/subscription/dnssec/enable.
     */
    public function secureZone($domain)
    {
        $existing = $this->getZoneSecurity($domain);
        if (!empty($existing)) {
            return $existing;
        }
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return false;
        }
        $enable = $this->request("PUT", "zone/" . rawurlencode($domain) . "/subscription/dnssec/enable");
        if (!$this->ok($enable)) {
            $this->addError("dnssec_not_available");
            return false;
        }
        $security = $this->getZoneSecurity($domain);
        $client_id = $this->client_data["id"] ?? false;
        $domain = rtrim($domain, ".");
        if (Engine::getType() == "admin" && !$client_id) {
            $client_id = $this->getClientIdByDomain($domain);
        }
        HBEventManager::notify("after_dns_zone_secured", array_merge(["domain" => $domain, "client_id" => $client_id], is_array($security) ? $security : []));
        return empty($security) ? false : $security;
    }

    /**
     * Disable DNSSEC. PUT dns/v2/zone/{zone}/subscription/dnssec/disable.
     */
    public function deleteZoneSecurity($domain)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return false;
        }
        $disable = $this->request("PUT", "zone/" . rawurlencode($domain) . "/subscription/dnssec/disable");
        if (!$this->ok($disable)) {
            return false;
        }
        $client_id = $this->client_data["id"] ?? false;
        $domain = rtrim($domain, ".");
        if (Engine::getType() == "admin" && !$client_id) {
            $client_id = $this->getClientIdByDomain($domain);
        }
        HBEventManager::notify("after_dns_zone_unsecured", ["domain" => $domain, "client_id" => $client_id]);
        $this->addInfo("DNSSEC disabled");
        return true;
    }

    /**
     * Rebuild DNSSEC: disable then re-enable, syncing HostBill's DNSSEC store.
     */
    public function rectifyZone($domain)
    {
        $domain = rtrim($domain, ".");
        if (!empty($this->account_details["id"])) {
            $dnsman = HBLoader::LoadComponent("DnsManage");
            $dom = $dnsman->getDomain($this->account_details["id"], $domain);
        } else {
            $dom = $this->getDNSDomain($domain);
        }
        if (!is_numeric($dom["domain_id"] ?? null)) {
            return false;
        }
        $sectools = new DNSSEC_Tools();
        if ($this->deleteZoneSecurity($domain)) {
            $sectools->removeZone($dom["domain_id"]);
        }
        if ($k = $this->secureZone($domain)) {
            $sectools->secureZone($dom["domain_id"], $k["keys"] ?? [], $k["ds"] ?? []);
        }
        $this->addInfo("dnssec_has_rectified");
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Zones (extended): search / NS subscription / statistics             */
    /* ------------------------------------------------------------------ */

    /**
     * Search domain zones (the canonical zone list; "Get Domain Zone" is
     * deprecated in favour of this). GET dns/v2/zones with optional filters:
     *   zone, zoneType (inzone|outzone), subscription
     *   (premium_ns_partner|premium_ns_georoute), subscriptionId, limit (<=100).
     * Returns the raw data rows, each: zone, zoneType, subscription,
     * subscriptionId, dtcreate, dtmodify.
     */
    public function searchZones(array $filters = [])
    {
        $allowed = ["zone", "zoneType", "subscription", "subscriptionId", "limit"];
        $query = [];
        foreach ($allowed as $k) {
            if (isset($filters[$k]) && $filters[$k] !== "") {
                $query[] = rawurlencode($k) . "=" . rawurlencode((string) $filters[$k]);
            }
        }
        if (!isset($filters["limit"])) {
            $query[] = "limit=100";
        }
        $path = "zones" . (empty($query) ? "" : "?" . implode("&", $query));
        $resp = $this->request("GET", $path, [], false);
        if ($this->ok($resp) && is_array($resp["data"] ?? null)) {
            return $resp["data"];
        }
        return [];
    }

    /**
     * Nameserver subscription detail for one zone (replaces the deprecated
     * Get Domain Zone for per-zone info).
     * GET dns/v2/zone/{zone}/nameserver-subscription.
     */
    public function getZoneNsSubscription($domain)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return false;
        }
        $resp = $this->request("GET", "zone/" . rawurlencode($domain) . "/nameserver-subscription", [], false);
        return $this->ok($resp) ? ($resp["data"] ?? []) : false;
    }

    /**
     * Partner-wide zone statistics.
     * GET dns/v2/statistics -> {totalBasicNs, totalPremiumNs, totalGeoroute}.
     */
    public function getZoneStatistics()
    {
        $resp = $this->request("GET", "statistics", [], false);
        return $this->ok($resp) ? ($resp["data"] ?? []) : false;
    }

    /**
     * Premium subscription usage statistics (per-day record counts).
     * GET dns/v2/premium-subscription/statistics -> [{records, activityDate}].
     */
    public function getPremiumSubscriptionStatistics()
    {
        $resp = $this->request("GET", "premium-subscription/statistics", [], false);
        return $this->ok($resp) && is_array($resp["data"] ?? null) ? $resp["data"] : [];
    }

    /* ------------------------------------------------------------------ */
    /* Zone Forwarding (URL + Email)                                        */
    /* ------------------------------------------------------------------ */

    /**
     * List URL forwardings for a zone.
     * GET dns/v2/zone/{zone}/url-forwardings
     *   -> [{zone, subdomain, targetUrl, status, dtcreate, dtmodify}].
     */
    public function getUrlForwardings($domain)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return [];
        }
        $resp = $this->request("GET", "zone/" . rawurlencode($domain) . "/url-forwardings", [], false);
        return $this->ok($resp) && is_array($resp["data"] ?? null) ? $resp["data"] : [];
    }

    /**
     * Add a URL forwarding.
     * POST dns/v2/zone/{zone}/url-forwarding
     * body: {subdomain, targetUrl, overrideConflictingRecord}.
     */
    public function addUrlForwarding($domain, $subdomain, $targetUrl, $override = false)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain) || $subdomain === "" || $targetUrl === "") {
            $this->addError("Subdomain and target URL are required");
            return false;
        }
        $body = [
            "subdomain" => (string) $subdomain,
            "targetUrl" => (string) $targetUrl,
            "overrideConflictingRecord" => (bool) $override,
        ];
        $resp = $this->request("POST", "zone/" . rawurlencode($domain) . "/url-forwarding", $body);
        if ($this->ok($resp)) {
            $this->addInfo("URL forwarding added: " . $subdomain);
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * Remove a URL forwarding (selector passed as query string).
     * DELETE dns/v2/zone/{zone}/url-forwarding?subdomain=...
     */
    public function removeUrlForwarding($domain, $subdomain)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain) || $subdomain === "") {
            return false;
        }
        $resp = $this->request("DELETE", "zone/" . rawurlencode($domain) . "/url-forwarding?subdomain=" . rawurlencode((string) $subdomain));
        if ($this->ok($resp)) {
            $this->addInfo("URL forwarding removed: " . $subdomain);
            return true;
        }
        return false;
    }

    /**
     * List email forwardings for a zone.
     * GET dns/v2/zone/{zone}/email-forwardings
     *   -> [{zone, user, targetEmail, status, dtcreate, dtmodify}].
     */
    public function getEmailForwardings($domain)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return [];
        }
        $resp = $this->request("GET", "zone/" . rawurlencode($domain) . "/email-forwardings", [], false);
        return $this->ok($resp) && is_array($resp["data"] ?? null) ? $resp["data"] : [];
    }

    /**
     * Add an email forwarding.
     * POST dns/v2/zone/{zone}/email-forwarding
     * body: {user, targetEmail, overrideConflictingRecord}.
     */
    public function addEmailForwarding($domain, $user, $targetEmail, $override = false)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain) || $user === "" || $targetEmail === "") {
            $this->addError("Source user and target email are required");
            return false;
        }
        $body = [
            "user" => (string) $user,
            "targetEmail" => (string) $targetEmail,
            "overrideConflictingRecord" => (bool) $override,
        ];
        $resp = $this->request("POST", "zone/" . rawurlencode($domain) . "/email-forwarding", $body);
        if ($this->ok($resp)) {
            $this->addInfo("Email forwarding added: " . $user);
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * Remove an email forwarding (selector passed as query string).
     * DELETE dns/v2/zone/{zone}/email-forwarding?user=...
     */
    public function removeEmailForwarding($domain, $user)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain) || $user === "") {
            return false;
        }
        $resp = $this->request("DELETE", "zone/" . rawurlencode($domain) . "/email-forwarding?user=" . rawurlencode((string) $user));
        if ($this->ok($resp)) {
            $this->addInfo("Email forwarding removed: " . $user);
            return true;
        }
        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Whitelabel Nameservers (partner-wide)                               */
    /* ------------------------------------------------------------------ */

    /**
     * Current whitelabel nameservers for the partner.
     * GET dns/v2/subscription/whitelabel/ns -> {nameservers[], adminEmail}.
     */
    public function getWhitelabelNameservers()
    {
        $resp = $this->request("GET", "subscription/whitelabel/ns", [], false);
        return $this->ok($resp) ? ($resp["data"] ?? []) : false;
    }

    /**
     * Save whitelabel nameservers.
     * POST dns/v2/subscription/whitelabel/ns body: {nameservers[], adminEmail}.
     * In the VN market, falls back to a VN-style admin email default when the
     * caller leaves it blank.
     */
    public function saveWhitelabelNameservers($nameservers, $adminEmail = "")
    {
        if (is_string($nameservers)) {
            $nameservers = preg_split('/[\s,]+/', trim($nameservers));
        }
        $nameservers = array_values(array_filter(array_map("trim", (array) $nameservers), "strlen"));
        $adminEmail = trim((string) $adminEmail);
        if ($adminEmail === "" && $this->isVN()) {
            $adminEmail = (string) $this->getCustom("whitelabel_admin_email");
        }
        if (empty($nameservers) || $adminEmail === "") {
            $this->addError("Whitelabel nameservers and admin email are required");
            return false;
        }
        $body = ["nameservers" => $nameservers, "adminEmail" => $adminEmail];
        $resp = $this->request("POST", "subscription/whitelabel/ns", $body);
        if ($this->ok($resp)) {
            $this->addInfo("Whitelabel nameservers saved");
            return true;
        }
        return false;
    }

    /**
     * Remove the partner whitelabel nameservers.
     * DELETE dns/v2/subscription/whitelabel/ns.
     */
    public function removeWhitelabelNameservers()
    {
        $resp = $this->request("DELETE", "subscription/whitelabel/ns");
        if ($this->ok($resp)) {
            $this->addInfo("Whitelabel nameservers removed");
            return true;
        }
        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Zone (Subscription) Record Templates (partner-wide, full CRUD)      */
    /* ------------------------------------------------------------------ */

    /**
     * List record templates. GET dns/v2/zone/subscription/templates
     * (optional filters: name, limit).
     */
    public function listRecordTemplates($name = "", $limit = "")
    {
        $query = [];
        if ($name !== "") {
            $query[] = "name=" . rawurlencode((string) $name);
        }
        if ($limit !== "") {
            $query[] = "limit=" . rawurlencode((string) $limit);
        }
        $path = "zone/subscription/templates" . (empty($query) ? "" : "?" . implode("&", $query));
        $resp = $this->request("GET", $path, [], false);
        return $this->ok($resp) && is_array($resp["data"] ?? null) ? $resp["data"] : [];
    }

    /**
     * Create a record template. POST dns/v2/zone/subscription/template
     * body: {templateName, defaultTemplate}.
     */
    public function createRecordTemplate($templateName, $defaultTemplate = false)
    {
        if (trim((string) $templateName) === "") {
            $this->addError("Template name is required");
            return false;
        }
        $body = ["templateName" => (string) $templateName, "defaultTemplate" => (bool) $defaultTemplate];
        $resp = $this->request("POST", "zone/subscription/template", $body);
        if ($this->ok($resp)) {
            $this->addInfo("Record template created: " . $templateName);
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * One record template by id.
     * GET dns/v2/zone/subscription/template/{templateId}.
     */
    public function getRecordTemplate($templateId)
    {
        if ((string) $templateId === "") {
            return false;
        }
        $resp = $this->request("GET", "zone/subscription/template/" . rawurlencode((string) $templateId), [], false);
        return $this->ok($resp) ? ($resp["data"] ?? []) : false;
    }

    /**
     * Update a record template's name / default flag.
     * PUT dns/v2/zone/subscription/template/{templateId}
     * body: {templateName, defaultTemplate}.
     */
    public function updateRecordTemplate($templateId, $templateName, $defaultTemplate = false)
    {
        if ((string) $templateId === "" || trim((string) $templateName) === "") {
            $this->addError("Template id and name are required");
            return false;
        }
        $body = ["templateName" => (string) $templateName, "defaultTemplate" => (bool) $defaultTemplate];
        $resp = $this->request("PUT", "zone/subscription/template/" . rawurlencode((string) $templateId), $body);
        if ($this->ok($resp)) {
            $this->addInfo("Record template updated");
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * Add a record to a template.
     * POST dns/v2/zone/subscription/template/{templateId}/add
     * body: {type, name, ttl, rdatas:[{value}]}.
     */
    public function addRecordToTemplate($templateId, array $record)
    {
        if ((string) $templateId === "") {
            return false;
        }
        $body = $this->buildTemplateRecord($record, true);
        if ($body === false) {
            $this->addError("Template record type, name and value are required");
            return false;
        }
        $resp = $this->request("POST", "zone/subscription/template/" . rawurlencode((string) $templateId) . "/add", $body);
        if ($this->ok($resp)) {
            $this->addInfo("Record added to template");
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * Remove a record from a template (matched by type/name/rdatas; no ttl).
     * POST dns/v2/zone/subscription/template/{templateId}/remove
     * body: {type, name, rdatas:[{value}]}.
     */
    public function removeRecordFromTemplate($templateId, array $record)
    {
        if ((string) $templateId === "") {
            return false;
        }
        $body = $this->buildTemplateRecord($record, false);
        if ($body === false) {
            $this->addError("Template record type, name and value are required");
            return false;
        }
        $resp = $this->request("POST", "zone/subscription/template/" . rawurlencode((string) $templateId) . "/remove", $body);
        if ($this->ok($resp)) {
            $this->addInfo("Record removed from template");
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * Delete a record template.
     * DELETE dns/v2/zone/subscription/template/{templateId}.
     */
    public function deleteRecordTemplate($templateId)
    {
        if ((string) $templateId === "") {
            return false;
        }
        $resp = $this->request("DELETE", "zone/subscription/template/" . rawurlencode((string) $templateId));
        if ($this->ok($resp)) {
            $this->addInfo("Record template deleted");
            return true;
        }
        return false;
    }

    /**
     * Shape a template record body. ADD requires ttl; REMOVE omits it. rdatas is
     * always an array of {value} objects. Accepts rdatas as array or a single
     * string "content".
     */
    protected function buildTemplateRecord(array $record, $withTtl)
    {
        $type = strtoupper(trim((string) ($record["type"] ?? "")));
        $name = (string) ($record["name"] ?? "");
        $rdatas = [];
        if (isset($record["rdatas"]) && is_array($record["rdatas"])) {
            foreach ($record["rdatas"] as $rd) {
                $v = is_array($rd) ? (string) ($rd["value"] ?? "") : (string) $rd;
                if ($v !== "") {
                    $rdatas[] = ["value" => $v];
                }
            }
        } elseif (isset($record["content"]) && (string) $record["content"] !== "") {
            $rdatas[] = ["value" => (string) $record["content"]];
        }
        if ($type === "" || $name === "" || empty($rdatas)) {
            return false;
        }
        $body = ["type" => $type, "name" => $name, "rdatas" => $rdatas];
        if ($withTtl) {
            $body["ttl"] = $this->fixTtl($record["ttl"] ?? 3600);
        }
        return $body;
    }

    /* ------------------------------------------------------------------ */
    /* Subscription management (NS + partner + standalone-domain premium)  */
    /* ------------------------------------------------------------------ */

    /**
     * Subscribe a zone to WebNIC nameservers at a tier.
     * POST dns/v2/zone/{zone}/nameserver-subscription
     * body: {subscriptionProduct: basic_ns|premium_ns_partner|premium_ns_georoute}.
     */
    public function addZoneToNsSubscription($domain, $subscriptionProduct = "basic_ns")
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return false;
        }
        if (!in_array($subscriptionProduct, ["basic_ns", "premium_ns_partner", "premium_ns_georoute"], true)) {
            $subscriptionProduct = "basic_ns";
        }
        $resp = $this->request("POST", "zone/" . rawurlencode($domain) . "/nameserver-subscription", ["subscriptionProduct" => $subscriptionProduct]);
        if ($this->ok($resp)) {
            if (!empty($resp["data"]["subscriptionId"])) {
                $this->details["option1"]["value"] = $resp["data"]["subscriptionId"];
            }
            $this->addInfo("Zone NS subscription set: " . $subscriptionProduct);
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * Add a zone to the partner (premium reseller) subscription.
     * POST dns/v2/subscription/partner/zone/{zone}.
     */
    public function addZoneToPartnerSubscription($domain)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return false;
        }
        $resp = $this->request("POST", "subscription/partner/zone/" . rawurlencode($domain));
        if ($this->ok($resp)) {
            $this->addInfo("Zone added to partner subscription: " . $domain);
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * Remove a zone from the partner subscription.
     * DELETE dns/v2/subscription/partner/zone/{zone}.
     */
    public function removeZoneFromPartnerSubscription($domain)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return false;
        }
        $resp = $this->request("DELETE", "subscription/partner/zone/" . rawurlencode($domain));
        if ($this->ok($resp)) {
            $this->addInfo("Zone removed from partner subscription: " . $domain);
            return true;
        }
        return false;
    }

    /**
     * List standalone-domain (premium) subscriptions.
     * GET dns/v2/subscription/standalone-domains (optional filters:
     * domainName, subscriptionId, subscriptionProduct, subscriptionAutoRenew,
     * limit).
     */
    public function listDomainSubscriptions(array $filters = [])
    {
        $allowed = ["domainName", "subscriptionId", "subscriptionProduct", "subscriptionAutoRenew", "limit"];
        $query = [];
        foreach ($allowed as $k) {
            if (isset($filters[$k]) && $filters[$k] !== "") {
                $query[] = rawurlencode($k) . "=" . rawurlencode((string) $filters[$k]);
            }
        }
        $path = "subscription/standalone-domains" . (empty($query) ? "" : "?" . implode("&", $query));
        $resp = $this->request("GET", $path, [], false);
        return $this->ok($resp) && is_array($resp["data"] ?? null) ? $resp["data"] : [];
    }

    /**
     * Subscribe a domain to premium (standalone) DNS.
     * POST dns/v2/subscription/standalone-domain
     * body: {domainName, subscriptionProduct, subscriptionTerm, subscriptionAutoRenew}.
     */
    public function subscribeDomain($domain, $subscriptionProduct = "premium_ns_georoute", $term = 12, $autoRenew = false)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return false;
        }
        $term = (int) $term;
        if (!in_array($term, [1, 12], true)) {
            $term = 12;
        }
        $body = [
            "domainName" => $domain,
            "subscriptionProduct" => (string) $subscriptionProduct,
            "subscriptionTerm" => $term,
            "subscriptionAutoRenew" => (bool) $autoRenew,
        ];
        $resp = $this->request("POST", "subscription/standalone-domain", $body);
        if ($this->ok($resp)) {
            if (!empty($resp["data"]["subscriptionId"])) {
                $this->details["option1"]["value"] = $resp["data"]["subscriptionId"];
            }
            $this->addInfo("Premium DNS subscribed: " . $domain);
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * Convenience: upgrade a zone from basic to premium (standalone) DNS.
     */
    public function upgradeToPremium($domain, $term = 12, $autoRenew = false)
    {
        return $this->subscribeDomain($domain, "premium_ns_georoute", $term, $autoRenew);
    }

    /**
     * One premium subscription by id.
     * GET dns/v2/subscription/standalone-domain/{subscriptionId}.
     */
    public function getDomainSubscriptionById($subscriptionId)
    {
        if ((string) $subscriptionId === "") {
            return false;
        }
        $resp = $this->request("GET", "subscription/standalone-domain/" . rawurlencode((string) $subscriptionId), [], false);
        return $this->ok($resp) ? ($resp["data"] ?? []) : false;
    }

    /**
     * One premium subscription by domain name.
     * GET dns/v2/subscription/standalone-domain/get-by-domain?domainName=...
     */
    public function getDomainSubscriptionByDomain($domain)
    {
        $domain = $this->getDomainName($domain);
        if (empty($domain)) {
            return false;
        }
        $resp = $this->request("GET", "subscription/standalone-domain/get-by-domain?domainName=" . rawurlencode($domain), [], false);
        return $this->ok($resp) ? ($resp["data"] ?? []) : false;
    }

    /**
     * Renew a premium subscription.
     * PUT dns/v2/subscription/standalone-domain/{subscriptionId}/renew
     * body: {subscriptionTerm}.
     */
    public function renewDomainSubscription($subscriptionId, $term = 12)
    {
        if ((string) $subscriptionId === "") {
            return false;
        }
        $term = (int) $term;
        if (!in_array($term, [1, 12], true)) {
            $term = 12;
        }
        $resp = $this->request("PUT", "subscription/standalone-domain/" . rawurlencode((string) $subscriptionId) . "/renew", ["subscriptionTerm" => $term]);
        if ($this->ok($resp)) {
            $this->addInfo("Premium DNS subscription renewed");
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * Unsubscribe (cancel) a premium subscription.
     * DELETE dns/v2/subscription/standalone-domain/{subscriptionId}.
     */
    public function unsubscribeDomain($subscriptionId)
    {
        if ((string) $subscriptionId === "") {
            return false;
        }
        $resp = $this->request("DELETE", "subscription/standalone-domain/" . rawurlencode((string) $subscriptionId));
        if ($this->ok($resp)) {
            $this->addInfo("Premium DNS subscription cancelled");
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * Enable auto-renewal on a premium subscription.
     * PUT dns/v2/subscription/standalone-domain/{subscriptionId}/auto-renewal/enable.
     */
    public function enableAutoRenewal($subscriptionId)
    {
        if ((string) $subscriptionId === "") {
            return false;
        }
        $resp = $this->request("PUT", "subscription/standalone-domain/" . rawurlencode((string) $subscriptionId) . "/auto-renewal/enable");
        if ($this->ok($resp)) {
            $this->addInfo("Auto-renewal enabled");
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * Disable auto-renewal on a premium subscription.
     * PUT dns/v2/subscription/standalone-domain/{subscriptionId}/auto-renewal/disable.
     */
    public function disableAutoRenewal($subscriptionId)
    {
        if ((string) $subscriptionId === "") {
            return false;
        }
        $resp = $this->request("PUT", "subscription/standalone-domain/" . rawurlencode((string) $subscriptionId) . "/auto-renewal/disable");
        if ($this->ok($resp)) {
            $this->addInfo("Auto-renewal disabled");
            return $resp["data"] ?? true;
        }
        return false;
    }

    /**
     * Toggle auto-renewal on a premium subscription.
     */
    public function toggleAutoRenewal($subscriptionId, $enable)
    {
        return $enable ? $this->enableAutoRenewal($subscriptionId) : $this->disableAutoRenewal($subscriptionId);
    }

    /* ------------------------------------------------------------------ */
    /* Visibility toggles                                                  */
    /* ------------------------------------------------------------------ */

    public function showBillingInfo()
    {
        return $this->options["hide_billing"]["value"] == "1" ? false : true;
    }

    public function showZoneManagement()
    {
        return $this->options["hide_zone_management"]["value"] == "1" ? false : true;
    }

    /* ------------------------------------------------------------------ */
    /* Misc helpers                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Resolve a domain name from a HostBill DNS domain id (or pass through a
     * literal name).
     */
    protected function getDomainName($domain)
    {
        if (is_string($domain) && !is_numeric($domain)) {
            return $domain;
        }
        $dnsman = HBLoader::LoadComponent("DnsManage");
        $dom = $dnsman->getDomain($this->account_details["id"], $domain);
        if ($dom) {
            return $dom[DnsManage::COLUMN_DOMAIN_NAME];
        }
        return false;
    }

    protected function getClientIdByDomain($domain)
    {
        if (!$domain) {
            return false;
        }
        $q = $this->db->prepare("SELECT a.client_id FROM hb_dns_domains d LEFT JOIN hb_accounts a ON (d.account_id = a.id) WHERE d.domain = :domain");
        $q->execute([":domain" => $domain]);
        $d = $q->fetch(PDO::FETCH_ASSOC);
        $q->closeCursor();
        return $d ? $d["client_id"] : false;
    }

    protected function getDNSDomain($domain)
    {
        $q = $this->db->prepare("SELECT * FROM hb_dns_domains WHERE `domain` = :domain");
        $q->execute([":domain" => $domain]);
        $dom = $q->fetch(PDO::FETCH_ASSOC);
        $q->closeCursor();
        return $dom;
    }

    /**
     * Snap a TTL to a sane value (WebNIC minimum is 60s).
     */
    protected function fixTtl($ttl)
    {
        $ttl = (int) $ttl;
        if ($ttl < 60) {
            return 3600;
        }
        return $ttl;
    }
}

?>
