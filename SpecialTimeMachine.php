<?php

class SpecialTimeMachine extends SpecialPage {

	public function __construct() {
		parent::__construct( 'TimeMachine' );
	}

	/**
	 * @param Parser $parser
	 */
	public function execute( $parser ) {
		$request = $this->getRequest();
		$date = $request->getCookie( 'timemachine-date', null, date( 'Y-m-d' ) );
		if ( $request->wasPosted() ) {
			$date = $request->getVal( 'date' );
			$response = $request->response();
			$request->setVal( 'timemachine-date-set', $date );
			$response->setCookie( 'timemachine-date', $date );

			$redirect = $request->getVal( 'redirect' );
			if ( $redirect !== null ) {
				$this->getOutput()->redirect( $redirect );
				return;
			}
		}

		$rawPresets = $this->getSkin()->msg( 'TimeMachinePresets' )->inContentLanguage();
		$presetsError = false;
		$presets = [];
		if ( $rawPresets->exists() ) {
			$lines = explode( "\n", $rawPresets->plain() );
			foreach ( $lines as $line ) {
				if ( strlen( trim( $line ) ) === 0 ) {
					continue;
				}

				$pipeIndex = strrpos( $line, '|' );
				if ( $pipeIndex === -1 ) {
					$presetsError = true;
					continue;
				}

				$label = substr( $line, 0, $pipeIndex );
				$value = substr ( $line, $pipeIndex + 1 );
				$presets[] = [
					'label' => $label,
					'data' => $value
				];
			}
		}

		$output = $this->getOutput();
		$output->enableOOUI();

		if ( $presetsError ) {
			$output->addHTML(
				Html::rawElement(
					'p',
					['class' => 'errorbox'],
					Parser::stripOuterParagraph($output->parseAsContent(
						'The [[WikiMedia:TimeMachinePresets|presets page]] is invalid and could not be fully parsed.'
					))
				)
			);
		}

		if (count($presets) > 0) {
			$output->addElement( 'p', [], wfMessage( 'timemachine-presets-p1' )->escaped() );
			$dropdown = new DropdownInputWidget( [
				'infusable' => true,
				'id' => 'preset-dropdown',
				'name' => 'date',
				'options' => $presets
			] );
			$button = new ButtonInputWidget( [
				'type' => 'submit',
				'label' => wfMessage( 'timemachine-button1' )->escaped(),
				'flags' => ['primary', 'progressive']
			] );
			$layout = new ActionFieldLayout( $dropdown, $button );
			$output->addInlineStyle( '.oo-ui-fieldLayout-align-left { max-width: 50em; }' );
			$output->addHTML( new FormLayout( ['method' => 'POST', 'items' => [$layout]] ) );
			$output->addModules( 'ext.timeMachine' );
		}

		$output->addHTML( '
			<p>' . wfMessage( count($presets) > 0 ? 'timemachine-p1-presets' : 'timemachine-p1' )->escaped() . '</p>
			<form method="post">
			<input type="date" name="date" value="' . $date . '" />
			<button type="submit" class="mw-ui-button mw-ui-progressive">' . wfMessage( 'timemachine-button1' )->escaped() . '</button>
			</form>
			<p>' . wfMessage( 'timemachine-p2' )->escaped() . '</p>
			<p>' . wfMessage( 'timemachine-p3' )->escaped() . '</p>
			<form method="post">
			<input type="hidden" name="date" value="" />
			<button type="submit" class="mw-ui-button">' . wfMessage( 'timemachine-button2' )->escaped() . '</button>
			</form>
		' );
		$this->setHeaders();
	}

	/**
	 * This method redirects to the first revision before the time set by the user in Special:TimeMachine
	 * It would be better if instead of redirecting it changed the request on the fly, but I haven't found
	 * a way yet.
	 *
	 * @param Title &$title
	 * @param \Article &$article
	 * @param OutputPage &$output
	 * @param User &$user
	 * @param \WebRequest $request
	 * @param \MediaWiki $mediaWiki
	 */
	public static function onBeforeInitialize( &$title, &$article, &$output, &$user, $request, $mediaWiki ) {
		if ( $request->getVal( 'action', 'view' ) != 'view' ) {
			return;
		}

		$date = $request->getCookie( 'timemachine-date' );
		if ( $date !== null ) {
			$output->addModuleStyles([
				'mediawiki.ui.button',
			]);
		}
		if ( !$date || $request->getBool( 'oldid' ) ) {
			return;
		}

		$dbr = wfGetDB( DB_REPLICA );

		$rev_timestamp = wfTimestamp( TS_UNIX, $date . ' 00:00:00' );
		$rev_page = $title->getArticleID();

		$rev_id = $dbr->selectField(
			'revision',
			'rev_id',
			[ 'rev_page' => $rev_page, 'rev_timestamp < ' . $dbr->timestamp( $rev_timestamp ) ],
			__METHOD__,
			[ 'ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 1 ]
		);

		// The page doesn't exist yet
		if ( !$rev_id ) {
			return;
		}

		// Redirect to the old revision of the page
		$rev = Revision::newFromId( $rev_id );
		$url = $rev->getTitle()->getLocalURL( [ 'oldid' => $rev_id ] );
		$output->redirect( $url );
	}
}
