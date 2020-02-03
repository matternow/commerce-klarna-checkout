<?php


namespace ellera\commerce\klarna\gateways;

use Craft;
use ellera\commerce\klarna\klarna\order\Capture;
use ellera\commerce\klarna\klarna\order\Create;
use ellera\commerce\klarna\klarna\order\Update;
use ellera\commerce\klarna\models\Order;
use craft\commerce\elements\Order as CraftOrder;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use ellera\commerce\klarna\models\forms\CheckoutForm;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;

/**
 * Class Checkout
 *
 * Checkout gateway for Klarna
 * https://developers.klarna.com/documentation/klarna-checkout/
 *
 * @package ellera\commerce\klarna\gateways
 */
class Checkout extends Base
{
    // Public Variables
    // =========================================================================

    /**
     * Gateway handle
     *
     * @var string
     */
    public $gateway_handle = 'klarna-checkout';

    /**
     * Setting: Title
     *
     * @var string
     */
    public $title = 'Klarna Checkout';

    /**
     * Setting: Order Complete Page
     *
     * @var string
     */
    public $push = 'shop/customer/order';

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'Klarna Checkout');
    }

    /**
     * @param \craft\commerce\elements\Order $order
     * @throws InvalidConfigException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\ErrorException
     */
    public function updateOrder(CraftOrder $order)
    {
        $response = new Update($this, $order);

        if($response->isSuccessful()) $this->log('Updated order '.$order->number.' ('.$order->id.')');

        if($response->getData()->shipping_address) {
            $order->setShippingAddress($this->createAddressFromResponse($response->getData()->shipping_address));
            if($response->getData()->shipping_address->email) $order->setEmail($response->getData()->shipping_address->email);
        }
        if($response->getData()->billing_address) {
            $order->setBillingAddress($this->createAddressFromResponse($response->getData()->billing_address));
            if($response->getData()->billing_address->email) $order->setEmail($response->getData()->billing_address->email);
        }
    }

    /**
     * @param Transaction $transaction
     * @param BasePaymentForm $form
     * @return RequestResponseInterface
     * @throws BadRequestHttpException
     * @throws InvalidConfigException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \yii\base\ErrorException
     */
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        // Check if the received form is of the right type
        if(!$form instanceof CheckoutForm)
            throw new BadRequestHttpException('Klarna Checkout only accepts CheckoutForm');

        // Populate the form
        $form->populate($transaction, $this);

        $response = $form->createOrder();

        if($response->isSuccessful()) $this->log('Authorized order '.$transaction->order->number.' ('.$transaction->order->id.')');

        return $response;
    }

    /**
     * @param Transaction $transaction
     * @param string $reference
     * @return RequestResponseInterface
     * @throws InvalidConfigException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\ErrorException
     */
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        $response = new Capture($this, $transaction);

        $response->setTransactionReference($reference);

        if($response->isSuccessful()) $this->log('Captured order '.$transaction->order->number.' ('.$transaction->order->id.')');

        else $this->log('Failed to capture order '.$transaction->order->id.'. Klarna responded with '.$response->getCode().': '.$response->getMessage());

        return $response;
    }

    /**
     * @param Transaction $transaction
     * @param BasePaymentForm $form
     * @return RequestResponseInterface
     * @throws BadRequestHttpException
     * @throws InvalidConfigException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \craft\commerce\errors\TransactionException
     * @throws \yii\base\ErrorException
     */
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        $response = $this->captureKlarnaOrder($transaction);

        if($response->isSuccessful()) $this->log('Purchased order '.$transaction->order->number.' ('.$transaction->order->id.')');

        $transaction->order->updateOrderPaidInformation();
        return $response;
    }

    /**
     * @param Transaction $transaction
     * @return Capture
     * @throws BadRequestHttpException
     * @throws InvalidConfigException
     * @throws \craft\commerce\errors\TransactionException
     * @throws \yii\base\ErrorException
     */
    protected function captureKlarnaOrder(Transaction $transaction) : Capture
    {
        $plugin = \craft\commerce\Plugin::getInstance();

        $response = new Capture($this, $transaction);

        $transaction->status = $response->isSuccessful() ? 'success' : 'failed';
        $transaction->code = $response->getCode();
        $transaction->message = $response->getMessage();
        $transaction->note = 'Automatic capture';
        $transaction->response = $response->getDecodedResponse();

        if(!$plugin->getTransactions()->saveTransaction($transaction)) throw new BadRequestHttpException('Could not save capture transaction');

        if($response->isSuccessful()) $this->log('Captured order '.$transaction->order->number.' ('.$transaction->order->id.')');

        return $response;
    }

    /**
     * @param array $params
     *
     * @return null|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    public function getPaymentFormHtml(array $params)
    {
        $order = $this->createCheckoutOrder();
        return $order->getHtmlSnippet();
    }

    /**
     * @return BasePaymentForm
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new CheckoutForm();
    }

    /**
     * @return Order
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    private function createCheckoutOrder() : Order
    {
        $commerce = craft\commerce\Plugin::getInstance();
        $cart = $commerce->getCarts()->getCart();

        $transaction = $commerce->getTransactions()->createTransaction($cart, null, 'authorize');

        $form = new CheckoutForm();
        $form->populate($transaction, $this);

        /** @var $response Create */
        $response = $this->authorize($transaction, $form);
        $transaction->reference = $response->getDecodedResponse()->order_id;
        $transaction->code = $response->getCode();
        $transaction->message = $response->getMessage();
        $commerce->getTransactions()->saveTransaction($transaction);

        if($response->isSuccessful()) $this->log('Created order '.$transaction->order->number.' ('.$transaction->order->id.')');
        else $this->log('Failed to create order '.$transaction->order->id.'. Klarna responded with '.$response->getCode().': '.$response->getMessage());

        return new Order($response);
    }

    /**
     * @inheritdoc
     */
    public function supportsCompletePurchase(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsPurchase(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsPartialRefund(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsAuthorize(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsCapture(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsPaymentSources(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsCompleteAuthorize(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsWebhooks(): bool
    {
        return false;
    }
}