<?php

namespace Mews\Pos;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Mews\Pos\Exceptions\UnsupportedPaymentModelException;
use Mews\Pos\Exceptions\UnsupportedTransactionTypeException;
use SimpleXMLElement;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class EstPos
 * @package Mews\Pos
 */
class EstPos implements PosInterface
{
    use PosHelpersTrait;

    /**
     * API URL
     *
     * @var string
     */
    public $url;

    /**
     * 3D Pay Gateway URL
     *
     * @var string
     */
    public $gateway;

    /**
     * Response Codes
     *
     * @var array
     */
    public $codes = [
        '00'    => 'approved',
        '01'    => 'bank_call',
        '02'    => 'bank_call',
        '05'    => 'reject',
        '09'    => 'try_again',
        '12'    => 'invalid_transaction',
        '28'    => 'reject',
        '51'    => 'insufficient_balance',
        '54'    => 'expired_card',
        '57'    => 'does_not_allow_card_holder',
        '62'    => 'restricted_card',
        '77'    => 'request_rejected',
        '99'    => 'general_error',
    ];

    /**
     * Transaction Types
     *
     * @var array
     */
    public $types = [
        'pay'   => 'Auth',
        'pre'   => 'PreAuth',
        'post'  => 'PostAuth',
    ];

    /**
     * Transaction Type
     *
     * @var string
     */
    public $type;

    /**
     * API Account
     *
     * @var array
     */
    protected $account = [];

    /**
     * Order Details
     *
     * @var array
     */
    protected $order = [];

    /**
     * Credit Card
     *
     * @var object
     */
    protected $card;

    /**
     * Request
     *
     * @var Request
     */
    protected $request;

    /**
     * Response Raw Data
     *
     * @var object
     */
    protected $data;

    /**
     * Processed Response Data
     *
     * @var mixed
     */
    public $response;

    /**
     * Configuration
     *
     * @var array
     */
    protected $config = [];

    /**
     * EstPos constructor.
     *
     * @param array $config
     * @param array $account
     * @return $this
     */
    public function __construct($config, $account)
    {
        $this->config = $config;
        $this->account = $account;

        $this->url = isset($this->config['urls'][$this->account->env]) ?
            $this->config['urls'][$this->account->env] :
            $this->config['urls']['production'];

        $this->gateway = isset($this->config['urls']['gateway'][$this->account->env]) ?
            $this->config['urls']['gateway'][$this->account->env] :
            $this->config['urls']['gateway']['production'];

        return $this;
    }

    /**
     * Create Regular Payment XML
     *
     * @return string
     */
    protected function createRegularPaymentXML()
    {
        $nodes = [
            'Name'      => $this->account->username,
            'Password'  => $this->account->password,
            'ClientId'  => $this->account->client_id,
            'Type'      => $this->type,
            'IPAddress' => $this->order->ip,
            'Email'     => $this->order->email,
            'OrderId'   => $this->order->id,
            'UserId'    => isset($this->order->user_id) ? $this->order->user_id : null,
            'Total'     => $this->order->amount,
            'Currency'  => $this->order->currency,
            'Taksit'    => $this->order->installment,
            'CardType'  => isset($this->card->type) ? $this->card->type : null,
            'Number'    => $this->card->number,
            'Expires'   => $this->card->month . '/' . $this->card->year,
            'Cvv2Val'   => $this->card->cvv,
            'Mode'      => 'P',
            'GroupId'   => '',
            'TransId'   => '',
        ];

        if ($this->order->name) {
            $nodes['BillTo'] = [
                'Name'   => $this->order->name,
            ];
        }

        return $this->createXML($nodes);
    }

    /**
     * Create Regular Payment Post XML
     *
     * @return string
     */
    protected function createRegularPostXML()
    {
        $nodes = [
            'Name'      => $this->account->username,
            'Password'  => $this->account->password,
            'ClientId'  => $this->account->client_id,
            'Type'      => $this->types[$this->order->transaction],
            'OrderId'   => $this->order->id,
        ];

        return $this->createXML($nodes);
    }

    /**
     * Create 3D Payment XML
     * @return string
     */
    protected function create3DPaymentXML()
    {
        $nodes = [
            'Name'                      => $this->account->username,
            'Password'                  => $this->account->password,
            'ClientId'                  => $this->account->client_id,
            'Type'                      => $this->type,
            'IPAddress'                 => $this->order->ip,
            'Email'                     => $this->order->email,
            'OrderId'                   => $this->order->id,
            'UserId'                    => isset($this->order->user_id) ? $this->order->user_id : null,
            'Total'                     => $this->order->amount,
            'Currency'                  => $this->order->currency,
            'Taksit'                    => $this->order->installment,
            'Number'                    => $this->request->get('md'),
            'Expires'                   => '',
            'Cvv2Val'                   => '',
            'PayerTxnId'                => $this->request->get('xid'),
            'PayerSecurityLevel'        => $this->request->get('eci'),
            'PayerAuthenticationCode'   => $this->request->get('cavv'),
            'CardholderPresentCode'     => '13',
            'Mode'                      => 'P',
            'GroupId'                   => '',
            'TransId'                   => '',
        ];

        if ($this->order->name) {
            $nodes['BillTo'] = [
                'Name'   => $this->order->name,
            ];
        }

        return $this->createXML($nodes);
    }

    /**
     * Get ProcReturnCode
     *
     * @return string|null
     */
    protected function getProcReturnCode()
    {
        return isset($this->data->ProcReturnCode) ? (string) $this->data->ProcReturnCode : null;
    }

    /**
     * Get Status Detail Text
     *
     * @return string|null
     */
    protected function getStatusDetail()
    {
        $proc_return_code =  $this->getProcReturnCode();

        return $proc_return_code ? (isset($this->codes[$proc_return_code]) ? (string) $this->codes[$proc_return_code] : null) : null;
    }

    /**
     * Create 3D Hash
     *
     * @return string
     */
    public function create3DHash()
    {
        $hash_str = '';

        if ($this->account->model == '3d') {
            $hash_str = $this->account->client_id . $this->order->id . $this->order->amount . $this->order->ok_url . $this->order->fail_url . $this->order->rand . $this->account->store_key;
        } elseif ($this->account->model == '3d_pay') {
            $hash_str = $this->account->client_id . $this->order->id . $this->order->amount . $this->order->ok_url . $this->order->fail_url . $this->order->transaction_type . $this->order->installment . $this->order->rand . $this->account->store_key;
        }

        return base64_encode(pack('H*', sha1($hash_str)));
    }

    /**
     * Check 3D Hash
     *
     * @return bool
     */
    public function check3DHash()
    {
        $hash_params = $this->request->get('HASHPARAMS');
        $hash_params_val = $this->request->get('HASHPARAMSVAL');
        $hash_param = $this->request->get('HASH');
        $params_val = '';
        $index1 = 0;

        while ($index1 < strlen($hash_params)) {
            $index2 = strpos($hash_params, ':', $index1);
            $value = $this->request->get(substr($hash_params, $index1, $index2 - $index1));

            if($value == null) $value = '';

            $params_val = $params_val . $value;

            $index1 = $index2 + 1;
        }

        $hash_val = $params_val . $this->account->store_key;
        $hash = base64_encode(pack('H*', sha1($hash_val)));

        $return = false;
        if ($hash_params && !($params_val != $hash_params_val || $hash_param != $hash)) {
            $return = true;
        }

        return $return;
    }

    /**
     * Regular Payment
     *
     * @return $this
     * @throws GuzzleException
     */
    public function makeRegularPayment()
    {
        $contents = '';
        if (in_array($this->order->transaction, ['pay', 'pre'])) {
            $contents = $this->createRegularPaymentXML();
        } elseif ($this->order->transaction == 'post') {
            $contents = $this->createRegularPostXML();
        }

        $this->send($contents);

        $status = 'declined';
        if ($this->getProcReturnCode() == '00') {
            $status = 'approved';
        }

        $this->response = (object) [
            'id'                => isset($this->data->AuthCode) ? $this->printData($this->data->AuthCode) : null,
            'order_id'          => isset($this->data->OrderId) ? $this->printData($this->data->OrderId) : null,
            'group_id'          => isset($this->data->GroupId) ? $this->printData($this->data->GroupId) : null,
            'trans_id'          => isset($this->data->TransId) ? $this->printData($this->data->TransId) : null,
            'response'          => isset($this->data->Response) ? $this->printData($this->data->Response) : null,
            'transaction_type'  => $this->type,
            'transaction'       => $this->order->transaction,
            'auth_code'         => isset($this->data->AuthCode) ? $this->printData($this->data->AuthCode) : null,
            'host_ref_num'      => isset($this->data->HostRefNum) ? $this->printData($this->data->HostRefNum) : null,
            'proc_return_code'  => isset($this->data->ProcReturnCode) ? $this->printData($this->data->ProcReturnCode) : null,
            'code'              => isset($this->data->ProcReturnCode) ? $this->printData($this->data->ProcReturnCode) : null,
            'status'            => $status,
            'status_detail'     => $this->getStatusDetail(),
            'error_code'        => isset($this->data->Extra->ERRORCODE) ? $this->printData($this->data->Extra->ERRORCODE) : null,
            'error_message'     => isset($this->data->Extra->ERRORCODE) ? $this->printData($this->data->ErrMsg) : null,
            'extra'             => isset($this->data->Extra) ? $this->data->Extra : null,
            'all'               => $this->data,
            'original'          => $this->data,
        ];

        return $this;
    }

    /**
     * Make 3D Payment
     *
     * @return $this
     * @throws GuzzleException
     */
    public function make3DPayment()
    {
        $this->request = Request::createFromGlobals();

        $status = 'declined';
        if ($this->check3DHash()) {
            $contents = $this->create3DPaymentXML();
            $this->send($contents);
        }

        $transaction_security = 'MPI fallback';
        if ($this->getProcReturnCode() == '00') {
            if ($this->request->get('mdStatus') == '1') {
                $transaction_security = 'Full 3D Secure';
            } elseif (in_array($this->request->get('mdStatus'), [2, 3, 4])) {
                $transaction_security = 'Half 3D Secure';
            }

            $status = 'approved';
        }

        $this->response = (object) [
            'id'                    => isset($this->data->AuthCode) ? $this->printData($this->data->AuthCode) : null,
            'order_id'              => isset($this->data->OrderId) ? $this->printData($this->data->OrderId) : null,
            'group_id'              => isset($this->data->GroupId) ? $this->printData($this->data->GroupId) : null,
            'trans_id'              => isset($this->data->TransId) ? $this->printData($this->data->TransId) : null,
            'response'              => isset($this->data->Response) ? $this->printData($this->data->Response) : null,
            'transaction_type'      => $this->type,
            'transaction'           => $this->order->transaction,
            'transaction_security'  => $transaction_security,
            'auth_code'             => isset($this->data->AuthCode) ? $this->printData($this->data->AuthCode) : null,
            'host_ref_num'          => isset($this->data->HostRefNum) ? $this->printData($this->data->HostRefNum) : null,
            'proc_return_code'      => isset($this->data->ProcReturnCode) ? $this->printData($this->data->ProcReturnCode) : null,
            'code'                  => isset($this->data->ProcReturnCode) ? $this->printData($this->data->ProcReturnCode) : null,
            'status'                => $status,
            'status_detail'         => $this->getStatusDetail(),
            'error_code'            => isset($this->data->Extra->ERRORCODE) ? $this->printData($this->data->Extra->ERRORCODE) : null,
            'error_message'         => isset($this->data->Extra->ERRORCODE) ? $this->printData($this->data->ErrMsg) : null,
            'md_status'             => $this->request->get('mdStatus'),
            'hash'                  => (string) $this->request->get('HASH'),
            'rand'                  => (string) $this->request->get('rnd'),
            'hash_params'           => (string) $this->request->get('HASHPARAMS'),
            'hash_params_val'       => (string) $this->request->get('HASHPARAMSVAL'),
            'masked_number'         => (string) $this->request->get('maskedCreditCard'),
            'month'                 => (string) $this->request->get('Ecom_Payment_Card_ExpDate_Month'),
            'year'                  => (string) $this->request->get('Ecom_Payment_Card_ExpDate_Year'),
            'amount'                => (string) $this->request->get('amount'),
            'currency'              => (string) $this->request->get('currency'),
            'tx_status'             => (string) $this->request->get('txstatus'),
            'eci'                   => (string) $this->request->get('eci'),
            'cavv'                  => (string) $this->request->get('cavv'),
            'xid'                   => (string) $this->request->get('xid'),
            'md_error_message'      => (string) $this->request->get('mdErrorMsg'),
            'name'                  => (string) $this->request->get('firmaadi'),
            'email'                 => (string) $this->request->get('Email'),
            'extra'                 => isset($this->data->Extra) ? $this->data->Extra : null,
            'all'                   => $this->data,
            '3d_all'                => $this->request->request->all(),
        ];

        return $this;
    }

    /**
     * Make 3D Pay Payment
     *
     * @return $this
     */
    public function make3DPayPayment()
    {
        $this->request = Request::createFromGlobals();

        $status = 'declined';

        if ($this->check3DHash() && (string) $this->request->get('ProcReturnCode') == '00') {
            if (in_array($this->request->get('mdStatus'), [1, 2, 3, 4])) {
                $status = 'approved';
            }
        }

        $transaction_security = 'MPI fallback';
        if ($status == 'approved') {
            if ($this->request->get('mdStatus') == '1') {
                $transaction_security = 'Full 3D Secure';
            } elseif (in_array($this->request->get('mdStatus'), [2, 3, 4])) {
                $transaction_security = 'Half 3D Secure';
            }
        }

        $this->response = (object) [
            'id'                    => (string) $this->request->get('oid'),
            'trans_id'              => (string) $this->request->get('TransId'),
            'auth_code'             => (string) $this->request->get('AuthCode'),
            'host_ref_num'          => (string) $this->request->get('HostRefNum'),
            'response'              => (string) $this->request->get('Response'),
            'transaction_type'      => $this->type,
            'transaction'           => $this->order->transaction,
            'transaction_security'  => $transaction_security,
            'code'                  => (string) $this->request->get('ProcReturnCode'),
            'md_status'             => $this->request->get('mdStatus'),
            'status'                => $status,
            'status_detail'         => isset($this->codes[$this->request->get('ProcReturnCode')]) ? (string) $this->request->get('ProcReturnCode') : null,
            'hash'                  => (string) $this->request->get('HASH'),
            'rand'                  => (string) $this->request->get('rnd'),
            'hash_params'           => (string) $this->request->get('HASHPARAMS'),
            'hash_params_val'       => (string) $this->request->get('HASHPARAMSVAL'),
            'masked_number'         => (string) $this->request->get('maskedCreditCard'),
            'month'                 => (string) $this->request->get('Ecom_Payment_Card_ExpDate_Month'),
            'year'                  => (string) $this->request->get('Ecom_Payment_Card_ExpDate_Year'),
            'amount'                => (string) $this->request->get('amount'),
            'currency'              => (string) $this->request->get('currency'),
            'tx_status'             => (string) $this->request->get('txstatus'),
            'eci'                   => (string) $this->request->get('eci'),
            'cavv'                  => (string) $this->request->get('cavv'),
            'xid'                   => (string) $this->request->get('xid'),
            'error_code'            => (string) $this->request->get('ErrCode'),
            'error_message'         => (string) $this->request->get('ErrMsg'),
            'md_error_message'      => (string) $this->request->get('mdErrorMsg'),
            'name'                  => (string) $this->request->get('firmaadi'),
            'email'                 => (string) $this->request->get('Email'),
            'extra'                 => $this->request->get('Extra'),
            'all'                   => $this->request->request->all(),
        ];

        return $this;
    }

    /**
     * Send contents to WebService
     *
     * @param $contents
     * @return $this
     * @throws GuzzleException
     */
    public function send($contents)
    {
        $client = new Client();

        $response = $client->request('POST', $this->url, [
            'body'  => $contents
        ]);

        $xml = new SimpleXMLElement($response->getBody());

        $this->data = (object) json_decode(json_encode($xml));

        return $this;
    }

    /**
     * Prepare Order
     *
     * @param object $order
     * @return mixed
     * @throws UnsupportedTransactionTypeException
     */
    public function prepare($order)
    {
        $this->type = $this->types['pay'];
        if (isset($order->transaction)) {
            if (array_key_exists($order->transaction, $this->types)) {
                $this->type = $this->types[$order->transaction];
            } else {
                throw new UnsupportedTransactionTypeException('Unsupported transaction type!');
            }
        }

        $this->order = $order;
    }

    /**
     * Make Payment
     *
     * @param object $card
     * @return mixed
     * @throws UnsupportedPaymentModelException
     * @throws GuzzleException
     */
    public function payment($card)
    {
        $this->card = $card;

        $model = 'regular';
        if (isset($this->account->model) && $this->account->model) {
            $model = $this->account->model;
        }

        if ($model == 'regular') {
            $this->makeRegularPayment();
        } elseif ($model == '3d') {
            $this->make3DPayment();
        } elseif ($model == '3d_pay') {
            $this->make3DPayPayment();
        } else {
            throw new UnsupportedPaymentModelException();
        }

        return $this;
    }

    /**
     * Refund Order
     *
     * @param $order_id
     * @param null $amount
     * @return $this
     * @throws GuzzleException
     */
    public function refund($order_id, $amount = null)
    {
        $nodes = [
            'Name'      => $this->account->username,
            'Password'  => $this->account->password,
            'ClientId'  => $this->account->client_id,
            'OrderId'   => $order_id,
            'Type'      => 'Credit',
        ];

        if ($amount) $nodes['Total'] = $amount;

        $xml = $this->createXML($nodes);
        $this->send($xml);

        $status = 'declined';
        if ($this->getProcReturnCode() == '00') {
            $status = 'approved';
        }

        $this->response = (object) [
            'order_id'          => isset($this->data->OrderId) ? $this->data->OrderId : null,
            'group_id'          => isset($this->data->GroupId) ? $this->data->GroupId : null,
            'response'          => isset($this->data->Response) ? $this->data->Response : null,
            'auth_code'         => isset($this->data->AuthCode) ? $this->data->AuthCode : null,
            'host_ref_num'      => isset($this->data->HostRefNum) ? $this->data->HostRefNum : null,
            'proc_return_code'  => isset($this->data->ProcReturnCode) ? $this->data->ProcReturnCode : null,
            'trans_id'          => isset($this->data->TransId) ? $this->data->TransId : null,
            'error_code'        => isset($this->data->Extra->ERRORCODE) ? $this->data->Extra->ERRORCODE : null,
            'error_message'     => isset($this->data->ErrMsg) ? $this->data->ErrMsg : null,
            'status'            => $status,
            'status_detail'     => $this->getStatusDetail(),
            'all'               => $this->data,
        ];

        return $this;
    }

    /**
     * Cancel Order
     *
     * @param $order_id
     * @return $this
     * @throws GuzzleException
     */
    public function cancel($order_id)
    {
        $xml = $this->createXML([
            'Name'      => $this->account->username,
            'Password'  => $this->account->password,
            'ClientId'  => $this->account->client_id,
            'OrderId'   => $order_id,
            'Type'      => 'Void',
        ]);

        $this->send($xml);

        $status = 'declined';
        if ($this->getProcReturnCode() == '00') {
            $status = 'approved';
        }

        $this->response = (object) [
            'order_id'          => isset($this->data->OrderId) ? $this->data->OrderId : null,
            'group_id'          => isset($this->data->GroupId) ? $this->data->GroupId : null,
            'response'          => isset($this->data->Response) ? $this->data->Response : null,
            'auth_code'         => isset($this->data->AuthCode) ? $this->data->AuthCode : null,
            'host_ref_num'      => isset($this->data->HostRefNum) ? $this->data->HostRefNum : null,
            'proc_return_code'  => isset($this->data->ProcReturnCode) ? $this->data->ProcReturnCode : null,
            'trans_id'          => isset($this->data->TransId) ? $this->data->TransId : null,
            'error_code'        => isset($this->data->Extra->ERRORCODE) ? $this->data->Extra->ERRORCODE : null,
            'error_message'     => isset($this->data->ErrMsg) ? $this->data->ErrMsg : null,
            'status'            => $status,
            'status_detail'     => $this->getStatusDetail(),
            'all'               => $this->data,
        ];

        return $this;
    }

    /**
     * Order Status
     *
     * @param $order_id
     * @return $this
     * @throws GuzzleException
     */
    public function status($order_id)
    {
        $xml = $this->createXML([
            'Name'      => $this->account->username,
            'Password'  => $this->account->password,
            'ClientId'  => $this->account->client_id,
            'OrderId'   => $order_id,
            'Extra'     => [
                'ORDERSTATUS'   => 'QUERY',
            ],
        ]);

        $this->send($xml);

        $status = 'declined';
        if ($this->getProcReturnCode() == '00') {
            $status = 'approved';
        }

        $this->response = (object) [
            'order_id'          => isset($this->data->OrderId) ? $this->data->OrderId : null,
            'response'          => isset($this->data->Response) ? $this->data->Response : null,
            'proc_return_code'  => isset($this->data->ProcReturnCode) ? $this->data->ProcReturnCode : null,
            'trans_id'          => isset($this->data->TransId) ? $this->data->TransId : null,
            'error_message'     => isset($this->data->ErrMsg) ? $this->data->ErrMsg : null,
            'host_ref_num'      => isset($this->data->Extra->HOST_REF_NUM) ? $this->data->Extra->HOST_REF_NUM : null,
            'order_status'      => isset($this->data->Extra->ORDERSTATUS) ? $this->data->Extra->ORDERSTATUS : null,
            'process_type'      => isset($this->data->Extra->CHARGE_TYPE_CD) ? $this->data->Extra->CHARGE_TYPE_CD : null,
            'pan'               => isset($this->data->Extra->PAN) ? $this->data->Extra->PAN : null,
            'num_code'          => isset($this->data->Extra->NUMCODE) ? $this->data->Extra->NUMCODE : null,
            'first_amount'      => isset($this->data->Extra->ORIG_TRANS_AMT) ? $this->data->Extra->ORIG_TRANS_AMT : null,
            'capture_amount'    => isset($this->data->Extra->CAPTURE_AMT) ? $this->data->Extra->CAPTURE_AMT : null,
            'status'            => $status,
            'status_detail'     => $this->getStatusDetail(),
            'all'               => $this->data,
            'xml'               => $xml,
        ];

        return $this;
    }

    /**
     * Order History
     *
     * @param $order_id
     * @return $this
     * @throws GuzzleException
     */
    public function history($order_id)
    {
        $xml = $this->createXML([
            'Name'      => $this->account->username,
            'Password'  => $this->account->password,
            'ClientId'  => $this->account->client_id,
            'OrderId'   => $order_id,
            'Extra'     => [
                'ORDERHISTORY'   => 'QUERY',
            ],
        ]);

        $this->send($xml);

        $status = 'declined';
        if ($this->getProcReturnCode() == '00') {
            $status = 'approved';
        }

        $this->response = (object) [
            'order_id'          => isset($this->data->OrderId) ? $this->printData($this->data->OrderId) : null,
            'response'          => isset($this->data->Response) ? $this->printData($this->data->Response) : null,
            'proc_return_code'  => isset($this->data->ProcReturnCode) ? $this->printData($this->data->ProcReturnCode) : null,
            'error_message'     => isset($this->data->ErrMsg) ? $this->printData($this->data->ErrMsg) : null,
            'num_code'          => isset($this->data->Extra->NUMCODE) ? $this->printData($this->data->Extra->NUMCODE) : null,
            'trans_count'       => isset($this->data->Extra->TRXCOUNT) ? $this->printData($this->data->Extra->TRXCOUNT) : null,
            'status'            => $status,
            'status_detail'     => $this->getStatusDetail(),
            'all'               => $this->data,
            'xml'               => $xml,
        ];

        return $this;
    }
}
