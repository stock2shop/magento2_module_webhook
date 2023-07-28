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
use Zend_Http_Client as Z;
// 2018-08-11 Dmitry Fedyuk https://www.upwork.com/fl/mage2pro
final class OrderSaveAfter implements ObserverInterface {
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
					$error_occurred = false;
					try {
						$payload = Payload::get($o);
						$res = self::post($payload, $o->getStore());
					} /** @var string $res */
					catch (\Exception $e) {
						$res = $e->getMessage();
						$error_occurred = true;
					}
					$comment = [
						"The Stock2Shop's webhook is notified."
						,"The order's status: «<b>{$status}</b>»."
						,"The order's state: «<b>{$state}</b>»."
						,sprintf("The webhook's response: «<b>%s</b>».", mb_substr($res, 0, 25000))
					];
					if ($error_occurred) {
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

	/**
	 * 2018-08-15
	 * @param array(string => mixed) $p
	 * @param Store $s
	 * @return string
	 */
	private static function post(array $payload, Store $s) {
		$om = OM::getInstance(); /** @var OM $om */
		$cfg = $om->get(Config::class); /** @var Config $cfg */
		$url = $cfg->getValue('stock2shop/order_export/url', SS::SCOPE_STORE, $s); /** @var string $url */

		$json = json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new \Exception("JSON encoding failed: " . json_last_error_msg());
		}

		// Set up cURL
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Content-Length: ' . strlen($json)
		]);

		// Execute the cURL request
		$result = curl_exec($ch);

		// Check for errors
		if (curl_errno($ch)) {
			throw new \Exception("Curl request failed: " . curl_error($ch));
		}
		curl_close($ch);

		return $result;
	}
}
