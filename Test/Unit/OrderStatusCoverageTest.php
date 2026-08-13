<?php

/**
 * Invariant tests for order-status coverage.
 *
 * The API reports more than a payment simply succeeding or failing: partial and
 * late payments, and the refund lifecycle. Every status it can send must be
 * understood here, otherwise the order is moved to an unrelated state or the
 * merchant is never told what happened to the payment.
 *
 * Statuses are classified three ways:
 *   - a completed or terminal outcome, which moves the order;
 *   - a cancellation, which ends the order without payment and reuses the
 *     configured "failed" status;
 *   - informational, which is acknowledged and leaves the order untouched.
 *
 * Standalone by design: this extension ships no PHPUnit setup, and a Magento
 * bootstrap is not needed to check a status table.
 *
 * Run:  php Test/Unit/OrderStatusCoverageTest.php
 */

require_once __DIR__ . '/../../Library/SCMerchantClient/Data/OrderStatusEnum.php';

use Spectrocoin\Merchant\Library\SCMerchantClient\Data\OrderStatusEnum;

/** Every status the API can put on the wire, by its numeric code. */
const WIRE_STATUSES = [
    'NEW' => 1, 'PENDING' => 2, 'PAID' => 3, 'FAILED' => 4, 'EXPIRED' => 5,
    'LATE_CRYPTO_PAYMENT' => 10, 'PARTIAL_PAYMENT' => 11, 'UNDERPAID' => 12,
    'CANCELLED' => 13, 'INVALID_PAYMENT' => 14, 'PROCESSING_REFUND' => 17,
    'REFUNDED' => 18, 'REJECTED_REFUND' => 19,
    'PENDING_LATE_CRYPTO_PAYMENT' => 20, 'REJECTED' => 21,
];

const CANCELLATIONS = ['FAILED', 'CANCELLED', 'REJECTED', 'INVALID_PAYMENT'];
const INFORMATIONAL = ['PARTIAL_PAYMENT', 'UNDERPAID', 'LATE_CRYPTO_PAYMENT',
                       'PENDING_LATE_CRYPTO_PAYMENT', 'PROCESSING_REFUND',
                       'REFUNDED', 'REJECTED_REFUND'];

class TestRunner
{
    private $failures = [];
    private $passed = 0;
    private $failed = 0;

    public function assertTrue($cond, $message)
    {
        if (!$cond) { $this->failures[] = $message; }
    }

    public function assertSame($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            $this->failures[] = $message . ' (expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true) . ')';
        }
    }

    public function run($name, callable $test)
    {
        $this->failures = [];
        try { $test($this); }
        catch (\Throwable $e) { $this->failures[] = 'threw ' . get_class($e) . ': ' . $e->getMessage(); }
        if (empty($this->failures)) { $this->passed++; echo "  PASS  {$name}\n"; }
        else {
            $this->failed++;
            echo "  FAIL  {$name}\n";
            foreach ($this->failures as $f) { echo "          {$f}\n"; }
        }
    }

    public function summary()
    {
        echo "\n{$this->passed} passed, {$this->failed} failed\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

$paymentSource = file_get_contents(__DIR__ . '/../../Model/Payment.php');

$t = new TestRunner();
echo "SpectroCoin Magento 2 — order-status coverage\n\n";

$t->run('every status the API can send has a code', function ($t) {
    foreach (WIRE_STATUSES as $name => $code) {
        $found = false;
        foreach (get_class_vars(OrderStatusEnum::class) as $value) {
            if ((int) $value === $code) { $found = true; break; }
        }
        $t->assertTrue($found, "OrderStatusEnum must define a constant for {$name} ({$code})");
    }
});

$t->run('cancellations are classified exactly', function ($t) {
    foreach (WIRE_STATUSES as $name => $code) {
        $t->assertSame(in_array($name, CANCELLATIONS, true),
            OrderStatusEnum::isCancellation($code),
            "{$name}: isCancellation() classification");
    }
});

$t->run('informational statuses are classified exactly', function ($t) {
    foreach (WIRE_STATUSES as $name => $code) {
        $t->assertSame(in_array($name, INFORMATIONAL, true),
            OrderStatusEnum::isInformational($code),
            "{$name}: isInformational() classification");
    }
});

$t->run('no status is both a cancellation and informational', function ($t) {
    foreach (WIRE_STATUSES as $name => $code) {
        $t->assertTrue(
            !(OrderStatusEnum::isCancellation($code) && OrderStatusEnum::isInformational($code)),
            "{$name} must not be classified both ways");
    }
});

$t->run('the status mapping consults both classifications', function ($t) use ($paymentSource) {
    $t->assertTrue(strpos($paymentSource, 'isCancellation(') !== false,
        'getOrderStatus() must route cancellations to the configured failed status');
    $t->assertTrue(strpos($paymentSource, 'isInformational(') !== false,
        'getOrderStatus() must leave informational statuses alone');
});

$t->run('an unrecognised status no longer borrows the test-order status', function ($t) use ($paymentSource) {
    $tail = substr($paymentSource, strpos($paymentSource, 'protected function getOrderStatus'));
    $tail = substr($tail, 0, strpos($tail, 'return $statusOption;'));
    $default = substr($tail, strrpos($tail, 'default:'));
    $t->assertTrue(strpos($default, 'order_status_test') === false,
        'the default branch must not reuse the test-order status for a live order');
    $t->assertTrue(strpos($default, 'null') !== false,
        'the default branch must decline to transition the order');
});

$t->run('no transition is applied when the mapping declines', function ($t) use ($paymentSource) {
    $body = substr($paymentSource, strpos($paymentSource, 'public function updateOrderStatus'));
    $body = substr($body, 0, strpos($body, 'catch'));
    $t->assertTrue(strpos($body, '=== null') !== false,
        'updateOrderStatus() must return early when there is no transition to apply');
    $t->assertTrue(strpos($body, 'return true;') < strpos($body, '->setState('),
        'the early return must come before the order is written');
});

exit($t->summary());
