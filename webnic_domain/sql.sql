-- webnic_domain module install schema.
-- Local domain-contact storage, shared table used by the WebNIC registrar.
-- Safe to run with IF NOT EXISTS so it never clobbers an existing table
-- created by another WebNIC module.
CREATE TABLE IF NOT EXISTS `hb_domain_contacts` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) DEFAULT NULL,
  `domain_id` int(11) DEFAULT NULL,
  `registrant` tinyint(1) DEFAULT NULL,
  `esigned` int(11) DEFAULT NULL,
  `sync` tinyint(1) DEFAULT NULL,
  `type` varchar(11) DEFAULT '0',
  `handle` varchar(55) NOT NULL DEFAULT '',
  `name` varchar(255) DEFAULT NULL,
  `nationalid` varchar(55) DEFAULT '',
  `householdsid` varchar(100) DEFAULT NULL,
  `taxid` varchar(55) DEFAULT '',
  `position` varchar(100) DEFAULT NULL,
  `phonenumber` varchar(35) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `birthday` varchar(55) DEFAULT NULL,
  `country` varchar(35) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `ward` varchar(255) DEFAULT NULL,
  `address1` varchar(255) DEFAULT '',
  `address2` varchar(255) DEFAULT '',
  `postcode` varchar(35) DEFAULT '',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `language` varchar(35) DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `code` (`handle`),
  KEY `iddp` (`nationalid`),
  KEY `taxid` (`taxid`),
  KEY `handle` (`handle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
