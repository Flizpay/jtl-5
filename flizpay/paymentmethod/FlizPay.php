<?php

declare(strict_types=1);

namespace Plugin\flizpay\paymentmethod;

use JTL\Catalog\Currency;
use JTL\Checkout\Bestellung;
use JTL\Plugin\Payment\Method;
use JTL\Session\Frontend;
use JTL\Shop;
use Plugin\flizpay\lib\Api\FlizPayService;
use Plugin\flizpay\lib\FlizPlugin;
use Plugin\flizpay\lib\Service\ConfigService;
use Plugin\flizpay\lib\Service\ConnectionService;
use Plugin\flizpay\lib\Service\OrderService;
use Plugin\flizpay\lib\Service\TransactionRepository;

/**
 * FLIZpay payment method (redirect PSP, order created before payment).
 *
 * The order exists when preparePaymentProcess() runs, so the FLIZpay
 * transaction carries the real order id and the customer returns to a
 * sessionless route keyed by the order hash. Payment outcomes never arrive
 * here — they are settled exclusively by signed webhooks. The return page
 * only reads local state while waiting for the webhook.
 */
class FlizPay extends Method
{
    public const FAILURE_URL = 'https://checkout.flizpay.de/failed';

    public const SOURCE = 'plugin';

    private ConfigService $config;

    private TransactionRepository $repository;

    private bool $payAgain = false;

    /**
     * @inheritdoc
     */
    public function init(int $nAgainCheckout = 0)
    {
        parent::init($nAgainCheckout);
        $this->payAgain   = $nAgainCheckout === 1;
        $this->config     = new ConfigService();
        $this->repository = new TransactionRepository();

        return $this;
    }

    /**
     * Hidden from checkout until the onboarding handshake completed and
     * FLIZpay's test webhook confirmed that the shop is reachable.
     */
    public function isValidIntern(array $args_arr = []): bool
    {
        return $this->config->isConnected();
    }

    /**
     * FLIZpay settles in EUR only; the amount snapshot must round-trip
     * unchanged through the webhook.
     */
    public function isSelectable(): bool
    {
        if (!parent::isSelectable()) {
            return false;
        }
        try {
            $currency = Frontend::getCurrency()->getCode();
        } catch (\Throwable) {
            return true;
        }

        return $currency === null || \strtoupper($currency) === 'EUR';
    }

    /**
     * Customers may retry an unpaid order from the order-status page; each
     * retry creates a fresh transaction under a new attempt number.
     */
    public function canPayAgain(): bool
    {
        return true;
    }

    /**
     * Creates the FLIZpay transaction and sends the customer to the hosted
     * checkout. Failures never throw — the template shows an error with a way
     * back to the order status page.
     */
    public function preparePaymentProcess(Bestellung $order): void
    {
        $smarty      = Shop::Smarty();
        $kBestellung = (int)$order->kBestellung;
        if ($kBestellung <= 0) {
            FlizPlugin::log('preparePaymentProcess without a saved order — aborting', \LOGLEVEL_ERROR);
            $smarty->assign('flizError', FlizPlugin::t('flizpayCreateError'));

            return;
        }

        try {
            $hash = $this->getOrderHash($order);
            if ($hash === null || $hash === '') {
                throw new \RuntimeException('order hash (tbestellid) missing');
            }

            $orderRow = $this->repository->ensureOrderRow($kBestellung);
            $attempt  = (int)$orderRow->nAttempt;
            $this->repository->setWawiHold($kBestellung, $this->isHeldFromWawi($kBestellung));

            $currency = $this->resolveCurrency($order);
            $amount   = $this->resolveAmount($order);
            if ($amount <= 0) {
                throw new \RuntimeException('order total is not payable: ' . $amount);
            }

            $transaction = (new FlizPayService($this->config->getApiKey()))->createTransaction(
                [
                    'amount'     => (float)$amount,
                    'currency'   => $currency,
                    'externalId' => (string)$kBestellung,
                    'successUrl' => Shop::getURL() . '/flizpay/return?ph=' . \rawurlencode($hash),
                    'failureUrl' => self::FAILURE_URL,
                    'customer'   => [
                        'email'     => (string)($order->oRechnungsadresse->cMail ?? ''),
                        'firstName' => (string)($order->oRechnungsadresse->cVorname ?? ''),
                        'lastName'  => (string)($order->oRechnungsadresse->cNachname ?? ''),
                    ],
                    'source'     => self::SOURCE,
                ],
                'jtl-' . \hash('sha256', $kBestellung . ':' . $attempt)
            );
            if ($transaction === null) {
                throw new \RuntimeException('FLIZpay did not return a usable transaction');
            }

            $this->repository->saveTransaction(
                $kBestellung,
                $transaction['transactionId'],
                $transaction['reference'],
                $attempt,
                \number_format($amount, 2, '.', ''),
                $currency
            );
            FlizPlugin::log(
                'transaction created',
                \LOGLEVEL_NOTICE,
                [
                    'order'   => $kBestellung,
                    'tx'      => $transaction['transactionId'],
                    'attempt' => $attempt,
                    'amount'  => $amount,
                    'payAgain' => $this->payAgain,
                ]
            );

            $smarty->assign('flizRedirectUrl', $transaction['redirectUrl'])
                ->assign('flizRedirectNotice', FlizPlugin::t('flizpayRedirectNotice'))
                ->assign('flizPayNow', FlizPlugin::t('flizpayPayNow'));

            if (!\headers_sent()) {
                \header('Location: ' . $transaction['redirectUrl']);
                exit;
            }
        } catch (\Throwable $e) {
            FlizPlugin::log(
                'preparePaymentProcess failed',
                \LOGLEVEL_ERROR,
                ['order' => $kBestellung, 'error' => $e->getMessage()]
            );
            $smarty->assign('flizError', FlizPlugin::t('flizpayCreateError'))
                ->assign('flizToOrderStatus', FlizPlugin::t('flizpayToOrderStatus'))
                ->assign('flizStatusUrl', $order->BestellstatusURL ?? '');
        }
    }

    /**
     * Gross order total in the order's currency, rounded to the precision
     * FLIZpay reports back in the webhook.
     */
    public function resolveAmount(Bestellung $order): float
    {
        $factor = (float)($order->fWaehrungsFaktor ?? 1.0);
        if ($factor <= 0) {
            $factor = 1.0;
        }

        return \round((float)$order->fGesamtsumme * $factor, 2);
    }

    public function resolveCurrency(Bestellung $order): string
    {
        $code = null;
        if (isset($order->Waehrung)) {
            $code = \is_object($order->Waehrung) && \method_exists($order->Waehrung, 'getCode')
                ? $order->Waehrung->getCode()
                : ($order->Waehrung->cISO ?? null);
        }
        if (empty($code) && !empty($order->kWaehrung)) {
            try {
                $code = (new Currency((int)$order->kWaehrung))->getCode();
            } catch (\Throwable) {
                $code = null;
            }
        }

        return \strtoupper((string)($code ?: 'EUR'));
    }

    /**
     * True when this order is currently withheld from JTL-Wawi by the plugin.
     */
    private function isHeldFromWawi(int $kBestellung): bool
    {
        if (!$this->config->holdFromWawi()) {
            return false;
        }
        $row = $this->getDB()->getSingleObject(
            'SELECT cAbgeholt FROM tbestellung WHERE kBestellung = :oid',
            ['oid' => $kBestellung]
        );

        return ($row->cAbgeholt ?? 'N') === 'Y';
    }

    /**
     * The webhook URL registered with FLIZpay for this shop.
     */
    public static function getWebhookUrl(): string
    {
        return ConnectionService::getWebhookUrl();
    }

    public function getOrderService(): OrderService
    {
        return new OrderService($this->getDB(), $this->repository);
    }
}
