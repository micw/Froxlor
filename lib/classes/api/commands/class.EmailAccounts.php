<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package    API
 * @since      0.10.0
 *
 */
class EmailAccounts extends ApiCommand implements ResourceEntity
{

	/**
	 * add a new email account for a given email-address either by id or emailaddr
	 *
	 * @param int $id
	 *        	optional email-address-id of email-address to add the account for
	 * @param string $emailaddr
	 *        	optional email-address to add the account for
	 * @param int $customerid
	 *        	optional, admin-only, the customer-id
	 * @param string $loginname
	 *        	optional, admin-only, the loginname
	 * @param string $email_password
	 *        	password for the account
	 * @param string $alternative_email
	 *        	optional email address to send account information to, default is the account that is being created
	 * @param int $email_quota
	 *        	optional quota if enabled in MB, default 0
	 *        	
	 * @access admin, customer
	 * @throws Exception
	 * @return array
	 */
	public function add()
	{
		if ($this->isAdmin() == false && Settings::IsInList('panel.customer_hide_options', 'email')) {
			throw new Exception("You cannot access this resource", 405);
		}

		if ($this->getUserDetail('email_accounts_used') < $this->getUserDetail('email_accounts') || $this->getUserDetail('email_accounts') == '-1') {

			// parameter
			$id = $this->getParam('id', true, 0);
			$ea_optional = ($id <= 0 ? false : true);
			$emailaddr = $this->getParam('emailaddr', $ea_optional, '');
			$email_password = $this->getParam('email_password');
			$alternative_email = $this->getParam('alternative_email', true, '');
			$quota = $this->getParam('email_quota', true, 0);

			// validation
			$quota = validate($quota, 'email_quota', '/^\d+$/', 'vmailquotawrong', array(), true);

			// get needed customer info to reduce the email-account-counter by one
			$customer = $this->getCustomerData('email_accounts');

			// check for imap||pop3 == 1, see #1298
			if ($customer['imap'] != '1' && $customer['pop3'] != '1') {
				standard_error('notallowedtouseaccounts', '', true);
			}

			// get email address
			$result = $this->apiCall('Emails.get', array(
				'id' => $id,
				'emailaddr' => $emailaddr
			));
			$id = $result['id'];

			$email_full = $result['email_full'];
			$idna_convert = new idna_convert_wrapper();
			$username = $idna_convert->decode($email_full);
			$password = validate($email_password, 'password', '', '', array(), true);
			$password = validatePassword($password, true);

			if ($result['popaccountid'] != 0) {
				throw new Exception("Email address '" . $email_full . "' has already an account assigned.", 406);
			}

			if (checkMailAccDeletionState($email_full)) {
				standard_error(array(
					'mailaccistobedeleted'
				), $email_full, true);
			}

			// alternative email address to send info to
			if (Settings::Get('panel.sendalternativemail') == 1) {
				$alternative_email = $idna_convert->encode(validate($alternative_email, 'alternative_email', '', '', array(), true));
				if (! validateEmail($alternative_email)) {
					standard_error('emailiswrong', $alternative_email, true);
				}
			} else {
				$alternative_email = '';
			}

			// validate quota if enabled
			if (Settings::Get('system.mail_quota_enabled') == 1) {
				if ($customer['email_quota'] != '-1' && ($quota == 0 || ($quota + $customer['email_quota_used']) > $customer['email_quota'])) {
					standard_error('allocatetoomuchquota', $quota, true);
				}
			} else {
				// disable
				$quota = 0;
			}

			if ($password == $email_full) {
				standard_error('passwordshouldnotbeusername', '', true);
			}

			// encrypt the password
			$cryptPassword = makeCryptPassword($password);

			$email_user = substr($email_full, 0, strrpos($email_full, "@"));
			$email_domain = substr($email_full, strrpos($email_full, "@") + 1);
			$maildirname = trim(Settings::Get('system.vmail_maildirname'));
			// Add trailing slash to Maildir if needed
			$maildirpath = $maildirname;
			if (! empty($maildirname) && substr($maildirname, - 1) != "/") {
				$maildirpath .= "/";
			}

			// insert data
			$stmt = Database::prepare("INSERT INTO `" . TABLE_MAIL_USERS . "` SET
				`customerid` = :cid,
				`email` = :email,
				`username` = :username," . (Settings::Get('system.mailpwcleartext') == '1' ? '`password` = :password, ' : '') . " 
				`password_enc` = :password_enc,
				`homedir` = :homedir,
				`maildir` = :maildir,
				`uid` = :uid,
				`gid` = :gid,
				`domainid` = :domainid,
				`postfix` = 'y',
				`quota` = :quota,
				`imap` = :imap,
				`pop3` = :pop3
			");
			$params = array(
				"cid" => $customer['customerid'],
				"email" => $email_full,
				"username" => $username,
				"password_enc" => $cryptPassword,
				"homedir" => Settings::Get('system.vmail_homedir'),
				"maildir" => $customer['loginname'] . '/' . $email_domain . "/" . $email_user . "/" . $maildirpath,
				"uid" => Settings::Get('system.vmail_uid'),
				"gid" => Settings::Get('system.vmail_gid'),
				"domainid" => $result['domainid'],
				"quota" => $quota,
				"imap" => $customer['imap'],
				"pop3" => $customer['pop3']
			);
			if (Settings::Get('system.mailpwcleartext') == '1') {
				$params["password"] = $password;
			}
			Database::pexecute($stmt, $params, true, true);
			$popaccountid = Database::lastInsertId();

			// add email address to its destination field
			$result['destination'] .= ' ' . $email_full;
			$stmt = Database::prepare("
				UPDATE `" . TABLE_MAIL_VIRTUAL . "`	SET `destination` = :destination, `popaccountid` = :popaccountid
				WHERE `customerid`= :cid AND `id`= :id
			");
			$params = array(
				"destination" => makeCorrectDestination($result['destination']),
				"popaccountid" => $popaccountid,
				"cid" => $customer['customerid'],
				"id" => $id
			);
			Database::pexecute($stmt, $params, true, true);

			// update customer usage
			Customers::increaseUsage($customer['customerid'], 'email_accounts_used');
			Customers::increaseUsage($customer['customerid'], 'email_quota_used', '', $quota);

			// update admin usage
			Admins::increaseUsage($customer['adminid'], 'email_accounts_used');
			Admins::increaseUsage($customer['adminid'], 'email_quota_used', '', $quota);

			// replacer array for mail to create account on server
			$replace_arr = array(
				'EMAIL' => $email_full,
				'USERNAME' => $username,
				'PASSWORD' => $password
			);

			// get the customers admin
			$stmt = Database::prepare("SELECT `name`, `email` FROM `" . TABLE_PANEL_ADMINS . "` WHERE `adminid`= :adminid");
			$admin = Database::pexecute_first($stmt, array(
				"adminid" => $customer['adminid']
			));

			// get template for mail subject
			$mail_subject = $this->getMailTemplate($customer, 'mails', 'pop_success_subject', $replace_arr, $this->lng['mails']['pop_success']['subject']);
			// get template for mail body
			$mail_body = $this->getMailTemplate($customer, 'mails', 'pop_success_mailbody', $replace_arr, $this->lng['mails']['pop_success']['mailbody']);

			$_mailerror = false;
			$mailerr_msg = "";
			try {
				$this->mailer()->SetFrom($admin['email'], getCorrectUserSalutation($admin));
				$this->mailer()->Subject = $mail_subject;
				$this->mailer()->AltBody = $mail_body;
				$this->mailer()->MsgHTML(str_replace("\n", "<br />", $mail_body));
				$this->mailer()->AddAddress($email_full);
				$this->mailer()->Send();
			} catch (phpmailerException $e) {
				$mailerr_msg = $e->errorMessage();
				$_mailerror = true;
			} catch (Exception $e) {
				$mailerr_msg = $e->getMessage();
				$_mailerror = true;
			}

			if ($_mailerror) {
				$this->logger()->logAction($this->isAdmin() ? ADM_ACTION : USR_ACTION, LOG_ERR, "[API] Error sending mail: " . $mailerr_msg);
				standard_error('errorsendingmail', $email_full, true);
			}

			$this->mailer()->ClearAddresses();

			// customer wants to send the e-mail to an alternative email address too
			if (Settings::Get('panel.sendalternativemail') == 1) {
				// get template for mail subject
				$mail_subject = $this->getMailTemplate($customer, 'mails', 'pop_success_alternative_subject', $replace_arr, $this->lng['mails']['pop_success_alternative']['subject']);
				// get template for mail body
				$mail_body = $this->getMailTemplate($customer, 'mails', 'pop_success_alternative_mailbody', $replace_arr, $this->lng['mails']['pop_success_alternative']['mailbody']);

				$_mailerror = false;
				try {
					$this->mailer()->SetFrom($admin['email'], getCorrectUserSalutation($admin));
					$this->mailer()->Subject = $mail_subject;
					$this->mailer()->AltBody = $mail_body;
					$this->mailer()->MsgHTML(str_replace("\n", "<br />", $mail_body));
					$this->mailer()->AddAddress($idna_convert->encode($alternative_email), getCorrectUserSalutation($customer));
					$this->mailer()->Send();
				} catch (phpmailerException $e) {
					$mailerr_msg = $e->errorMessage();
					$_mailerror = true;
				} catch (Exception $e) {
					$mailerr_msg = $e->getMessage();
					$_mailerror = true;
				}

				if ($_mailerror) {
					$this->logger()->logAction($this->isAdmin() ? ADM_ACTION : USR_ACTION, LOG_ERR, "[API] Error sending mail: " . $mailerr_msg);
					standard_error(array(
						'errorsendingmail'
					), $alternative_email, true);
				}

				$this->mailer()->ClearAddresses();
			}

			$this->logger()->logAction($this->isAdmin() ? ADM_ACTION : USR_ACTION, LOG_INFO, "[API] added email account for '" . $result['email_full'] . "'");
			$result = $this->apiCall('Emails.get', array(
				'emailaddr' => $result['email_full']
			));
			return $this->response(200, "successfull", $result);
		}
		throw new Exception("No more resources available", 406);
	}

	/**
	 * You cannot directly get an email account.
	 * You need to call Emails.get()
	 */
	public function get()
	{
		throw new Exception('You cannot directly get an email account. You need to call Emails.get()', 303);
	}

	/**
	 * update email-account entry for given email-address by either id or email-address
	 *
	 * @param int $id
	 *        	optional, the email-address-id
	 * @param string $emailaddr
	 *        	optional, the email-address to update
	 * @param int $customerid
	 *        	optional, admin-only, the customer-id
	 * @param string $loginname
	 *        	optional, admin-only, the loginname
	 * @param int $email_quota
	 *        	optional, update quota
	 * @param string $email_password
	 *        	optional, update password
	 *        	
	 * @access admin,customer
	 * @throws Exception
	 * @return array
	 */
	public function update()
	{
		if ($this->isAdmin() == false && Settings::IsInList('panel.customer_hide_options', 'email')) {
			throw new Exception("You cannot access this resource", 405);
		}

		// parameter
		$id = $this->getParam('id', true, 0);
		$ea_optional = ($id <= 0 ? false : true);
		$emailaddr = $this->getParam('emailaddr', $ea_optional, '');

		// validation
		$result = $this->apiCall('Emails.get', array(
			'id' => $id,
			'emailaddr' => $emailaddr
		));
		$id = $result['id'];

		if (empty($result['popaccountid']) || $result['popaccountid'] == 0) {
			throw new Exception("Email address '" . $result['email_full'] . "' has no account assigned.", 406);
		}

		$password = $this->getParam('email_password', true, '');
		$quota = $this->getParam('email_quota', true, $result['quota']);

		// get needed customer info to reduce the email-account-counter by one
		$customer = $this->getCustomerData();

		// validation
		$quota = validate($quota, 'email_quota', '/^\d+$/', 'vmailquotawrong', array(), true);

		$upd_query = "";
		$upd_params = array(
			"id" => $result['popaccountid'],
			"cid" => $customer['customerid']
		);
		if (! empty($password)) {
			if ($password == $result['email_full']) {
				standard_error('passwordshouldnotbeusername', '', true);
			}
			$password = validatePassword($password, true);
			$cryptPassword = makeCryptPassword($password);
			$upd_query .= (Settings::Get('system.mailpwcleartext') == '1' ? "`password` = :password, " : '') . "`password_enc`= :password_enc";
			$upd_params['password_enc'] = $cryptPassword;
			if (Settings::Get('system.mailpwcleartext') == '1') {
				$upd_params['password'] = $password;
			}
		}

		if (Settings::Get('system.mail_quota_enabled') == 1) {
			if ($quota != $result['quota']) {
				if ($customer['email_quota'] != '-1' && ($quota == 0 || ($quota + $customer['email_quota_used'] - $result['quota']) > $customer['email_quota'])) {
					standard_error('allocatetoomuchquota', $quota, true);
				}
				if (! empty($upd_query)) {
					$upd_query .= ", ";
				}
				$upd_query .= "`quota` = :quota";
				$upd_params['quota'] = $quota;
			}
		} else {
			// disable
			$quota = 0;
		}

		// build update query
		if (! empty($upd_query)) {
			$upd_stmt = Database::prepare("
				UPDATE `" . TABLE_MAIL_USERS . "` SET " . $upd_query . " WHERE `id` = :id AND `customerid`= :cid
			");
			Database::pexecute($upd_stmt, $upd_params, true, true);
		}

		if ($customer['email_quota'] != '-1') {
			Customers::increaseUsage($customer['customerid'], 'email_quota_used', '', ($quota - $result['quota']));
			Admins::increaseUsage($customer['adminid'], 'email_quota_used', '', ($quota - $result['quota']));
		}

		$this->logger()->logAction($this->isAdmin() ? ADM_ACTION : USR_ACTION, LOG_INFO, "[API] updated email account '" . $result['email_full'] . "'");
		$result = $this->apiCall('Emails.get', array(
			'emailaddr' => $result['email_full']
		));
		return $this->response(200, "successfull", $result);
	}

	/**
	 * You cannot directly list email forwarders.
	 * You need to call Emails.listing()
	 */
	public function listing()
	{
		throw new Exception('You cannot directly list email forwarders. You need to call Emails.listing()', 303);
	}

	/**
	 * delete email-account entry for given email-address by either id or email-address
	 *
	 * @param int $id
	 *        	optional, the email-address-id
	 * @param string $emailaddr
	 *        	optional, the email-address to delete the account for
	 * @param int $customerid
	 *        	optional, admin-only, the customer-id
	 * @param string $loginname
	 *        	optional, admin-only, the loginname
	 * @param bool $delete_userfiles
	 *        	optional, default false
	 *        	
	 * @access admin,customer
	 * @throws Exception
	 * @return array
	 */
	public function delete()
	{
		if ($this->isAdmin() == false && Settings::IsInList('panel.customer_hide_options', 'email')) {
			throw new Exception("You cannot access this resource", 405);
		}

		// parameter
		$id = $this->getParam('id', true, 0);
		$ea_optional = ($id <= 0 ? false : true);
		$emailaddr = $this->getParam('emailaddr', $ea_optional, '');
		$delete_userfiles = $this->getParam('delete_userfiles', true, 0);

		// validation
		$result = $this->apiCall('Emails.get', array(
			'id' => $id,
			'emailaddr' => $emailaddr
		));
		$id = $result['id'];

		if (empty($result['popaccountid']) || $result['popaccountid'] == 0) {
			throw new Exception("Email address '" . $result['email_full'] . "' has no account assigned.", 406);
		}

		// get needed customer info to reduce the email-account-counter by one
		$customer = $this->getCustomerData();

		// delete entry
		$stmt = Database::prepare("
			DELETE FROM `" . TABLE_MAIL_USERS . "` WHERE `customerid`= :cid AND `id`= :id
		");
		Database::pexecute($stmt, array(
			"cid" => $customer['customerid'],
			"id" => $result['popaccountid']
		), true, true);

		// update mail-virtual entry
		$result['destination'] = str_replace($result['email_full'], '', $result['destination']);

		$stmt = Database::prepare("
			UPDATE `" . TABLE_MAIL_VIRTUAL . "` SET `destination` = :dest, `popaccountid` = '0' WHERE `customerid`= :cid AND `id`= :id
		");
		$params = array(
			"dest" => makeCorrectDestination($result['destination']),
			"cid" => $customer['customerid'],
			"id" => $id
		);
		Database::pexecute($stmt, $params, true, true);
		$result['popaccountid'] = 0;

		if (Settings::Get('system.mail_quota_enabled') == '1' && $customer['email_quota'] != '-1') {
			$quota = (int) $result['quota'];
		} else {
			$quota = 0;
		}

		if ($delete_userfiles) {
			inserttask('7', $customer['loginname'], $result['email_full']);
		}

		// decrease usage for customer
		Customers::decreaseUsage($customer['customerid'], 'email_accounts_used');
		Customers::decreaseUsage($customer['customerid'], 'email_quota_used', '', $quota);
		// decrease admin usage
		Admins::decreaseUsage($customer['adminid'], 'email_accounts_used');
		Admins::decreaseUsage($customer['adminid'], 'email_quota_used', '', $quota);

		$this->logger()->logAction($this->isAdmin() ? ADM_ACTION : USR_ACTION, LOG_INFO, "[API] deleted email account for '" . $result['email_full'] . "'");
		return $this->response(200, "successfull", $result);
	}
}
