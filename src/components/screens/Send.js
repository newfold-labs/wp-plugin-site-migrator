import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Layout } from '../Layout';
import { Loading } from '../Loading';
import { api } from '../../utils/api';
import { SOURCE_STEPS } from '../../steps';

const size = ( bytes ) => {
	if ( bytes >= 1073741824 ) {
		return `${ ( bytes / 1073741824 ).toFixed( 1 ) } GB`;
	}
	if ( bytes >= 1048576 ) {
		return `${ ( bytes / 1048576 ).toFixed( 1 ) } MB`;
	}
	return `${ Math.ceil( bytes / 1024 ) } KB`;
};

/**
 * How long ago, in words, for a timestamp in seconds.
 *
 * @param {number} at Unix timestamp.
 * @return {string} A phrase, or empty when there is no timestamp.
 */
const ago = ( at ) => {
	if ( ! at ) {
		return '';
	}

	const seconds = Math.max( 0, Math.round( Date.now() / 1000 ) - at );

	if ( seconds < 60 ) {
		return __( 'just now', 'nfd-site-migrator' );
	}

	if ( seconds < 3600 ) {
		return sprintf(
			/* translators: %d: number of minutes. */
			__( '%d min ago', 'nfd-site-migrator' ),
			Math.round( seconds / 60 )
		);
	}

	return sprintf(
		/* translators: %d: number of hours. */
		__( '%d h ago', 'nfd-site-migrator' ),
		Math.round( seconds / 3600 )
	);
};

/**
 * Hand the package to the destination without anybody carrying it.
 *
 * The key is minted here, on the site that holds the content, which is the mirror of pairing: a
 * destination mints a code so a site cannot be *targeted* by a stranger, a source mints a key so
 * a site cannot be *read* by one. Neither half of a migration can be started from outside.
 *
 * The key is shown once. Only its hash is kept, so there is no route that could return it again
 * and no database read that hands somebody a working one — which is worth the cost of a user who
 * loses it having to press the button a second time.
 *
 * @return {Element} The screen.
 */
export const Send = () => {
	const navigate = useNavigate();
	const [ status, setStatus ] = useState( null );
	const [ key, setKey ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ copied, setCopied ] = useState( '' );
	const live = useRef( true );

	const refresh = async () => {
		const response = await api.transfer.status();

		if ( live.current && ! response.failed ) {
			setStatus( response );
		}

		return response;
	};

	// Polled rather than pushed, because the work is happening on the other site and this one
	// only learns about it when it is asked to hand over bytes. Five seconds is slow enough to
	// be free and fast enough that the transfer visibly moves.
	useEffect( () => {
		live.current = true;
		refresh();

		const timer = window.setInterval( refresh, 5000 );

		return () => {
			live.current = false;
			window.clearInterval( timer );
		};
	}, [] );

	const issue = async () => {
		setBusy( true );
		setError( '' );

		const response = await api.transfer.issue();

		setBusy( false );

		if ( response.failed ) {
			setError( response.error );
			return;
		}

		setKey( response.key );
		refresh();
	};

	const revoke = async () => {
		setBusy( true );
		setError( '' );

		const response = await api.transfer.revoke();

		setBusy( false );
		setKey( '' );

		if ( response.failed ) {
			setError( response.error );
			return;
		}

		refresh();
	};

	const copy = async ( value, what ) => {
		try {
			await window.navigator.clipboard.writeText( value );
			setCopied( what );
			window.setTimeout( () => setCopied( '' ), 2000 );
		} catch ( e ) {
			// Clipboard access can be refused outright, and the value is on screen to be
			// selected by hand either way. Nothing here is worth an error message.
		}
	};

	const active = !! status?.active;
	const claimed = !! status?.claimed;
	const total = status?.bytes || 0;
	const sent = status?.sent || 0;

	// What the destination has taken, against what the package weighs. It overshoots on a resumed
	// transfer — bytes handed over twice are counted twice — so it is capped and described as
	// what was *sent*, not as how complete the other site is. Only the destination can answer
	// that honestly, and its own screen does.
	const percent = total
		? Math.min( 100, Math.round( ( sent / total ) * 100 ) )
		: 0;

	// "Reading right now" is the only thing this site can honestly observe: it hears from the
	// destination once per range request and not otherwise. A recent read means bytes are
	// moving; twenty seconds of silence means the transfer is between requests, paused, or over.
	const reading =
		claimed &&
		!! status?.last_seen &&
		Date.now() / 1000 - status.last_seen < 20;

	return (
		<Layout
			steps={ SOURCE_STEPS }
			step="deliver"
			eyebrow={ __( 'Source', 'nfd-site-migrator' ) }
			title={ __( 'Send it to the destination', 'nfd-site-migrator' ) }
			intro={ __(
				'The destination fetches the package straight from here — nothing is downloaded to your computer. Generate a key, paste it on the other site, and leave both tabs open.',
				'nfd-site-migrator'
			) }
			working={ reading }
		>
			{ error && (
				<div className="nfd-sm-note nfd-sm-note--stop">{ error }</div>
			) }

			{ ! status && ! error && (
				<div className="nfd-sm-card">
					<Loading>
						{ __( 'Checking the package…', 'nfd-site-migrator' ) }
					</Loading>
				</div>
			) }

			{ status && ! status.ready && (
				<div className="nfd-sm-note nfd-sm-note--stop">
					{ __(
						'There is no finished package here yet. Go back and finish the export first.',
						'nfd-site-migrator'
					) }
				</div>
			) }

			{ status?.ready && ! active && (
				<div className="nfd-sm-card">
					<p className="nfd-sm-eyebrow">
						{ __( 'Ready to send', 'nfd-site-migrator' ) }
					</p>
					<p className="nfd-sm-hint">
						{ sprintf(
							/* translators: %s: package size. */
							__(
								'%s, held here until the destination has taken all of it. The key you generate is the only way in, and it works for one site only.',
								'nfd-site-migrator'
							),
							size( total )
						) }
					</p>
					<div className="nfd-sm-actions">
						<button
							type="button"
							className="nfd-sm-btn nfd-sm-btn--primary"
							id="nfd-sm-issue-transfer"
							disabled={ busy }
							onClick={ issue }
						>
							{ busy
								? __( 'Generating…', 'nfd-site-migrator' )
								: __(
										'Generate a transfer key',
										'nfd-site-migrator'
								  ) }
						</button>
					</div>
				</div>
			) }

			{ key && (
				<div className="nfd-sm-card" id="nfd-sm-transfer-key">
					<p className="nfd-sm-eyebrow">
						{ __( 'This site’s address', 'nfd-site-migrator' ) }
					</p>
					<p className="nfd-sm-paircode nfd-sm-paircode--url">
						{ status?.site_url }
					</p>

					<p className="nfd-sm-eyebrow">
						{ __( 'Transfer key', 'nfd-site-migrator' ) }
					</p>
					<p className="nfd-sm-paircode nfd-sm-paircode--long">
						{ key }
					</p>

					<div className="nfd-sm-actions">
						<button
							type="button"
							className="nfd-sm-btn"
							onClick={ () =>
								copy( status?.site_url || '', 'url' )
							}
						>
							{ 'url' === copied
								? __( 'Copied', 'nfd-site-migrator' )
								: __( 'Copy address', 'nfd-site-migrator' ) }
						</button>
						<button
							type="button"
							className="nfd-sm-btn nfd-sm-btn--primary"
							onClick={ () => copy( key, 'key' ) }
						>
							{ 'key' === copied
								? __( 'Copied', 'nfd-site-migrator' )
								: __( 'Copy key', 'nfd-site-migrator' ) }
						</button>
					</div>

					<p className="nfd-sm-hint">
						{ __(
							'Shown once — only a hash of it is kept here. It binds to the first site that uses it and expires after six hours of nothing happening, so a key that leaks after the transfer starts is already useless.',
							'nfd-site-migrator'
						) }
					</p>
				</div>
			) }

			{ active && (
				<div className="nfd-sm-card" id="nfd-sm-transfer-status">
					<div className="nfd-sm-progress-head">
						<p className="nfd-sm-eyebrow">
							{ claimed
								? __( 'Being fetched', 'nfd-site-migrator' )
								: __(
										'Waiting for the destination',
										'nfd-site-migrator'
								  ) }
						</p>
						<p className="nfd-sm-counter">
							{ sprintf(
								/* translators: 1: bytes sent, 2: package size. */
								__( '%1$s of %2$s', 'nfd-site-migrator' ),
								size( sent ),
								size( total )
							) }
						</p>
					</div>

					<div className="nfd-sm-progress">
						<div
							className="nfd-sm-progress-bar"
							style={ { width: `${ percent }%` } }
						/>
					</div>

					<dl className="nfd-sm-facts">
						<div>
							<dt>{ __( 'Claimed by', 'nfd-site-migrator' ) }</dt>
							<dd>
								{ status.claimed_by ||
									( claimed
										? __(
												'unnamed site',
												'nfd-site-migrator'
										  )
										: __(
												'nobody yet',
												'nfd-site-migrator'
										  ) ) }
							</dd>
						</div>
						<div>
							<dt>{ __( 'Last read', 'nfd-site-migrator' ) }</dt>
							<dd>
								{ ago( status.last_seen ) ||
									__( 'never', 'nfd-site-migrator' ) }
							</dd>
						</div>
					</dl>

					<p className="nfd-sm-hint">
						{ claimed
							? __(
									'The destination is reading the package. Its own screen has the accurate progress — this one counts what has gone out, which is higher if anything was fetched twice after an interruption.',
									'nfd-site-migrator'
							  )
							: __(
									'Nothing has used the key yet. Paste the address and the key into the destination’s import screen.',
									'nfd-site-migrator'
							  ) }
					</p>

					<div className="nfd-sm-actions nfd-sm-actions--bare">
						<button
							type="button"
							className="nfd-sm-btn"
							disabled={ busy }
							onClick={ revoke }
						>
							{ __( 'Withdraw the key', 'nfd-site-migrator' ) }
						</button>
						{ active && ! key && (
							<button
								type="button"
								className="nfd-sm-btn"
								disabled={ busy }
								onClick={ issue }
							>
								{ __( 'New key', 'nfd-site-migrator' ) }
							</button>
						) }
					</div>
				</div>
			) }

			<div className="nfd-sm-card">
				<p className="nfd-sm-eyebrow">
					{ __( 'Or move the files yourself', 'nfd-site-migrator' ) }
				</p>
				<p className="nfd-sm-hint">
					{ __(
						'If the destination cannot reach this site over the internet — it is behind a firewall, or on a laptop — download the package and upload it there instead. Slower, and it works everywhere.',
						'nfd-site-migrator'
					) }
				</p>
				<div className="nfd-sm-actions">
					<button
						type="button"
						className="nfd-sm-btn"
						id="nfd-sm-go-download"
						onClick={ () => navigate( '/download' ) }
					>
						{ __(
							'Download the package instead',
							'nfd-site-migrator'
						) }
					</button>
				</div>
			</div>
		</Layout>
	);
};
