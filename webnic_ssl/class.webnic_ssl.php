<?php

/**
 * WebNIC SSL provisioning module for HostBill.
 *
 * Implements the HostBill SSLModule contract against WebNIC's REST API
 * (`/ssl/v2/*`, authenticated through `/reseller/v2/api-user/token`). The
 * module places and manages SSL certificate orders: product discovery, CSR
 * ordering, domain-control validation (DNS / HTTP file / approver email),
 * reissue, renewal, status synchronisation and certificate download.
 *
 * API conventions (all WebNIC REST calls):
 *   - JSON bodies, `Authorization: Bearer <token>`, success envelope `code == 1000`.
 *   - Bearer tokens are cached in HBCache and transparently refreshed once on a
 *     401 before the request is retried (see sendRequest()).
 *   - The LIVE host is used by default; the TEST (OTE) host is used when the
 *     "OTE Environment" connection checkbox is enabled.
 *
 * This is a fresh module. It does not depend on, extend or modify the older
 * webnic_ssl_v3 module; it only reproduces its proven request/response mapping.
 *
 * @see https://api.webnic.cc           Live API
 * @see https://oteapi.webnic.cc        OTE / test API
 *
 * @package   HiTechCloud WebNIC Suite
 * @author    HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @copyright 2026 HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @license   MIT
 * @version   1.1.0
 */
class webnic_ssl extends SSLModule
{
    /**
     * Capability flags.
     *  - haveuser/haveadmin/havetpl: standard SSL module surfaces.
     *  - haveapi: exposes the REST routes under api/ to the User API plugin.
     */
    protected $info = ["haveuser" => true, "haveadmin" => true, "havetpl" => true, "haveapi" => true];

    protected $version = '1.1.0';
    protected $_repository = 'hosting_webnic_ssl';
    protected $modname = 'WebNIC SSL';
    protected $description = 'WebNIC SSL provisioning module for HostBill';

    /**
     * Product-configuration options rendered on the HostBill product page.
     *  - product_key: loadable select populated by listProducts().
     *  - DCV: loadablemultiple select populated by listDCVMethod().
     *  - SAN_LIMIT: hard cap on SAN entries (further clamped by product rules).
     *  - special_instruction: free text forwarded to WebNIC on order placement.
     *  - san: enables the SAN entry form.
     */
    protected $options = [
        'product_key' => [
            'name' => 'SSL Product',
            'value' => false,
            'type' => 'loadable',
            'default' => 'listProducts',
        ],
        'market' => [
            'name' => 'Market',
            'value' => 'vn',
            'type' => 'select',
            'default' => [
                'vn' => 'Vietnam (VN)',
                'global' => 'Global',
            ],
        ],
        \SSL\OPT\DCV => [
            'name' => 'DCV Type',
            'value' => false,
            'type' => 'loadablemultiple',
            'default' => 'listDCVMethod',
        ],
        \SSL\OPT\SAN_LIMIT => [
            'type' => 'input',
            'name' => 'Maximum SAN entries',
            'value' => false,
        ],
        'special_instruction' => [
            'name' => 'Special Instruction',
            'type' => 'textarea',
            'value' => '',
        ],
        'san' => [
            'name' => 'SAN',
            'forms' => 'san',
            'variable' => 'san',
        ],
    ];

    /**
     * Connection (server) fields. WebNIC authenticates with an API username and
     * secret; the checkbox selects the OTE test environment.
     */
    protected $serverFields = [
        self::CONNECTION_FIELD_HOSTNAME => false,
        self::CONNECTION_FIELD_IPADDRESS => false,
        self::CONNECTION_FIELD_USERNAME => true,
        self::CONNECTION_FIELD_PASSWORD => true,
        self::CONNECTION_FIELD_INPUT1 => false,
        self::CONNECTION_FIELD_INPUT2 => false,
        self::CONNECTION_FIELD_TEXTAREA => false,
        self::CONNECTION_FIELD_CHECKBOX => true,
        self::CONNECTION_FIELD_NAMESERVERS => false,
        self::CONNECTION_FIELD_MAXACCOUNTS => false,
        self::CONNECTION_FIELD_STATUSURL => false,
    ];

    protected $serverFieldsDescription = [
        self::CONNECTION_FIELD_USERNAME => 'API Username',
        self::CONNECTION_FIELD_PASSWORD => 'API Secret',
        self::CONNECTION_FIELD_CHECKBOX => 'OTE Environment',
    ];

    /**
     * Per-service stored details. Visible fields (domain, order id, status) are
     * shown on the service page; the hidden fields persist order state across
     * calls (CSR, chosen DCV + its details, contacts, SAN list, WebNIC order /
     * contact / organization ids, auth info and the raw vendor status strings).
     */
    protected $details = [
        \SSL\CN => [
            'name' => 'domain',
            'type' => 'input',
        ],
        \SSL\ORDERID => [
            'name' => 'Order ID',
            'type' => 'input',
        ],
        \SSL\STATUS => [
            'name' => 'Certificate Status',
            'type' => 'select',
            'default' => [
                \SSL\STATUS\INCOMPLETE,
                \SSL\STATUS\PENDING,
                \SSL\STATUS\PROCESSING,
                \SSL\STATUS\VALIDATION,
                \SSL\STATUS\ISSUED,
                \SSL\STATUS\CANCELLED,
                \SSL\STATUS\EXPIRED,
            ],
        ],
        \SSL\CSR => [
            'type' => 'hidden',
        ],
        \SSL\DCV => [
            'type' => 'hidden',
        ],
        \SSL\DCV_DETAILS => [
            'type' => 'hidden',
        ],
        \SSL\CONTACTS => [
            'type' => 'hidden',
        ],
        \SSL\SAN => [
            'type' => 'hidden',
        ],
        'productKey' => [
            'type' => 'hidden',
        ],
        'organizationId' => [
            'type' => 'hidden',
        ],
        'administratorContactId' => [
            'type' => 'hidden',
        ],
        'technicalContactId' => [
            'type' => 'hidden',
        ],
        'authInfo' => [
            'type' => 'hidden',
        ],
        'dcvStatus' => [
            'type' => 'hidden',
        ],
        'orderStatusRaw' => [
            'type' => 'hidden',
        ],
        'certStatusRaw' => [
            'type' => 'hidden',
        ],
    ];

    /** Admin/client service commands exposed beyond the SSL defaults. */
    protected $commands = ['Reissue'];

    /** Connection data supplied by connect(): username, password, secure flag. */
    private $connect_data = [];

    const LIVE = 'https://api.webnic.cc';
    const TEST = 'https://oteapi.webnic.cc';
    const TOKEN_PATH = '/reseller/v2/api-user/token';
    const TOKEN_CACHE_PREFIX = 'webnic_ssl.token.';
    const PRODUCT_CACHE_PREFIX = 'webnic_ssl.products.';
    const RULE_CACHE_PREFIX = 'webnic_ssl.rules.';

    // ------------------------------------------------------------------
    // SSLModule lifecycle / discovery
    // ------------------------------------------------------------------

    /** Receive the server connection parameters for this call. */
    public function connect($connect)
    {
        $this->connect_data = $connect;
    }

    /** Default billing/order term, in months. */
    protected function getDefaultPeriod()
    {
        return 12;
    }

    /**
     * Configured WebNIC market for this product: 'vn' (Vietnam) or 'global'.
     * Credentials are shared across markets; only market-specific behaviour
     * (contact defaults, product filtering, future VND handling) is gated on it.
     * Defaults to 'vn' when the option is absent (e.g. during testConnection()).
     */
    protected function getMarket()
    {
        $market = strtolower(trim((string) ($this->options['market']['value'] ?? 'vn')));
        return $market === 'global' ? 'global' : 'vn';
    }

    /** True when the configured market is Vietnam (VN). */
    protected function isVN()
    {
        return $this->getMarket() === 'vn';
    }

    /**
     * Market-specific product filter. WebNIC exposes one catalogue per API user
     * today, so both markets currently see the full list; the hook exists so a
     * VN-only (or Global-only) product subset can be enforced here later without
     * touching the discovery flow.
     */
    protected function filterProductsForMarket(array $products)
    {
        return $products;
    }

    /**
     * Apply the VN-market default country code to a contact/organization value
     * when none was supplied. Global behaves as the standard flow (no default).
     */
    protected function applyMarketCountryDefault($countryCode)
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        if ($countryCode === '' && $this->isVN()) {
            return 'VN';
        }
        return $countryCode;
    }

    /** Verify credentials by forcing a fresh product listing. */
    public function testConnection()
    {
        try {
            $this->getProducts(true);
            return true;
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /**
     * Loadable source for the product_key option: WebNIC SSL products as
     * [productKey, "Name / provider / certType"] pairs.
     */
    public function listProducts()
    {
        try {
            $products = $this->getProducts();
            $list = [];

            foreach ($products as $product) {
                $parts = [$product['productName']];
                if (!empty($product['provider'])) {
                    $parts[] = $product['provider'];
                }
                if (!empty($product['certType'])) {
                    $parts[] = $product['certType'];
                }
                $list[] = [$product['productKey'], implode(' / ', $parts)];
            }

            return $list;
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /** Loadable-multiple source for the DCV option, and the API dcv-methods route. */
    public function listDCVMethod()
    {
        return [
            [\SSL\DCV\EMAIL, 'Email'],
            [\SSL\DCV\DNS, 'DNS'],
            [\SSL\DCV\HTTP, 'HTTP'],
        ];
    }

    /** Contact fields required for this product's certificate type (DV/OV/EV). */
    public function CertContacts($params)
    {
        return SSLType::contacts_init($this->getProductCertType(), $params);
    }

    /**
     * Declare the ordering capabilities HostBill should present (CSR required,
     * supported DCV methods, wildcard/SAN support and SAN limit) derived from
     * the configured product's metadata and rules.
     */
    public function CertOptions($product)
    {
        try {
            $productKey = $this->getConfiguredProductKey();
            $meta = $this->getProductMeta($productKey);
            $rules = $this->getProductRules($productKey);
            $allowSan = !empty($meta['allowSan']) || !empty($meta['allowWsan']) || !empty($rules['allowSan']) || !empty($rules['allowWsan']);
            $configuredDcv = (array) ($this->options[\SSL\OPT\DCV]['value'] ?: []);
            $dcv = array_values(array_unique(array_filter($configuredDcv)));

            if (empty($dcv)) {
                $dcv = [\SSL\DCV\EMAIL, \SSL\DCV\DNS, \SSL\DCV\HTTP];
            }

            $sanLimit = 0;
            if ($allowSan) {
                $sanLimit = (int) ($rules['maxSan'] ?? 0);
                $configuredLimit = (int) ($this->options[\SSL\OPT\SAN_LIMIT]['value'] ?: 0);
                if ($configuredLimit > 0) {
                    $sanLimit = $sanLimit > 0 ? min($sanLimit, $configuredLimit) : $configuredLimit;
                }
            }

            return [
                \SSL\OPT\CSR => true,
                \SSL\CSR_LATER => false,
                \SSL\OPT\DCV => $dcv,
                \SSL\OPT\WILDCARD => !empty($meta['wildcard']) || !empty($rules['wildcard']),
                \SSL\OPT\SAN_LIMIT => $allowSan ? max(0, $sanLimit) : 0,
                \SSL\OPT\SAN_DOMAIN => $allowSan,
                \SSL\OPT\SAN_PUBLICIPS => false,
            ];
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return [\SSL\OPT\CSR => true];
        }
    }

    // ------------------------------------------------------------------
    // Order lifecycle
    // ------------------------------------------------------------------

    /** Place a new WebNIC SSL order from the stored CSR, contacts and DCV choice. */
    public function Create()
    {
        try {
            $payload = $this->buildCreatePayload();
            try {
                $response = $this->apiJson('POST', '/ssl/v2/orders/new', $payload);
            } catch (Exception $e) {
                throw new Exception('Create order failed: ' . $e->getMessage());
            }

            $this->details[\SSL\ORDERID]['value'] = $response['orderId'];
            $this->details['productKey']['value'] = $payload['productKey'];
            $this->details[\SSL\STATUS]['value'] = \SSL\STATUS\VALIDATION;

            $this->persistAuthDataFromOrderResponse($response);

            return true;
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /**
     * Renew the certificate. WebNIC issues a new order id for the renewal; the
     * stored order id, contacts and status are updated to the renewed order.
     */
    public function Renewal()
    {
        try {
            $orderId = $this->requireOrderId();
            $payload = [
                'term' => $this->getOrderTermYears(),
            ];

            if (!empty($this->details[\SSL\CSR]['value'])) {
                $payload['csr'] = $this->details[\SSL\CSR]['value'];
            }

            $response = $this->apiJson('POST', '/ssl/v2/orders/' . rawurlencode($orderId) . '/renew', $payload);
            if (empty($response['renewal']) || empty($response['renewOrder']['orderId'])) {
                throw new Exception('WebNIC did not return the renewed order id.');
            }

            $renewOrder = $response['renewOrder'];
            $this->details[\SSL\ORDERID]['value'] = $renewOrder['orderId'];
            $this->details['administratorContactId']['value'] = $renewOrder['admid'] ?? $this->details['administratorContactId']['value'];
            $this->details['technicalContactId']['value'] = $renewOrder['tecid'] ?? $this->details['technicalContactId']['value'];
            $this->details['orderStatusRaw']['value'] = $renewOrder['orderStatus'] ?? '';
            $this->details['certStatusRaw']['value'] = $renewOrder['certStatus'] ?? '';
            $this->details[\SSL\STATUS]['value'] = $this->mapVendorStatus(
                $renewOrder['orderStatus'] ?? '',
                $renewOrder['certStatus'] ?? ''
            );

            $authInfo = $this->fetchAuthInfo($renewOrder['orderId'], false);
            if (!empty($authInfo)) {
                $this->persistAuthDataFromAuthInfo($authInfo);
            }

            return true;
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /** Reissue the certificate with a new CSR (and current SAN set). */
    public function Reissue()
    {
        try {
            $orderId = $this->requireOrderId();
            $payload = [
                'csr' => $this->requireCsr(),
            ];
            $san = $this->getSanNames();
            if (!empty($san)) {
                $payload['sanfield'] = $san;
            }

            $response = $this->apiJson('POST', '/ssl/v2/orders/' . rawurlencode($orderId) . '/reissue', $payload);

            $this->details[\SSL\STATUS]['value'] = \SSL\STATUS\VALIDATION;
            $this->persistAuthDataFromOrderResponse($response);

            return true;
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /** Cancel the WebNIC order and mark the service cancelled locally. */
    public function Terminate()
    {
        try {
            $orderId = $this->requireOrderId();
            $this->apiJson('POST', '/ssl/v2/orders/' . rawurlencode($orderId) . '/cancel');
            $this->details[\SSL\STATUS]['value'] = \SSL\STATUS\CANCELLED;
            $this->details['orderStatusRaw']['value'] = 'CANCELLED';
            $this->details['certStatusRaw']['value'] = 'CANCELLED';
            return true;
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Domain Control Validation (DCV)
    // ------------------------------------------------------------------

    /** Approver email candidates per domain (for email-based validation). */
    public function CertDCVEmail()
    {
        try {
            $provider = strtolower((string) ($this->getCurrentProvider() ?: ''));
            $list = [];

            foreach ($this->getOrderNames() as $name) {
                $query = ['commonName' => $this->normalizeValidationName($name)];
                if ($provider) {
                    $query['certAuthority'] = $provider;
                }

                $response = $this->apiJson('GET', '/ssl/v2/domainValidations/approver-list', [], $query);
                $emails = array_merge((array) ($response['baseEmail'] ?? []), (array) ($response['domainEmail'] ?? []));
                $list[$name] = array_values(array_unique(array_filter($emails)));
            }

            return $list;
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return [];
        }
    }

    /** HTTP (file) validation details per domain. */
    public function CertDcvHttp()
    {
        try {
            return $this->formatFileAuthDetails($this->fetchAuthInfo());
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return [];
        }
    }

    /** DNS (TXT) validation records per domain. */
    public function CertDcvDns()
    {
        try {
            return $this->formatDnsAuthDetails($this->fetchAuthInfo());
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return [];
        }
    }

    /**
     * Change the active DCV method via WebNIC's Change Auth Type endpoint
     * (PUT /ssl/v2/orders/{orderId}/auth). DNS<->File<->Email transitions are all
     * supported: the call switches the order's authType and returns fresh DCV
     * details, which are persisted. Switching TO email additionally sets the
     * chosen approver address per domain (PUT .../email/approver).
     */
    public function CertDCVMethodChange($method, $email = false)
    {
        try {
            $target = $this->hostBillDcvToVendor($method);
            $orderId = $this->requireOrderId();

            $data = $this->changeAuthType($target);
            if (!empty($data)) {
                $this->persistAuthDataFromAuthInfo($data);
            }

            if ($target === 'email') {
                $available = $this->CertDCVEmail();
                $selected = $this->normalizeSelectedEmails($email, $available);

                $entries = $this->getAuthEntries(!empty($data) ? $data : $this->fetchAuthInfo($orderId));
                foreach ($entries as $entry) {
                    $name = $entry['name'] ?? '';
                    if ($name === '') {
                        continue;
                    }
                    if (empty($selected[$name])) {
                        throw new Exception('Missing approver email selection for ' . $name);
                    }

                    $this->apiJson(
                        'PUT',
                        '/ssl/v2/orders/' . rawurlencode($orderId) . '/email/approver',
                        [],
                        ['name' => $name, 'email' => $selected[$name]]
                    );
                }

                $this->details[\SSL\DCV]['value'] = \SSL\DCV\EMAIL;
                $this->details[\SSL\DCV_DETAILS]['value'] = $selected;
                return true;
            }

            $this->details[\SSL\DCV]['value'] = $this->vendorDcvToHostBill($target);
            return true;
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /**
     * Change the order's DCV auth type (dns / file / email) via WebNIC's
     * PUT /ssl/v2/orders/{orderId}/auth and return the refreshed DCV details
     * (commonName + san entries).
     */
    protected function changeAuthType($authType, $sendGuideEmail = false)
    {
        $payload = ['authType' => $authType];
        if ($sendGuideEmail) {
            $payload['sendAuthGuideEmail'] = true;
        }

        return $this->apiJson(
            'PUT',
            '/ssl/v2/orders/' . rawurlencode($this->requireOrderId()) . '/auth',
            $payload
        );
    }

    /** Re-send the approver email for every email-validated domain on the order. */
    public function CertDCVEmailResend()
    {
        try {
            $authInfo = $this->fetchAuthInfo();
            $resent = false;

            foreach ($this->getAuthEntries($authInfo) as $entry) {
                if (($entry['authType'] ?? '') !== 'email') {
                    continue;
                }

                $this->apiJson(
                    'POST',
                    '/ssl/v2/orders/' . rawurlencode($this->requireOrderId()) . '/send/email/approver',
                    [],
                    ['name' => $entry['name']]
                );
                $resent = true;
            }

            if (!$resent) {
                throw new Exception('Current validation method is not email, nothing to resend.');
            }

            return true;
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Synchronisation & download
    // ------------------------------------------------------------------

    /**
     * Pull the latest order/cert status from WebNIC, map it to a HostBill
     * status, refresh DCV + auth info, fire the issued-certificate notification
     * on transition, and return the certificate expiry as next_due when known.
     */
    public function getSynchInfo()
    {
        try {
            $orderId = $this->requireOrderId();
            $orderInfo = $this->getOrderInfo($orderId);
            $oldStatus = $this->details[\SSL\STATUS]['value'];

            $this->details['orderStatusRaw']['value'] = $orderInfo['orderStatus'] ?? '';
            $this->details['certStatusRaw']['value'] = $orderInfo['certStatus'] ?? '';
            $this->details['administratorContactId']['value'] = $orderInfo['admid'] ?? $this->details['administratorContactId']['value'];
            $this->details['technicalContactId']['value'] = $orderInfo['tecid'] ?? $this->details['technicalContactId']['value'];
            $this->details[\SSL\STATUS]['value'] = $this->mapVendorStatus(
                $orderInfo['orderStatus'] ?? '',
                $orderInfo['certStatus'] ?? ''
            );

            $dcvStatus = $this->fetchDcvStatus($orderId, false);
            if (!empty($dcvStatus)) {
                $this->details['dcvStatus']['value'] = $dcvStatus;
            }

            $authInfo = $this->fetchAuthInfo($orderId, false);
            if (!empty($authInfo)) {
                $this->persistAuthDataFromAuthInfo($authInfo);
            }

            $this->notifyWhenCertIssued($oldStatus);

            if (!empty($orderInfo['dtcertexpire'])) {
                return ['next_due' => substr($orderInfo['dtcertexpire'], 0, 10)];
            }

            return [true];
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /** Download the issued certificate chain as an array of PEM blocks. */
    public function CertDownload()
    {
        try {
            $body = $this->downloadCertificateArchive('PEM', true);

            $certs = $this->extractCertificatesFromDownload($body);
            if (empty($certs)) {
                throw new Exception('Unable to parse PEM certificates from downloaded WebNIC archive.');
            }

            return $certs;
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return [];
        }
    }

    /**
     * Download the issued certificate in any WebNIC-supported container and
     * return the raw (binary) archive body.
     * POST /ssl/v2/orders/{orderId}/download/format/{format}.
     * Formats: PEM, DER, PKCS7, PKCS12, NGINX, JKS.
     */
    protected function downloadCertificateArchive($format = 'PEM', $bundle = true)
    {
        $format = strtoupper(trim((string) $format)) ?: 'PEM';
        return $this->apiBinary(
            'POST',
            '/ssl/v2/orders/' . rawurlencode($this->requireOrderId()) . '/download/format/' . rawurlencode($format),
            ['bundle' => $bundle ? 'true' : 'false']
        );
    }

    // ------------------------------------------------------------------
    // CSR tools (generate / decode)
    // ------------------------------------------------------------------

    /**
     * Generate a CSR + private key server-side. POST /ssl/v2/csr/generate.
     * Returns ['csr' => ..., 'privateKey' => ...] (both single-line, '\n'-escaped).
     *
     * @param array $params commonName, country, keySize, locality, state,
     *                       organizationName, organizationUnit, sanfield[]
     */
    public function generateCsr(array $params)
    {
        $payload = array_filter([
            'commonName' => (string) ($params['commonName'] ?? ''),
            'country' => $this->applyMarketCountryDefault($params['country'] ?? ''),
            'keySize' => (int) ($params['keySize'] ?? 2048),
            'locality' => (string) ($params['locality'] ?? ''),
            'state' => (string) ($params['state'] ?? ''),
            'organizationName' => (string) ($params['organizationName'] ?? ''),
        ], [$this, 'keepNullableValue']);

        if (($payload['keySize'] ?? 0) !== 4096) {
            $payload['keySize'] = 2048;
        }

        $san = array_values(array_filter((array) ($params['sanfield'] ?? [])));
        if (!empty($san)) {
            $payload['sanfield'] = $san;
        }

        $this->validateRequiredFields('CSR', $payload, ['commonName', 'country', 'locality', 'organizationName']);

        return $this->apiJson('POST', '/ssl/v2/csr/generate', $payload);
    }

    /**
     * Generate a CSR for this service, defaulting the common name to the service
     * domain and pulling organization details from the stored certificate
     * contacts. Returns ['csr' => ..., 'privateKey' => ...].
     */
    public function generateCsrForService(array $overrides = [])
    {
        $contacts = $this->getContactsValue();
        $organization = (array) ($contacts['organization'] ?? []);
        $admin = (array) ($contacts['admin'] ?? []);

        $params = [
            'commonName' => $overrides['commonName'] ?? ($this->details[\SSL\CN]['value'] ?: ($this->account_details['domain'] ?? '')),
            'country' => $overrides['country'] ?? $this->pickValue($organization, ['country', 'countryCode'], $this->pickValue($admin, ['country', 'countryCode'], '')),
            'state' => $overrides['state'] ?? $this->pickValue($organization, ['state'], $this->pickValue($admin, ['state'], '')),
            'locality' => $overrides['locality'] ?? $this->pickValue($organization, ['city'], $this->pickValue($admin, ['city'], '')),
            'organizationName' => $overrides['organizationName'] ?? $this->pickValue($organization, ['name', 'company'], $this->pickValue($admin, ['company', 'orgname'], '')),
            'keySize' => (int) ($overrides['keySize'] ?? 2048),
            'sanfield' => $overrides['sanfield'] ?? $this->getSanNames(),
        ];

        return $this->generateCsr($params);
    }

    /**
     * Decode a CSR into its subject fields. POST /ssl/v2/csr/decode.
     * Line breaks in the CSR are normalised to the literal '\n' WebNIC expects.
     */
    public function decodeCsr($csr)
    {
        $csr = trim((string) $csr);
        if ($csr === '') {
            throw new Exception('A CSR is required to decode.');
        }

        $singleLine = str_replace(["\r\n", "\r", "\n"], '\n', $csr);
        return $this->apiJson('POST', '/ssl/v2/csr/decode', ['csr' => $singleLine]);
    }

    /** Decode the CSR currently stored on this service (for display). */
    public function decodeStoredCsr()
    {
        return $this->decodeCsr($this->requireCsr());
    }

    // ------------------------------------------------------------------
    // Organization & organization validation
    // ------------------------------------------------------------------

    /**
     * Submit organization validation for an OV/EV order.
     * POST /ssl/v2/organization/{organizationId}/validate.
     * The validation type defaults to the product cert type (ov/ev) and the
     * verified user defaults to the stored administrator contact.
     */
    public function submitOrganizationValidation($validationType = null, array $verifiedUser = [])
    {
        $organizationId = (string) ($this->details['organizationId']['value'] ?? '');
        if ($organizationId === '') {
            $organizationId = $this->ensureOrganizationId();
        }

        if ($validationType === null || $validationType === '') {
            $validationType = strtolower($this->getProductCertType());
        }

        if (empty($verifiedUser)) {
            $verifiedUser = $this->buildVerifiedUser();
        }

        $this->validateRequiredFields('Verified user', $verifiedUser, ['firstName', 'lastName', 'jobTitle', 'phoneNumber', 'email']);

        return $this->apiJson(
            'POST',
            '/ssl/v2/organization/' . rawurlencode($organizationId) . '/validate',
            ['validationType' => strtolower((string) $validationType), 'verifiedUser' => $verifiedUser]
        );
    }

    /**
     * Current organization validation status.
     * GET /ssl/v2/organization/{organizationId}/validate/status.
     */
    public function getOrganizationValidationStatus($organizationId = null)
    {
        $organizationId = (string) ($organizationId ?: ($this->details['organizationId']['value'] ?? ''));
        if ($organizationId === '') {
            throw new Exception('No organization id is stored for this service.');
        }

        return $this->apiJson('GET', '/ssl/v2/organization/' . rawurlencode($organizationId) . '/validate/status');
    }

    /** Organization record. GET /ssl/v2/organization/{organizationId}/info. */
    public function getOrganizationInfo($organizationId = null)
    {
        $organizationId = (string) ($organizationId ?: ($this->details['organizationId']['value'] ?? ''));
        if ($organizationId === '') {
            throw new Exception('No organization id is stored for this service.');
        }

        return $this->apiJson('GET', '/ssl/v2/organization/' . rawurlencode($organizationId) . '/info');
    }

    /**
     * Search organizations under the reseller account.
     * POST /ssl/v2/organization/search ({filters:[{field,operator,value}], pagination}).
     */
    public function searchOrganizations(array $filters = [], $page = 1, $pageSize = 20)
    {
        return $this->apiJson('POST', '/ssl/v2/organization/search', [
            'filters' => array_values($filters),
            'pagination' => [
                'page' => max(1, (int) $page),
                'pageSize' => max(1, (int) $pageSize),
            ],
        ]);
    }

    /** Build WebNIC's verifiedUser object from the stored contacts. */
    protected function buildVerifiedUser()
    {
        $contacts = $this->getContactsValue();
        $organization = (array) ($contacts['organization'] ?? []);
        $admin = (array) ($contacts['admin'] ?? []);

        return array_filter([
            'firstName' => $this->pickValue($admin, ['firstname', 'firstName'], $this->pickValue($organization, ['firstname', 'firstName'], '')),
            'lastName' => $this->pickValue($admin, ['lastname', 'lastName'], $this->pickValue($organization, ['lastname', 'lastName'], '')),
            'jobTitle' => $this->pickValue($admin, ['jobtitle', 'jobTitle'], $this->pickValue($organization, ['jobtitle', 'jobTitle'], '')),
            'phoneNumber' => $this->normalizePhoneNumber($this->pickValue($admin, ['phone', 'phoneNumber'], $this->pickValue($organization, ['phone', 'phoneNumber'], ''))),
            'email' => strtolower($this->pickValue($admin, ['email'], $this->pickValue($organization, ['email'], ''))),
        ], [$this, 'keepNullableValue']);
    }

    // ------------------------------------------------------------------
    // DCV reads (client-facing wrappers)
    // ------------------------------------------------------------------

    /** Public DCV status for this service. GET /ssl/v2/orders/{orderId}/dcv-status. */
    public function getDcvStatus()
    {
        return $this->fetchDcvStatus($this->requireOrderId());
    }

    /**
     * Per-method validation details for display: DNS records, HTTP file details,
     * and the active email approvers, derived from GET .../auth/info.
     */
    public function getValidationDetails()
    {
        $authInfo = $this->fetchAuthInfo();
        $emails = [];

        foreach ($this->getAuthEntries($authInfo) as $entry) {
            if (($entry['authType'] ?? '') === 'email' && !empty($entry['name'])) {
                $emails[$entry['name']] = $entry['value'] ?? '';
            }
        }

        return [
            'method' => $this->vendorDcvToHostBill($this->extractVendorAuthType($authInfo)),
            'dns' => $this->formatDnsAuthDetails($authInfo),
            'file' => $this->formatFileAuthDetails($authInfo),
            'email' => $emails,
        ];
    }

    // ------------------------------------------------------------------
    // Payload builders
    // ------------------------------------------------------------------

    /** Assemble the POST /ssl/v2/orders/new body from stored service state. */
    protected function buildCreatePayload()
    {
        $productKey = $this->getConfiguredProductKey();
        $rules = $this->getProductRules($productKey);
        $contacts = $this->ensureContacts();
        $dcv = $this->getRequestedVendorDcv();
        $payload = [
            'productKey' => $productKey,
            'term' => $this->getOrderTermYears(),
            'csr' => $this->requireCsr(),
            'administratorContactId' => $contacts['administrator'],
            'technicalContactId' => $contacts['technical'],
            'authType' => $dcv,
        ];

        $san = $this->getSanNames();
        if (!empty($san)) {
            $payload['sanfield'] = $san;
        }

        if (in_array((string) ($rules['certType'] ?? 'DV'), ['OV', 'EV'], true)) {
            $payload['organizationId'] = $this->ensureOrganizationId();
        }

        if ($dcv === 'email') {
            $payload['approverEmail'] = $this->buildApproverEmailPayload();
        }

        $instruction = trim((string) ($this->options['special_instruction']['value'] ?? ''));
        if ($instruction !== '') {
            $payload['specialInstruction'] = $instruction;
        }

        return $payload;
    }

    /**
     * Resolve WebNIC administrator and technical contact ids, creating them via
     * POST /ssl/v2/contact/create when not already stored.
     */
    protected function ensureContacts()
    {
        $administratorId = (string) ($this->details['administratorContactId']['value'] ?? '');
        $technicalId = (string) ($this->details['technicalContactId']['value'] ?? '');

        if ($administratorId !== '' && $technicalId !== '') {
            return [
                'administrator' => $administratorId,
                'technical' => $technicalId,
            ];
        }

        $contacts = $this->getContactsPayload();
        try {
            $response = $this->apiJson('POST', '/ssl/v2/contact/create', $contacts);
        } catch (Exception $e) {
            throw new Exception('Create contact failed: ' . $e->getMessage());
        }
        $mapped = ['administrator' => '', 'technical' => ''];

        foreach ((array) $response as $contact) {
            $type = $contact['contactType'] ?? '';
            if (isset($mapped[$type])) {
                $mapped[$type] = $contact['contactId'] ?? '';
            }
        }

        if ($mapped['administrator'] === '' || $mapped['technical'] === '') {
            throw new Exception('WebNIC contact creation did not return both administrator and technical contact ids.');
        }

        $this->details['administratorContactId']['value'] = $mapped['administrator'];
        $this->details['technicalContactId']['value'] = $mapped['technical'];

        return $mapped;
    }

    /**
     * Resolve the WebNIC organization id (required for OV/EV), creating it via
     * POST /ssl/v2/organization/create when not already stored.
     */
    protected function ensureOrganizationId()
    {
        $organizationId = (string) ($this->details['organizationId']['value'] ?? '');
        if ($organizationId !== '') {
            return $organizationId;
        }

        $contacts = $this->getContactsValue();
        $organization = $this->normalizeOrganizationContact(
            $contacts['organization'] ?? [],
            $contacts['admin'] ?? []
        );

        try {
            $response = $this->apiJson('POST', '/ssl/v2/organization/create', ['organization' => $organization]);
        } catch (Exception $e) {
            throw new Exception('Create organization failed: ' . $e->getMessage());
        }
        if (empty($response['organizationId'])) {
            throw new Exception('WebNIC organization creation did not return organizationId.');
        }

        $this->details['organizationId']['value'] = (string) $response['organizationId'];
        return (string) $response['organizationId'];
    }

    /** Build the approverEmail array (one {name,email} per domain) for ordering. */
    protected function buildApproverEmailPayload()
    {
        $selected = $this->normalizeSelectedEmails(
            $this->details[\SSL\DCV_DETAILS]['value'] ?? [],
            $this->CertDCVEmail()
        );
        $payload = [];

        foreach ($this->getOrderNames() as $name) {
            if (empty($selected[$name])) {
                throw new Exception('Missing approver email selection for ' . $name);
            }
            $payload[] = [
                'name' => $name,
                'email' => $selected[$name],
            ];
        }

        return $payload;
    }

    /** Build {administrator, technical} contact payloads from stored contact data. */
    protected function getContactsPayload()
    {
        $contacts = $this->getContactsValue();
        $organization = $contacts['organization'] ?? [];
        $administrator = $this->normalizePersonContact($contacts['admin'] ?? [], $organization);
        $technical = $this->normalizePersonContact($contacts['tech'] ?? [], $organization);

        if (empty($technical['email'])) {
            $technical = $administrator;
        }

        return [
            'administrator' => $administrator,
            'technical' => $technical,
        ];
    }

    /**
     * Map a HostBill contact (falling back to organization data) onto WebNIC's
     * person-contact schema and validate the required fields.
     */
    protected function normalizePersonContact($contact, $organization = [])
    {
        $company = $this->pickValue($contact, ['company', 'orgname', 'name'], $this->pickValue($organization, ['name', 'company'], ''));
        $firstName = $this->pickValue($contact, ['firstname', 'firstName'], '');
        $lastName = $this->pickValue($contact, ['lastname', 'lastName'], '');

        if ($firstName === '' && $lastName === '' && !empty($company)) {
            $firstName = $company;
        }

        $payload = array_filter([
            'company' => $company,
            'firstName' => $firstName,
            'lastName' => $lastName ?: $firstName,
            'address1' => $this->pickValue($contact, ['address1'], $this->pickValue($organization, ['address1'], '')),
            'address2' => $this->pickValue($contact, ['address2'], $this->pickValue($organization, ['address2'], null)),
            'city' => $this->pickValue($contact, ['city'], $this->pickValue($organization, ['city'], '')),
            'state' => $this->pickValue($contact, ['state'], $this->pickValue($organization, ['state'], '')),
            'countryCode' => $this->applyMarketCountryDefault($this->pickValue($contact, ['country', 'countryCode'], $this->pickValue($organization, ['country', 'countryCode'], ''))),
            'zip' => $this->pickValue($contact, ['postcode', 'zip'], $this->pickValue($organization, ['postcode', 'zip'], '')),
            'phoneNumber' => $this->normalizePhoneNumber($this->pickValue($contact, ['phone', 'phoneNumber'], '')),
            'faxNumber' => $this->pickValue($contact, ['fax', 'faxNumber'], null),
            'email' => strtolower($this->pickValue($contact, ['email'], '')),
        ], [$this, 'keepNullableValue']);

        $this->validateRequiredFields('Contact', $payload, ['company', 'firstName', 'lastName', 'address1', 'city', 'state', 'countryCode', 'zip', 'phoneNumber', 'email']);

        return $payload;
    }

    /**
     * Map HostBill organization data (falling back to the administrator contact)
     * onto WebNIC's organization schema and validate the required fields.
     */
    protected function normalizeOrganizationContact($organization, $administrator = [])
    {
        $contact = array_filter([
            'company' => $this->pickValue($organization, ['name', 'company'], $this->pickValue($administrator, ['company', 'orgname'], '')),
            'firstName' => $this->pickValue($organization, ['firstname', 'firstName'], $this->pickValue($administrator, ['firstname', 'firstName'], '')),
            'lastName' => $this->pickValue($organization, ['lastname', 'lastName'], $this->pickValue($administrator, ['lastname', 'lastName'], '')),
            'address1' => $this->pickValue($organization, ['address1'], $this->pickValue($administrator, ['address1'], '')),
            'address2' => $this->pickValue($organization, ['address2'], $this->pickValue($administrator, ['address2'], null)),
            'city' => $this->pickValue($organization, ['city'], $this->pickValue($administrator, ['city'], '')),
            'state' => $this->pickValue($organization, ['state'], $this->pickValue($administrator, ['state'], '')),
            'countryCode' => $this->applyMarketCountryDefault($this->pickValue($organization, ['country', 'countryCode'], $this->pickValue($administrator, ['country', 'countryCode'], ''))),
            'zip' => $this->pickValue($organization, ['postcode', 'zip'], $this->pickValue($administrator, ['postcode', 'zip'], '')),
            'phoneNumber' => $this->normalizePhoneNumber($this->pickValue($organization, ['phone', 'phoneNumber'], $this->pickValue($administrator, ['phone', 'phoneNumber'], ''))),
            'email' => strtolower($this->pickValue($organization, ['email'], $this->pickValue($administrator, ['email'], ''))),
            'jobTitle' => $this->pickValue($organization, ['jobtitle', 'jobTitle'], $this->pickValue($administrator, ['jobtitle', 'jobTitle'], '')),
        ], [$this, 'keepNullableValue']);

        $this->validateRequiredFields('Organization', $contact, ['company', 'firstName', 'lastName', 'address1', 'city', 'state', 'countryCode', 'zip', 'phoneNumber', 'email', 'jobTitle']);

        return $contact;
    }

    // ------------------------------------------------------------------
    // Vendor reads
    // ------------------------------------------------------------------

    /** GET the order's auth (DCV) info; swallow errors unless $throwOnError. */
    protected function fetchAuthInfo($orderId = null, $throwOnError = true)
    {
        try {
            return $this->apiJson('GET', '/ssl/v2/orders/' . rawurlencode($orderId ?: $this->requireOrderId()) . '/auth/info');
        } catch (Exception $e) {
            if ($throwOnError) {
                throw $e;
            }
            return [];
        }
    }

    /** GET the order's DCV status; swallow errors unless $throwOnError. */
    protected function fetchDcvStatus($orderId, $throwOnError = true)
    {
        try {
            return $this->apiJson('GET', '/ssl/v2/orders/' . rawurlencode($orderId) . '/dcv-status');
        } catch (Exception $e) {
            if ($throwOnError) {
                throw $e;
            }
            return [];
        }
    }

    /** GET /ssl/v2/orders/info for the given order id. */
    protected function getOrderInfo($orderId)
    {
        return $this->apiJson('GET', '/ssl/v2/orders/info', [], ['orderId' => $orderId]);
    }

    // ------------------------------------------------------------------
    // Auth-data persistence & formatting
    // ------------------------------------------------------------------

    /** Persist DCV/auth data from an order placement/reissue response. */
    protected function persistAuthDataFromOrderResponse($response)
    {
        $commonName = $response['commonName'] ?? [];
        $san = (array) ($response['san'] ?? []);
        $entries = [];

        if (!empty($commonName)) {
            $entries[] = $commonName;
        }
        foreach ($san as $entry) {
            $entries[] = $entry;
        }

        $authInfo = [
            'commonName' => $commonName,
            'san' => $san,
        ];

        $this->details['authInfo']['value'] = $authInfo;
        $this->persistNormalizedAuthEntries($entries);
    }

    /** Persist DCV/auth data from a GET .../auth/info response. */
    protected function persistAuthDataFromAuthInfo($authInfo)
    {
        $entries = $this->getAuthEntries($authInfo);
        $this->details['authInfo']['value'] = $authInfo;
        $this->persistNormalizedAuthEntries($entries);
    }

    /** Derive the active DCV method + per-domain details and store them. */
    protected function persistNormalizedAuthEntries($entries)
    {
        if (empty($entries)) {
            return;
        }

        $first = reset($entries);
        $method = $this->vendorDcvToHostBill($first['authType'] ?? '');
        $details = [];

        foreach ($entries as $entry) {
            $name = $entry['name'] ?? '';
            if ($name === '') {
                continue;
            }

            if (($entry['authType'] ?? '') === 'email') {
                $details[$name] = $entry['value'] ?? '';
            } else {
                $details[$name] = $entry;
            }
        }

        if ($method !== '') {
            $this->details[\SSL\DCV]['value'] = $method;
        }
        if (!empty($details)) {
            $this->details[\SSL\DCV_DETAILS]['value'] = $details;
        }
    }

    /** Shape file-based auth entries into HostBill's {url,data} per domain. */
    protected function formatFileAuthDetails($authInfo)
    {
        $details = [];

        foreach ($this->getAuthEntries($authInfo) as $entry) {
            if (($entry['authType'] ?? '') !== 'file') {
                continue;
            }

            $path = trim((string) ($entry['path'] ?? ''), '/');
            $fileName = ltrim((string) ($entry['fileName'] ?? ''), '/');
            $url = 'http://' . $path;
            if ($fileName !== '') {
                $url .= '/' . $fileName;
            }

            $details[$entry['name']] = [
                'url' => $url,
                'data' => $entry['value'] ?? '',
            ];
        }

        return $details;
    }

    /** Shape DNS auth entries into HostBill's TXT-record structure per domain. */
    protected function formatDnsAuthDetails($authInfo)
    {
        $details = [];

        foreach ($this->getAuthEntries($authInfo) as $entry) {
            if (($entry['authType'] ?? '') !== 'dns') {
                continue;
            }

            $details[$entry['name']] = [
                'domain' => $entry['name'],
                'type' => $entry['recordType'] ?? 'TXT',
                'name' => $entry['host'] ?? $entry['name'],
                'content' => $entry['value'] ?? '',
                'status' => ['NOTVALIDATED'],
            ];
        }

        return $details;
    }

    /** Flatten auth info into a list of entries (commonName first, then SAN). */
    protected function getAuthEntries($authInfo)
    {
        $entries = [];

        if (!empty($authInfo['commonName'])) {
            $entries[] = $authInfo['commonName'];
        }
        foreach ((array) ($authInfo['san'] ?? []) as $entry) {
            $entries[] = $entry;
        }

        return $entries;
    }

    /** First non-empty vendor authType present across the auth entries. */
    protected function extractVendorAuthType($authInfo)
    {
        foreach ($this->getAuthEntries($authInfo) as $entry) {
            if (!empty($entry['authType'])) {
                return $entry['authType'];
            }
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Local state accessors
    // ------------------------------------------------------------------

    /** All certificate names on the order: common name plus SAN entries. */
    protected function getOrderNames()
    {
        $names = [];
        $cn = $this->details[\SSL\CN]['value'] ?: ($this->account_details['domain'] ?? '');
        if ($cn !== '') {
            $names[] = $cn;
        }
        foreach ($this->getSanNames() as $san) {
            $names[] = $san;
        }
        return array_values(array_unique(array_filter($names)));
    }

    /** Non-empty SAN names stored for this service. */
    protected function getSanNames()
    {
        return array_values(array_filter((array) ($this->details[\SSL\SAN]['value'] ?? [])));
    }

    /** Vendor DCV method requested for this order (chosen, else configured, else DNS). */
    protected function getRequestedVendorDcv()
    {
        $dcv = $this->details[\SSL\DCV]['value'] ?? '';
        if ($dcv === '' && !empty($this->options[\SSL\OPT\DCV]['value'])) {
            $value = (array) $this->options[\SSL\OPT\DCV]['value'];
            $dcv = reset($value);
        }
        if ($dcv === '') {
            $dcv = \SSL\DCV\DNS;
        }
        return $this->hostBillDcvToVendor($dcv);
    }

    /** Order term in whole years (minimum 1). */
    protected function getOrderTermYears()
    {
        return max(1, (int) $this->periodYears);
    }

    /** Require and return the stored WebNIC order id. */
    protected function requireOrderId()
    {
        $orderId = trim((string) ($this->details[\SSL\ORDERID]['value'] ?? ''));
        if ($orderId === '') {
            throw new Exception('Missing WebNIC SSL order id.');
        }
        return $orderId;
    }

    /** Require and return the stored CSR. */
    protected function requireCsr()
    {
        $csr = trim((string) ($this->details[\SSL\CSR]['value'] ?? ''));
        if ($csr === '') {
            throw new Exception('CSR is required before placing the WebNIC SSL order.');
        }
        return $csr;
    }

    /** Require and return the configured product key for this HostBill product. */
    protected function getConfiguredProductKey()
    {
        $productKey = trim((string) ($this->options['product_key']['value'] ?? ''));
        if ($productKey === '') {
            throw new Exception('WebNIC SSL product is not configured for this HostBill product.');
        }
        return $productKey;
    }

    /** Certificate type (DV/OV/EV) of the configured product; defaults to OV. */
    protected function getProductCertType()
    {
        try {
            $rules = $this->getProductRules($this->getConfiguredProductKey());
            return (string) ($rules['certType'] ?? 'OV');
        } catch (Exception $e) {
            return 'OV';
        }
    }

    /** Certificate-authority/provider of the order's product, if resolvable. */
    protected function getCurrentProvider()
    {
        $productKey = $this->details['productKey']['value'] ?: ($this->options['product_key']['value'] ?? '');
        if ($productKey === '') {
            return '';
        }

        try {
            $meta = $this->getProductMeta($productKey);
            return $meta['provider'] ?? '';
        } catch (Exception $e) {
            return '';
        }
    }

    // ------------------------------------------------------------------
    // Product catalogue (cached)
    // ------------------------------------------------------------------

    /** Metadata row for a single product key. */
    protected function getProductMeta($productKey)
    {
        foreach ($this->getProducts() as $product) {
            if (($product['productKey'] ?? '') === $productKey) {
                return $product;
            }
        }

        throw new Exception('Unable to find WebNIC SSL product metadata for ' . $productKey);
    }

    /** Product list, cached 15 minutes per base-url + API user. */
    protected function getProducts($force = false)
    {
        $cacheKey = self::PRODUCT_CACHE_PREFIX . md5($this->getBaseUrl() . '|' . $this->getApiUsername());
        if (!$force) {
            $cached = HBCache::get($cacheKey);
            if (is_array($cached) && !empty($cached)) {
                return $this->filterProductsForMarket($cached);
            }
        }

        $products = (array) $this->apiJson('GET', '/ssl/v2/products/list', [], [], ['force_token' => $force]);
        HBCache::set($cacheKey, $products, HBC\T_MINUTE * 15);
        return $this->filterProductsForMarket($products);
    }

    /**
     * Loadable-with-price product list (the legacy "Get Product List" variant,
     * GET /ssl/v2/products/list/price). It carries a base `price` per product;
     * WebNIC marks it obsolete in favour of /products/list, so it is offered as
     * an alternate selection source rather than the default. Cached 15 minutes.
     */
    public function listProductsWithPrice()
    {
        try {
            $products = $this->getProductPriceList();
            $list = [];

            foreach ($products as $product) {
                $parts = [$product['productName']];
                if (!empty($product['provider'])) {
                    $parts[] = $product['provider'];
                }
                if (isset($product['price'])) {
                    $parts[] = $product['price'];
                }
                $list[] = [$product['productKey'], implode(' / ', $parts)];
            }

            return $list;
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /** Product list with base price, cached 15 minutes per base-url + API user. */
    protected function getProductPriceList($force = false)
    {
        $cacheKey = self::PRODUCT_CACHE_PREFIX . 'price.' . md5($this->getBaseUrl() . '|' . $this->getApiUsername());
        if (!$force) {
            $cached = HBCache::get($cacheKey);
            if (is_array($cached) && !empty($cached)) {
                return $this->filterProductsForMarket($cached);
            }
        }

        $products = (array) $this->apiJson('GET', '/ssl/v2/products/list/price', [], [], ['force_token' => $force]);
        HBCache::set($cacheKey, $products, HBC\T_MINUTE * 15);
        return $this->filterProductsForMarket($products);
    }

    /** Ordering rules for a product key, cached 30 minutes per base-url + key. */
    protected function getProductRules($productKey)
    {
        $cacheKey = self::RULE_CACHE_PREFIX . md5($this->getBaseUrl() . '|' . $productKey);
        $cached = HBCache::get($cacheKey);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $rules = (array) ($this->apiJson('GET', '/ssl/v2/products/rules', [], ['productKey' => $productKey])['rules'] ?? []);
        if (empty($rules)) {
            throw new Exception('WebNIC did not return rules for product ' . $productKey);
        }

        HBCache::set($cacheKey, $rules, HBC\T_MINUTE * 30);
        return $rules;
    }

    // ------------------------------------------------------------------
    // Additional WebNIC SSL API features (orders / products / contacts)
    // ------------------------------------------------------------------

    /**
     * Paginated order search across the reseller account.
     * POST /ssl/v2/orders/search ({filters:[{field,operator,value}], pagination}).
     * Allowed filter fields: orderId, commonName, email, company, orderStatus,
     * certStatus, product, term. Returns the raw search `data`.
     */
    public function searchOrders(array $filters = [], $page = 1, $pageSize = 20)
    {
        return $this->apiJson('POST', '/ssl/v2/orders/search', [
            'filters' => array_values($filters),
            'pagination' => [
                'page' => max(1, (int) $page),
                'pageSize' => max(1, (int) $pageSize),
            ],
        ]);
    }

    /**
     * Detailed metadata for a single SSL product.
     * GET /ssl/v2/products/info?productKey=...
     */
    public function getProductInfo($productKey)
    {
        $productKey = trim((string) $productKey);
        if ($productKey === '') {
            throw new Exception('A product key is required.');
        }
        return $this->apiJson('GET', '/ssl/v2/products/info', [], ['productKey' => $productKey]);
    }

    /**
     * Real-time price quote for a specific product configuration.
     * GET /ssl/v2/products/pricing?productKey=&term=[&commonSAN&wildcardSAN]
     */
    public function getProductPricing($productKey, $term, $commonSan = null, $wildcardSan = null)
    {
        $productKey = trim((string) $productKey);
        if ($productKey === '') {
            throw new Exception('A product key is required.');
        }
        $query = [
            'productKey' => $productKey,
            'term' => max(1, (int) $term),
        ];
        if ($commonSan !== null) {
            $query['commonSAN'] = (int) $commonSan;
        }
        if ($wildcardSan !== null) {
            $query['wildcardSAN'] = (int) $wildcardSan;
        }
        return $this->apiJson('GET', '/ssl/v2/products/pricing', [], $query);
    }

    /**
     * Retrieve a stored SSL contact.
     * GET /ssl/v2/contact/info/{contactID}
     */
    public function getSslContactInfo($contactId)
    {
        $contactId = trim((string) $contactId);
        if ($contactId === '') {
            throw new Exception('A contact id is required.');
        }
        return $this->apiJson('GET', '/ssl/v2/contact/info/' . rawurlencode($contactId));
    }

    /**
     * Delete an SSL contact.
     * DELETE /ssl/v2/contact/delete/{contactId}
     */
    public function deleteSslContact($contactId)
    {
        $contactId = trim((string) $contactId);
        if ($contactId === '') {
            throw new Exception('A contact id is required.');
        }
        return $this->apiJson('DELETE', '/ssl/v2/contact/delete/' . rawurlencode($contactId));
    }

    /**
     * Update an organization's contact details.
     * PUT /ssl/v2/organization/{organizationId}/contact/update
     * body: {organization:{...}, technicalContactId?}.
     * The organization payload is normalised through the same schema used when
     * creating the organization. $organizationId defaults to the stored one.
     */
    public function updateOrganizationContact(array $organization, $technicalContactId = null, $organizationId = null)
    {
        $organizationId = (string) ($organizationId ?: ($this->details['organizationId']['value'] ?? ''));
        if ($organizationId === '') {
            throw new Exception('No organization id is stored for this service.');
        }
        $payload = ['organization' => $this->normalizeOrganizationContact($organization)];
        if (!empty($technicalContactId)) {
            $payload['technicalContactId'] = (string) $technicalContactId;
        }
        return $this->apiJson(
            'PUT',
            '/ssl/v2/organization/' . rawurlencode($organizationId) . '/contact/update',
            $payload
        );
    }

    // ------------------------------------------------------------------
    // HTTP transport
    // ------------------------------------------------------------------

    /**
     * Perform a request and return the decoded `data` payload, enforcing the
     * WebNIC success envelope (code == 1000).
     */
    protected function apiJson($method, $path, $body = [], $query = [], $options = [])
    {
        $response = $this->request($method, $path, $body, $query, $options);
        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            throw new Exception('Unexpected response from WebNIC API at ' . $path);
        }

        if ((string) ($decoded['code'] ?? '') !== '1000') {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? 'Unknown WebNIC API error';
            $subCode = $decoded['error']['subCode'] ?? '';
            throw new Exception(trim($subCode . ' ' . $message));
        }

        return $decoded['data'] ?? [];
    }

    /** Perform a multipart request and return the raw (binary) body. */
    protected function apiBinary($method, $path, $multipart = [], $query = [], $options = [])
    {
        $options['multipart'] = true;
        $options['binary'] = true;
        $response = $this->request($method, $path, $multipart, $query, $options);
        return $response['body'];
    }

    /** Resolve a token (optionally forced) then dispatch the request with retry. */
    protected function request($method, $path, $body = [], $query = [], $options = [])
    {
        $token = $this->getAccessToken(!empty($options['force_token']));
        return $this->sendRequest($method, $path, $body, $query, $token, $options, true);
    }

    /**
     * Low-level cURL dispatch. JSON by default, multipart when requested. On a
     * 401 with $allowRetry, refreshes the token once and retries the call.
     *
     * @return array{status:int,headers:string,body:string}
     */
    protected function sendRequest($method, $path, $body, $query, $token, $options, $allowRetry)
    {
        $url = rtrim($this->getBaseUrl(), '/') . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [!empty($options['binary']) ? 'Accept: */*' : 'Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $curl = curl_init();
        $method = strtoupper($method);
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADER => true,
        ];

        if (!empty($options['multipart'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        } elseif ($method !== 'GET' && $method !== 'DELETE') {
            $headers[] = 'Content-Type: application/json';
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode((object) $body);
        }

        $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($curl, $curlOptions);

        HBDebug::debug('HB >> WebNIC SSL', ['method' => $method, 'path' => $path, 'query' => $query, 'body_keys' => array_keys((array) $body)]);

        $raw = curl_exec($curl);
        if ($raw === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new Exception('WebNIC connection error: ' . $error);
        }

        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);

        $response = [
            'status' => $statusCode,
            'headers' => substr($raw, 0, $headerSize),
            'body' => substr($raw, $headerSize),
        ];

        HBDebug::debug('HB << WebNIC SSL', ['method' => $method, 'path' => $path, 'status' => $statusCode]);

        if ($statusCode === 401 && $allowRetry) {
            $freshToken = $this->getAccessToken(true);
            return $this->sendRequest($method, $path, $body, $query, $freshToken, $options, false);
        }

        if ($statusCode >= 400) {
            $decoded = json_decode($response['body'], true);
            if (is_array($decoded)) {
                $message = $decoded['error']['message'] ?? $decoded['message'] ?? 'HTTP error ' . $statusCode;
                $subCode = $decoded['error']['subCode'] ?? '';
                throw new Exception(trim($subCode . ' ' . $message));
            }
            throw new Exception('WebNIC API HTTP error ' . $statusCode);
        }

        return $response;
    }

    /**
     * Obtain a bearer token for the current base-url/user, from HBCache or by
     * authenticating against TOKEN_PATH. Cached for its TTL minus a 60s margin.
     */
    protected function getAccessToken($force = false)
    {
        $cacheKey = self::TOKEN_CACHE_PREFIX . md5($this->getBaseUrl() . '|' . $this->getApiUsername());
        if (!$force) {
            $cached = HBCache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => rtrim($this->getBaseUrl(), '/') . self::TOKEN_PATH,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'username' => $this->getApiUsername(),
                'password' => $this->getApiPassword(),
            ]),
        ]);

        $raw = curl_exec($curl);
        if ($raw === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new Exception('WebNIC token request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new Exception('WebNIC token endpoint returned an invalid response.');
        }

        if ($statusCode >= 400 || (string) ($decoded['code'] ?? '') !== '1000') {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? 'Unable to authenticate to WebNIC';
            throw new Exception($message);
        }

        $token = (string) ($decoded['data']['access_token'] ?? '');
        if ($token === '') {
            throw new Exception('WebNIC token endpoint did not return an access token.');
        }

        $ttl = max(300, ((int) ($decoded['data']['expires_in'] ?? 3600)) - 60);
        HBCache::set($cacheKey, $token, $ttl);
        return $token;
    }

    /** Live host by default; OTE/test host when the secure checkbox is set. */
    protected function getBaseUrl()
    {
        return !empty($this->connect_data['secure']) ? self::TEST : self::LIVE;
    }

    /** Configured API username. */
    protected function getApiUsername()
    {
        return (string) ($this->connect_data['username'] ?? '');
    }

    /** Configured API password/secret. */
    protected function getApiPassword()
    {
        return (string) ($this->connect_data['password'] ?? '');
    }

    // ------------------------------------------------------------------
    // Status & DCV mapping
    // ------------------------------------------------------------------

    /** Map WebNIC order/cert status strings onto a HostBill SSL status. */
    protected function mapVendorStatus($orderStatus, $certStatus)
    {
        $orderStatus = strtoupper((string) $orderStatus);
        $certStatus = strtoupper((string) $certStatus);

        if (in_array($certStatus, ['ACTIVE', 'COMPLETED'], true) || $orderStatus === 'COMPLETED') {
            return \SSL\STATUS\ISSUED;
        }

        if ($orderStatus === 'EXPIRED' || $certStatus === 'EXPIRED') {
            return \SSL\STATUS\EXPIRED;
        }

        if (in_array($orderStatus, ['CANCELLED', 'REVOKED', 'REFUNDED', 'REJECTED'], true) || in_array($certStatus, ['REVOKED', 'CANCELLED', 'FAILED'], true)) {
            return \SSL\STATUS\CANCELLED;
        }

        if (in_array($orderStatus, ['PENDING', 'PENDING_REISSUE', 'PROCESSED', 'IN_PROCESS', 'INITIAL'], true)) {
            return \SSL\STATUS\VALIDATION;
        }

        return \SSL\STATUS\PROCESSING;
    }

    /** HostBill DCV constant -> WebNIC vendor authType. */
    protected function hostBillDcvToVendor($method)
    {
        switch ((string) $method) {
            case \SSL\DCV\EMAIL:
                return 'email';
            case \SSL\DCV\DNS:
                return 'dns';
            case \SSL\DCV\HTTP:
            case \SSL\DCV\HTTPS:
                return 'file';
            default:
                return 'dns';
        }
    }

    /** WebNIC vendor authType -> HostBill DCV constant. */
    protected function vendorDcvToHostBill($method)
    {
        switch ((string) $method) {
            case 'email':
                return \SSL\DCV\EMAIL;
            case 'dns':
                return \SSL\DCV\DNS;
            case 'file':
                return \SSL\DCV\HTTP;
            default:
                return '';
        }
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    /**
     * Coerce selected approver emails into one valid choice per domain, falling
     * back to the first available email when the selection is absent/invalid.
     */
    protected function normalizeSelectedEmails($selected, $available)
    {
        $selected = (array) $selected;
        $normalized = [];

        foreach ($available as $name => $emails) {
            $value = '';

            if (isset($selected[$name]) && in_array($selected[$name], $emails, true)) {
                $value = $selected[$name];
            } elseif (!empty($selected) && is_string(reset($selected)) && count($available) === 1) {
                $first = reset($selected);
                if (in_array($first, $emails, true)) {
                    $value = $first;
                }
            }

            if ($value === '' && !empty($emails)) {
                $value = reset($emails);
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    /** Strip a leading wildcard label so the base domain can be validated. */
    protected function normalizeValidationName($name)
    {
        return preg_replace('/^\*\./', '', (string) $name);
    }

    /** Stored certificate contacts (admin/tech/organization), tolerating key variants. */
    protected function getContactsValue()
    {
        if (isset($this->details[\SSL\CONTACTS]['value'])) {
            return (array) $this->details[\SSL\CONTACTS]['value'];
        }
        if (defined('SSL\\OPT\\CONTACTS') && isset($this->details[\SSL\OPT\CONTACTS]['value'])) {
            return (array) $this->details[\SSL\OPT\CONTACTS]['value'];
        }
        return [];
    }

    /** First non-empty value among $keys in $data, else $default. */
    protected function pickValue($data, $keys, $default = '')
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }
        return $default;
    }

    /** array_filter callback: keep every value except the empty string. */
    protected function keepNullableValue($value)
    {
        return $value !== '';
    }

    /** Upper-case, trimmed country code. */
    protected function normalizeCountryCode($value)
    {
        return strtoupper(trim((string) $value));
    }

    /** E.164-ish phone normalisation: strip spaces, ensure a leading '+'. */
    protected function normalizePhoneNumber($value)
    {
        $value = preg_replace('/\s+/', '', trim((string) $value));
        if ($value !== '' && $value[0] !== '+') {
            $value = '+' . ltrim($value, '+');
        }
        return $value;
    }

    /** Assert required contact/organization fields and validate country/email. */
    protected function validateRequiredFields($label, $payload, $required)
    {
        $missing = [];

        foreach ($required as $field) {
            if (!isset($payload[$field]) || $payload[$field] === '' || $payload[$field] === null) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new Exception($label . ' missing required fields: ' . implode(', ', $missing));
        }

        if (!empty($payload['countryCode']) && strlen($payload['countryCode']) !== 2) {
            throw new Exception($label . ' countryCode must be a 2-letter ISO code, got: ' . $payload['countryCode']);
        }

        if (!empty($payload['email']) && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception($label . ' email is invalid: ' . $payload['email']);
        }
    }

    /** Extract PEM certificates from a download body, unzipping when needed. */
    protected function extractCertificatesFromDownload($body)
    {
        $certs = $this->extractPemCertificates($body);
        if (!empty($certs)) {
            return $certs;
        }

        if (!class_exists('ZipArchive')) {
            return [];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wnssl');
        if (!$tmp) {
            return [];
        }

        file_put_contents($tmp, $body);
        $zip = new ZipArchive();
        $result = $zip->open($tmp);
        if ($result !== true) {
            @unlink($tmp);
            return [];
        }

        $certs = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            foreach ($this->extractPemCertificates($content) as $certificate) {
                $certs[] = $certificate;
            }
        }

        $zip->close();
        @unlink($tmp);

        return array_values(array_unique($certs));
    }

    /** Pull every normalised PEM CERTIFICATE block out of a text blob. */
    protected function extractPemCertificates($content)
    {
        if (!is_string($content) || $content === '') {
            return [];
        }

        preg_match_all('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $content, $matches);
        if (empty($matches[1])) {
            return [];
        }

        return array_map(function ($certificate) {
            return "-----BEGIN CERTIFICATE-----\n" . trim($certificate) . "\n-----END CERTIFICATE-----";
        }, $matches[1]);
    }

    // ------------------------------------------------------------------
    // Ownership check (used by the REST api routes)
    // ------------------------------------------------------------------

    /**
     * True when the given HostBill account/service belongs to the given client.
     * The REST routes call this to enforce ownership — auth alone only proves a
     * client is calling, not that this client owns the service id in the URL.
     */
    public function serviceBelongsToClient($accountId, $clientId)
    {
        if (!$accountId || !$clientId) {
            return false;
        }

        $stmt = $this->db->prepare('SELECT client_id FROM hb_accounts WHERE id = :id');
        $stmt->execute([':id' => (int) $accountId]);
        $owner = (int) $stmt->fetchColumn();
        $stmt->closeCursor();

        return $owner === (int) $clientId;
    }
}

?>
