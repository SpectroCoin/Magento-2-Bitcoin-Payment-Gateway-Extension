<?php

namespace Spectrocoin\Merchant\Library\SCMerchantClient\Data;

class OrderStatusEnum {
	public static $New = 1;
	public static $Pending = 2;
	public static $Paid = 3;
	public static $Failed = 4;
	public static $Expired = 5;
	public static $Test = 6;
	public static $TestPaid = 15;
	public static $TestExpired = 16;
	public static $LateCryptoPayment = 10;
	public static $PartialPayment = 11;
	public static $Underpaid = 12;
	public static $Cancelled = 13;
	public static $InvalidPayment = 14;
	public static $ProcessingRefund = 17;
	public static $Refunded = 18;
	public static $RejectedRefund = 19;
	public static $PendingLateCryptoPayment = 20;
	public static $Rejected = 21;

	/**
	 * Statuses that end the order without a completed payment. They share the
	 * merchant's configured "failed" status rather than adding new settings.
	 *
	 * @param int $status
	 * @return bool
	 */
	public static function isCancellation($status) {
		return in_array((int) $status, [
			self::$Failed,
			self::$Cancelled,
			self::$Rejected,
			self::$InvalidPayment,
		], true);
	}

	/**
	 * Statuses that report on a payment already under way and carry no
	 * shop-side transition. The merchant is told; the order is left alone.
	 * Moving the order automatically here would either complete one that was
	 * not paid in full or reverse one the merchant may already have settled.
	 *
	 * @param int $status
	 * @return bool
	 */
	public static function isInformational($status) {
		return in_array((int) $status, [
			self::$PartialPayment,
			self::$Underpaid,
			self::$LateCryptoPayment,
			self::$PendingLateCryptoPayment,
			self::$ProcessingRefund,
			self::$Refunded,
			self::$RejectedRefund,
		], true);
	}
}
