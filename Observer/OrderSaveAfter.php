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
				if ($cfg->getValue('sales/stock2shop/enable', SS::SCOPE_STORE, $o->getStore())) {
					/** @var string $state */ /** @var string $status */
					list($state, $status) = [$o->getState(), $o->getStatus()];
					try {$res = self::post(Payload::get($o), $o->getStore());} /** @var string $res */
					catch (\Exception $e) {$res = $e->getMessage();}
					$h = $o->addStatusHistoryComment(__(
						implode('<br>', [
							"The Stock2Shop's webhook is notified."
							,"The order's status: «<b>{$status}</b>»."
							,"The order's state: «<b>{$state}</b>»."
							,sprintf("The webhook's response: «<b>%s</b>».", mb_substr($res, 0, 25000))
						])
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
	private static function post(array $p, Store $s) {
		$om = OM::getInstance(); /** @var OM $om */
		$cfg = $om->get(Config::class); /** @var Config $cfg */
		$url = $cfg->getValue('sales/stock2shop/url', SS::SCOPE_STORE, $s); /** @var string $url */
		$z = new Z($url, [
			'timeout' => 120
			/**
			 * 2017-07-16
			 * By default it is «Zend_Http_Client»: @see C::$config
			 * https://github.com/magento/zf1/blob/1.13.1/library/Zend/Http/Client.php#L126
			 */
			,'useragent' => 'Mage2.PRO'
		]); /** @var Z $r */
		if (0 === strpos(strtolower($url), 'https') || false !== strpos($url, 'localhost')) {
			/**
			 * 2017-07-16
			 * @see \Zend_Http_Client_Adapter_Socket is the default adapter for Zend_Http_Client:
			 * @see C::$config https://github.com/magento/zf1/blob/1.13.1/library/Zend/Http/Client.php#L126
			 * But the adapter can be changed in $config, so we create another adapter.
			 */
			$r->setAdapter((new \Zend_Http_Client_Adapter_Socket)->setStreamContext([
				'ssl' => ['allow_self_signed' => true, 'verify_peer' => false]
			]));
		}
		$z->setMethod(Z::POST);
		$z->setRawData(json_encode($p, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
		return $z->request()->getBody();
	}
}