<?php

declare(strict_types=1);

namespace Plugin\flizpay\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

/**
 * Creates the FLIZpay order-state and transaction tables.
 *
 * xplugin_flizpay_order      – one row per order paid (or attempted) via FLIZpay.
 *                              Holds the attempt counter, per-status terminal markers and
 *                              the idempotency/mutex flags (nPaid, nMailSent).
 * xplugin_flizpay_transaction – one row per FLIZpay transaction ever issued for an order.
 *                              Acts as the allow-list for webhook events and stores the
 *                              amount/currency snapshot taken at transaction creation.
 */
class Migration20260826120000 extends Migration implements IMigration
{
    protected $author = 'FLIZpay';

    protected $description = 'Create FLIZpay order and transaction tables';

    public function up()
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS xplugin_flizpay_order (
                kBestellung   INT UNSIGNED  NOT NULL,
                nAttempt      INT UNSIGNED  NOT NULL DEFAULT 0,
                cCompletedTx  VARCHAR(64)   NULL,
                cFailedTx     VARCHAR(64)   NULL,
                cCanceledTx   VARCHAR(64)   NULL,
                nPaid         TINYINT(1)    NOT NULL DEFAULT 0,
                nMailSent     TINYINT(1)    NOT NULL DEFAULT 0,
                nWawiHold     TINYINT(1)    NOT NULL DEFAULT 0,
                fDiscount     DECIMAL(12,2) NULL,
                dCreated      DATETIME      NOT NULL,
                dUpdated      DATETIME      NULL,
                PRIMARY KEY (kBestellung)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->execute(
            "CREATE TABLE IF NOT EXISTS xplugin_flizpay_transaction (
                kFlizTransaction INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                kBestellung      INT UNSIGNED  NOT NULL,
                cTransactionId   VARCHAR(64)   NOT NULL,
                cReference       VARCHAR(191)  NOT NULL,
                nAttempt         INT UNSIGNED  NOT NULL DEFAULT 0,
                fOriginalAmount  DECIMAL(12,2) NOT NULL,
                cCurrency        CHAR(3)       NOT NULL,
                cStatus          VARCHAR(20)   NOT NULL DEFAULT 'created',
                dCreated         DATETIME      NOT NULL,
                dUpdated         DATETIME      NULL,
                PRIMARY KEY (kFlizTransaction),
                UNIQUE KEY uq_flizpay_txid (cTransactionId),
                KEY idx_flizpay_order (kBestellung),
                KEY idx_flizpay_scan (cStatus, dCreated)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down()
    {
        if ($this->doDeleteData() === false) {
            return;
        }
        $this->execute('DROP TABLE IF EXISTS xplugin_flizpay_transaction');
        $this->execute('DROP TABLE IF EXISTS xplugin_flizpay_order');
    }
}
