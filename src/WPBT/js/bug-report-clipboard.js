/**
 * Clipboard: Initializes Bug Report "Copy to clipboard" buttons.
 *
 * @package WordPress_Beta_Tester
 */

const bugReportClipboard = new ClipboardJS( "#wordpress-beta-tester-bug-reports button" );

bugReportClipboard.on(
	"success",
	function( e ) {
		const success = jQuery( e.trigger ).next( ".success" );

		success.removeClass( "hidden" );

		setTimeout(
			function() {
				success.addClass( "hidden" );
			},
			3000
		);
	}
);
