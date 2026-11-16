<?php
/**
 * Versioned CREATE TABLE SQL for MNS (T-0.2 / AC-M0-2).
 *
 * SQL shape follows brief T-0.2 §4 (dbDelta rules).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Schema {

	/** Latest schema version shipped by this plugin build. */
	public const LATEST = 1;

	/**
	 * CREATE TABLE statements for a schema version.
	 *
	 * @param int $version Schema version (1…).
	 * @return array<int,string>
	 */
	public static function sql( int $version ): array {
		if ( 1 === $version ) {
			return self::sql_v1();
		}
		return array();
	}

	/**
	 * Short names for version 1 tables (same order as MNS_DB::TABLES).
	 *
	 * @return array<int,string>
	 */
	public static function tables_for( int $version ): array {
		if ( 1 === $version ) {
			return MNS_DB::TABLES;
		}
		return array();
	}

	/**
	 * @return array<int,string>
	 */
	private static function sql_v1(): array {
		$cc = MNS_DB::charset_collate();

		$contract = MNS_DB::table( 'contract_versions' );
		$files    = MNS_DB::table( 'files' );
		$nurses   = MNS_DB::table( 'nurses' );
		$patients = MNS_DB::table( 'patients' );
		$nsvc     = MNS_DB::table( 'nurse_services' );
		$nreg     = MNS_DB::table( 'nurse_regions' );
		$nweek    = MNS_DB::table( 'nurse_week' );
		$nday     = MNS_DB::table( 'nurse_day_slots' );
		$services = MNS_DB::table( 'services' );
		$orders   = MNS_DB::table( 'orders' );
		$olog     = MNS_DB::table( 'order_log' );
		$oedits   = MNS_DB::table( 'order_edits' );
		$notifs   = MNS_DB::table( 'notifications' );

		return array(
			"CREATE TABLE {$contract} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
version int(11) NOT NULL,
title varchar(191) NOT NULL DEFAULT '',
body longtext NOT NULL,
active tinyint(1) NOT NULL DEFAULT 0,
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY version (version),
KEY active (active)
) {$cc};",

			"CREATE TABLE {$files} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
owner_user_id bigint(20) unsigned NOT NULL,
order_id bigint(20) unsigned DEFAULT NULL,
kind varchar(24) NOT NULL,
rel_path varchar(255) NOT NULL,
mime varchar(100) NOT NULL DEFAULT '',
size bigint(20) unsigned NOT NULL DEFAULT 0,
sha256 char(64) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
deleted_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
KEY owner_kind (owner_user_id,kind),
KEY order_id (order_id)
) {$cc};",

			"CREATE TABLE {$nurses} (
user_id bigint(20) unsigned NOT NULL,
status varchar(16) NOT NULL DEFAULT 'pending',
gender varchar(8) NOT NULL DEFAULT '',
national_id char(10) NOT NULL DEFAULT '',
license_no varchar(32) NOT NULL DEFAULT '',
qualification varchar(32) NOT NULL DEFAULT '',
experience varchar(8) NOT NULL DEFAULT '',
workplace varchar(120) NOT NULL DEFAULT '',
about varchar(200) NOT NULL DEFAULT '',
accepting tinyint(1) NOT NULL DEFAULT 1,
profile_photo_file_id bigint(20) unsigned DEFAULT NULL,
photo_approved tinyint(1) NOT NULL DEFAULT 0,
contract_version_id bigint(20) unsigned DEFAULT NULL,
contract_accepted_at datetime DEFAULT NULL,
contract_accepted_ip varbinary(16) DEFAULT NULL,
applied_at datetime NOT NULL,
reviewed_at datetime DEFAULT NULL,
reviewed_by bigint(20) unsigned DEFAULT NULL,
reject_note text,
meta longtext,
PRIMARY KEY  (user_id),
UNIQUE KEY national_id (national_id),
KEY status (status)
) {$cc};",

			"CREATE TABLE {$patients} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
owner_user_id bigint(20) unsigned NOT NULL,
kind varchar(8) NOT NULL DEFAULT 'other',
name varchar(120) NOT NULL DEFAULT '',
age_group varchar(8) NOT NULL DEFAULT 'adult',
gender varchar(8) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
deleted_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
KEY owner (owner_user_id)
) {$cc};",

			"CREATE TABLE {$nsvc} (
nurse_id bigint(20) unsigned NOT NULL,
service_id bigint(20) unsigned NOT NULL,
PRIMARY KEY  (nurse_id,service_id),
KEY service_id (service_id)
) {$cc};",

			"CREATE TABLE {$nreg} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
nurse_id bigint(20) unsigned NOT NULL,
province_code char(3) NOT NULL,
city_id int(11) NOT NULL DEFAULT 0,
district_no smallint(6) NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY region (nurse_id,province_code,city_id,district_no),
KEY scope (province_code,city_id,district_no)
) {$cc};",

			"CREATE TABLE {$nweek} (
nurse_id bigint(20) unsigned NOT NULL,
weekday tinyint(4) NOT NULL,
slot_id varchar(8) NOT NULL,
PRIMARY KEY  (nurse_id,weekday,slot_id)
) {$cc};",

			"CREATE TABLE {$nday} (
nurse_id bigint(20) unsigned NOT NULL,
day date NOT NULL,
slot_id varchar(8) NOT NULL,
available tinyint(1) NOT NULL DEFAULT 1,
PRIMARY KEY  (nurse_id,day,slot_id),
KEY day_slot (day,slot_id)
) {$cc};",

			"CREATE TABLE {$services} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
slug varchar(64) NOT NULL,
category varchar(32) NOT NULL DEFAULT '',
name varchar(191) NOT NULL,
active tinyint(1) NOT NULL DEFAULT 1,
price_from bigint(20) unsigned NOT NULL DEFAULT 0,
price_to bigint(20) unsigned NOT NULL DEFAULT 0,
has_drug_step tinyint(1) NOT NULL DEFAULT 0,
sort int(11) NOT NULL DEFAULT 0,
config longtext,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY slug (slug),
KEY active_sort (active,sort)
) {$cc};",

			"CREATE TABLE {$orders} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
code char(6) NOT NULL,
account_user_id bigint(20) unsigned NOT NULL,
patient_id bigint(20) unsigned NOT NULL,
service_id bigint(20) unsigned NOT NULL,
source varchar(10) NOT NULL DEFAULT 'site',
plan_id bigint(20) unsigned DEFAULT NULL,
session_no int(11) DEFAULT NULL,
status varchar(16) NOT NULL DEFAULT 'submitted',
review_flag tinyint(1) NOT NULL DEFAULT 0,
review_reason varchar(32) DEFAULT NULL,
urgent_flag tinyint(1) NOT NULL DEFAULT 0,
province_code char(3) NOT NULL DEFAULT '',
city_id int(11) NOT NULL DEFAULT 0,
district_no smallint(6) NOT NULL DEFAULT 0,
address text NOT NULL,
contact_phone varchar(16) NOT NULL DEFAULT '',
service_date date NOT NULL,
slot_id varchar(8) NOT NULL,
gender_pref varchar(8) NOT NULL DEFAULT '',
nurse_user_id bigint(20) unsigned DEFAULT NULL,
assigned_at datetime DEFAULT NULL,
assignment_mode varchar(8) DEFAULT NULL,
essentials longtext,
notes text,
started_at datetime DEFAULT NULL,
ended_at datetime DEFAULT NULL,
duration_minutes int(11) DEFAULT NULL,
completed_at datetime DEFAULT NULL,
cancelled_at datetime DEFAULT NULL,
cancelled_by varchar(8) DEFAULT NULL,
cancel_reason text,
cancel_counts_for_nurse tinyint(1) NOT NULL DEFAULT 0,
steps longtext,
version int(11) NOT NULL DEFAULT 1,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY code (code),
UNIQUE KEY plan_session (plan_id,session_no),
KEY account_status (account_user_id,status,created_at),
KEY nurse_status (nurse_user_id,status,service_date),
KEY slot_status (service_date,slot_id,status),
KEY status_date (status,service_date),
KEY patient_id (patient_id),
KEY service_id (service_id)
) {$cc};",

			"CREATE TABLE {$olog} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
order_id bigint(20) unsigned NOT NULL,
actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
actor_role varchar(10) NOT NULL DEFAULT 'system',
action varchar(32) NOT NULL,
from_status varchar(16) DEFAULT NULL,
to_status varchar(16) DEFAULT NULL,
payload longtext,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY order_id (order_id,id)
) {$cc};",

			"CREATE TABLE {$oedits} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
order_id bigint(20) unsigned NOT NULL,
field varchar(64) NOT NULL,
old_value text,
new_value text,
edited_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY order_id (order_id)
) {$cc};",

			"CREATE TABLE {$notifs} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL,
type varchar(32) NOT NULL,
order_id bigint(20) unsigned DEFAULT NULL,
payload longtext,
seen_at datetime DEFAULT NULL,
created_at datetime NOT NULL,
expires_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
KEY user_seen (user_id,seen_at),
KEY expires_at (expires_at)
) {$cc};",
		);
	}
}
