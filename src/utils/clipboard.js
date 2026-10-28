import { __ } from '@wordpress/i18n';

/**
 * Copy text to the clipboard, on the origins this plugin actually runs on.
 *
 * `navigator.clipboard` exists only in a **secure context** — HTTPS, or `localhost`. A WordPress
 * site being migrated is very often neither: `http://something.local` under Local, a staging box
 * on plain HTTP, an IP address. There the property is not merely refused, it is `undefined`, so
 * `navigator.clipboard.writeText()` throws a TypeError before any permission is ever considered.
 *
 * That is how both copy buttons came to do nothing at all: the call threw, the `catch` around it
 * treated the throw as "the user declined" and stayed quiet, and the screen gave no sign either
 * way. A button that silently does nothing is worse than no button, because the value looks
 * copied and the paste goes somewhere else.
 *
 * So the modern API is tried when it is there, and `document.execCommand( 'copy' )` is the
 * fallback when it is not. `execCommand` is deprecated and every browser still implements it;
 * it is the only thing that works on an insecure origin, and this is exactly its remaining use.
 * It must run inside the user gesture that asked for it, which it does — these are click
 * handlers.
 *
 * Note the test suite cannot catch the failure this exists for: the site under test is served at
 * `http://localhost:8888`, and localhost is a secure context, so the path that broke on a real
 * site is the one path that always worked in CI.
 *
 * @param {string} value The text to put on the clipboard.
 * @return {Promise<boolean>} Whether it actually landed there.
 */
export async function copyText( value ) {
	const text = String( value || '' );

	if ( '' === text ) {
		return false;
	}

	// The real thing, where it exists. `writeText` can still reject — a denied permission, a
	// document that is not focused — so a rejection falls through to the fallback rather than
	// being reported as success.
	if ( window.navigator?.clipboard?.writeText ) {
		try {
			await window.navigator.clipboard.writeText( text );
			return true;
		} catch ( e ) {
			// Fall through.
		}
	}

	return legacyCopy( text );
}

/**
 * The pre-`navigator.clipboard` way: select text in a throwaway field and ask the document to
 * copy the selection.
 *
 * The field has to be *in* the document and focusable for the selection to exist at all, so it
 * cannot be `display: none`. It is positioned off-screen instead, and made `readOnly` so a
 * mobile keyboard does not appear for the instant it is alive. Whatever the user had selected is
 * put back afterwards, because copying a pairing code should not silently destroy a selection
 * they were in the middle of making.
 *
 * @param {string} text The text to copy.
 * @return {boolean} Whether the copy command succeeded.
 */
function legacyCopy( text ) {
	const { body } = window.document;

	if ( ! body ) {
		return false;
	}

	const field = window.document.createElement( 'textarea' );

	field.value = text;
	field.setAttribute( 'readonly', '' );
	field.setAttribute( 'aria-hidden', 'true' );
	field.style.position = 'fixed';
	field.style.top = '0';
	field.style.left = '-9999px';
	field.style.opacity = '0';

	body.appendChild( field );

	// Through the node rather than the global, which is what `no-global-get-selection` is asking
	// for: a document this code did not assume it was standing in.
	const previous = field.ownerDocument.defaultView.getSelection();
	const restore =
		previous && previous.rangeCount > 0 ? previous.getRangeAt( 0 ) : null;

	let copied = false;

	try {
		field.select();
		field.setSelectionRange( 0, text.length );
		copied = window.document.execCommand( 'copy' );
	} catch ( e ) {
		copied = false;
	}

	body.removeChild( field );

	if ( restore && previous ) {
		previous.removeAllRanges();
		previous.addRange( restore );
	}

	return copied;
}

/**
 * What a copy button should say right now.
 *
 * Three states rather than two, because the failure has to be visible. On an origin where the
 * browser refuses both ways there is nothing to notice otherwise, and "nothing happened" is
 * indistinguishable from "it worked" until somebody pastes the wrong thing somewhere else.
 *
 * @param {string} copied What was last copied, or `failed:<what>`.
 * @param {string} what   Which value this button copies.
 * @param {string} idle   The label when nothing has been clicked.
 * @return {string} The label.
 */
export function copyLabel( copied, what, idle ) {
	if ( copied === what ) {
		return __( 'Copied', 'nfd-site-migrator' );
	}

	if ( copied === `failed:${ what }` ) {
		return __( 'Press ⌘C instead', 'nfd-site-migrator' );
	}

	return idle;
}
