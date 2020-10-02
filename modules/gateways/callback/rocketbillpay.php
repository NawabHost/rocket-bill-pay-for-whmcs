<?php

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\Request;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

class RocketBillPay
{
    /**
     * @var self
     */
    private static $instance;

    /**
     * @var string
     */
    protected $gatewayModuleName;

    /**
     * @var array
     */
    protected $gatewayParams;

    /**
     * @var boolean
     */
    public $isSandbox;

    /**
     * @var boolean
     */
    public $isActive;

    /**
     * @var integer
     */
    protected $customerCurrency;

    /**
     * @var object
     */
    protected $gatewayCurrency;

    /**
     * @var integer
     */
    protected $clientCurrency;

    /**
     * @var float
     */
    protected $convoRate;

    /**
     * @var array
     */
    protected $invoice;

    /**
     * @var float
     */
    protected $due;

    /**
     * @var float
     */
    protected $fee;

    /**
     * @var float
     */
    public $total;

    /**
     * @var \GuzzleHttp\Client
     */
    protected $httpClient;

    /**
     * @var \Symfony\Component\HttpFoundation\Request
     */
    public $request;

    /**
     * @var array
     */
    private $credential;

    /**
     * RocketBillPay constructor.
     */
    function __construct()
    {
        $this->setGateway();
        $this->setHttpClient();
        $this->setRequest();
        $this->setInvoice();
    }

    /**
     * The instance.
     *
     * @return self
     */
    public static function init()
    {
        if (self::$instance == null) {
            self::$instance = new RocketBillPay;
        }

        return self::$instance;
    }

    /**
     * Set the payment gateway.
     */
    private function setGateway()
    {
        $this->gatewayModuleName = basename(__FILE__, '.php');
        $this->gatewayParams     = getGatewayVariables($this->gatewayModuleName);
        $this->isActive          = !empty($this->gatewayParams['type']);
        $this->isSandbox         = !empty($this->gatewayParams['sandbox']);

        $this->credential = [
            'shortcode' => $this->gatewayParams['shortcode'],
            'userid'    => $this->gatewayParams['userid'],
            'password'  => $this->gatewayParams['password'],
            'opcode'    => $this->gatewayParams['opcode'],
        ];
    }

    /**
     * Get and set request
     */
    private function setRequest()
    {
        $this->request = Request::createFromGlobals();
    }

    /**
     * Set guzzle as HTTP client.
     */
    private function setHttpClient()
    {
        $baseUri = 'http://103.11.136.153/BillPayGW/';
        if ($this->isSandbox) {
            $baseUri = 'http://103.11.136.153/BillPayGWTest/';
        }
        $this->httpClient = new Client(
            [
                'base_uri'    => $baseUri,
                'http_errors' => false,
                'timeout'     => 30,
                'auth'        => [
                    $this->gatewayParams['authUser'],
                    $this->gatewayParams['authPassword'],
                ],
                'headers'     => [
                    'Accept' => 'application/json',
                ],
            ]
        );
    }

    /**
     * Set the invoice.
     */
    private function setInvoice()
    {
        $this->invoice = localAPI('GetInvoice', [
            'invoiceid' => $this->request->get('id'),
        ]);

        $this->setCurrency();
        $this->setDue();
        $this->setFee();
        $this->setTotal();
    }

    /**
     * Set currency
     */
    private function setCurrency()
    {
        $this->gatewayCurrency  = (int)$this->gatewayParams['convertto'];
        $this->customerCurrency = Capsule::table('tblclients')
                                         ->where('id', '=', $this->invoice['userid'])
                                         ->value('currency');

        if (!empty($this->gatewayCurrency) && ($this->customerCurrency !== $this->gatewayCurrency)) {
            $this->convoRate = Capsule::table('tblcurrencies')
                                      ->where('id', '=', $this->gatewayCurrency)
                                      ->value('rate');
        } else {
            $this->convoRate = 1;
        }
    }

    /**
     * Set due.
     */
    private function setDue()
    {
        $this->due = $this->invoice['balance'];
    }

    /**
     * Set Fee.
     */
    private function setFee()
    {
        $this->fee = empty($this->gatewayParams['fee']) ? 0 : (($this->gatewayParams['fee'] / 100) * $this->due);
    }

    /**
     * Set Total.
     */
    private function setTotal()
    {
        $this->total = ceil(($this->due + $this->fee) * $this->convoRate);
    }

    /**
     * Check if transaction if exists.
     *
     * @param string $txnid
     *
     * @return mixed
     */
    private function checkTransaction($txnid)
    {
        return localAPI(
            'GetTransactions',
            ['transid' => $txnid]
        );
    }

    /**
     * Log the transaction.
     *
     * @param array $payload
     *
     * @return mixed
     */
    private function logTransaction($payload)
    {
        return logTransaction(
            $this->gatewayParams['name'],
            [
                $this->gatewayModuleName => $payload,
                'post'                   => $this->request->request->all(),
            ],
            $payload['status']
        );
    }

    /**
     * Add transaction to the invoice.
     *
     * @param string $txnid
     *
     * @return array
     */
    private function addTransaction($txnid)
    {
        $fields = [
            'invoiceid' => $this->invoice['invoiceid'],
            'transid'   => $txnid,
            'gateway'   => $this->gatewayModuleName,
            'date'      => Carbon::now()->toDateTimeString(),
        ];
        $add    = localAPI('AddInvoicePayment', $fields);

        return array_merge($add, $fields);
    }

    /**
     * Get error message by code.
     *
     * @param string $code
     *
     * @return string
     */
    private function getErrorMessage($code)
    {
        $errors = [
            '01' => 'Invalid Basic Authentication',
            '02' => 'Invalid Host Authentication',
            '03' => 'Invalid Authentication',
            '04' => 'Invalid Operation Code',
            '05' => 'Biller Short Code Missing',
            '06' => 'User Id Missing',
            '07' => 'Password Missing',
            '08' => 'Operation Code Missing',
            '09' => 'Bill Ref No Missing',
            '10' => 'Bill Amount Missing',
            '11' => 'Invalid Bill Amount',
            '13' => 'Txn Id Missing',
        ];

        return isset($errors[$code]) ? $errors[$code] : 'Invalid error code';
    }

    /**
     * Verify Transaction.
     *
     * @return array
     */
    public function verifyPayment()
    {
        try {
            $txnid           = $this->request->get('txnid');
            $fields          = $this->credential;
            $fields['txnid'] = $txnid;
            $response        = $this->httpClient->post('BillInfoService', [
                'query' => $fields,
            ]);

            $data = explode('|', $response->getBody()->getContents());

            if (is_array($data)) {
                if ($data[0] === $this->credential['shortcode']) {
                    return [
                        'status'    => 'success',
                        'message'   => 'Transaction ID has been verified.',
                        'txnid'     => $txnid,
                        'shortcode' => $data[0],
                        'reference' => $data[1],
                        'amount'    => $data[2],
                        'datetime'  => $data[3],
                        'account'   => $data[4],
                    ];
                }

                return [
                    'status'  => 'error',
                    'code'    => $data[0],
                    'message' => $data[1],
                ];
            }

            return [
                'status'  => 'error',
                'message' => 'Invalid response from Rocket Bill Pay API.',
            ];
        } catch (GuzzleException $exception) {
            return [
                'status'  => 'error',
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Make the transaction.
     *
     * @return array
     */
    public function makeTransaction()
    {
        $verifyData = $this->verifyPayment();

        if (is_array($verifyData)) {
            if ($verifyData['success'] == 'success') {
                $existing = $this->checkTransaction($verifyData['txnid']); // TODO: Pre-check before call API.

                if ($existing['totalresults'] > 0) {
                    return [
                        'status'  => 'error',
                        'message' => 'The transaction has been already used.',
                    ];
                }

                if ($verifyData['amount'] < $this->total) {
                    return [
                        'status'  => 'error',
                        'message' => 'You\'ve paid less than amount is required.',
                    ];
                }

                $this->logTransaction($verifyData); // TODO: Log full response.

                $txnAddResult = $this->addTransaction($verifyData['txnid']);

                if ($txnAddResult['result'] === 'success') {
                    return [
                        'status'  => 'success',
                        'message' => 'The payment has been successfully verified.',
                    ];
                }

                return [
                    'status'  => 'error',
                    'message' => 'Unable to create transaction.',
                ];
            }

            return [
                'status'  => 'error',
                'message' => $verifyData['message'],
            ];
        }

        return [
            'status'  => 'error',
            'message' => 'Payment validation error.',
        ];
    }
}

if (!$_SERVER['REQUEST_METHOD'] === 'POST') {
    die("Direct access forbidden.");
}

$rocketBillPay = RocketBillPay::init();

if (!$rocketBillPay->isActive) {
    die("The gateway is unavailable.");
}

header('Content-Type: application/json');

echo json_encode($rocketBillPay->makeTransaction());