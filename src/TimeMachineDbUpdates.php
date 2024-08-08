<?php

namespace MediaWiki\Extension\TimeMachine;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class TimeMachineDbUpdates implements LoadExtensionSchemaUpdatesHook {
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__ ) . '/sql/';
		$dbType = $updater->getDB()->getType();

		if ( $dbType === 'mysql' ) {
			$updater->addExtensionTable( 'timemachine_title_history',
				$dir . 'tables-generated.sql'
			);
		} elseif ( $dbType === 'sqlite' ) {
			$updater->addExtensionTable( 'timemachine_title_history',
				$dir . 'sqlite/tables-generated.sql'
			);
		} elseif ( $dbType === 'postgres' ) {
			$updater->addExtensionTable( 'timemachine_title_history',
				$dir . 'postgres/tables-generated.sql'
			);
		}
	}
}
