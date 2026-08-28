<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Controller;

use JTL\Shop;
use JTL\Smarty\JTLSmarty;
use Laminas\Diactoros\Response\RedirectResponse;
use Plugin\flizpay\lib\FlizPlugin;
use Plugin\flizpay\lib\Service\OrderService;
use Plugin\flizpay\lib\Service\TransactionRepository;
use Plugin\flizpay\paymentmethod\FlizPay;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /flizpay/return?ph=<order hash>
 *
 * The FLIZpay successUrl target. Works sessionless (the customer may return in
 * a different browser after paying in the FLIZpay app). It reads only local
 * state: paid customers go to the confirmation page, otherwise an interstitial
 * polls /flizpay/status until the signed webhook lands.
 */
class ReturnController
{
    public static function handle(ServerRequestInterface $request, array $args, JTLSmarty $smarty): ResponseInterface
    {
        $ph          = \trim((string)($request->getQueryParams()['ph'] ?? ''));
        $kBestellung = self::resolveOrderId($ph);
        if ($kBestellung === 0) {
            return new RedirectResponse(Shop::getURL() . '/');
        }

        $repository   = new TransactionRepository();
        $orderService = new OrderService(null, $repository);
        $orderData    = $orderService->getOrderData($kBestellung);
        if ($orderData === null || !$orderService->isFlizPayOrder($orderData)) {
            return new RedirectResponse(Shop::getURL() . '/');
        }

        $state = self::paymentState($kBestellung, $repository, $orderService);
        if ($state === 'completed') {
            return new RedirectResponse(self::successTarget($kBestellung, $orderService));
        }

        return self::renderInterstitial($smarty, $ph, $kBestellung, $state, $orderService);
    }

    public static function resolveOrderId(string $ph): int
    {
        if ($ph === '' || \strlen($ph) > 191) {
            return 0;
        }
        $row = FlizPlugin::getDB()->getSingleObject(
            'SELECT kBestellung FROM tbestellid WHERE cId = :ph',
            ['ph' => $ph]
        );

        return (int)($row->kBestellung ?? 0);
    }

    /**
     * @return string 'completed' | 'failed' | 'pending'
     */
    public static function paymentState(
        int $kBestellung,
        TransactionRepository $repository,
        OrderService $orderService
    ): string {
        $orderData = $orderService->getOrderData($kBestellung);
        if ($orderData !== null && $orderData->dBezahltDatum !== null) {
            return 'completed';
        }
        // the latest transaction, not the current-attempt one: settling a
        // failure advances the order's attempt counter, so the transaction that
        // just failed is no longer the current attempt
        $tx = $repository->getLatestTransaction($kBestellung);
        if ($tx !== null && \in_array((string)$tx->cStatus, ['failed', 'canceled'], true)) {
            return 'failed';
        }

        return 'pending';
    }

    /**
     * Confirmation target for a paid order — the payment method's own
     * getReturnURL (Abschlussseite when configured, order-status page
     * otherwise; both work sessionless).
     */
    public static function successTarget(int $kBestellung, ?OrderService $orderService = null): string
    {
        try {
            $orderService ??= new OrderService();
            $order        = $orderService->loadOrder($kBestellung);
            if ($order !== null) {
                $url = (new FlizPay(FlizPlugin::getModuleId()))->getReturnURL($order);
                if (\is_string($url) && $url !== '') {
                    return $url;
                }
            }
        } catch (\Throwable $e) {
            FlizPlugin::log('successTarget failed', \LOGLEVEL_ERROR, ['order' => $kBestellung, 'error' => $e->getMessage()]);
        }

        return Shop::getURL() . '/';
    }

    /**
     * Order-status page (carries the "pay again" button for retries).
     */
    public static function orderStatusUrl(int $kBestellung, ?OrderService $orderService = null): string
    {
        try {
            $orderService ??= new OrderService();
            $order        = $orderService->loadOrder($kBestellung);
            $url          = $order->BestellstatusURL ?? '';
            if (\is_string($url) && $url !== '') {
                return $url;
            }
        } catch (\Throwable) {
        }

        try {
            return Shop::Container()->getLinkService()->getStaticRoute('jtl.php') . '?bestellungen=1';
        } catch (\Throwable) {
            return Shop::getURL() . '/';
        }
    }

    private static function renderInterstitial(
        JTLSmarty $smarty,
        string $ph,
        int $kBestellung,
        string $state,
        OrderService $orderService
    ): ResponseInterface {
        $template = \dirname(__DIR__, 2) . '/frontend/template/return_polling.tpl';
        $plugin   = FlizPlugin::getPlugin();
        $baseUrl  = $plugin !== null ? \rtrim($plugin->getPaths()->getBaseURL(), '/') : '';

        return $smarty
            ->assign('flizState', $state)
            ->assign('flizPollUrl', Shop::getURL() . '/flizpay/status?ph=' . \rawurlencode($ph))
            ->assign('flizStatusUrl', self::orderStatusUrl($kBestellung, $orderService))
            ->assign('flizSpinner', $baseUrl !== '' ? $baseUrl . '/frontend/fliz-loading-wheel.svg' : '')
            ->assign('flizLang', [
                'processingHeading' => FlizPlugin::t('flizpayProcessingHeading'),
                'processingText'    => FlizPlugin::t('flizpayProcessingText'),
                'processingSlow'    => FlizPlugin::t('flizpayProcessingSlow'),
                'failedHeading'     => FlizPlugin::t('flizpayFailedHeading'),
                'failedText'        => FlizPlugin::t('flizpayFailedText'),
                'toOrderStatus'     => FlizPlugin::t('flizpayToOrderStatus'),
            ])
            ->getResponse($template);
    }
}
