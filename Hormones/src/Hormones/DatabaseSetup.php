<?php

/*
 *
 * Hormones
 *
 * Copyright (C) 2017 SOFe
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
*/

declare(strict_types=1);

namespace Hormones;

use Hormones\Hormone\Defaults\VerifyDatabaseVersionHormone;
use libasynql\MysqlCredentials;
use libasynql\PingMysqlTask;
use libasynql\result\MysqlErrorResult;
use libasynql\result\MysqlResult;
use libasynql\result\MysqlSelectResult;
use libasynql\result\MysqlSuccessResult;
use Logger;

class DatabaseSetup{
	/**
	 * @internal Only to be called from HormonesPlugin.php
	 *
	 * @param MysqlCredentials $cred
	 * @param HormonesPlugin   $plugin
	 * @param int              &$organId
	 *
	 * @return bool
	 */
	public static function setupDatabase(MysqlCredentials $cred, HormonesPlugin $plugin, &$organId) : bool{
		$plugin->getLogger()->debug("Checking database...");
		$mysqli = $cred->newMysqli();
		$mysqli->query("CREATE TABLE IF NOT EXISTS hormones_metadata (name VARCHAR(20) PRIMARY KEY, val VARCHAR(20))");

		$mysqli->query("LOCK TABLES hormones_metadata WRITE, hormones_organs WRITE, hormones_blood WRITE, hormones_tissues WRITE, hormones_mod_banlist WRITE"); // this should lock all startup operations by Hormones

		$result = MysqlResult::executeQuery($mysqli, "SELECT val FROM hormones_metadata WHERE name = ?", [["s", "version"]]);
		if($result instanceof MysqlSelectResult and count($result->rows) > 0){
			$version = (int) $result->rows[0]["val"];
			if($version < HormonesPlugin::DATABASE_VERSION){
				$plugin->getLogger()->notice("Updating the database! Other servers in the network might become incompatible and require updating.");
				$hormone = new VerifyDatabaseVersionHormone;
				$hormone->pluginVersion = $plugin->getDescription()->getVersion();
				$hormone->dbVersion = HormonesPlugin::DATABASE_VERSION;

				// TODO update database
				// NOTE handle compatibility and concurrency issues with loaded servers, probably by firing a StopServerHormone or CheckCompatibilityHormone, or explicitly shutdown specified servers

				$mysqli->query("UPDATE hormones_metadata SET val = ? WHERE name = ?", [["s", HormonesPlugin::DATABASE_VERSION], ["s", "version"]]);
			}elseif($version > HormonesPlugin::DATABASE_VERSION){
				$plugin->getLogger()->critical("Plugin is outdated");
				$plugin->getServer()->getPluginManager()->disablePlugin($plugin);
				return false;
			}else{
				$plugin->getLogger()->debug("Database OK");
			}
		}else{
			$plugin->getLogger()->info("Thanks for using Hormones the first time. Setting up database tables...");
			DatabaseSetup::initialSetup($mysqli, $plugin->getLogger());

			$op = $mysqli->prepare("INSERT INTO hormones_metadata (val, name) VALUES (?, ?)");
			$value = HormonesPlugin::DATABASE_VERSION;
			$name = "version";
			$op->bind_param("ss", $value, $name);
			$op->execute();
		}

		$organName = $plugin->getConfig()->getNested("localize.organ");
		$result = MysqlResult::executeQuery($mysqli, "SELECT organId FROM hormones_organs WHERE name = ?", [["s", $organName]]);
		if($result instanceof MysqlSelectResult and isset($result->rows[0])){
			$organId = (int) $result->rows[0]["organId"];
		}else{
			$result = MysqlResult::executeQuery($mysqli, "INSERT INTO hormones_organs (name) VALUES (?)", [["s", $organName]]);
			if($result instanceof MysqlSuccessResult){
				$organId = (int) $result->insertId;
			}else{
				assert($result instanceof MysqlErrorResult);
				throw $result->getException();
			}
		}

		$mysqli->query("UNLOCK TABLES");

		PingMysqlTask::init($plugin, $cred);

		return true;
	}

	private static function initialSetup(\mysqli $mysqli, Logger $logger){
		$queries = [
			/** @lang MySQL */
			"CREATE TABLE IF NOT EXISTS hormones_metadata (
				name VARCHAR(20) PRIMARY KEY,
				val VARCHAR(20)
			);",
			/** @lang MySQL */
			"CREATE TABLE IF NOT EXISTS hormones_organs (
				organId TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
				name VARCHAR(64) UNIQUE
			) AUTO_INCREMENT = 0;",
			/** @lang MySQL */
			"CREATE TRIGGER organs_organId_limit BEFORE INSERT ON hormones_organs FOR EACH ROW
			BEGIN
				IF (NEW.organId < 0 OR NEW.organId > 63) THEN
					SIGNAL SQLSTATE '45000'
						SET MESSAGE_TEXT = 'organ flag is beyond range';
				END IF;
			END",
			/** @lang MySQL */
			/*"CREATE FUNCTION organ_name_to_id(inName VARCHAR(64))
				RETURNS TINYINT
			DETERMINISTIC
				BEGIN
					-- just select, no need to change stuff
					IF EXISTS(SELECT @id := organId
					          FROM hormones_organs
					          WHERE hormones_organs.name = inName)
					THEN
						RETURN @id;
					ELSE
						IF (SELECT COUNT(*)
						    FROM hormones_organs) = 64
						THEN
							-- table full, try to empty some rows
							DELETE FROM hormones_organs
							WHERE NOT EXISTS(SELECT tissueId
							                 FROM hormones_tissues
							                 WHERE hormones_tissues.organId = hormones_organs.organId);
							IF ROW_COUNT() = 0
							THEN
								SIGNAL SQLSTATE '45000'
								SET MESSAGE_TEXT = 'Too many organs; consider deleting unused ones';
							END IF;
						END IF;
						-- find the first empty row
						IF EXISTS(SELECT name
						          FROM hormones_organs
						          WHERE hormones_organs.organId = 0)
						THEN
							-- our gap-finding query doesn't work if 0 i
							INSERT INTO hormones_organs (organId, name) VALUES (0, inName);
							RETURN 0;
						ELSE
							IF EXISTS(
								SELECT @empty_id := t1.organId + 1 empty_id
								FROM hormones_organs t1 LEFT JOIN hormones_organs t2 ON t2.organId = t1.organId + 1
								HAVING hormones_organs.organId IS NULL
								ORDER BY t1.organId ASC
								LIMIT 1
							)
							THEN
								RETURN @empty_id;
							ELSE
								SIGNAL SQLSTATE '45000'
								SET MESSAGE_TEXT = 'Assertion error: organ count is not 64, but no gaps found and organId=0 is not null';
							END IF;
						END IF;
					END IF;
				END",*/
			/** @lang MySQL */
			"CREATE TABLE IF NOT EXISTS hormones_blood (
				hormoneId BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
				type VARCHAR(64) NOT NULL,
				receptors BIT(64) DEFAULT x'FFFFFFFFFFFFFFFF',
				creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				expiry TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				json TEXT
			);",
			/** @lang MySQL */
			"CREATE TABLE IF NOT EXISTS hormones_tissues (
				tissueId CHAR(32) PRIMARY KEY,
				organId TINYINT UNSIGNED NOT NULL,
				lastOnline TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				usedSlots SMALLINT UNSIGNED,
				maxSlots SMALLINT UNSIGNED,
				ip VARCHAR(68),
				port SMALLINT UNSIGNED,
				hormonesVersion MEDIUMINT,
				displayName VARCHAR(100),
				processId SMALLINT UNSIGNED,
				FOREIGN KEY (organId) REFERENCES hormones_organs(organId) ON UPDATE CASCADE ON DELETE RESTRICT
			);",
			/** @lang MySQL */
			"CREATE TABLE IF NOT EXISTS hormones_mod_banlist (
				name VARCHAR(20) PRIMARY KEY,
				start TIMESTAMP NOT NULL,
				stop TIMESTAMP,
				message VARCHAR(512) DEFAULT '',
				organs BIT(64) DEFAULT x'FFFFFFFFFFFFFFFF',
				doer VARCHAR(20)
			);"
		];
		foreach($queries as $query){
			$result = $mysqli->query($query);
			if($result !== true){
				$logger->error("Failed to execute database setup query: $mysqli->error\n$query");
			}
		}
	}
}
