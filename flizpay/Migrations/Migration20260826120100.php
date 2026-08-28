<?php

declare(strict_types=1);

namespace Plugin\flizpay\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

/**
 * Creates the FLIZpay runtime key-value store.
 *
 * Values written by the plugin at runtime (webhook key/URL, webhook-alive flag,
 * cashback data, reported plugin version) live here instead of in the plugin
 * settings, so that the settings-save handshake and the seconds-later inbound
 * test webhook never read stale, cached values.
 */
class Migration20260826120100 extends Migration implements IMigration
{
    protected $author = 'FLIZpay';

    protected $description = 'Create FLIZpay runtime config table';

    public function up()
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS xplugin_flizpay_config (
                cKey     VARCHAR(64) NOT NULL,
                cValue   TEXT        NULL,
                dUpdated DATETIME    NOT NULL,
                PRIMARY KEY (cKey)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down()
    {
        if ($this->doDeleteData() === false) {
            return;
        }
        $this->execute('DROP TABLE IF EXISTS xplugin_flizpay_config');
    }
}
