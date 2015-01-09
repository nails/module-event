# INITIAL ADMIN DB
# This is the schema of the event module database as of 09/01/2015
DROP TABLE IF EXISTS `nails_event`;
CREATE TABLE `nails_event` (`id` int(11) unsigned NOT NULL AUTO_INCREMENT, `type` varchar(50) NOT NULL DEFAULT '', `url` varchar(300) DEFAULT NULL, `data` text, `ref` int(11) unsigned DEFAULT NULL, `created` datetime NOT NULL, `created_by` int(11) unsigned DEFAULT NULL, PRIMARY KEY (`id`), KEY `created_by` (`created_by`), CONSTRAINT `nails_event_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `nails_user` (`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8;