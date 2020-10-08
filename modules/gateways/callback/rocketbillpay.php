<?php

use Carbon\Carbon;
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
     * @var string
     */
    protected $baseUrl;

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
    public function __construct()
    {
        $this->setGateway();
        $this->setRequest();
        $this->setInvoice();

        $this->baseUrl = $this->isSandbox ? 'http://103.11.136.153/BillPayGWTest/BillInfoService' : 'http://103.11.136.153/BillPayGW/BillInfoService';
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
     * Set request
     */
    private function setRequest()
    {
        $this->request = Request::createFromGlobals();
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
     * Set currency.
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
     * Set fee.
     */
    private function setFee()
    {
        $this->fee = empty($this->gatewayParams['fee']) ? 0 : (($this->gatewayParams['fee'] / 100) * $this->due);
    }

    /**
     * Set total.
     */
    private function setTotal()
    {
        $this->total = ceil(($this->due + $this->fee) * $this->convoRate);
    }

    /**
     * Check if transaction is exists.
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
            'amount'    => $this->due,
            'fees'      => $this->fee,
        ];
        $add    = localAPI('AddInvoicePayment', $fields);

        return array_merge($add, $fields);
    }

    /**
     * Verify transaction.
     *
     * @return array
     */
    public function verifyPayment()
    {
        $txnid           = $this->request->get('txnid');
        $fields          = $this->credential;
        $fields['txnid'] = $txnid;
        $context         = [
            'http' => [
                'method'  => 'GET',
                'header'  => "Authorization: Basic " . base64_encode($this->gatewayParams['authUser'] . ":" . $this->gatewayParams['authPassword']),
                'timeout' => 30,
            ],
        ];
        $context         = stream_context_create($context);
        $query           = http_build_query($fields);
        $url             = $this->baseUrl . '?' . $query;
        $response        = file_get_contents($url, false, $context);
        $data            = explode('|', $response);

        if (!is_array($data)) {
            return [
                'status'  => 'error',
                'message' => 'Invalid response from Rocket Bill Pay API.',
            ];
        }

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
            'message' => isset($data[1]) ? $data[1] : 'Unknown error',
        ];
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
die(json_encode($rocketBillPay->makeTransaction()));
