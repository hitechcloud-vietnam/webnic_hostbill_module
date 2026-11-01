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
 * @package Domain\webnic_domain
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
    protected $version = '1.2026-11-02.1';

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
        $this->http = new Client();
    }

    /* =====================================================================
     *  API CLIENT
     * ===================================================================== */

    /** Whether the module requires authentication. */
    public function needAuth()
    {
        return true;
    }

    /** Is the configured environment LIVE? (OTE != 1) */
    protected function isLive()
    {
        return $this->configuration['OTE Environment']['value'] != 1;
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

            // Skip .vn TLDs (handled by the local VNNIC registrar).
            $tldsResponse['data'] = array_filter($tldsResponse['data'], function ($item) {
                return substr($item, -2) !== 'vn';
            });

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

            $priceActs = ['register' => 'register', 'renewal' => 'renew', 'transfer' => 'transfer', 'restore' => 'redemption'];
            foreach ($pricesResponse['data']['items'] as $tldPrice) {
                foreach (['productPricing', 'localPrice'] as $bucket) {
                    if (empty($tldPrice[$bucket]['price'])) {
                        continue;
                    }
                    foreach ($tldPrice[$bucket]['price'] as $priceType => $details) {
                        if (!isset($priceActs[$priceType]) || empty($details['ascii'])) {
                            continue;
                        }
                        foreach ($details['ascii'] as $year => $price) {
                            $key = '.' . $tldPrice['productKey'];
                            $prices['VND'][$key][$year]['period']                  = $year;
                            $prices['VND'][$key][$year][$priceActs[$priceType]]    = round($price * 26000 / 1000) * 1000;
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

        // Vietnamese registrant-name / sensitive-keyword validation (skipped for manual).
        if (empty($this->options['manual_reg'])) {
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
                'registrantUserId'       => $this->configuration['registrant_user_id']['value'],
                'registrantContactId'    => $registrant,
                'administratorContactId' => $admin,
                'technicalContactId'     => $technical,
                'billingContactId'       => $billing,
            ];
            if (isset($this->options['ext'][$this->proxy_field]) && in_array($this->options['tld'], $this->proxy_tlds)) {
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

        // Non-blocking sensitive keyword notice + blocking name checks (unless manual).
        $domainFilter = $this->checkKeywordSensitive($this->name);
        if ($domainFilter) {
            $this->addError('Domain contains a sensitive keyword, please review: ' . $domainFilter . '.');
        }
        if (empty($this->options['manual_reg'])) {
            if (!$this->runRegistrantNameChecks(false)) {
                return false;
            }
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
                'registrantUserId'       => $this->configuration['registrant_user_id']['value'],
                'registrantContactId'    => $registrant,
                'administratorContactId' => $admin,
                'technicalContactId'     => $technical,
                'billingContactId'       => $billing,
            ];
            if (isset($this->options['ext'][$this->proxy_field]) && in_array($this->options['tld'], $this->proxy_tlds)) {
                $params['subscribeProxy'] = true;
            }
            if (!array_key_exists('subscribeProxy', $params)) {
                $params['subscribeProxy'] = false;
            }
            $params['authInfo'] = $this->options['epp_code'];

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
        $response = $this->postApi('domain/v2/renew', ['domainName' => $this->name, 'term' => $this->period]);
        if (!$this->ok($response)) {
            $this->addError($this->errMsg($response));
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
        $requireFields = ['firstname', 'lastname', 'nationalid', 'phonenumber', 'state', 'city', 'address1'];
        $registrant    = $this->domain_contacts['registrant'];

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

        if ($enforceAvailabilityEkyc) {
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
