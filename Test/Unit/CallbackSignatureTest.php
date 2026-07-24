<?php
namespace Spectrocoin\Merchant\Test\Unit;

use PHPUnit\Framework\TestCase;
use Spectrocoin\Merchant\Library\SCMerchantClient\SCMerchantClient;
use Spectrocoin\Merchant\Library\SCMerchantClient\Data\OrderCallback;

/**
 * Regression test for the unauthenticated payment-bypass fix.
 *
 * Reproduces the reported vulnerability: an attacker POSTs a "paid" callback
 * (status=3) for their own order with a bogus signature. Before the fix the
 * signature was never checked and such a callback was accepted. This test
 * asserts SCMerchantClient::validateCreateOrderCallback() rejects a forged
 * signature and accepts only a callback correctly signed with SpectroCoin's
 * private key.
 */
class CallbackSignatureTest extends TestCase
{
    /** @var string PEM of a throwaway private key standing in for SpectroCoin's signer */
    private $privateKeyPem;

    /** @var string temp file holding the matching public certificate */
    private $publicKeyFile;

    /** @var string */
    private $userId = 'test-user';

    /** @var string */
    private $merchantApiId = 'test-api';

    protected function setUp(): void
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($res, 'Could not generate a test RSA key');

        openssl_pkey_export($res, $this->privateKeyPem);
        $details = openssl_pkey_get_details($res);

        $this->publicKeyFile = tempnam(sys_get_temp_dir(), 'sc_pub_') . '.pem';
        file_put_contents($this->publicKeyFile, $details['key']);
    }

    protected function tearDown(): void
    {
        if ($this->publicKeyFile && file_exists($this->publicKeyFile)) {
            unlink($this->publicKeyFile);
        }
    }

    private function makeClient(): SCMerchantClient
    {
        $client = new SCMerchantClient('https://spectrocoin.com/api/merchant/1', $this->userId, $this->merchantApiId, false);
        // Verify against our locally generated public key instead of the live URL.
        $client->setPublicSpectroCoinCertLocation($this->publicKeyFile);
        return $client;
    }

    /**
     * Build the callback field set. When $sign is null the payload is signed
     * with the test private key exactly as SpectroCoin's server would, using
     * the same field order/formatting that validateCreateOrderCallback expects.
     */
    private function makeCallback($sign = null): OrderCallback
    {
        $values = [
            'userId'         => $this->userId,
            'merchantApiId'  => $this->merchantApiId,
            'merchantId'     => 10,
            'apiId'          => 20,
            'orderId'        => '100000001',
            'payCurrency'    => 'EUR',
            'payAmount'      => 25.00,
            'receiveCurrency'=> 'EUR',
            'receiveAmount'  => 25.00,
            'receivedAmount' => 25.00,
            'description'    => '',
            'orderRequestId' => 999,
            'status'         => 3, // Paid
        ];

        $build = function ($signValue) use ($values) {
            return new OrderCallback(
                $values['userId'], $values['merchantApiId'], $values['merchantId'],
                $values['apiId'], $values['orderId'], $values['payCurrency'],
                $values['payAmount'], $values['receiveCurrency'], $values['receiveAmount'],
                $values['receivedAmount'], $values['description'], $values['orderRequestId'],
                $values['status'], $signValue
            );
        };

        if ($sign !== null) {
            return $build($sign);
        }

        // Reproduce the verifier's signed payload from the getter values.
        $c = $build('placeholder');
        $payload = [
            'merchantId'     => $c->getMerchantId(),
            'apiId'          => $c->getApiId(),
            'orderId'        => $c->getOrderId(),
            'payCurrency'    => $c->getPayCurrency(),
            'payAmount'      => $c->getPayAmount(),
            'receiveCurrency'=> $c->getReceiveCurrency(),
            'receiveAmount'  => $c->getReceiveAmount(),
            'receivedAmount' => $c->getReceivedAmount(),
            'description'    => $c->getDescription(),
            'orderRequestId' => $c->getOrderRequestId(),
            'status'         => $c->getStatus(),
        ];
        $data = http_build_query($payload);
        $pkeyid = openssl_pkey_get_private($this->privateKeyPem);
        openssl_sign($data, $signature, $pkeyid, OPENSSL_ALGO_SHA1);

        return $build(base64_encode($signature));
    }

    public function testForgedSignatureIsRejected(): void
    {
        $client = $this->makeClient();
        $forged = $this->makeCallback(base64_encode('this-is-not-a-valid-signature'));

        $this->assertFalse(
            $client->validateCreateOrderCallback($forged),
            'A callback with a forged signature must be rejected'
        );
    }

    public function testEmptySignatureIsRejected(): void
    {
        $client = $this->makeClient();
        $empty = $this->makeCallback('');

        $this->assertFalse(
            $client->validateCreateOrderCallback($empty),
            'A callback with an empty signature must be rejected'
        );
    }

    public function testTamperedStatusIsRejected(): void
    {
        // Sign a legitimate payload, then flip the status to Paid (3). The
        // signature no longer covers the mutated field, so it must fail.
        $client = $this->makeClient();
        $signed = $this->makeCallback();

        $tampered = new OrderCallback(
            $this->userId, $this->merchantApiId, 10, 20, '100000001', 'EUR', 25.00,
            'EUR', 25.00, 0.00, '', 999, 3, $signed->getSign()
        );

        // receivedAmount was changed from 25.00 to 0.00, so the signature is invalid.
        $this->assertFalse(
            $client->validateCreateOrderCallback($tampered),
            'A callback whose signed fields were tampered with must be rejected'
        );
    }

    public function testWrongMerchantIsRejected(): void
    {
        // Correctly signed, but the client belongs to a different merchant.
        $client = new SCMerchantClient('https://spectrocoin.com/api/merchant/1', 'other-user', 'other-api', false);
        $client->setPublicSpectroCoinCertLocation($this->publicKeyFile);

        $this->assertFalse(
            $client->validateCreateOrderCallback($this->makeCallback()),
            'A callback for a different merchant must be rejected'
        );
    }

    public function testValidSignatureIsAccepted(): void
    {
        $client = $this->makeClient();

        $this->assertTrue(
            $client->validateCreateOrderCallback($this->makeCallback()),
            'A correctly signed callback must be accepted'
        );
    }
}
