<?php
if (!file_exists($userdata)) {
	print($userdata . " does not exist - please setup before run the tests" . PHP_EOL);
	exit(1);
}

// include autoloader / api / etc
require dirname(__DIR__) . '/lib/classes/api/api_includes.inc.php';

Database::needRoot(true);
Database::query("DROP DATABASE IF EXISTS `test1sql1`;");
Database::needRoot(false);

// clear all tables
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_CUSTOMERS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_DOMAINS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_DOMAINTOIP . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_DOMAIN_DNS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_FTP_USERS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_FTP_GROUPS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_FTP_QUOTATALLIES . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_MAIL_VIRTUAL . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_MAIL_USERS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_DISKSPACE . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_DISKSPACE_ADMINS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_TRAFFIC . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_TRAFFIC_ADMINS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_TICKETS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_TASKS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_SESSIONS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_LOG . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_HTPASSWDS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_HTACCESS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_DOMAINREDIRECTS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_ADMINS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_IPSANDPORTS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_API_KEYS . "`;");
Database::query("TRUNCATE TABLE `" . TABLE_PANEL_DATABASES . "`;");
Database::query("ALTER TABLE `" . TABLE_PANEL_FPMDAEMONS . "` AUTO_INCREMENT=2;");

// add superadmin
Database::query("INSERT INTO `" . TABLE_PANEL_ADMINS . "` SET
	`loginname` = 'admin',
	`password` = '".makeCryptPassword('admin')."',
	`name` = 'Froxlor-Administrator',
	`email` = 'admin@dev.froxlor.org',
	`def_language` = 'English',
	`customers` = -1,
	`customers_see_all` = 1,
	`caneditphpsettings` = 1,
	`domains` = -1,
	`domains_see_all` = 1,
	`change_serversettings` = 1,
	`diskspace` = -1024,
	`mysqls` = -1,
	`emails` = -1,
	`email_accounts` = -1,
	`email_forwarders` = -1,
	`email_quota` = -1,
	`ftps` = -1,
	`tickets` = -1,
	`tickets_see_all` = 1,
	`subdomains` = -1,
	`traffic` = -1048576,
	`ip` = -1
");
$adminid = Database::lastInsertId();

// add api-key
Database::query("INSERT INTO `" . TABLE_API_KEYS . "` SET
	`adminid` = '1',
	`customerid` = '0',
	`apikey` = 'test',
	`secret` = 'test',
	`valid_until` = -1,
	`allowed_from` = ''
");

// add first ip (system default)
Database::query("INSERT INTO `" . TABLE_PANEL_IPSANDPORTS . "` SET
	`ip` = '82.149.225.46',
	`port` = '80',
	`listen_statement` = '0',
	`namevirtualhost_statement` = '0',
	`vhostcontainer` = '1',
	`vhostcontainer_servername_statement` = '1',
	`specialsettings` = '',
	`ssl` = '0'
");
$defaultip = Database::lastInsertId();
Settings::Set('system.defaultip', $defaultip, true);

// add ssl ip (system default)
Database::query("INSERT INTO `" . TABLE_PANEL_IPSANDPORTS . "` SET
	`ip` = '82.149.225.56',
	`port` = '443',
	`listen_statement` = '0',
	`namevirtualhost_statement` = '0',
	`vhostcontainer` = '1',
	`vhostcontainer_servername_statement` = '1',
	`specialsettings` = '',
	`ssl` = '1'
");
$defaultip = Database::lastInsertId();
Settings::Set('system.defaultsslip', $defaultip, true);

// get userdata of admin 'admin'
$sel_stmt = Database::prepare("SELECT * FROM `" . TABLE_PANEL_ADMINS . "` WHERE `adminid` = '1'");
$admin_userdata = Database::pexecute_first($sel_stmt);
$admin_userdata['adminsession'] = 1;

Settings::Set('panel.standardlanguage', 'English', true);
Settings::Set('system.lastguid', '10000', true);
