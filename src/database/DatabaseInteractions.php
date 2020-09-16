<?php
require_once __DIR__ . '/../objects/AccountRequest.php';

function getBlockReason($username) {
	$dbr = wfGetDB( DB_REPLICA );

	$row = $dbr->selectRow('scratch_accountrequest_block', array('block_reason'), ['LOWER(block_username)' => strtolower($username)], __METHOD__);
	return $row ? $row->block_reason : false;
}

function createAccountRequest($username, $requestNotes, $email, $ip) {
	$dbw = wfGetDB( DB_MASTER );
	$dbw->insert('scratch_accountrequest', [
		'request_username' => $username,
		'request_email' => $email,
		'request_timestamp' => wfTimestampNow(),
		'request_notes' => $requestNotes,
		'request_ip' => $ip,
		'request_status' => 'new'
	], __METHOD__);
	
	return $dbw->insertID();
}

abstract class AbstractAccountRequestPager extends ReverseChronologicalPager {
	private $criteria;
	function __construct($username, $status) {
		$this->criteria = [];
		if ($status != null) {
			$this->criteria['request_status'] = $status;
		}
		if ($username != null) {
			$this->criteria['LOWER(request_username)'] = strtolower($username);
		}
		
		parent::__construct();
	}
	
	function getQueryInfo() {
		return [
			'tables' => 'scratch_accountrequest',
			'fields' => ['request_id', 'request_username', 'request_email', 'request_timestamp', 'request_notes', 'request_ip', 'request_status'],
			'conds' => $this->criteria
		];
	}
	
	function getIndexField() {
		return 'request_timestamp';
	}
	
	function formatRow($row) {
		return $this->rowFromRequest(AccountRequest::fromRow($row));
	}
	
	abstract function rowFromRequest($accountRequest);
}

function getAccountRequestById($id) {
	$dbr = wfGetDB( DB_REPLICA );
	$result = $dbr->selectRow('scratch_accountrequest', array('request_id', 'request_username', 'request_email', 'request_timestamp', 'request_notes', 'request_ip', 'request_status'), ['request_id' => $id], __METHOD__);
	
	return $result ? AccountRequest::fromRow($result) : false;
}

function actionRequest(AccountRequest $request, string $action, $userPerformingAction, string $comment) {
	$dbw = wfGetDB( DB_MASTER );
	
	$dbw->insert('scratch_accountrequest_history', [
		'history_request_id' => $request->id,
		'history_action' => $action,
		'history_comment' => $comment,
		'history_performer' => $userPerformingAction,
		'history_timestamp' => wfTimestampNow()
	], __METHOD__);
	
	//if the action also updates the status, then set the status appropriately
	if (isset(actionToStatus[$action])) {
		$dbw->update('scratch_accountrequest', [
			'request_status' => actionToStatus[$action]
		], ['request_id' => $request->id], __METHOD__);
	}
}

function getRequestHistory(AccountRequest $request) : array {
	$dbr = wfGetDB( DB_REPLICA );
	
	$result = $dbr->select(['scratch_accountrequest_history', 'user'], [
		'history_timestamp',
		'history_action',
		'history_comment',
		'user_name'
	], ['history_request_id' => $request->id], __METHOD__, ['order_by' => ['history_timestamp', 'ASC']], [
		'user' => ['LEFT JOIN', ['user_id=history_performer']]
	]);
	
	$history = array();
	foreach ($result as $row) {
		$history[] = AccountRequestHistoryEntry::fromRow($row);
	}
	
	return $history;
}

function createAccount(AccountRequest $request) {
	User::newFromName($request->username)->addToDatabase();
}

function userExists(string $username) : bool {
	$dbr = wfGetDB( DB_REPLICA );
	
	return User::newFromName($username)->getId() != 0; //TODO: make this case-insensitive
}

function hasActiveRequest(string $username) : bool {
	$dbr = wfGetDB( DB_REPLICA );
	
	return $dbr->selectRowCount('scratch_accountrequest', array('1'), ['LOWER(request_username)' => strtolower($username), 'request_status' => ['new', 'awaiting-admin', 'awaiting-user']], __METHOD__) > 0;
}
