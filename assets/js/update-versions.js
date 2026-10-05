/**
 * Update Version column on the Plugins screen.
 *
 * Each row's <select> switches a plugin between Latest and Latest Stable, or
 * pins it to a specific tag. A plugin's tag list is fetched the first time its
 * select takes focus, so the page itself costs no extra requests and only the
 * dropdowns actually used are ever loaded.
 */
( function () {
	'use strict';

	var settings = window.wpbtUpdateVersions;

	if ( ! settings ) {
		return;
	}

	/**
	 * POST to admin-ajax and hand back the decoded payload.
	 *
	 * @param {Object}   fields Request fields, nonce and action excluded.
	 * @param {Function} done   Called with ( data, errorMessage ).
	 */
	function post( fields, done ) {
		var body = new URLSearchParams();

		body.append( 'nonce', settings.nonce );
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		window
			.fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( payload && payload.success ) {
					done( payload.data, null );
					return;
				}

				done( null, ( payload && payload.data && payload.data.message ) || settings.strings.failed );
			} )
			.catch( function () {
				done( null, settings.strings.failed );
			} );
	}

	/**
	 * Set the status text beside a select.
	 *
	 * @param {HTMLSelectElement} select  The select element.
	 * @param {string}            message Text to show, empty to clear.
	 */
	function status( select, message ) {
		var node = select.parentNode.querySelector( '.wpbt-update-version-status' );

		if ( node ) {
			node.textContent = message;
		}
	}

	/**
	 * Append the plugin's tag list to a select.
	 *
	 * A pinned version already has its own option, so it is skipped here rather
	 * than listed twice.
	 *
	 * @param {HTMLSelectElement} select   The select element.
	 * @param {Array}             versions Tags from the server, newest first.
	 */
	function addVersionOptions( select, versions ) {
		var current = select.value;
		var group = document.createElement( 'optgroup' );

		group.label = settings.strings.specificVersion;

		versions.forEach( function ( version ) {
			var option;

			if ( version.value === current ) {
				return;
			}

			option = document.createElement( 'option' );
			option.value = version.value;
			option.textContent = version.prerelease
				? version.label + ' (' + settings.strings.prerelease + ')'
				: version.label;

			group.appendChild( option );
		} );

		if ( group.children.length ) {
			select.appendChild( group );
		}
	}

	/**
	 * Load a plugin's tag list the first time its select is focused.
	 *
	 * @param {Event} event The focus event.
	 */
	function onFocus( event ) {
		var select = event.target;

		if ( select.getAttribute( 'data-versions' ) ) {
			return;
		}

		// Claim it up front so a second focus cannot start a parallel request.
		select.setAttribute( 'data-versions', 'loading' );
		status( select, settings.strings.loading );

		post( { action: 'wpbt_plugin_versions', plugin: select.getAttribute( 'data-plugin' ) }, function ( data, error ) {
			if ( error ) {
				select.removeAttribute( 'data-versions' );
				status( select, error );
				return;
			}

			addVersionOptions( select, data.versions );
			select.setAttribute( 'data-versions', 'loaded' );
			status( select, '' );
		} );
	}

	/**
	 * Handle a change on one of the update version selects.
	 *
	 * @param {Event} event The change event.
	 */
	function onChange( event ) {
		var select = event.target;
		var plugin = select.getAttribute( 'data-plugin' );
		var previous = select.getAttribute( 'data-previous' ) || '';

		select.disabled = true;
		status( select, settings.strings.saving );

		post( { action: 'wpbt_set_update_version', plugin: plugin, version: select.value }, function ( data, error ) {
			select.disabled = false;

			if ( error ) {
				select.value = previous;
				status( select, error );
				return;
			}

			select.setAttribute( 'data-previous', select.value );
			status( select, settings.strings.saved );
		} );
	}

	/**
	 * Wire up every update version select on the page.
	 */
	function init() {
		var selects = document.querySelectorAll( '.wpbt-update-version-select' );

		Array.prototype.forEach.call( selects, function ( select ) {
			select.setAttribute( 'data-previous', select.value );
			select.addEventListener( 'focus', onFocus );
			select.addEventListener( 'change', onChange );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
