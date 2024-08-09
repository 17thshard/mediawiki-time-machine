<?php

namespace MediaWiki\Extension\TimeMachine;

use Html;
use OOUI\ActionFieldLayout;
use OOUI\ButtonInputWidget;
use OOUI\DropdownInputWidget;
use OOUI\FormLayout;
use Parser;
use SpecialPage;

class SpecialTimeMachine extends SpecialPage {

    public function __construct() {
        parent::__construct( 'TimeMachine' );
    }

    /**
     * @param Parser $parser
     */
    public function execute( $parser ) {
        $request = $this->getRequest();
        $currentlyTimeTraveling = Utils::getTimeTravelTarget( $request ) !== false;
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
                $output->addHTML(
                    '<p class="successbox">' . wfMessage( 'timemachine-success-traveling' )->escaped() . '</p>'
                );
            } else {
                $output->addHTML(
                    '<p class="successbox">' . wfMessage( 'timemachine-success-reset' )->escaped() . '</p>'
                );
            }
        }

        if ( $currentlyTimeTraveling && !$request->wasPosted() ) {
            $output->addHTML(
                '<p class="warningbox">' . wfMessage( 'timemachine-info-traveling' )->escaped() . '</p>'
            );
        }

        $hasPresets = $this->renderPresets( $date );

        $output->addHTML(
            '
			<p>' .
            wfMessage( $hasPresets ? 'timemachine-p1-presets' : 'timemachine-p1' )->escaped() .
            '</p>
			<form method="post">
			<input type="date" name="date" value="' .
            $date .
            '" />
			<button type="submit" class="mw-ui-button mw-ui-progressive">' .
            wfMessage( 'timemachine-button1' )->escaped() .
            '</button>
			</form>
			<p>' .
            wfMessage( 'timemachine-p2' )->escaped() .
            '</p>'
        );

        if ( $currentlyTimeTraveling ) {
            $output->addHTML(
                '<p>' . wfMessage( 'timemachine-p3' )->escaped() . '</p>
				<form method="post">
				<input type="hidden" name="date" value="" />
				<button type="submit" class="mw-ui-button">' . wfMessage( 'timemachine-button2' )->escaped() . '</button>
				</form>'
            );
        }

        $this->setHeaders();
    }

    private function renderPresets( ?string $currentDate ): bool {
        [
            $presets,
            $presetsErrored
        ] = $this->loadPresets();

        $output = $this->getOutput();
        $output->enableOOUI();

        if ( $presetsErrored ) {
            $output->addHTML(
                Html::rawElement(
                    'p',
                    [ 'class' => 'errorbox' ],
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

        $output->addElement( 'p', [], wfMessage( 'timemachine-presets-p1' )->escaped() );
        $dropdown = new DropdownInputWidget(
            [
                'infusable' => true,
                'id' => 'preset-dropdown',
                'name' => 'date',
                'options' => [
                    [
                        'label' => '',
                        'data' => '',
                        'disabled' => true,
                    ],
                    ...$presets
                ],
                'value' => $currentDate
            ]
        );
        $button = new ButtonInputWidget(
            [
                'type' => 'submit',
                'label' => wfMessage( 'timemachine-button1' )->escaped(),
                'flags' => [
                    'primary',
                    'progressive'
                ]
            ]
        );
        $layout = new ActionFieldLayout( $dropdown, $button );
        $output->addInlineStyle( '.oo-ui-fieldLayout-align-left { max-width: 50em; }' );
        $output->addHTML(
            new FormLayout(
                [
                    'method' => 'POST',
                    'items' => [ $layout ]
                ]
            )
        );
        $output->addModules( 'ext.timeMachine' );

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
                $result[] = [
                    'label' => $label,
                    'data' => $value
                ];
            }
        }

        return [
            $result,
            $errored
        ];
    }
}
