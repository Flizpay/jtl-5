<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Admin;

use JTL\DB\DbInterface;
use JTL\Helpers\Form;
use JTL\Plugin\PluginInterface;
use JTL\Smarty\JTLSmarty;
use Plugin\flizpay\lib\Api\FlizPayService;
use Plugin\flizpay\lib\FlizPlugin;
use Plugin\flizpay\lib\Service\CashbackService;
use Plugin\flizpay\lib\Service\ConfigService;
use Plugin\flizpay\lib\Service\ConnectionService;
use Plugin\flizpay\lib\Service\TransactionRepository;

/**
 * Backend tab "Status": connection state, cashback values and the open
 * FLIZpay payments as read-only operational visibility.
 *
 * While a fresh handshake is waiting for FLIZpay's test webhook the template
 * reloads every few seconds, so the merchant sees the connection turn green
 * without touching anything.
 */
class StatusTab
{
    private const HANDSHAKE_WAIT_SECONDS = 90;

    private ConfigService $config;

    private TransactionRepository $repository;

    public function __construct(private readonly DbInterface $db, private readonly ?PluginInterface $plugin)
    {
        $this->config     = new ConfigService($this->db);
        $this->repository = new TransactionRepository($this->db);
    }

    public function render(JTLSmarty $smarty): string
    {
        $messages = $this->handleAction();

        $handshakeAt   = $this->config->get(ConfigService::KEY_HANDSHAKE_AT);
        $awaitingTest  = !$this->config->isWebhookAlive()
            && $this->config->getApiKey() !== ''
            && $handshakeAt !== null
            && (\time() - \strtotime($handshakeAt)) < self::HANDSHAKE_WAIT_SECONDS;

        return $smarty
            ->assign('flizMessages', $messages)
            ->assign('flizTokenInput', Form::getTokenInput())
            ->assign('flizConnected', $this->config->isConnected())
            ->assign('flizApiKeySet', $this->config->getApiKey() !== '')
            ->assign('flizWebhookKeySet', \strlen($this->config->getWebhookKey()) >= 32)
            ->assign('flizWebhookAlive', $this->config->isWebhookAlive())
            ->assign('flizWebhookUrl', $this->config->get(ConfigService::KEY_WEBHOOK_URL) ?: ConnectionService::getWebhookUrl())
            ->assign('flizExpectedWebhookUrl', ConnectionService::getWebhookUrl())
            ->assign('flizLastWebhookAt', $this->config->get(ConfigService::KEY_LAST_WEBHOOK_AT))
            ->assign('flizCashback', $this->config->getCashback())
            ->assign('flizVersion', FlizPlugin::getVersion())
            ->assign('flizHoldFromWawi', $this->config->holdFromWawi())
            ->assign('flizAwaitingTest', $awaitingTest)
            ->assign('flizOpenPayments', $this->repository->listOpenForAdmin())
            ->fetch($this->getTemplatePath());
    }

    /**
     * @return array<int, array{type: string, text: string}>
     */
    private function handleAction(): array
    {
        $action = (string)($_POST['flizAction'] ?? '');
        if ($action === '') {
            return [];
        }
        if (!Form::validateToken()) {
            return [['type' => 'danger', 'text' => 'Sicherheitsprüfung fehlgeschlagen. Bitte die Seite neu laden.']];
        }
        $messages = [];

        try {
            switch ($action) {
                case 'reconnect':
                    $apiKey = $this->config->getApiKey();
                    if ($apiKey === '') {
                        $messages[] = ['type' => 'danger', 'text' => 'Es ist kein API-Key hinterlegt.'];
                        break;
                    }
                    $result     = (new ConnectionService($this->config))->runHandshake($apiKey);
                    $messages[] = ['type' => $result['success'] ? 'success' : 'danger', 'text' => $result['message']];
                    break;

                case 'refreshCashback':
                    $apiKey = $this->config->getApiKey();
                    if ($apiKey === '') {
                        $messages[] = ['type' => 'danger', 'text' => 'Es ist kein API-Key hinterlegt.'];
                        break;
                    }
                    $cashback = (new FlizPayService($apiKey))->fetchCashback();
                    (new CashbackService($this->db, $this->config))->update($cashback);
                    $messages[] = [
                        'type' => 'success',
                        'text' => $cashback === null
                            ? 'Kein aktiver Rabatt hinterlegt – die Darstellung im Checkout wurde zurückgesetzt.'
                            : 'Rabattdaten wurden aktualisiert.',
                    ];
                    break;
            }
        } catch (\Throwable $e) {
            $messages[] = ['type' => 'danger', 'text' => 'Aktion fehlgeschlagen: ' . $e->getMessage()];
        }

        return $messages;
    }

    private function getTemplatePath(): string
    {
        $base = $this->plugin !== null
            ? $this->plugin->getPaths()->getAdminPath()
            : \dirname(__DIR__, 2) . '/adminmenu/';

        return \rtrim($base, '/') . '/templates/connection_status.tpl';
    }
}
