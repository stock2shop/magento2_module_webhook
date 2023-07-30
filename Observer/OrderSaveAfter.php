<?php
namespace Stock2Shop\OrderExport\Observer;
use Magento\Framework\App\Config;
use Magento\Framework\App\ObjectManager as OM;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface as IHistory;
use Magento\Sales\Model\Order as O;
use Magento\Sales\Model\Order\Status\History;
use Magento\Store\Model\ScopeInterface as SS;
use Magento\Store\Model\Store;
use Stock2Shop\OrderExport\Payload;
// 2018-08-11 Dmitry Fedyuk https://www.upwork.com/fl/mage2pro
final class OrderSaveAfter implements ObserverInterface {

	private static $encoding_error_msg = null;
	private static $curl_error_msg = null;

	/**
	 * 2018-08-11
	 * @override
	 * @see ObserverInterface::execute()
	 * @param Observer $ob
	 */
	function execute(Observer $ob) {
		static $in; /** @var bool $in */
		if (!$in) {
			$in = true;
			try {
				$o = $ob['order']; /** @var O $o */
				$om = OM::getInstance(); /** @var OM $om */
				$cfg = $om->get(Config::class); /** @var Config $cfg */
				if ($cfg->getValue('stock2shop/order_export/enable', SS::SCOPE_STORE, $o->getStore())) {
					/** @var string $state */ /** @var string $status */
					list($state, $status) = [$o->getState(), $o->getStatus()];
					$payload = Payload::get($o);
					$encoded_str = self::encode($payload);
					$order_id = $o->getIncrementId();
					$payload_str = !empty(self::$encoding_error_msg)
						? '{"error": "Magento webhook failed to encode order, please look at order ' . $order_id . ' on website."}'
						: $encoded_str;
					$res = self::post($payload_str, $o->getStore());

					// Set errors as webhook response, if any
					$errors = [];
					if (!empty(self::$encoding_error_msg)) {
						$errors[] = 'JSON encoding error: ' . self::$encoding_error_msg;
					}
					if (!empty(self::$curl_error_msg)) {
						$errors[] = 'Curl error: ' . self::$curl_error_msg;
					}
					$res = !empty($errors) ? implode(', ', $errors) : $res;

					$comment = [
						"The Stock2Shop's webhook is notified."
						,"The order's status: «<b>{$status}</b>»."
						,"The order's state: «<b>{$state}</b>»."
						,sprintf("The webhook's response: «<b>%s</b>».", mb_substr($res, 0, 25000))
					];
					if (!empty($errors)) {
						$comment[] = sprintf("The serialized payload: %s", htmlspecialchars(serialize($payload)));
					}
					$h = $o->addStatusHistoryComment(__(
						implode('<br>', $comment)
					)); /** @var History|IHistory $h */
					$h->setIsVisibleOnFront(false);
					$h->setIsCustomerNotified(false);
					$h->save();
				}
			}
			finally {$in = false;}
		}
	}

	private static function encode(array $payload)
	{
		$response = json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		if (json_last_error() !== JSON_ERROR_NONE) {
			self::$encoding_error_msg = json_last_error_msg();
		}
		return $response;
	}

	/**
	 * 2018-08-15
	 * @param string $payload
	 * @param Store $s
	 * @return string
	 */
	private static function post(string $payload, Store $s) {
		$om = OM::getInstance(); /** @var OM $om */
		$cfg = $om->get(Config::class); /** @var Config $cfg */
		$url = $cfg->getValue('stock2shop/order_export/url', SS::SCOPE_STORE, $s); /** @var string $url */

		// Set up cURL
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Content-Length: ' . strlen($payload)
		]);

		// Execute the cURL request
		$result = curl_exec($ch);

		// Check for errors
		if (curl_errno($ch)) {
			self::$curl_error_msg = curl_error($ch);
		}

		curl_close($ch);

		return $result;
	}
}
