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
        $this->client = new Client([
            'headers' => [
                'User-Agent' => self::pluginUserAgent(),
            ],
        ]);

    }

    /**
     * @param $privateKey
     */
    public function setPrivateMerchantKey($privateKey) {
        $this->privateMerchantKey = $privateKey;
    }

    /**
     * Override the location (URL or local file path) of the SpectroCoin public
     * certificate used to verify callback signatures. Defaults to the remote
     * URL; pinning a locally shipped copy avoids an unauthenticated network
     * fetch on every callback and makes signature verification testable.
     *
     * @param string $location
     */
    public function setPublicSpectroCoinCertLocation($location) {
        $this->publicSpectroCoinCertLocation = $location;
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
		if ($publicKey === false) {
			return -1;
		}
		$public_key_pem = openssl_pkey_get_public($publicKey);
		if ($public_key_pem === false) {
			return -1;
		}
		// NOTE (secondary weakness): SpectroCoin's legacy merchant API signs
		// callbacks with RSA-SHA1, so the verifier must use SHA1 to match the
		// signer. SHA1 is deprecated; the newer SpectroCoin plugins instead
		// fetch the authoritative order status from the API with merchant
		// credentials rather than trusting a caller-supplied, SHA1-signed
		// payload. Migrating to that scheme is the recommended follow-up.
		$r = openssl_verify($data, $sig, $public_key_pem, OPENSSL_ALGO_SHA1);

		return $r;
	}


    /** Platform this build of the client ships with. */
    private const PLUGIN_PLATFORM = 'Magento2';

    /** Bump with the release: this is what identifies the build server-side. */
    private const PLUGIN_VERSION = '1.0.5';

    /**
     * Identifies the plugin and its version on every API call, so the version
     * actually deployed across merchant installations is visible to us without
     * having to ask anyone.
     *
     * Carries no merchant or site identity: the request is already
     * authenticated, so the caller is known, and the shop URL is not ours to
     * volunteer.
     *
     * @return string
     */
    private static function pluginUserAgent()
    {
        return sprintf(
            'SpectroCoin-%s/%s (PHP/%s)',
            self::PLUGIN_PLATFORM,
            self::PLUGIN_VERSION,
            PHP_VERSION
        );
    }
}
