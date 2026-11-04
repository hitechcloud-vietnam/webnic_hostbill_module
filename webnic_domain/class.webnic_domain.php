<?php
/**
 * WebNIC Domain Registrar Module (webnic_domain)
 * ------------------------------------------------------------------
 * A clean, from-scratch HostBill domain registrar module for WebNIC,
 * built against the WebNIC REST v2 API.
 *
 * Authoritative API facts:
 *   - LIVE base  : https://api.webnic.cc/
 *   - TEST base  : https://oteapi.webnic.cc/
 *   - "OTE Environment" checkbox: value==1 -> TEST, otherwise LIVE
 *     (mirrors: $live = OTE != 1).
 *   - Auth       : POST {base}reseller/v2/api-user/token  {username,password}
 *                  -> {code:1000, data:{access_token, expires_in}}
 *   - Success    : top-level code == 1000, payload in `data`.
 *   - Errors     : `message` and `error.message`.
 *   - Headers    : Content-Type: application/json, Authorization: Bearer <token>
 *
 * This module is self-contained under webnic_domain/ and does NOT reference
 * any other WebNIC module at runtime.
 *
 * @package   HiTechCloud WebNIC Suite
 * @author    HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @copyright 2026 HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @license   MIT
 * @version   1.2026-11-03.1
 */

use Domain\webnic_domain\ORM\ORM_domcontacts as DContacts;
use Site\domains\ORM\Domain as ORM_DOMAIN;
use GuzzleHttp\Client;

require_once APPDIR_LIBS . DS . 'idn' . DS . 'class.idn.php';

// Self-contained dependencies (do not rely on autoload for the ORM / filter).
require_once __DIR__ . DS . 'orm' . DS . 'class.orm_domcontacts.php';
require_once __DIR__ . DS . 'libs' . DS . 'class.domainfilter.php';

/**
 * Class webnic_domain
 */
class webnic_domain extends DomainModule implements Observer, DomainLookupInterface, DomainPremiumInterface, DomainPriceImport
{
    use Components\Traits\LoggerTrait;

    const LOG_FILE = 'webnic_domain.log';

    /** Cache + config storage key used by this module (isolated from webnicv2). */
    const STORE_KEY = 'webnic_domain';

    /**
     * Module repository key.
     * @var string
     */
    protected $_repository = 'domain_webnic_domain';

    /**
     * Module version. Bump on every feature change so HostBill re-reads the contract.
     * @var string
     */
    protected $version = '1.2026-11-03.1';

    /**
     * Module name, visible in admin portal.
     * @var string
     */
    protected $modname = 'WebNIC Domain';

    /**
     * Module description, visible in admin portal.
     * @var string
     */
    protected $description = 'WebNIC domain registrar module (REST v2)';

    /**
     * Guzzle HTTP client.
     * @var \GuzzleHttp\Client
     */
    protected $http;

    /**
     * Module capability flags.
     * @var bool[]
     */
    protected $info = [
        'haveadmin'       => true,   // has admin controller
        'haveuser'        => false,  // no dedicated client controller
        'havetpl'         => false,  // no front-end tpl output
        'haveapi'         => false,  // no public api controller
        'havecron'        => false,  // no cron controller
        'isobserver'      => true,   // reacts to hooks
        'extras_menu'     => true,   // link in admin "Extras" menu
        'client_mainmenu' => false,  // no client menu link
        'header_js'       => true,
        'admin_mainmenu'  => true,   // link in admin Setup -> Other Modules
    ];

    /**
     * Cached access token (in-memory for this request).
     * @var string
     */
    protected $token;

    /**
     * Language strings.
     * @var array
     */
    protected $lang = [
        'english' => [
            'source'             => 'Source',
            'registrant_user_id' => 'Registrant User ID',
            'hide_form'          => 'Hide forms during transfer',
            'test'               => 'Test Mode',
        ],
    ];

    /**
     * Registrar configuration, rendered in Settings -> Apps.
     * @var array
     */
    protected $configuration = [
        'Username' => [
            'value'   => '',
            'type'    => 'input',
            'default' => false,
        ],
        'Password' => [
            'value'   => '',
            'type'    => 'password',
            'default' => false,
        ],
        'OTE Environment' => [
            'value'   => '',
            'type'    => 'check',
            'default' => false,
        ],
        // Connect market. VN enforces Vietnamese registrant rules, EKYC,
        // proxy-TLD handling and VND price conversion; Global uses the
        // standard international flow with source-currency pricing.
        'Market' => [
            'value'   => 'vn',
            'type'    => 'select',
            'default' => ['vn' => 'Vietnam (VN)', 'global' => 'Global'],
        ],
        'registrant_user_id' => [
            'value'   => '',
            'type'    => 'input',
            'default' => false,
        ],
    ];

    /**
     * TLDs that support WebNIC proxy/local-presence.
     * @var string[]
     */
    protected $proxy_tlds = [
        'my', 'com.my', 'net.my', 'org.my', 'sg', 'com.sg', 'asia',
        'kr', 'co.kr', 'it', 'de', 'jp', 'id', 'co.id', 'web.id',
    ];

    /** Extended-attribute field name that toggles proxy. */
    protected $proxy_field = 'webnicproxy';

    /** Prefix for locally generated contact handles. */
    protected $prefix = 'WEBNICDOM-';

    /**
     * Commands available to admin/automation.
     * @var array
     */
    protected $commands = [
        'Register',
        'Transfer',
        'Renew',
        'RestoreDomain',
        'EppCode',
        'ManualRegister',
        'ContactInfo',
        'Suspended',
        'UnSuspended',
        'SendVerify',
    ];

    /**
     * Commands available to the client.
     * @var array
     */
    protected $clientCommands = ['EppCode', 'ContactInfo', 'SendVerify'];

    /**
     * API hosts.
     * @var string[]
     */
    protected $host = [
        'live' => 'https://api.webnic.cc/',
        'test' => 'https://oteapi.webnic.cc/',
    ];

    /**
     * Token endpoints.
     * @var string[]
     */
    protected $tokenEndpoint = [
        'live' => 'https://api.webnic.cc/reseller/v2/api-user/token',
        'test' => 'https://oteapi.webnic.cc/reseller/v2/api-user/token',
    ];

    /** Fallback nameservers used when none supplied. */
    protected $defaultNameservers = ['ns1.photuesoftware.vn', 'ns2.photuesoftware.vn'];

    /** Nameservers used while a domain is on hold/suspended. */
    protected $holdNameservers = ['ns1-hold.photuesoftware.vn', 'ns2-hold.photuesoftware.vn'];

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->init();

        if (Engine::getType() != 'admin' || RequestHandler::singleton()->getController() != 'servers') {
            return false;
        }
    }

    public function __wakeup()
    {
        $this->init();
    }

    /**
     * Initialise the HTTP client.
     */
    protected function init()
    {
        // Bound the connect phase and the overall request so an unreachable
        // WebNIC host (IP not whitelisted / firewall dropping outbound) cannot
        // freeze the admin UI on price/lookup/product calls. Guzzle defaults to
        // no timeout at all, so these must be set explicitly. All API traffic
        // flows through this client, so the defaults cover every call.
        $this->http = new Client([
            'connect_timeout' => 10,
            'timeout'         => 30,
        ]);
    }

    /* =====================================================================
     *  API CLIENT
     * ===================================================================== */

    /** Whether the module requires authentication. */
    public function needAuth()
    {
        return true;
    }

    /** Public accessor: the prepared punycode domain name for the loaded domain. */
    public function apiDomainName()
    {
        if (empty($this->name)) {
            $this->_prepare_name();
        }
        return $this->name;
    }

    /** Is the configured environment LIVE? (OTE != 1) */
    protected function isLive()
    {
        return $this->configuration['OTE Environment']['value'] != 1;
    }

    /**
     * Is the module connecting to the Vietnam market?
     * Gates all Vietnam-specific behavior (registrant-name rules, EKYC,
     * proxy-TLD handling and VND price conversion). Defaults to VN when
     * the Market selector has never been saved.
     * @return bool
     */
    protected function isVN()
    {
        $market = $this->configuration['Market']['value'] ?? 'vn';
        return $market === '' || $market === 'vn';
    }

    /** Base host for the current environment. */
    protected function baseHost()
    {
        return $this->isLive() ? $this->host['live'] : $this->host['test'];
    }

    /**
     * Fetch (and cache) a WebNIC access token.
     *
     * @return bool
     * @throws \ErrorException on auth failure.
     */
    protected function access_token()
    {
        $user     = $this->configuration['Username']['value'];
        $pass     = $this->configuration['Password']['value'];
        $endpoint = $this->isLive() ? $this->tokenEndpoint['live'] : $this->tokenEndpoint['test'];

        // Reuse a cached token when present.
        $cached = HBCache::get(self::STORE_KEY);
        if ($cached) {
            return true;
        }

        $resp = null;
        try {
            $re = $this->http->post($endpoint, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => ['username' => $user, 'password' => $pass],
            ]);
            $resp = json_decode($re->getBody(), true);

            if (isset($resp['code']) && $resp['code'] == 1000) {
                HBCache::set(self::STORE_KEY, $resp['data']['access_token'], $resp['data']['expires_in']);
                HBConfig::setConfig(self::STORE_KEY, json_encode($resp['data']), true);
                return true;
            }
        } catch (\Exception $e) {
            // fall through to the thrown error below
        }

        throw new \ErrorException(implode(': ', [
            $resp['message'] ?? 'Authentication failed',
            $resp['error']['message'] ?? '',
        ]));
    }

    /** GET helper. */
    public function getApi($url)
    {
        return $this->request('GET', $url);
    }

    /** POST helper. */
    public function postApi($url, $params = [])
    {
        return $this->request('POST', $url, $params);
    }

    /** PUT helper. */
    public function putApi($url, $params = [])
    {
        return $this->request('PUT', $url, $params);
    }

    /** DELETE helper. */
    public function deleteApi($url)
    {
        return $this->request('DELETE', $url);
    }

    /**
     * Perform an authenticated WebNIC API request.
     * Refreshes the token once and retries on auth failure.
     *
     * @param string $type   HTTP verb.
     * @param string $url    Path relative to the base host.
     * @param array  $params JSON body for POST/PUT.
     * @return array decoded JSON response.
     */
    public function request($type, $url, $params = [], $retry = true)
    {
        try {
            $this->access_token();
        } catch (\Exception $e) {
            return ['code' => 'error', 'message' => $e->getMessage(), 'error' => ['message' => $e->getMessage()]];
        }

        $config = json_decode(HBConfig::getConfig(self::STORE_KEY), true);
        $token  = $config['access_token'] ?? '';
        $method = strtolower($type);
        $full   = $this->baseHost() . $url;

        $options = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
        ];
        if (!empty($params) || in_array($method, ['post', 'put'])) {
            $options['json'] = $params;
        }

        try {
            $re = $this->http->{$method}($full, $options);
            return json_decode($re->getBody(), true);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            $decoded  = $response ? json_decode($response->getBody(), true) : null;

            // On 401/403 refresh the token once and retry.
            if ($retry && $response && in_array($response->getStatusCode(), [401, 403])) {
                HBCache::delete(self::STORE_KEY);
                HBConfig::setConfig(self::STORE_KEY, json_encode([]), true);
                return $this->request($type, $url, $params, false);
            }
            return $decoded ?: ['code' => 'error', 'message' => $e->getMessage()];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** Was an API response successful (code == 1000)? */
    protected function ok($resp)
    {
        return isset($resp['code']) && (string) $resp['code'] === '1000';
    }

    /** Extract a human-readable error message from a response. */
    protected function errMsg($resp)
    {
        if (isset($resp['error']['message'])) {
            return $resp['error']['message'];
        }
        if (isset($resp['message'])) {
            return $resp['message'];
        }
        return 'Unknown WebNIC API error';
    }

    /* =====================================================================
     *  NAME / CONNECTION
     * ===================================================================== */

    /**
     * Build the full punycode domain name from options into $this->name.
     */
    protected function _prepare_name()
    {
        $sld = rtrim($this->options['sld'], '.');
        $tld = ltrim($this->options['tld'], '.');
        $idn = new IDN();
        $tld = $idn->decode($tld);
        if (!$tld) {
            return null;
        }
        $this->name = $sld . '.' . $tld;
    }

    /**
     * Test the API credentials by querying the reseller balance.
     * @return bool
     */
    public function testConnection()
    {
        $resp = $this->getApi('reseller/v2/balance');

        if ($this->ok($resp)) {
            $balance = $resp['data']['balance'][0] ?? null;
            if ($balance) {
                $this->addInfo('Account balance: ' . number_format($balance['amount'], 2) . ' ' . $balance['currency']);
            } else {
                $this->addInfo('Connected, but no balance information was returned.');
            }
            return true;
        }

        $this->addError('WebNIC API error: ' . $this->errMsg($resp) . ' (code: ' . ($resp['code'] ?? 'n/a') . ')');
        return false;
    }

    /* =====================================================================
     *  AVAILABILITY / LOOKUP / PREMIUM
     * ===================================================================== */

    /**
     * Quick availability check on $this->name.
     * @return bool
     */
    private function checkAvailability()
    {
        try {
            $resp = $this->getApi('domain/v2/query?domainName=' . $this->name);
            if ($this->ok($resp) && !empty($resp['data']['available'])) {
                return true;
            }
        } catch (\Exception $ex) {
            $this->addError($ex->getMessage());
        }
        return false;
    }

    /**
     * DomainLookupInterface + DomainPremiumInterface: availability & premium pricing.
     *
     * @param string $sld
     * @param string $tld
     * @param array  $settings
     * @return array {available:bool, premium?:array}
     */
    public function lookupDomain($sld, $tld, $settings = [])
    {
        $name   = rtrim($sld, '.') . '.' . ltrim($tld, '.');
        $name   = HBLoader::LoadModel('Domains')->getDomainIDN($name);
        $return = ['available' => false];

        $response = $this->getApi('domain/v2/query?domainName=' . $name);
        if ($this->ok($response)) {
            $data = $response['data'];
            if (!$data) {
                return $return;
            }
            $return['available'] = $data['available'];
            if (!empty($response['data']['premium']) && array_key_exists('premiumInfo', $data)) {
                $return['premium'] = [
                    'register' => $data['premiumInfo']['registerPrice'],
                    'renew'    => $data['premiumInfo']['renewPrice'],
                    'transfer' => $data['premiumInfo']['transferPrice'],
                    'currency' => $data['premiumInfo']['currency'],
                ];
            }
        }
        return $return;
    }

    /**
     * DomainPriceImport: import TLD pricing (register/renew/transfer/redemption).
     * Prices are converted from WebNIC's thousand-unit values to VND.
     * @return array
     */
    public function getDomainPrices()
    {
        $prices = [];
        try {
            $tldsResponse = $this->getApi('domain/v2/exts');
            if (!$this->ok($tldsResponse)) {
                return $prices;
            }

            // Vietnam market skips .vn TLDs (handled by the local VNNIC
            // registrar). The Global market imports every TLD, .vn included.
            if ($this->isVN()) {
                $tldsResponse['data'] = array_filter($tldsResponse['data'], function ($item) {
                    return substr($item, -2) !== 'vn';
                });
            }

            $tldString   = implode(',', $tldsResponse['data']);
            $priceParams = [
                'filters'    => [
                    ['field' => 'productKey', 'value' => $tldString],
                    ['field' => 'transtype', 'value' => 'register,renewal,transfer,restore'],
                ],
                'pagination' => ['page' => 1, 'pageSize' => 1],
            ];

            $pricesResponse = $this->postApi('domain/v2/exts/pricing', $priceParams);
            if (!$this->ok($pricesResponse)) {
                return $prices;
            }

            // Second call pulls every item in one page.
            $priceParams['pagination']['pageSize'] = $pricesResponse['data']['totalItems'];
            $pricesResponse = $this->postApi('domain/v2/exts/pricing', $priceParams);
            if (!$this->ok($pricesResponse)) {
                return $prices;
            }

            $isVN      = $this->isVN();
            $priceActs = ['register' => 'register', 'renewal' => 'renew', 'transfer' => 'transfer', 'restore' => 'redemption'];
            foreach ($pricesResponse['data']['items'] as $tldPrice) {
                foreach (['productPricing', 'localPrice'] as $bucket) {
                    if (empty($tldPrice[$bucket]['price'])) {
                        continue;
                    }
                    // VN converts to VND; Global keeps the source currency as-is.
                    $currency = $isVN ? 'VND' : ($tldPrice[$bucket]['currency'] ?? 'USD');
                    foreach ($tldPrice[$bucket]['price'] as $priceType => $details) {
                        if (!isset($priceActs[$priceType]) || empty($details['ascii'])) {
                            continue;
                        }
                        foreach ($details['ascii'] as $year => $price) {
                            $key    = '.' . $tldPrice['productKey'];
                            // VN: multiply to VND and round to the nearest 1,000.
                            // Global: import the raw source-currency amount.
                            $amount = $isVN ? round($price * 26000 / 1000) * 1000 : $price;
                            $prices[$currency][$key][$year]['period']               = $year;
                            $prices[$currency][$key][$year][$priceActs[$priceType]] = $amount;
                        }
                    }
                }
            }
        } catch (\Exception $ex) {
            $this->addError($ex->getMessage());
            $this->addError('Failed to get TLD list');
        }
        return $prices;
    }

    /* =====================================================================
     *  REGISTER / TRANSFER / RENEW / DELETE
     * ===================================================================== */

    /** Manual registration (skips registrant-name validation). */
    public function ManualRegister()
    {
        $this->options['manual_reg'] = true;
        return $this->Register();
    }

    /**
     * Register a domain at WebNIC.
     * @return bool
     */
    public function Register()
    {
        if (!$this->validateRegistrant()) {
            return false;
        }

        $this->_prepare_name();

        // Vietnamese registrant-name / sensitive-keyword / EKYC validation.
        // Only enforced for the Vietnam market; Global skips it entirely.
        if ($this->isVN() && empty($this->options['manual_reg'])) {
            if (!$this->runRegistrantNameChecks(true)) {
                return false;
            }
        }

        $domainNameArray = explode('.', $this->name);
        $sld = head($domainNameArray);
        $tld = end($domainNameArray);
        $lookupDomain = $this->lookupDomain($sld, $tld);

        if (!$lookupDomain['available']) {
            $this->addError('Domain not available');
            return false;
        }
        if (!empty($lookupDomain['premium']) && !self::arePremiumDomainsAllowed()) {
            $this->addError(self::ERR_PREMIUM_DOMAINS_DISABLED);
            return false;
        }

        $ns = $this->prepareNameserversData();

        try {
            $contacts   = $this->NewContacts();
            $registrant = $this->createContact($contacts['registrant'], 'registrant');
            $admin      = $this->createContact($contacts['admin'], 'administrator');
            $technical  = $this->createContact($contacts['tech'], 'technical');
            $billing    = $this->createContact($contacts['billing'], 'billing');

            $params = [
                'domainName'             => $this->name,
                'domainType'             => array_key_exists('premium', $lookupDomain) ? 'premium' : 'standard',
                'term'                   => $this->period,
                'nameservers'            => $ns,
                'registrantUserId'       => (string) $this->configuration['registrant_user_id']['value'],
                'registrantContactId'    => $registrant,
                'administratorContactId' => $admin,
                'technicalContactId'     => $technical,
                'billingContactId'       => $billing,
            ];
            // IDN language code (register-domain `lang`, registry default "eng").
            $lang = $this->idnLanguageCode();
            if ($lang) {
                $params['lang'] = $lang;
            }
            // Proxy / local-presence handling is Vietnam-market only.
            if ($this->isVN() && isset($this->options['ext'][$this->proxy_field]) && in_array($this->options['tld'], $this->proxy_tlds)) {
                $params['addons']['proxy'] = true;
            }
            $params['addons']['autoRenew'] = false;

            HBDebug::debug('webnic_domain_register', $params);
            $response = $this->postApi('domain/v2/register', $params);
            HBDebug::debug('webnic_domain_register_resp', $response);

            if (!$this->ok($response)) {
                $this->addError($this->errMsg($response));
                return false;
            }

            // Pending orders (offline-processed TLDs) still return code 1000 but
            // with data.pendingOrder == true; mark the domain pending, not active.
            if (!empty($response['data']['pendingOrder'])) {
                if (!empty($response['data']['pendingOrderId'])) {
                    $ex = $this->details['extended'];
                    $ex['pendingOrderId'] = $response['data']['pendingOrderId'];
                    $this->updateExtended($ex);
                }
                $this->addDomain(HBC\DOMAIN_STATE_PENDINGREGISTRATIO);
                $this->addInfo('Domain registration submitted and is pending processing.');
                return true;
            }

            $this->addDomain(HBC\DOMAIN_STATE_ACTIVE);
            $this->addInfo('Domain has been successfully registered.');
            return true;
        } catch (\Exception $e) {
            HBDebug::debug('webnic_domain', $e->getMessage());
            $this->addError($e->getMessage());
            return false;
        }
    }

    /**
     * Transfer a domain into WebNIC.
     * @return bool
     */
    public function Transfer()
    {
        if (!$this->validateRegistrant()) {
            return false;
        }

        $this->_prepare_name();

        $domainNameArray = explode('.', $this->name);
        $sld = head($domainNameArray);
        $tld = end($domainNameArray);
        $lookupDomain = $this->lookupDomain($sld, $tld);

        // Vietnam-only: sensitive-keyword notice + registrant-name / EKYC checks.
        if ($this->isVN()) {
            $domainFilter = $this->checkKeywordSensitive($this->name);
            if ($domainFilter) {
                $this->addError('Domain contains a sensitive keyword, please review: ' . $domainFilter . '.');
            }
            if (empty($this->options['manual_reg'])) {
                if (!$this->runRegistrantNameChecks(false)) {
                    return false;
                }
            }
        }

        try {
            $contacts   = $this->NewContacts();
            $registrant = $this->createContact($contacts['registrant'], 'registrant');
            $admin      = $this->createContact($contacts['admin'], 'administrator');
            $technical  = $this->createContact($contacts['tech'], 'technical');
            $billing    = $this->createContact($contacts['billing'], 'billing');

            $params = [
                'domainName'             => $this->name,
                'domainType'             => array_key_exists('premium', $lookupDomain) ? 'premium' : 'standard',
                'authInfo'               => $this->options['epp_code'],
                'registrantUserId'       => (string) $this->configuration['registrant_user_id']['value'],
                'registrantContactId'    => $registrant,
                'administratorContactId' => $admin,
                'technicalContactId'     => $technical,
                'billingContactId'       => $billing,
                // NS/nameserver details are a deprecated transfer-in param; no longer sent.
                'subscribeProxy'         => false,
            ];
            // Proxy subscription is Vietnam-market only.
            if ($this->isVN() && isset($this->options['ext'][$this->proxy_field]) && in_array($this->options['tld'], $this->proxy_tlds)) {
                $params['subscribeProxy'] = true;
            }
            // HK bundled-domain support (optional transfer-in parameter).
            if (!empty($this->options['ext']['bundledDomain'])) {
                $params['bundledDomain'] = $this->options['ext']['bundledDomain'];
            }

            $response = $this->postApi('domain/v2/transfer-in', $params);
            // WebNIC returns 100 (pending) on an accepted transfer-in request.
            if (!in_array((string) ($response['code'] ?? ''), ['100', '1000'])) {
                $this->addError($this->errMsg($response));
                return false;
            }

            $this->addDomain(HBC\DOMAIN_STATE_PENDINGTRANSFER);
            $this->addInfo('Transfer Domain Action succeeded.');
            return true;
        } catch (\Exception $e) {
            HBDebug::debug('webnic_domain', $e->getMessage());
            $this->addError($e->getMessage());
            return false;
        }
    }

    /**
     * Renew a domain.
     * @return bool
     */
    public function Renew()
    {
        $this->_prepare_name();

        $params = [
            'domainName' => $this->name,
            'term'       => $this->period,
        ];

        // Send the current expiry date as an idempotency guard so an accidental
        // repeat call cannot double-renew (per the 2025-08-14 / 2025-11-11 renew
        // API changes). Also set domainType so premium renewals are priced right.
        $details = $this->_domain_details();
        if (is_array($details)) {
            if (!empty($details['dtexpire'])) {
                $expiryParts = explode('T', (string) $details['dtexpire']);
                $params['domainExpiryDate'] = reset($expiryParts);
            }
            $params['domainType'] = !empty($details['premium']) ? 'premium' : 'standard';
        }

        $response = $this->postApi('domain/v2/renew', $params);
        if (!$this->ok($response)) {
            // A domain in the redemption grace period can no longer be renewed;
            // it must be restored first. Surface that and point at Restore().
            $msg = $this->errMsg($response);
            if (stripos($msg, 'redemption') !== false || stripos($msg, 'grace') !== false) {
                $this->addError('Domain is in the redemption grace period and cannot be renewed; use Restore (redemption) first.');
                return false;
            }
            $this->addError($msg);
            return false;
        }
        $this->addPeriod();
        $this->addInfo('Domain renewed.');
        return true;
    }

    /**
     * Delete a domain and its associated registry contacts.
     * @return bool
     */
    public function Delete()
    {
        $domainContacts = $this->_domain_details('contactId');
        if (!$domainContacts) {
            return false;
        }
        $contacts = array_unique(array_values($domainContacts));
        if (!$this->name) {
            return false;
        }

        $response = $this->deleteApi('domain/v2/delete?domainName=' . $this->name);
        if ($this->ok($response)) {
            foreach ($contacts as $contact) {
                $this->deleteApi('domain/v2/contact?contactId=' . $contact);
            }
            $this->addInfo('Domain deleted.');
            return true;
        }

        $errors = $response['error'] ?? false;
        if (!$errors) {
            $this->addError($this->errMsg($response));
            return false;
        }
        if (is_array($errors) && isset($errors['message'])) {
            $this->addError($errors['message']);
        } elseif (is_array($errors)) {
            foreach ($errors as $error) {
                $this->addError(is_array($error) ? ($error['message'] ?? '') : $error);
            }
        }
        return false;
    }

    /* =====================================================================
     *  EPP / LOCK / STATUS / HOLD
     * ===================================================================== */

    /**
     * Send the EPP/auth code to the registrant email.
     * @return string message.
     */
    public function getEppCode()
    {
        $this->_prepare_name();
        $response = $this->postApi('domain/v2/auth-info/send?domainName=' . $this->name, ['domainName' => $this->name]);
        if ($this->ok($response)) {
            $data = $response['data'];
            if (!$data) {
                return false;
            }
            $this->logAction(['action' => 'Send EppCode', 'change' => 'Email: ' . $data['recipient']]);
            return 'Authorization code has been sent to the domain registrant email: ' . $data['recipient'];
        }
        return $this->errMsg($response);
    }

    /**
     * Reset the EPP/auth code.
     * @return string message.
     */
    public function ChangeEpp()
    {
        $this->_prepare_name();
        $response = $this->postApi('domain/v2/auth-info/reset?domainName=' . $this->name, ['domainName' => $this->name]);
        if ($this->ok($response)) {
            $this->logAction(['action' => 'Reset EppCode']);
            $this->addInfo('Reset successful!');
            return $response['message'];
        }
        return $this->errMsg($response);
    }

    /** Unlock a domain (status=active). */
    public function Unlock()
    {
        $this->_prepare_name();
        $response = $this->putApi(
            'domain/v2/status?domainName=' . $this->name . '&status=active',
            ['domainName' => $this->name, 'status' => 'active']
        );
        if ($this->ok($response)) {
            $this->logAction(['action' => 'Unlock Domain']);
            $this->addInfo('Domain unlocked successfully!');
            return $response['message'];
        }
        return $this->errMsg($response);
    }

    /** Lock a domain (status=transfer_protected). */
    public function Lock()
    {
        $this->_prepare_name();
        $response = $this->putApi(
            'domain/v2/status?domainName=' . $this->name . '&status=transfer_protected',
            ['domainName' => $this->name, 'status' => 'transfer_protected']
        );
        if ($this->ok($response)) {
            $this->logAction(['action' => 'Lock Domain']);
            $this->addInfo('Domain locked successfully!');
            return $response['message'];
        }
        return $this->errMsg($response);
    }

    /**
     * Suspend a domain by swapping to hold nameservers (original NS stored in extended data).
     * @return bool
     */
    public function Suspended()
    {
        if ($this->status === HBC\DOMAIN_STATE_SUSPENDED) {
            $this->addError('Domain is already suspended.');
            return false;
        }
        $this->_prepare_name();
        $nameservers = $this->prepareNameserversData();

        $response = $this->putApi('domain/v2/dns?domainName=' . $this->name, ['nameservers' => $this->holdNameservers]);
        if (!$this->ok($response)) {
            $this->addError($this->errMsg($response));
            return false;
        }

        // Preserve current nameservers so UnSuspended() can restore them.
        $ex = $this->details['extended'];
        $ex['original_ns'] = $nameservers;
        $this->updateExtended($ex);

        $this->options['ns1'] = $this->holdNameservers[0];
        $this->options['ns2'] = $this->holdNameservers[1];
        $this->addDomain(HBC\DOMAIN_STATE_SUSPENDED);

        \HBEventManager::notify('after_domain_document_hold', [
            'id'        => $this->domain_id,
            'name'      => $this->name,
            'client_id' => $this->client_data['id'],
        ]);

        $this->addInfo('Domain has been successfully suspended.');
        return true;
    }

    /**
     * Un-suspend a domain by restoring the stored original nameservers.
     * @return bool
     */
    public function UnSuspended()
    {
        $this->_prepare_name();
        $ex = $this->details['extended'];
        $original_ns = $ex['original_ns'] ?? $this->defaultNameservers;

        $response = $this->putApi('domain/v2/dns?domainName=' . $this->name, ['nameservers' => $original_ns]);
        if (!$this->ok($response)) {
            $this->addError($this->errMsg($response));
            return false;
        }
        $this->addDomain(HBC\DOMAIN_STATE_ACTIVE);
        $this->addInfo('Domain has been successfully un-suspended.');
        $this->synchInfo();
        return true;
    }

    /** Alias of Suspended(). */
    public function Hold()
    {
        return $this->Suspended();
    }

    /** Alias of UnSuspended(). */
    public function RemoveHold()
    {
        return $this->UnSuspended();
    }

    /** Alias used by the admin controller. */
    public function clientHold($id = null)
    {
        if ($id) {
            $this->getFromDB($id);
            $this->setDomainConfig(HBLoader::LoadModel('Domains')->getDomainModuleConfig($id));
        }
        return $this->Hold();
    }

    /**
     * Update registry status flags. Accepts status[] from the admin status form.
     * Allowed values: "active", "transfer_protected", "name_protected".
     */
    public function updateStatus($params = [])
    {
        $this->_prepare_name();
        if (empty($params['status'])) {
            return false;
        }
        $statuses = (array) $params['status'];
        $map = [
            'clientTransferProhibited' => 'transfer_protected',
            'clientUpdateProhibited'   => 'name_protected',
        ];
        $target = 'active';
        foreach ($statuses as $s) {
            if (isset($map[$s])) {
                $target = $map[$s];
                break;
            }
        }
        $response = $this->putApi(
            'domain/v2/status?domainName=' . $this->name . '&status=' . $target,
            ['domainName' => $this->name, 'status' => $target]
        );
        if (!$this->ok($response)) {
            $this->addError($this->errMsg($response));
            return false;
        }
        $this->logAction(['action' => 'Update Status', 'change' => $target]);
        return true;
    }

    /* =====================================================================
     *  SYNC / INFO
     * ===================================================================== */

    /**
     * Sync domain status, nameservers, expiry and id-protection from WebNIC.
     * @return array|bool
     */
    public function synchInfo()
    {
        $data = $this->_domain_details();
        if (!$data) {
            return false;
        }

        $return = [];
        switch ($data['status']) {
            case 'pendingCreate':
                $return['status'] = HBC\DOMAIN_STATE_PENDINGREGISTRATIO;
                break;
            case 'clientHold':
                $return['status'] = HBC\DOMAIN_STATE_SUSPENDED;
                break;
            case 'pendingDelete':
                $return['status'] = HBC\DOMAIN_STATE_CANCELLED;
                break;
            case 'pendingTransfer':
                // leave status untouched while a transfer is in flight
                break;
            case 'Inactive':
                $return['status'] = HBC\DOMAIN_STATE_EXPIRED;
                break;
            default:
                $return['status'] = HBC\DOMAIN_STATE_ACTIVE;
                break;
        }

        $return['ns'] = $data['nameservers'];

        if (isset($data['dtexpire'])) {
            $expires = explode('T', (string) $data['dtexpire']);
            $return['expires'] = reset($expires);
            if (strtotime($return['expires']) < time()) {
                $return['status'] = 'Expired';
            }
        }

        $return['idprotection'] = $data['whoisPrivacy'];
        return $return;
    }

    /** Alias for frameworks that call SyncInfo(). */
    public function SyncInfo()
    {
        return $this->synchInfo();
    }

    /**
     * Fetch raw domain details from WebNIC.
     * @param string|null $key optional single key to return.
     * @return array|mixed|bool
     */
    protected function _domain_details($key = null)
    {
        $this->_prepare_name();
        $response = $this->getApi('domain/v2/info?domainName=' . $this->name);
        if ($this->ok($response)) {
            if ($key && array_key_exists($key, $response['data'])) {
                return $response['data'][$key];
            }
            return $response['data'];
        }
        Engine::addError('Unable_get_domain_info');
        return false;
    }

    /**
     * Admin: domain status flags for the status/details ajax panes.
     * @return array
     */
    public function getDomainInfo()
    {
        $data = $this->_domain_details();
        if (!$data) {
            return ['domainstatus' => []];
        }
        $status = [];
        if (!empty($data['domainStatus'])) {
            $status = (array) $data['domainStatus'];
        } elseif (!empty($data['status'])) {
            $status = [$data['status']];
        }
        return [
            'domainstatus' => $status,
            'nameservers'  => $data['nameservers'] ?? [],
            'whoisPrivacy' => $data['whoisPrivacy'] ?? false,
        ];
    }

    /**
     * Admin: transfer status info for the transfer ajax pane.
     * @return array|bool
     */
    public function TransferAction($params = [])
    {
        $this->_prepare_name();
        $response = $this->getApi('domain/v2/info?domainName=' . $this->name);
        if (!$this->ok($response)) {
            return false;
        }
        $data = $response['data'];
        $reDate = '';
        if (isset($data['dtcreate'])) {
            $parts  = explode('T', (string) $data['dtcreate']);
            $reDate = reset($parts);
        }
        return [
            'status' => $data['status'] ?? '',
            'reDate' => $reDate,
            'type'   => 'transfer-in',
            'acID'   => $data['registrarId'] ?? '',
            'reID'   => $this->configuration['registrant_user_id']['value'],
        ];
    }

    /* =====================================================================
     *  NAMESERVERS
     * ===================================================================== */

    /**
     * Collect nameservers from options (ns1..nsN), falling back to defaults.
     * @return array
     */
    private function prepareNameserversData()
    {
        $nameservers = array_values(array_filter($this->options, function ($value, $key) {
            return preg_match('/^ns\d+$/', $key) && $value !== '';
        }, ARRAY_FILTER_USE_BOTH));

        if (empty($nameservers)) {
            $this->options['ns1'] = $this->defaultNameservers[0];
            $this->options['ns2'] = $this->defaultNameservers[1];
            $nameservers = $this->defaultNameservers;
        }
        return $nameservers;
    }

    /**
     * List registered glue hosts for this domain.
     * @return array hostname => [ip,...]
     */
    public function getRegisterNameServers()
    {
        $this->_prepare_name();
        $hosts = [];

        $params = [
            'filters'    => [
                ['field' => 'hostname', 'operator' => 'LIKE', 'value' => $this->name],
                ['field' => 'status', 'operator' => 'EQUAL', 'value' => 'active'],
            ],
            'pagination' => ['page' => 1, 'pageSize' => 1],
        ];
        $list = $this->postApi('domain/v2/host/list', $params);
        if (!$this->ok($list)) {
            return $hosts;
        }
        $params['pagination']['pageSize'] = $list['data']['totalItems'];
        $list = $this->postApi('domain/v2/host/list', $params);
        if (!$this->ok($list)) {
            return $hosts;
        }
        foreach ($list['data']['items'] as $host) {
            $info = $this->getApi('domain/v2/host/info?host=' . $host['hostname']);
            if ($this->ok($info)) {
                $hosts[$info['data']['nameserver']] = $info['data']['ip'];
            }
        }
        return $hosts;
    }

    /**
     * Get the domain's currently assigned nameservers.
     * @return array|bool
     */
    public function getNameServers()
    {
        $info = $this->_domain_details();
        if (!$info) {
            return false;
        }
        return $info['nameservers'];
    }

    /**
     * Update the domain's nameservers.
     * @return bool
     */
    public function updateNameServers()
    {
        $this->_prepare_name();
        $nameservers = $this->prepareNameserversData();
        $response = $this->putApi('domain/v2/dns?domainName=' . $this->name, ['nameservers' => $nameservers]);
        return $this->ok($response);
    }

    /**
     * Register a new glue host.
     * @return bool
     */
    public function registerNameServer()
    {
        $this->_prepare_name();
        $name   = rtrim($this->options['NameServer'], '.' . $this->name) . '.' . $this->name;
        $params = ['host' => $name, 'extList' => [$this->options['tld']]];
        foreach (explode(',', $this->options['NameServerIP']) as $address) {
            if (!empty($address)) {
                $params['ipList'][] = $address;
            }
        }
        $response = $this->postApi('domain/v2/host/create/extension', $params);
        if ((string) ($response['code'] ?? '') != '3000') {
            $this->logAction(['action' => 'Register Name Server', 'result' => false, 'change' => false, 'error' => $this->errMsg($response)]);
            $this->addError($this->errMsg($response));
            return false;
        }
        if (!empty($response['data']['fail'])) {
            foreach ($response['data']['fail'] as $fail) {
                $this->logAction(['action' => 'Register Name Server', 'result' => false, 'change' => $name . ' - ' . $this->options['NameServerIP'], 'error' => $fail['errors']['message']]);
            }
        }
        if (!empty($response['data']['success'])) {
            $this->logAction(['action' => 'Register Name Server', 'result' => true, 'change' => $name . ' - ' . $this->options['NameServerIP'], 'error' => false]);
        }
        $this->addInfo($response['message']);
        return true;
    }

    /**
     * Modify a glue host's IP addresses.
     * @return bool
     */
    public function modifyNameServer()
    {
        $this->_prepare_name();
        $name = rtrim($this->options['NameServer'], '.' . $this->name) . '.' . $this->name;
        $details = $this->getApi('domain/v2/host/info?host=' . $name);
        if (!$this->ok($details)) {
            return false;
        }
        $existingIps = $details['data']['ip'];
        $oldIp = explode(',', str_replace(' ', '', $this->options['NameServerOldIP']));
        $newIp = explode(',', str_replace(' ', '', $this->options['NameServerNewIP']));
        $existingIps = array_merge(array_diff($existingIps, $oldIp), $newIp);

        $response = $this->postApi('domain/v2/host/modify', ['host' => $name, 'ipList' => $existingIps]);
        if (!$this->ok($response)) {
            $this->logAction(['action' => 'Modify NameServer', 'result' => false, 'change' => false, 'error' => $this->errMsg($response)]);
            $this->addError($this->errMsg($response));
            return false;
        }
        if (!empty($response['data']['success'])) {
            $this->logAction(['action' => 'Modify NameServer', 'result' => true, 'change' => $name, 'error' => false]);
        }
        $this->addInfo($response['message']);
        return true;
    }

    /**
     * Delete a glue host IP (or the whole host when its last IP is removed).
     * @return bool
     */
    public function deleteNameServer()
    {
        $this->_prepare_name();
        $name = rtrim($this->options['NameServer'], '.' . $this->name) . '.' . $this->name;
        $details = $this->getApi('domain/v2/host/info?host=' . $name);
        if (!$this->ok($details)) {
            return false;
        }
        $existingIps = $details['data']['ip'];
        $ipToDelete  = $this->options['NameServerDeleteIP'];
        $index = array_search($ipToDelete, $existingIps);
        if ($index !== false) {
            unset($existingIps[$index]);
        }

        if (empty($existingIps)) {
            $response = $this->deleteApi('domain/v2/host/extension?host=' . $name . '&ext=' . $this->options['tld']);
        } else {
            $response = $this->postApi('domain/v2/host/modify', ['host' => $name, 'ipList' => array_values($existingIps)]);
        }
        if (!$this->ok($response)) {
            $this->logAction(['action' => 'Modify NameServer', 'result' => false, 'change' => false, 'error' => $this->errMsg($response)]);
            $this->addError($this->errMsg($response));
            return false;
        }
        if (!empty($response['data']['success'])) {
            $this->logAction(['action' => 'Modify NameServer', 'result' => true, 'change' => $name, 'error' => false]);
        }
        $this->addInfo($response['message']);
        return true;
    }

    /* =====================================================================
     *  ID PROTECTION (WHOIS PRIVACY)
     * ===================================================================== */

    /**
     * Toggle WHOIS privacy for the domain.
     * @return bool
     */
    public function updateIDProtection()
    {
        $this->_prepare_name();
        $details = $this->_domain_details();
        if (!$details) {
            return false;
        }
        $newProtection = !$details['whoisPrivacy'];
        $response = $this->putApi('domain/v2/whois-privacy/toggle', ['domainName' => $this->name, 'active' => $newProtection]);
        if (!$this->ok($response)) {
            $this->logAction(['action' => 'Update ID Protection', 'result' => false, 'change' => false, 'error' => $this->errMsg($response)]);
            $this->addError($this->errMsg($response));
            return false;
        }
        $change = $details['whoisPrivacy']
            ? ['from' => 'Enabled', 'to' => 'Disabled']
            : ['from' => 'Disabled', 'to' => 'Enabled'];
        $this->logAction(['action' => 'Update ID Protection', 'result' => true, 'change' => $change, 'error' => false]);
        $this->addInfo($response['message']);
        return true;
    }

    /* =====================================================================
     *  VERIFICATION / CERTIFICATE
     * ===================================================================== */

    /**
     * Resend the registrant verification email.
     * @return string|bool recipient email on success.
     */
    public function SendVerify()
    {
        $this->_prepare_name();
        $resp = $this->postApi('domain/v2/resend-verification-email?domainName=' . $this->name, ['domainName' => $this->name]);

        if ($this->ok($resp)) {
            $recipient = $resp['data']['recipient'] ?? null;
            if ($recipient) {
                $this->addInfo('Verification email sent to: ' . $recipient);
                return $recipient;
            }
            $this->addError('Could not determine the recipient email.');
            return false;
        }
        $this->addError($this->errMsg($resp));
        return false;
    }

    /**
     * Download a domain certificate PDF (raw bytes).
     *
     * @param string $lang eng|chi|zho|ind|tha|vie
     * @return string|false raw PDF content.
     */
    public function downloadCertificateApi($lang = 'eng')
    {
        $this->_prepare_name();
        $endpoint = 'domain/v2/download/certificate?domainName=' . urlencode($this->name) . '&lang=' . $lang;
        $url = $this->baseHost() . $endpoint;

        try {
            $this->access_token();
            $config = json_decode(HBConfig::getConfig(self::STORE_KEY), true);

            $response = $this->http->get($url, [
                'headers' => [
                    'Accept'        => 'application/pdf',
                    'Authorization' => 'Bearer ' . ($config['access_token'] ?? ''),
                ],
                'stream'  => true,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->addError('Download certificate failed (HTTP ' . $response->getStatusCode() . ')');
                return false;
            }
            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            $this->addError('Exception downloading certificate: ' . $e->getMessage());
            return false;
        }
    }

    /* =====================================================================
     *  CONTACTS
     * ===================================================================== */

    /**
     * Create a WebNIC contact (and its registry contact) from a local handle.
     *
     * @param string $handle local contact handle.
     * @param string $type   registrant|administrator|technical|billing
     * @return string|false  WebNIC contactId.
     */
    protected function createContact($handle, $type = 'registrant')
    {
        $this->_prepare_name();
        $domainNameArray = explode('.', $this->name);
        $tld = end($domainNameArray);

        $contact_data = DContacts::where('handle', $handle)->first();
        if (!$contact_data) {
            return false;
        }

        $cdata = $contact_data->toArray();
        $contactForm = $this->_contact_data($cdata);

        $postfields = [$type => $contactForm];
        $postfields = $this->applyConvertAccents($postfields);

        $resp = $this->postApi('domain/v2/contact/create', $postfields);

        $contactID = null;
        if ($this->ok($resp)) {
            $contactID = $resp['data'][0]['contactId'];
            $contact_data->handle = $contactID;
            $contact_data->save();
        } else {
            if (($resp['error']['subCode'] ?? '') === 'DOM4000' && !empty($resp['validationErrors'])) {
                foreach ($resp['validationErrors'] as $errorFields) {
                    $this->addError([$errorFields['field'] => $errorFields['message']]);
                }
            }
            return false;
        }

        // Create registry contact scoped to the TLD + contact type.
        $this->postApi("domain/v2/contact/create/{$tld}/{$type}?contactId={$contactID}", $postfields);

        return $contactID;
    }

    /**
     * Format a local contact row into the WebNIC contact payload.
     * @param array $cdata
     * @return array
     */
    protected function _contact_data($cdata = [])
    {
        $return = [
            'category'     => $cdata['type'] == 'ind' ? 'individual' : 'organization',
            'email'        => $cdata['email'],
            'state'        => $this->prepareCity($cdata['state']),
            'city'         => $cdata['city'],
            'address1'     => $cdata['address1'],
            'zip'          => '760000',
            'countryCode'  => $cdata['country'],
            'customFields' => [
                'state'                => $cdata['state'],
                'gender'               => $cdata['gender'] ?? 'Male',
                'identificationNumber' => $cdata['nationalid'],
            ],
        ];

        $return['company'] = $cdata['name'];
        $return['customFields']['organizationRegistrationNumber'] = $cdata['taxid'];

        // Split the full name into first/last halves.
        $words     = explode(' ', trim((string) $cdata['name']));
        $wordCount = count($words);
        if ($wordCount <= 2) {
            $return['firstName'] = $words[0] ?? '';
            $return['lastName']  = $words[1] ?? '';
        } else {
            $splitAt = (int) floor($wordCount / 2);
            $return['firstName'] = implode(' ', array_slice($words, 0, $splitAt));
            $return['lastName']  = implode(' ', array_slice($words, $splitAt));
        }

        if (!empty($cdata['birthday'])) {
            $birthday = str_replace('/', '-', $cdata['birthday']);
            $return['customFields']['birthday'] = date('Y-m-d', strtotime($birthday));
        }

        if (!empty($cdata['phonenumber'])) {
            $phone = Utilities::get_phone_info($cdata['phonenumber'], $cdata['country']);
            $return['phoneNumber'] = '+' . $phone['ccode'] . '.' . $phone['number'];
        }

        return $return;
    }

    /**
     * Admin/client: fetch the four domain contacts for display.
     * @return array
     */
    public function getContactInfo()
    {
        $types    = ['registrant', 'admin', 'billing', 'tech'];
        $contacts = [];
        $cids     = $this->details['extended']['contacts'] ?? null;

        if (!$cids) {
            $cids = $this->NewContacts();
        }

        foreach ($types as $type) {
            $row = DContacts::where('handle', $cids[$type] ?? '')->first();

            if (!isset($contacts['registrant']) && empty($row)) {
                $this->NewContacts();
            }
            if (!$row) {
                continue;
            }

            $arr = $row->toArray();
            foreach (['id', 'esigned', 'notes', 'registrant', 'domain_id', 'client_id', 'language', 'created_at', 'updated_at', 'sync'] as $strip) {
                unset($arr[$strip]);
            }
            if (($arr['type'] ?? '') === 'org') {
                unset($arr['nationalid'], $arr['position'], $arr['birthday'], $arr['gender']);
            }
            if (($arr['type'] ?? '') === 'ind') {
                unset($arr['taxid'], $arr['companyname'], $arr['householdsid']);
            }
            $contacts[$type] = $arr;
        }
        return $contacts;
    }

    /**
     * Persist contact edits coming from the client area and sync to the extended record.
     */
    public function updateContactInfo()
    {
        $client   = $this->client_data;
        $contacts = [];
        $types    = ['registrant', 'admin', 'billing', 'tech'];

        foreach ($types as $type) {
            $existingHandle = $this->details['extended']['contacts'][$type] ?? null;
            $cdata = DContacts::where('handle', $existingHandle)->first();

            if (!$cdata) {
                $cdata = new DContacts();
                $cdata->handle    = uniqid($this->prefix . $this->domain_id . '-');
                $cdata->client_id = $client['id'];
                $cdata->domain_id = $this->domain_id;
                $cdata->type      = $this->options[$type]['companyname'] ? 'org' : 'ind';
            } else {
                $cdata->type      = $this->options[$type]['type'];
                $cdata->client_id = $client['id'];
            }

            if ($this->options[$type]['name']) {
                $cdata->name = $this->options[$type]['name'];
            } elseif (($this->options[$type]['type'] ?? '') === 'org') {
                $cdata->name = $this->options[$type]['companyname'];
            } else {
                $cdata->name = $this->options[$type]['lastname'] . ' ' . $this->options[$type]['firstname'];
            }

            if ($type == 'registrant') {
                $cdata->registrant = 1;
            } else {
                $cdata->registrant = '';
                $cdata->type       = 'ind';
            }

            $phone = Utilities::get_phone_info($this->options[$type]['phonenumber'], $this->options[$type]['country']);
            $cdata->taxid        = $this->options[$type]['taxid'];
            $cdata->householdsid = $this->options[$type]['householdsid'];
            $cdata->phonenumber  = '+' . $phone['ccode'] . '-' . $phone['number'];
            $cdata->nationalid   = $this->options[$type]['nationalid'];
            $cdata->position     = $this->options[$type]['position'];
            $cdata->address1     = $this->options[$type]['address1'];
            $cdata->address2     = $this->options[$type]['address2'];
            $cdata->city         = $this->options[$type]['city'];
            $cdata->state        = $this->options[$type]['state'];
            $cdata->postcode     = $this->options[$type]['postcode'];
            $cdata->country      = $this->options[$type]['country'];
            $cdata->email        = $this->options[$type]['email'];
            $cdata->birthday     = $this->options[$type]['birthday'];
            $cdata->gender       = $this->options[$type]['gender'];
            $cdata->notes        = $this->options[$type]['notes'];
            $cdata->sync         = 1;
            $cdata->save();

            $contacts[$type] = $cdata->handle;
        }

        $this->updateExtended(['contacts' => $contacts]);
    }

    /**
     * Push locally stored contacts to WebNIC (modify + modify-at-registry).
     * @return bool
     */
    public function SyncContact()
    {
        $localcontact  = $this->details['extended']['contacts'];
        $contact_types = ['registrant', 'admin', 'tech', 'billing'];

        foreach ($contact_types as $contacttype) {
            $contact_data = DContacts::where('handle', $localcontact[$contacttype])->first();
            if (!$contact_data) {
                return false;
            }
            $contact = $this->_contact_data($contact_data->toArray());
            $postdata = ['contactId' => $localcontact[$contacttype], 'details' => $contact];

            $resp = $this->postApi('domain/v2/contact/modify', $postdata);
            if ($this->ok($resp)) {
                $this->addInfo('Update Contact successfully');
                $this->postApi('domain/v2/contact/modify-at-registry', $postdata);
            } else {
                $this->addError($this->errMsg($resp));
                HBDebug::debug('webnic_domain', $resp);
            }
        }
        return true;
    }

    /**
     * Rebuild the four local contacts from the order (or registry when active).
     * @return array type => handle
     */
    public function NewContacts()
    {
        DContacts::where('domain_id', $this->domain_id)->delete();

        if ($this->status === 'Active') {
            $contacts = $this->getDomainContacts();
        } else {
            $contacts = $this->domain_contacts;
        }

        $client = $this->client_data;
        $c      = [];
        $types  = ['registrant', 'admin', 'billing', 'tech'];

        foreach ($types as $type) {
            $id    = uniqid($this->prefix . $this->domain_id . '-');
            $cdata = new DContacts();

            if ($this->status === 'Active') {
                $this->options[$type] = $contacts[$type];
                $cdata->type = $contacts[$type]['type'];
                if (!empty($contacts[$type]['handle'])) {
                    $id = $contacts[$type]['handle'];
                }
            } else {
                $cdata->type = $contacts[$type]['companyname'] ? 'org' : 'ind';
            }

            $cdata->registrant = 1;
            if ($type != 'registrant') {
                $cdata->type       = 'ind';
                $cdata->registrant = '';
                $cdata->position   = $this->options[$type]['position'];
            }

            $cdata->handle    = $id;
            $cdata->client_id = $client['id'];
            $cdata->domain_id = $this->domain_id;

            if (!empty($contacts[$type]['companyname']) && $type === 'registrant') {
                $cdata->name  = $contacts[$type]['companyname'];
                $cdata->taxid = $contacts[$type]['taxid'];
            } else {
                $cdata->name       = $contacts[$type]['lastname'] . ' ' . $contacts[$type]['firstname'];
                $cdata->nationalid = $this->options[$type]['nationalid'];
            }

            if ($this->options[$type]['name']) {
                $cdata->name = $this->options[$type]['name'];
            } elseif ($cdata->type === 'org') {
                $cdata->name = $this->options[$type]['companyname'];
            } else {
                $cdata->name = $this->options[$type]['lastname'] . ' ' . $this->options[$type]['firstname'];
            }

            if (($this->options[$type]['gender'] ?? '') === 'M') {
                $gender = 'Male';
            } elseif (($this->options[$type]['gender'] ?? '') === 'F') {
                $gender = 'Female';
            } else {
                $gender = $this->options[$type]['gender'];
            }

            $cdata->phonenumber = $this->options[$type]['phonenumber'];
            $cdata->address1    = $this->options[$type]['address1'];
            $cdata->address2    = $this->options[$type]['address2'];
            $cdata->city        = $this->options[$type]['city'];
            $cdata->state       = $this->options[$type]['state'];
            $cdata->postcode    = $this->options[$type]['postcode'];
            $cdata->country     = $this->options[$type]['country'];
            $cdata->email       = $this->options[$type]['email'];
            $cdata->birthday    = $this->options[$type]['birthday'];
            $cdata->gender      = $gender;
            $cdata->notes       = $this->options[$type]['notes'];
            $cdata->sync        = 1;
            $cdata->save();

            $c[$type] = $cdata->handle;
        }

        $ex = $this->details['extended'];
        $ex['contacts'] = $c;
        $this->updateExtended($ex);
        return $c;
    }

    /**
     * Fetch the four domain contacts from WebNIC (for active domains).
     * @return array|bool
     */
    public function getDomainContacts()
    {
        $contacts = [];
        try {
            $domain = $this->_domain_details();
            if ($domain) {
                foreach ($domain['contactId'] as $key => $handle) {
                    if ($key == 'technical') {
                        $key = 'tech';
                    }
                    $contacts[$key] = $this->getContact($handle);
                }
            }
        } catch (\Exception $ex) {
            return false;
        }
        return $contacts;
    }

    /**
     * Fetch a single contact from WebNIC and map it to local fields.
     * @param string $handle
     * @return array|bool
     */
    public function getContact($handle)
    {
        try {
            $resp = $this->getApi('domain/v2/contact/query?contactId=' . $handle);
            if ($this->ok($resp)) {
                $d = $resp['data']['details'];
                $contact = [
                    'handle'      => $resp['data']['contactId'],
                    'type'        => $d['category'] == 'organization' ? 'org' : 'ind',
                    'name'        => $d['category'] == 'organization' ? $d['company'] : $d['firstName'] . ' ' . $d['lastName'],
                    'nationalid'  => $d['customFields']['identificationNumber'] ?? '',
                    'taxid'       => $d['customFields']['organizationRegistrationNumber'] ?? '',
                    'phonenumber' => $d['phoneNumber'],
                    'country'     => $d['countryCode'],
                    'state'       => $this->prepareCity($d['state'], true),
                    'city'        => $d['city'],
                    'email'       => $d['email'],
                    'gender'      => $d['customFields']['gender'] ?? 'Male',
                    'birthday'    => $d['customFields']['birthday'] ?? '',
                    'address1'    => $d['address1'],
                    'postcode'    => $d['zip'],
                    'position'    => '',
                ];
                return $this->convertToUtf8($contact);
            }
        } catch (\Exception $ex) {
            // ignore
        }
        return false;
    }

    /* =====================================================================
     *  EXTENDED ATTRIBUTES
     * ===================================================================== */

    /**
     * TLD-specific extended registration attributes.
     * @return array
     */
    public function getExtendedAttributes()
    {
        $attributes   = [];
        $attributes[] = ['name' => 'identificationNumber', 'description' => 'Identification Number', 'type' => 'input'];
        $attributes[] = ['name' => 'organizationRegistrationNumber', 'description' => 'Organization Registration Number', 'type' => 'input'];
        // IDN language code, required when registering IDN (non-ASCII) domains.
        $attributes[] = ['name' => 'idnLanguageCode', 'description' => 'IDN Language Code (e.g. CHI, ZHO, JPN, KOR; blank for ASCII/eng)', 'type' => 'input'];

        if (substr($this->options['tld'], -2) == 'hk') {
            $attributes[] = ['name' => 'bundledDomain', 'description' => 'HK Bundled Domain (optional, transfer-in)', 'type' => 'input'];
        }

        if (substr($this->options['tld'], -2) == 'us') {
            $attributes[] = ['name' => 'nexus', 'description' => 'Nexus Category', 'type' => 'select', 'option' => [
                ['value' => 'C11', 'title' => 'US Citizen'],
                ['value' => 'C12', 'title' => 'Permanent Resident'],
                ['value' => 'C21', 'title' => 'Business Entity'],
                ['value' => 'C31', 'title' => 'Foreign Entity'],
                ['value' => 'C32', 'title' => 'US Based Office'],
            ]];
        }

        if (in_array($this->options['tld'], ['com.cn', 'gov.cn', 'net.cn', 'org.cn', 'cn', '中国', '公司', '网络'])) {
            $attributes[] = ['name' => 'organizationType', 'description' => 'Application Purpose (Organization only)', 'type' => 'select', 'option' => [
                ['value' => 'ORG', 'title' => 'Organization Code Certificate'],
                ['value' => 'YYZZ', 'title' => 'Business License'],
                ['value' => 'TYDM', 'title' => 'Certificate for Uniform Social Credit Code'],
                ['value' => 'BDDM', 'title' => 'Military Code Designation'],
                ['value' => 'JDDWFW', 'title' => 'Military Paid External Service License'],
                ['value' => 'SYDWFR', 'title' => 'Public Institution Legal Person Certificate'],
                ['value' => 'SHTTFR', 'title' => 'Social Organization Legal Person Registration Certificate'],
                ['value' => 'ZJCS', 'title' => 'Religion Activity Site Registration Certificate'],
                ['value' => 'MBFQY', 'title' => 'Private Non-Enterprise Entity Registration Certificate'],
                ['value' => 'JJHFR', 'title' => 'Fund Legal Person Registration Certificate'],
                ['value' => 'LSZY', 'title' => 'Practicing License of Law Firm'],
                ['value' => 'WGZHWH', 'title' => 'Registration Certificate of Foreign Cultural Center in China'],
                ['value' => 'WLCZJG', 'title' => 'Resident Representative Office of Tourism Departments of Foreign Government'],
                ['value' => 'SFJD', 'title' => 'Judicial Expertise License'],
                ['value' => 'JWJG', 'title' => 'Overseas Organization Certificate'],
                ['value' => 'SHFWJG', 'title' => 'Social Service Agency Registration Certificate'],
                ['value' => 'MBXXBX', 'title' => 'Private School Permit'],
                ['value' => 'YLJGZY', 'title' => 'Medical Institution Practicing License'],
                ['value' => 'GZJGZY', 'title' => 'Notary Organization Practicing License'],
                ['value' => 'BJWSXX', 'title' => 'Beijing School for Children of Foreign Embassy Staff Permit'],
            ]];
            $attributes[] = ['name' => 'individualType', 'description' => 'Individual Type', 'type' => 'select', 'option' => [
                ['value' => 'SFZ', 'title' => 'ID'],
                ['value' => 'HZ', 'title' => 'Passport'],
                ['value' => 'GAJMTX', 'title' => 'Exit-Entry Permit for Hong Kong and Macao'],
                ['value' => 'TWJMTX', 'title' => 'Travel passes for Taiwan Residents'],
                ['value' => 'WJLSFZ', 'title' => 'Foreign Permanent Resident ID Card'],
                ['value' => 'GAJZZ', 'title' => 'Residence permit for Hong Kong and Macao residents'],
                ['value' => 'TWJZZ', 'title' => 'Residence permit for Taiwan residents'],
                ['value' => 'JGZ', 'title' => 'Medical Institution Practicing License'],
                ['value' => 'QT', 'title' => 'Others'],
            ]];
        } else {
            $attributes[] = ['name' => 'organizationType', 'description' => 'Application Purpose (Organization only)', 'type' => 'select', 'option' => [
                ['value' => 'OTA000003', 'title' => 'Company'],
                ['value' => 'OTA000005', 'title' => 'Business'],
                ['value' => 'OTA000025', 'title' => 'Non Profit Organization'],
                ['value' => 'OTA000006', 'title' => 'Education'],
                ['value' => 'OTA000012', 'title' => 'Legal Professional'],
                ['value' => 'OTA000032', 'title' => 'Others'],
            ]];
        }

        if (substr($this->options['tld'], -2) == 'hk' || substr($this->options['tld'], -2) == 'my') {
            $attributes[] = ['name' => 'dateOfBirth', 'description' => 'Date of Birth (YYYY-MM-DD)', 'type' => 'input'];
        }

        if (!empty($attributes)) {
            foreach ($attributes as &$attr) {
                if (isset($this->options['ext'][$attr['name']])) {
                    $attr['default'] = $this->options['ext'][$attr['name']];
                }
            }
            return [trim($this->options['tld'], '. ') => $attributes];
        }
        return [];
    }

    /* =====================================================================
     *  DISPLAY HINTS
     * ===================================================================== */

    /** Hide the contact form during transfer when configured. */
    public function hideContacts()
    {
        return isset($this->configuration['hide_form']['value']) && $this->configuration['hide_form']['value'] == '1';
    }

    /** Nameserver management is handled via WebNIC DNS, hide the default UI. */
    public function hideNameServers()
    {
        return true;
    }

    /* =====================================================================
     *  VALIDATION HELPERS (Vietnamese registrant rules)
     * ===================================================================== */

    /**
     * Validate required registrant fields present before register/transfer.
     * @return bool
     */
    protected function validateRegistrant()
    {
        $requireFields = ['firstname', 'lastname', 'phonenumber', 'state', 'city', 'address1'];
        $registrant    = $this->domain_contacts['registrant'];

        // nationalid is a Vietnam-market registrant requirement only.
        if ($this->isVN()) {
            $requireFields[] = 'nationalid';
        }
        if (!empty($registrant['companyname'])) {
            $requireFields[] = 'taxid';
        }

        $errors = [];
        foreach ($requireFields as $field) {
            if (empty($registrant[$field])) {
                $errors[] = "The field '$field' is required.";
            }
        }
        if ($errors) {
            $this->addError($errors);
            return false;
        }
        return true;
    }

    /**
     * Run the sensitive-keyword and registrant-name validations.
     * @param bool $enforceAvailabilityEkyc whether to enforce the EKYC/keyword blocks (register path).
     * @return bool true when validation passes.
     */
    protected function runRegistrantNameChecks($enforceAvailabilityEkyc = true)
    {
        $registrant = $this->domain_contacts['registrant'];

        if ($enforceAvailabilityEkyc) {
            $domainFilter = $this->checkKeywordSensitive($this->name);
            if ($domainFilter) {
                $this->addError("Domain contains a sensitive keyword, please review: $domainFilter.");
                return false;
            }
        }

        if ($registrant['companyname'] !== '' && $this->checkRegistrantCompanyName($registrant['companyname'])) {
            $this->addError('Please review the organization registrant name: ' . $registrant['companyname']);
            return false;
        }
        if ($registrant['companyname'] === '' && $this->checkRegistrantName($registrant['lastname'] . ' ' . $registrant['firstname'])) {
            $this->addError('Please review the individual registrant name: [B]' . $registrant['lastname'] . ' ' . $registrant['firstname'] . '[B]');
            return false;
        }

        $registrant_name = $registrant['companyname'] ? $registrant['companyname'] : $registrant['lastname'] . ' ' . $registrant['firstname'];
        if ($this->check_str_vietnamese($registrant_name) == false) {
            $this->addError('Please review the registrant name: [B]' . $registrant_name . '[B]');
            return false;
        }

        if ($enforceAvailabilityEkyc && $this->isVN()) {
            $full_name = $registrant['companyname'] ? mb_strtoupper($registrant['companyname']) : mb_strtoupper($registrant['lastname'] . ' ' . $registrant['firstname']);
            $idnumber  = $registrant['companyname'] ? $registrant['taxid'] : $registrant['nationalid'];

            if (class_exists('\Other\domain_documents\ORM\ORM_ekyccontact')) {
                $ekyc = \Other\domain_documents\ORM\ORM_ekyccontact::where('name', $full_name)->where('idnumber', $idnumber)->first();
                if (!$ekyc && $registrant['companyname'] == '') {
                    $this->addError('No matching EKYC record found. Please verify and use ManualRegister.');
                    return false;
                }
                if ($ekyc) {
                    $this->addInfo('Matching EKYC record found: ' . $full_name);
                }
            }
        }
        return true;
    }

    /**
     * Check a domain name against the sensitive-keyword list.
     * @param string $domainName
     * @return string|false "keyword - description" or false.
     */
    public function checkKeywordSensitive($domainName)
    {
        $file = __DIR__ . DS . 'libs' . DS . 'keywords.json';
        if (!is_file($file)) {
            return false;
        }
        $keywords   = json_decode(file_get_contents($file), true) ?: [];
        $domainName = self::convertAccentsAndSpecialToNormal($domainName);

        foreach ($keywords as $keyword => $description) {
            if (stripos($domainName, $keyword) !== false) {
                return "$keyword - $description";
            }
        }
        return false;
    }

    /**
     * Normalise Vietnamese accents and collapse whitespace.
     * @param string $str
     * @return string
     */
    public function convertAccentsAndSpecialToNormal($str)
    {
        $str = preg_replace("/(à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ)/", 'a', $str);
        $str = preg_replace("/(è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ)/", 'e', $str);
        $str = preg_replace("/(ì|í|ị|ỉ|ĩ)/", 'i', $str);
        $str = preg_replace("/(ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ)/", 'o', $str);
        $str = preg_replace("/(ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ)/", 'u', $str);
        $str = preg_replace("/(ỳ|ý|ỵ|ỷ|ỹ)/", 'y', $str);
        $str = preg_replace("/(đ)/", 'd', $str);
        $str = preg_replace("/(À|Á|Ạ|Ả|Ã|Â|Ầ|Ấ|Ậ|Ẩ|Ẫ|Ă|Ằ|Ắ|Ặ|Ẳ|Ẵ)/", 'A', $str);
        $str = preg_replace("/(È|É|Ẹ|Ẻ|Ẽ|Ê|Ề|Ế|Ệ|Ể|Ễ)/", 'E', $str);
        $str = preg_replace("/(Ì|Í|Ị|Ỉ|Ĩ)/", 'I', $str);
        $str = preg_replace("/(Ò|Ó|Ọ|Ỏ|Õ|Ô|Ồ|Ố|Ộ|Ổ|Ỗ|Ơ|Ờ|Ớ|Ợ|Ở|Ỡ)/", 'O', $str);
        $str = preg_replace("/(Ù|Ú|Ụ|Ủ|Ũ|Ư|Ừ|Ứ|Ự|Ử|Ữ)/", 'U', $str);
        $str = preg_replace("/(Ỳ|Ý|Ỵ|Ỷ|Ỹ)/", 'Y', $str);
        $str = preg_replace("/(Đ)/", 'D', $str);

        $str = preg_replace('/[\t\n\r\0\x0B]/', '', $str);
        $str = preg_replace('/([\s])\1+/', ' ', $str);
        return trim($str);
    }

    /**
     * Validate that a value looks like a diacritic-bearing Vietnamese full name.
     * @param string $value
     * @return bool
     */
    public function check_str_vietnamese($value)
    {
        $name_arr = explode(' ', $value);
        if (count($name_arr) < 3) {
            return false;
        }
        return (bool) preg_match('/[^a-zA-Z0-9[:space:]\@\.\#\*\!\$\^\&\(\)]/', $value);
    }

    /**
     * Returns true when an individual registrant name looks invalid.
     * @param string $str
     * @return bool
     */
    public function checkRegistrantName($str)
    {
        $str   = preg_replace('/\s+/', ' ', $str);
        $words = explode(' ', $str);
        if (count($words) < 2) {
            return true;
        }
        if (count($words) > 4) {
            return true;
        }
        if (preg_match('/[^\x00-\x7F\x20-\x7E]/', $str)) {
            return false;
        }
        return false;
    }

    /**
     * Returns true when an organization registrant name looks invalid.
     * @param string $str
     * @return bool
     */
    public function checkRegistrantCompanyName($str)
    {
        if (preg_match('/\bcong ty\b/i', $str)) {
            return false;
        }
        if (preg_match('/[^\x00-\x7F\x20-\x7E]/', $str)) {
            return false;
        }
        return true;
    }

    /* =====================================================================
     *  ADDITIONAL WEBNIC API FEATURES
     * ===================================================================== */

    /**
     * IDN language code for the current order (register/transfer `lang`).
     * Read from the extended attribute "idnLanguageCode"; empty = registry default.
     * @return string
     */
    protected function idnLanguageCode()
    {
        $code = $this->options['ext']['idnLanguageCode'] ?? '';
        return trim((string) $code);
    }

    /**
     * Restore a domain from the redemption grace period.
     * POST domain/v2/restore  {domainName, agreeRestorePolicy, domainType?}
     * domainType ("premium") is mandatory for premium domains.
     * @return bool
     */
    public function RestoreDomain()
    {
        $this->_prepare_name();

        $params = [
            'domainName'         => $this->name,
            'agreeRestorePolicy' => true,
        ];

        $details = $this->_domain_details();
        if (is_array($details) && !empty($details['premium'])) {
            $params['domainType'] = 'premium';
        }

        $response = $this->postApi('domain/v2/restore', $params);
        if (!$this->ok($response)) {
            $this->addError($this->errMsg($response));
            return false;
        }
        $this->logAction(['action' => 'Restore Domain']);
        $this->addInfo('Domain restoration submitted successfully.');
        return true;
    }

    /** Alias of RestoreDomain(). */
    public function Restore()
    {
        return $this->RestoreDomain();
    }

    /**
     * List domains bound to a host/nameserver (admin host panel).
     * POST domain/v2/host/domainlist/search?nameserver=...  {pagination}
     * @param string $nameserver
     * @param int    $page
     * @param int    $pageSize
     * @return array items (empty on failure).
     */
    public function getHostLinkedDomains($nameserver, $page = 1, $pageSize = 50)
    {
        $url    = 'domain/v2/host/domainlist/search';
        if ($nameserver !== '') {
            $url .= '?nameserver=' . urlencode($nameserver);
        }
        $params = ['pagination' => ['page' => (int) $page, 'pageSize' => (int) $pageSize]];
        $resp   = $this->postApi($url, $params);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return [];
        }
        return $resp['data']['items'] ?? [];
    }

    /**
     * Query the transfer type for the current domain.
     * GET domain/v2/query-transfer-type?domainName=...
     * @return string|false registrar_transfer|reseller_transfer|domain_owner
     */
    public function queryTransferType()
    {
        $this->_prepare_name();
        $resp = $this->getApi('domain/v2/query-transfer-type?domainName=' . $this->name);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        return $resp['data']['transferType'] ?? false;
    }

    /**
     * Get the Universal WHOIS record for the current domain.
     * GET domain/v2/whois?domainName=...
     * Note: RDDS/WHOIS is being phased out and this is now best-effort.
     * @return array|false
     */
    public function getUniversalWhois()
    {
        $this->_prepare_name();
        $resp = $this->getApi('domain/v2/whois?domainName=' . $this->name);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        return $resp['data'] ?? false;
    }

    /* --------------------------------------------------------------------- *
     *  DNSSEC  (path domain/v2/dnssec shared by GET/POST/DELETE)
     * --------------------------------------------------------------------- */

    /**
     * Whether DNSSEC is supported for the current domain's registry.
     * GET domain/v2/dnssec/support?domainName=...
     * @return bool
     */
    public function checkDnssecSupported()
    {
        $this->_prepare_name();
        $resp = $this->getApi('domain/v2/dnssec/support?domainName=' . $this->name);
        if (!$this->ok($resp)) {
            return false;
        }
        return !empty($resp['data']['dnssecSupported']);
    }

    /**
     * Get the current DNSSEC DS records.
     * GET domain/v2/dnssec?domainName=...
     * @return array dsDatas (empty on none/failure).
     */
    public function getDnssecInfo()
    {
        $this->_prepare_name();
        $resp = $this->getApi('domain/v2/dnssec?domainName=' . $this->name);
        if (!$this->ok($resp)) {
            return [];
        }
        return $resp['data']['dsDatas'] ?? [];
    }

    /**
     * Replace the DNSSEC DS record set.
     * POST domain/v2/dnssec?domainName=...  {dsDatas:[{keyTag,algorithm,digestType,digest}]}
     * @param array $dsDatas
     * @return bool
     */
    public function updateDnssec($dsDatas = [])
    {
        $this->_prepare_name();

        // Normalise every DS field to a string, as the API expects.
        $clean = [];
        foreach ((array) $dsDatas as $ds) {
            if (empty($ds['keyTag']) || empty($ds['digest'])) {
                continue;
            }
            $clean[] = [
                'keyTag'     => (string) $ds['keyTag'],
                'algorithm'  => (string) ($ds['algorithm'] ?? ''),
                'digestType' => (string) ($ds['digestType'] ?? ''),
                'digest'     => (string) $ds['digest'],
            ];
        }
        if (empty($clean)) {
            $this->addError('No DNSSEC DS records supplied.');
            return false;
        }

        $resp = $this->postApi('domain/v2/dnssec?domainName=' . $this->name, ['dsDatas' => $clean]);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        $this->logAction(['action' => 'Update DNSSEC']);
        $this->addInfo('DNSSEC records updated.');
        return true;
    }

    /**
     * Disable DNSSEC for the current domain.
     * DELETE domain/v2/dnssec?domainName=...
     * @return bool
     */
    public function deleteDnssec()
    {
        $this->_prepare_name();
        $resp = $this->deleteApi('domain/v2/dnssec?domainName=' . $this->name);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        $this->logAction(['action' => 'Delete DNSSEC']);
        $this->addInfo('DNSSEC disabled.');
        return true;
    }

    /* --------------------------------------------------------------------- *
     *  REGISTRANT ACCOUNTS
     * --------------------------------------------------------------------- */

    /**
     * Create a WebNIC registrant account.
     * POST domain/v2/registrant/create?username=...
     * @param string $username min 10 chars, mixed alphanumeric.
     * @return array|false data (incl. registrantUserId) on success.
     */
    public function createRegistrantAccount($username)
    {
        $username = trim((string) $username);
        if ($username === '') {
            $this->addError('Registrant username is required.');
            return false;
        }
        $resp = $this->postApi('domain/v2/registrant/create?username=' . urlencode($username));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        $this->addInfo('Registrant account created: ' . ($resp['data']['registrantUserId'] ?? ''));
        return $resp['data'] ?? [];
    }

    /**
     * Search registrant accounts by username keyword (min 5 chars).
     * GET domain/v2/registrant/list?username=...
     * The per-row id is returned under `id` (not registrantUserId).
     * @param string $username
     * @return array list (empty on none/failure).
     */
    public function listRegistrantAccounts($username)
    {
        $username = trim((string) $username);
        if (strlen($username) < 5) {
            $this->addError('Registrant search keyword must be at least 5 characters.');
            return [];
        }
        $resp = $this->getApi('domain/v2/registrant/list?username=' . urlencode($username));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return [];
        }
        return $resp['data'] ?? [];
    }

    /**
     * Reassign a domain to a different registrant account.
     * PUT domain/v2/registrant-account?domainName=...&registrantUsername=...
     * @param string $domainName
     * @param string $registrantUsername
     * @return bool
     */
    public function reassignRegistrantAccount($domainName, $registrantUsername)
    {
        $domainName         = trim((string) $domainName);
        $registrantUsername = trim((string) $registrantUsername);
        if ($domainName === '' || $registrantUsername === '') {
            $this->addError('Both domain name and registrant username are required.');
            return false;
        }
        $url  = 'domain/v2/registrant-account?domainName=' . urlencode($domainName)
              . '&registrantUsername=' . urlencode($registrantUsername);
        $resp = $this->putApi($url);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        $this->addInfo('Domain reassigned to registrant: ' . $registrantUsername);
        return true;
    }

    /* --------------------------------------------------------------------- *
     *  TRANSFER-AWAY STATUS  (registrar + reseller)
     * --------------------------------------------------------------------- */

    /**
     * Get registrar transfer-away status by domain name.
     * GET domain/v2/transfer-away/status?domainName=...
     * @param string $domainName
     * @return array|false
     */
    public function getRegistrarTransferAwayStatus($domainName)
    {
        $resp = $this->getApi('domain/v2/transfer-away/status?domainName=' . urlencode((string) $domainName));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        return $resp['data'] ?? false;
    }

    /**
     * Get registrar transfer-away status by record id.
     * GET domain/v2/transfer-away/status/{id}
     * @param string|int $id
     * @return array|false
     */
    public function getRegistrarTransferAwayStatusById($id)
    {
        $resp = $this->getApi('domain/v2/transfer-away/status/' . urlencode((string) $id));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        return $resp['data'] ?? false;
    }

    /**
     * Approve/reject a registrar transfer-away request.
     * PUT domain/v2/transfer-away/status/{id}  {approveType, approve, approveRemarks?}
     * @param string|int $id
     * @param string     $approveType registrant|partner
     * @param bool       $approve
     * @param string     $remarks
     * @return bool
     */
    public function updateRegistrarTransferAwayStatus($id, $approveType, $approve, $remarks = '')
    {
        $params = [
            'approveType' => $approveType,
            'approve'     => (bool) $approve,
        ];
        if ($remarks !== '') {
            $params['approveRemarks'] = $remarks;
        }
        $resp = $this->putApi('domain/v2/transfer-away/status/' . urlencode((string) $id), $params);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        $this->addInfo('Registrar transfer-away status updated.');
        return true;
    }

    /**
     * Get reseller transfer-away status by domain name.
     * GET domain/v2/reseller-transfer/status?domainName=...
     * @param string $domainName
     * @return array|false
     */
    public function getResellerTransferAwayStatus($domainName)
    {
        $resp = $this->getApi('domain/v2/reseller-transfer/status?domainName=' . urlencode((string) $domainName));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        return $resp['data'] ?? false;
    }

    /**
     * Get reseller transfer-away status by record id.
     * GET domain/v2/reseller-transfer/status/{id}
     * @param string|int $id
     * @return array|false
     */
    public function getResellerTransferAwayStatusById($id)
    {
        $resp = $this->getApi('domain/v2/reseller-transfer/status/' . urlencode((string) $id));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        return $resp['data'] ?? false;
    }

    /**
     * Approve/reject a reseller transfer-away request.
     * PUT domain/v2/reseller-transfer/status/{id}  {approveType, approve, approveRemarks?}
     * @param string|int $id
     * @param string     $approveType registrant|partner|both
     * @param bool       $approve
     * @param string     $remarks
     * @return bool
     */
    public function updateResellerTransferAwayStatus($id, $approveType, $approve, $remarks = '')
    {
        $params = [
            'approveType' => $approveType,
            'approve'     => (bool) $approve,
        ];
        if ($remarks !== '') {
            $params['approveRemarks'] = $remarks;
        }
        $resp = $this->putApi('domain/v2/reseller-transfer/status/' . urlencode((string) $id), $params);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        $this->addInfo('Reseller transfer-away status updated.');
        return true;
    }

    /* --------------------------------------------------------------------- *
     *  BULK OPERATIONS
     * --------------------------------------------------------------------- */

    /**
     * Submit a bulk domain registration batch.
     * POST domain/v2/bulk/register  {domainRegistrationList:[...]}
     * @param array $registrationList each: domainName, term, nameservers, *ContactId, registrantUserId, domainType?
     * @return string|false taskId on success.
     */
    public function bulkRegister($registrationList)
    {
        if (empty($registrationList)) {
            $this->addError('No domains supplied for bulk registration.');
            return false;
        }
        $resp = $this->postApi('domain/v2/bulk/register', ['domainRegistrationList' => array_values($registrationList)]);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        $taskId = $resp['data']['taskId'] ?? false;
        $this->addInfo('Bulk registration submitted. Task ID: ' . $taskId);
        return $taskId;
    }

    /**
     * Submit a bulk domain renewal batch.
     * POST domain/v2/bulk/renew  {domainRenewalList:[{domainName,term}]}
     * @param array $renewalList each: domainName, term
     * @return string|false taskId on success.
     */
    public function bulkRenew($renewalList)
    {
        if (empty($renewalList)) {
            $this->addError('No domains supplied for bulk renewal.');
            return false;
        }
        $resp = $this->postApi('domain/v2/bulk/renew', ['domainRenewalList' => array_values($renewalList)]);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        $taskId = $resp['data']['taskId'] ?? false;
        $this->addInfo('Bulk renewal submitted. Task ID: ' . $taskId);
        return $taskId;
    }

    /**
     * Get the overview of a bulk batch.
     * GET domain/v2/bulk/report?taskId=...
     * @param string $taskId
     * @return array|false
     */
    public function getBulkReportOverview($taskId)
    {
        $taskId = trim((string) $taskId);
        if ($taskId === '') {
            $this->addError('Task ID is required.');
            return false;
        }
        $resp = $this->getApi('domain/v2/bulk/report?taskId=' . urlencode($taskId));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        return $resp['data'] ?? false;
    }

    /**
     * Get the per-domain detail rows of a bulk batch.
     * POST domain/v2/bulk/report/{taskId}/details/list  {filters?, pagination?}
     * @param string $taskId
     * @param array  $filters    each: field (domainName|status), operator (EQUAL), value
     * @param array  $pagination page, pageSize
     * @return array items (empty on failure).
     */
    public function getBulkReportDetails($taskId, $filters = [], $pagination = [])
    {
        $taskId = trim((string) $taskId);
        if ($taskId === '') {
            $this->addError('Task ID is required.');
            return [];
        }
        $params = [];
        if (!empty($filters)) {
            $params['filters'] = array_values($filters);
        }
        $params['pagination'] = [
            'page'     => (int) ($pagination['page'] ?? 1),
            'pageSize' => (int) ($pagination['pageSize'] ?? 50),
        ];
        $resp = $this->postApi('domain/v2/bulk/report/' . urlencode($taskId) . '/details/list', $params);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return [];
        }
        return $resp['data']['items'] ?? [];
    }

    /**
     * Bulk-replace contacts across many domains (creates a pending order).
     * POST domain/v2/contact/replace  {domainList:[], contactTypeMap:{...}}
     * Returns the Job Order ID string (query via Get Pending Order Info).
     * @param array $domainList
     * @param array $contactTypeMap keys: registrant|administrator|technical|billing => contactId
     * @return string|false job order id.
     */
    public function bulkReplaceContact($domainList, $contactTypeMap)
    {
        $domainList = array_values(array_filter((array) $domainList));
        $map        = array_filter((array) $contactTypeMap, function ($v) {
            return $v !== '' && $v !== null;
        });
        if (empty($domainList) || empty($map)) {
            $this->addError('A domain list and at least one contact id are required.');
            return false;
        }
        $resp = $this->postApi('domain/v2/contact/replace', ['domainList' => $domainList, 'contactTypeMap' => $map]);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        // data is a plain Job Order ID string for this endpoint.
        $jobOrderId = is_string($resp['data'] ?? null) ? $resp['data'] : ($resp['data']['id'] ?? '');
        $this->addInfo('Bulk contact replacement submitted. Job Order ID: ' . $jobOrderId);
        return $jobOrderId;
    }

    /* --------------------------------------------------------------------- *
     *  TRANSFER-IN STATUS  +  RESELLER-TRANSFER SUBMIT
     * --------------------------------------------------------------------- */

    /**
     * Submit a reseller transfer (gaining/losing reseller handover).
     * POST domain/v2/reseller-transfer  {domainName, registrantUserId, domainType?}
     * Distinct from the reseller transfer-away status approval endpoints.
     * @param string      $domainName
     * @param string|null $registrantUserId defaults to the configured registrant.
     * @param string      $domainType       standard|premium
     * @return array|false data on success.
     */
    public function submitResellerTransfer($domainName, $registrantUserId = null, $domainType = 'standard')
    {
        $domainName = trim((string) $domainName);
        if ($domainName === '') {
            $this->addError('Domain name is required.');
            return false;
        }
        $params = [
            'domainName'       => $domainName,
            'registrantUserId' => (string) ($registrantUserId ?? $this->configuration['registrant_user_id']['value']),
            'domainType'       => $domainType === 'premium' ? 'premium' : 'standard',
        ];
        $resp = $this->postApi('domain/v2/reseller-transfer', $params);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        $this->addInfo('Reseller transfer submitted for ' . $domainName);
        return $resp['data'] ?? [];
    }

    /**
     * Get registrar transfer-IN status by domain name.
     * GET domain/v2/transfer-in/status?domainName=...
     * @param string $domainName
     * @return array|false
     */
    public function getTransferInStatus($domainName)
    {
        $resp = $this->getApi('domain/v2/transfer-in/status?domainName=' . urlencode((string) $domainName));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        return $resp['data'] ?? false;
    }

    /**
     * Get registrar transfer-IN status by record id.
     * GET domain/v2/transfer-in/status/{id}
     * @param string|int $id
     * @return array|false
     */
    public function getTransferInStatusById($id)
    {
        $resp = $this->getApi('domain/v2/transfer-in/status/' . urlencode((string) $id));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        return $resp['data'] ?? false;
    }

    /* --------------------------------------------------------------------- *
     *  REGISTRY SUSPEND  +  PROXY (PRIVACY) SUBSCRIPTION
     * --------------------------------------------------------------------- */

    /**
     * Registry-level suspend (clientHold) at WebNIC.
     * POST domain/v2/suspend?domainName=...
     * Distinct from Suspended(), which swaps to hold nameservers locally.
     * @param string $domainName
     * @return bool
     */
    public function domainSuspend($domainName)
    {
        $domainName = trim((string) $domainName);
        if ($domainName === '') {
            $this->addError('Domain name is required.');
            return false;
        }
        $resp = $this->postApi('domain/v2/suspend?domainName=' . urlencode($domainName));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        $this->logAction(['action' => 'Registry Suspend', 'change' => $domainName]);
        $this->addInfo('Domain suspended at registry: ' . $domainName);
        return true;
    }

    /**
     * Registry suspend status.
     * GET domain/v2/suspendStatus?domainName=...
     * @param string $domainName
     * @return bool|null true=suspended, false=active, null on failure.
     */
    public function getDomainSuspendStatus($domainName)
    {
        $resp = $this->getApi('domain/v2/suspendStatus?domainName=' . urlencode((string) $domainName));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return null;
        }
        return (string) ($resp['data']['suspend'] ?? '') === 'true';
    }

    /**
     * Toggle the annual WebNIC proxy (privacy) service subscription.
     * PUT domain/v2/proxy  {action: subscribe|unsubscribe, domainName}
     * Distinct from updateIDProtection() (WHOIS privacy toggle).
     * @param string $domainName
     * @param bool   $subscribe
     * @return bool
     */
    public function toggleProxyService($domainName, $subscribe = true)
    {
        $domainName = trim((string) $domainName);
        if ($domainName === '') {
            $this->addError('Domain name is required.');
            return false;
        }
        $resp = $this->putApi('domain/v2/proxy', [
            'action'     => $subscribe ? 'subscribe' : 'unsubscribe',
            'domainName' => $domainName,
        ]);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        $this->logAction(['action' => 'Toggle Proxy Service', 'change' => ($subscribe ? 'subscribe' : 'unsubscribe') . ' ' . $domainName]);
        $this->addInfo('Proxy service ' . ($subscribe ? 'subscribed' : 'unsubscribed') . ': ' . $domainName);
        return true;
    }

    /* --------------------------------------------------------------------- *
     *  LOOKUP / RULES HELPERS
     * --------------------------------------------------------------------- */

    /**
     * Availability of a nameserver/host for a given extension.
     * POST domain/v2/host/check?nameserver=...&ext=...
     * @param string $nameserver
     * @param string $ext        TLD extension (e.g. "com").
     * @return array|false
     */
    public function checkHost($nameserver, $ext)
    {
        $nameserver = trim((string) $nameserver);
        $ext        = ltrim(trim((string) $ext), '.');
        if ($nameserver === '' || $ext === '') {
            $this->addError('Both nameserver and extension are required.');
            return false;
        }
        $resp = $this->postApi('domain/v2/host/check?nameserver=' . urlencode($nameserver) . '&ext=' . urlencode($ext));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        return $resp['data'] ?? false;
    }

    /**
     * Smart availability query: a domain plus suggested alternate TLDs.
     * GET domain/v2/smart-query?domainName=...&sortBy=...
     * @param string $domainName
     * @param string $sortBy     optional comma-separated TLD priority list.
     * @return array resultList (empty on none/failure).
     */
    public function smartQuery($domainName, $sortBy = '')
    {
        $url = 'domain/v2/smart-query?domainName=' . urlencode((string) $domainName);
        if ($sortBy !== '') {
            $url .= '&sortBy=' . urlencode((string) $sortBy);
        }
        $resp = $this->getApi($url);
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return [];
        }
        return $resp['data']['resultList'] ?? [];
    }

    /**
     * Per-extension rules (registration / transfer / grace / document).
     * GET domain/v2/ext-rules?ext=...&ruleType=...
     * @param string $ext
     * @param string $ruleType registration|transfer|grace|document
     * @return array|false
     */
    public function getExtensionRules($ext, $ruleType = 'registration')
    {
        $ext = ltrim(trim((string) $ext), '.');
        if ($ext === '') {
            $this->addError('Extension is required.');
            return false;
        }
        $resp = $this->getApi('domain/v2/ext-rules?ext=' . urlencode($ext) . '&ruleType=' . urlencode((string) $ruleType));
        if (!$this->ok($resp)) {
            $this->addError($this->errMsg($resp));
            return false;
        }
        return $resp['data'] ?? false;
    }

    /* =====================================================================
     *  MISC HELPERS
     * ===================================================================== */

    /**
     * Recursively strip Vietnamese accents from a payload before sending.
     * @param array $data
     * @return array
     */
    private function applyConvertAccents(array $data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->applyConvertAccents($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->convertAccentsAndSpecialToNormal($value);
            }
        }
        return $data;
    }

    /**
     * Recursively HTML-entity-decode string values.
     * @param mixed $data
     * @return mixed
     */
    private function convertToUtf8($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->convertToUtf8($value);
            }
            return $data;
        }
        if (is_string($data)) {
            return html_entity_decode($data, ENT_QUOTES, 'UTF-8');
        }
        return $data;
    }

    /**
     * Map a state name <-> state code using HostBill's locations table.
     * @param string $state
     * @param bool   $reverse code->name when true.
     * @return string
     */
    protected function prepareCity($state, $reverse = false)
    {
        $states_list = Other\Locations\ORM\ORM_State::get()->toArray();
        $states = [];
        foreach ($states_list as $value) {
            $states[$value['code']] = $value['name'];
        }

        if ($state) {
            if ($reverse === false) {
                $ok = array_search($state, $states);
                if ($ok) {
                    return $ok;
                }
            } else {
                $ok = $states[strtoupper($state)] ?? null;
                if ($ok) {
                    return $ok;
                }
            }
        }
        return $state;
    }
}
