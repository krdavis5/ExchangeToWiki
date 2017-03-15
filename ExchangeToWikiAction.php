<?php

/**
 * Handles the 'exchangetowiki' action
 * Based on Extension:EmailToWiki
 *
 * @author Kenneth (Trey) Davis
 * @ingroup ExchangeToWiki
 */

class ExchangeToWikiAction extends FormlessAction {

	/**
	 * Return the name of the action this object responds to
	 * @return String lowercase
	 */
	public function getName() {
		return 'exchangetowiki';
	}

	public function getDescription() {
		return '';
	}

	public function onView() {
		return null;
	}
	/**
	 * The main action entry point. Do all output for display and send it to
	 * the context output.
	 *
	 * @param string $action
	 * @return boolean
	 */
	public function show() {
		global $wgOut, $wgRequest;
		$this->getOutput()->disable();
		if ( preg_match_all( "|inet6? addr:\s*([0-9a-f.:]+)|", `/sbin/ifconfig`, $matches ) && !in_array( $_SERVER['REMOTE_ADDR'], $matches[1] ) ) {
			header( 'Bad Request', true, 400 );
			print $this->logAdd( "Emails can only be added by the EmailInterface.php script running on the local host!" );
		} else $this->processEmails();
	}

	/**
	 * Process any unprocessed email files created by EmailInterface.php
	 */
	public function processEmails() {

		$dir = dirname( __FILE__ );
		$dirbasename = basename( $dir );

		// Allow different tmp directory to be used
		$etwTmpDir = $dir . '/ExchangeToWiki.tmp';

		$this->logAdd( "ExchangeToWiki.php (" . EXCHANGETOWIKI_VERSION . ") started processing " . basename( $etwTmpDir ) );
		if ( !is_dir( $etwTmpDir ) ) die( $this->logAdd( "Directory \"$etwTmpDir\" doesn't exist!" ) );

		// Scan messages in folder
		$nemails = 0;
		$nfiles = 0;
		foreach ( glob( "$etwTmpDir/*" ) as $dir ) {
			$msg = basename( $dir );
			$title = Title::newFromText( $msg );

			// Get bodytext for this message
			$content = file_get_contents( "$dir/_BODYTEXT_" );

			// Apply filtering
			if ( $this->filter( $content ) ) {

				// Create article for bodytext
				$wikipage = new WikiPage( $title );
				$wikitext = new WikitextContent( $content );
				// Use of EDIT_FORCE_BOT causes articles to be overwritten if they exist!
				$wikipage->doEditContent( $wikitext, wfMessage( 'etw_articlecomment' ), EDIT_FORCE_BOT );
				$nemails++;
			} else $this->logAdd( "email \"$msg\" was blocked by the filter." );

			// Remove the processed message folder
			exec( "rm -rf \"$dir\"" );
		}
		$this->logAdd( "Finished ($nemails messages and $nfiles files imported)" );
	}

	/**
	 * Append an error message to the log
	 */
	public function logAdd( $err ) {
		$dir = dirname( __FILE__ );
		$dirbasename = basename( $dir );
		$etwErrLog = $dir . '/ExchangeToWiki.log';
		$fh = fopen( $etwErrLog, 'a' );
		$time = date( 'd M Y, H:i:s' );
		fwrite( $fh, "PHP [$time]: $err\n" );
		fclose( $fh );
		return $err;
	}

	/**
	 * Apply filtering rules to the email and return whether allowed or not
	 * - if the filter option is set for this message, check the given DB table and field for existence of the From or Forward addresses
	 */
	public function filter( $message ) {
		global $etwFilterTable, $etwFilterField;
		if ( preg_match( "/^\s*\|\s*filter\s*=/m", $message ) ) {
			$dbr = &wfGetDB( DB_SLAVE );
			$tbl = $dbr->tableName( $etwFilterTable );
			if ( preg_match( "/^\s*\|\s*forward\s*=\s*(.+?)\s*$/m", $message, $m ) ) {
				foreach ( explode( ',', $m[1] ) as $email ) {
					if ( preg_match( "/<(.+?)>$/", $email, $m ) ) $email = $m[1];
					if ( $dbr->selectRow( $tbl, '1', "$etwFilterField REGEXP ':?$email$'" ) ) return true;
				}
			}
			return false;
		}
		return true;
	}
}