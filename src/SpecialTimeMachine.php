<?php

namespace MediaWiki\Extension\TimeMachine;

use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Parser\Parser;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialTimeMachine extends SpecialPage {

	public function __construct() {
		parent::__construct( 'TimeMachine' );
	}

	/**
	 * @param Parser $parser
	 */
	public function execute( $parser ) {
		$request = $this->getRequest();
		$currentlyTimeTraveling = Utils::isTimeMachinePermanentlyActive( $request );
		$date = $request->getCookie( Utils::COOKIE, null, date( 'Y-m-d' ) );

		$output = $this->getOutput();

		if ( $request->wasPosted() ) {
			$date = $request->getVal( 'date' );
			$response = $request->response();
			$request->setVal( 'timemachine-date-set', $date );
			$currentlyTimeTraveling = $date !== null && $date !== '';

			if ( $currentlyTimeTraveling ) {
				$response->setCookie( Utils::COOKIE, $date );
			} else {
				$response->clearCookie( Utils::COOKIE );
			}

			$redirect = $request->getVal( 'redirect' );
			if ( $redirect !== null ) {
				$this->getOutput()->redirect( $redirect );

				return;
			}

			if ( $currentlyTimeTraveling ) {
				$output->addHTML( Html::successBox( $this->msg( 'timemachine-success-traveling' )->escaped() ) );
			} else {
				$output->addHTML( Html::successBox( $this->msg( 'timemachine-success-reset' )->escaped() ) );
			}
		}

		if ( $currentlyTimeTraveling && !$request->wasPosted() ) {
			$output->addHTML( Html::warningBox( $this->msg( 'timemachine-info-traveling' )->escaped() ) );
		}

		$hasPresets = $this->renderPresets( $date );

		$output->addElement(
			'p',
			[],
			$this->msg( $hasPresets ? 'timemachine-p1-presets' : 'timemachine-p1' )->escaped()
		);
		$this->renderDateForm( $date );
		$output->addElement( 'p', [], $this->msg( 'timemachine-p2' )->escaped() );

		if ( $currentlyTimeTraveling ) {
			$disableFormDescriptor = [
				'info' => [
					'type' => 'info',
					'default' => $this->msg( 'timemachine-p3' )->escaped(),
				]
			];
			$disableForm = HTMLForm::factory( 'ooui', $disableFormDescriptor, $this->getContext() );
			$disableForm->addHiddenField( 'date', '' )->setMethod( 'post' )->setSubmitDestructive()->setSubmitTextMsg(
					'timemachine-button2'
				)->prepareForm()->displayForm( false );
		}

		$this->setHeaders();
	}

	private function renderPresets( ?string $currentDate ): bool {
		[
			$presets,
			$presetsErrored
		] = $this->loadPresets();

		$output = $this->getOutput();
		if ( $presetsErrored ) {
			$output->addHTML(
				Html::errorBox(
					Parser::stripOuterParagraph(
						$output->parseAsContent(
							'The [[WikiMedia:TimeMachinePresets|presets page]] is invalid and could not be fully parsed.'
						)
					)
				)
			);
		}

		if ( count( $presets ) == 0 ) {
			return false;
		}

		$formDescriptor = [
			'info' => [
				'type' => 'info',
				'default' => $this->msg( 'timemachine-presets-p1' )->escaped(),
			],
			'date' => [
				'id' => 'date-select',
				'name' => 'date',
				'type' => 'select',
				'options' => [
					'' => '',
					...$presets
				],
				'default' => $currentDate
			]
		];
		$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$form->setMethod( 'post' )->setSubmitTextMsg( 'timemachine-button1' )->prepareForm()->displayForm( false );

		return true;
	}

	private function loadPresets(): array {
		$raw = $this->getSkin()->msg( 'TimeMachinePresets' )->inContentLanguage();
		$errored = false;
		$result = [];
		if ( $raw->exists() ) {
			$lines = explode( "\n", $raw->plain() );
			foreach ( $lines as $line ) {
				if ( strlen( trim( $line ) ) === 0 ) {
					continue;
				}

				$pipeIndex = strrpos( $line, '|' );
				if ( $pipeIndex === -1 ) {
					$errored = true;
					continue;
				}

				$label = substr( $line, 0, $pipeIndex );
				$value = substr( $line, $pipeIndex + 1 );
				$result[$label] = $value;
			}
		}

		return [
			$result,
			$errored
		];
	}

	private function renderDateForm( ?string $currentDate ) {
		$formDescriptor = [
			'date' => [
				'id' => 'date-input',
				'name' => 'date',
				'type' => 'date',
				'default' => $currentDate
			]
		];
		$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$form->setMethod( 'post' )->setSubmitTextMsg( 'timemachine-button1' )->prepareForm()->displayForm( false );
	}
}
