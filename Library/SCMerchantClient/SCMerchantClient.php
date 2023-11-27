<?php
namespace Spectrocoin\Merchant\Library\SCMerchantClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Spectrocoin\Merchant\Library\SCMerchantClient\Message\CreateOrderRequest;
use Spectrocoin\Merchant\Library\SCMerchantClient\Message\CreateOrderResponse;
use Spectrocoin\Merchant\Library\SCMerchantClient\Data\ApiError;
use Spectrocoin\Merchant\Library\SCMerchantClient\Data\OrderCallback;

class SCMerchantClient {

    private $merchantApiUrl;
    private $privateMerchantCertLocation;
    private $publicSpectroCoinCertLocation;

    private $userId;
    private $merchantApiId;
    private $debug;

    private $privateMerchantKey;

    protected $client;


    /**
     * @param $merchantApiUrl
     * @param $userId
     * @param $merchantApiId
     * @param bool $debug
     */
    function __construct($merchantApiUrl, $userId, $merchantApiId, $debug = true)
    {
        $this->privateMerchantCertLocation = dirname(__FILE__) . '/../cert/mprivate.pem';
        $this->publicSpectroCoinCertLocation = 'https://spectrocoin.com/files/merchant.public.pem';
        $this->merchantApiUrl = $merchantApiUrl;
        $this->userId = $userId;
        $this->merchantApiId = $merchantApiId;
        $this->debug = $debug;
        $this->client = new Client();

    }

    /**
     * @param $privateKey
     */
    public function setPrivateMerchantKey($privateKey) {
        $this->privateMerchantKey = $privateKey;
    }
    
    /**
     * @param CreateOrderRequest $request
     * @return ApiError|CreateOrderResponse
     */
    public function createOrder(CreateOrderRequest $request)
    {
        $payload = array(
            'userId' => $this->userId,
			'merchantApiId' => $this->merchantApiId,
			'orderId' => $request->getOrderId(),
			'payCurrency' => $request->getPayCurrency(),
			'payAmount' => $request->getPayAmount(),
			'receiveCurrency' => $request->getReceiveCurrency(),
			'receiveAmount' => $request->getReceiveAmount(),
			'description' => $request->getDescription(),
			'culture' => $request->getCulture(),
			'callbackUrl' => $request->getCallbackUrl(),
			'successUrl' => $request->getSuccessUrl(),
			'failureUrl' => $request->getFailureUrl(),
        );

        $data = http_build_query($payload);
        $signature = $this->generateSignature($data);
        $payload['sign'] = $signature;

        try {
            $response = $this->client->post($this->merchantApiUrl . '/createOrder', [
                'form_params' => $payload,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            ]);
            $body = json_decode($response->getBody()->getContents());
            if (is_array($body) && count($body) > 0 && isset($body[0]->code)) {
                return new ApiError($body[0]->code, $body[0]->message);
            } else {
                return new CreateOrderResponse(
                    $body->orderRequestId,
                    $body->orderId,
                    $body->depositAddress,
                    $body->payAmount,
                    $body->payCurrency,
                    $body->receiveAmount,
                    $body->receiveCurrency,
                    $body->validUntil,
                    $body->redirectUrl
                );
            }

        } catch (RequestException | GuzzleException $e) {
            $errorBody = json_decode($e->getResponse()->getBody());
            if ($errorBody !== null && is_array($errorBody) && count($errorBody) > 0 && isset($errorBody[0]->code)) {
                $code = $errorBody[0]->code;
                $message = $errorBody[0]->message;
				error_log("SPECTROCOIN HTTP Error: " . $e->getMessage() . " Code: " . $code . " Message: " . $message . "\n", 0);
				return new ApiError($code, $message);
			} else {
				error_log("SPECTROCOIN HTTP Error: " . $e->getMessage() . "\n", 0);
            }
        }
    }



    private function generateSignature($data)
	{
		$privateKey = $this->privateMerchantKey != null ? $this->privateMerchantKey : file_get_contents($this->privateMerchantCertLocation);
		$pkeyid = openssl_pkey_get_private($privateKey);

		$s = openssl_sign($data, $signature, $pkeyid, OPENSSL_ALGO_SHA1);
		$encodedSignature = base64_encode($signature);

		return $encodedSignature;
	}
    

	/**
	 * @param $r $_REQUEST
	 * @return OrderCallback|null
	 */
	public function parseCreateOrderCallback($r)
	{
		$result = null;

		if ($r != null && isset($r['userId'], $r['merchantApiId'], $r['merchantId'], $r['apiId'], $r['orderId'], $r['payCurrency'], $r['payAmount'], $r['receiveCurrency'], $r['receiveAmount'], $r['receivedAmount'], $r['description'], $r['orderRequestId'], $r['status'], $r['sign'])) {
			$result = new OrderCallback($r['userId'], $r['merchantApiId'], $r['merchantId'], $r['apiId'], $r['orderId'], $r['payCurrency'], $r['payAmount'], $r['receiveCurrency'], $r['receiveAmount'], $r['receivedAmount'], $r['description'], $r['orderRequestId'], $r['status'], $r['sign']);
		}

		return $result;
	}

	/**
	 * @param OrderCallback $c
	 * @return bool
	 */
	public function validateCreateOrderCallback(OrderCallback $c)
	{
		$valid = false;

		if ($c != null) {

			if ($this->userId != $c->getUserId() || $this->merchantApiId != $c->getMerchantApiId())
				return $valid;

			if (!$c->validate())
				return $valid;

			$payload = array(
				'merchantId' => $c->getMerchantId(),
				'apiId' => $c->getApiId(),
				'orderId' => $c->getOrderId(),
				'payCurrency' => $c->getPayCurrency(),
				'payAmount' => $c->getPayAmount(),
				'receiveCurrency' => $c->getReceiveCurrency(),
				'receiveAmount' => $c->getReceiveAmount(),
				'receivedAmount' => $c->getReceivedAmount(),
				'description' => $c->getDescription(),
				'orderRequestId' => $c->getOrderRequestId(),
				'status' => $c->getStatus(),
			);

			$data = http_build_query($payload);
			$valid = $this->validateSignature($data, $c->getSign()) == 1;
		}

		return $valid;
	}

	/**
	 * @param $data
	 * @param $signature
	 * @return int
	 */
	private function validateSignature($data, $signature)
	{
		$sig = base64_decode($signature);
		$publicKey = file_get_contents($this->publicSpectroCoinCertLocation);
		$public_key_pem = openssl_pkey_get_public($publicKey);
		$r = openssl_verify($data, $sig, $public_key_pem, OPENSSL_ALGO_SHA1);

		return $r;
	}

}
