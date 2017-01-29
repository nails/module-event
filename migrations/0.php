<?php

/**
 * Migration:   0
 * Started:     09/01/2015
 * Finalised:   09/01/2015
 */

namespace Nails\Database\Migration\Nailsapp\ModuleEvent;

use Nails\Common\Console\Migrate\Base;

class Migration0 extends Base
{
    /**
     * Execute the migration
     * @return Void
     */
    public function execute()
    {
        $this->query("
            CREATE TABLE `{{NAILS_DB_PREFIX}}event` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `type` varchar(50) NOT NULL DEFAULT '',
                `url` varchar(300) DEFAULT NULL,
                `data` text,
                `ref` int(11) unsigned DEFAULT NULL,
                `created` datetime NOT NULL,
                `created_by` int(11) unsigned DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `created_by` (`created_by`),
                CONSTRAINT `{{NAILS_DB_PREFIX}}event_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
    }
}
