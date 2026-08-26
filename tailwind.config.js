/**
 * Design tokens from the export-flow handoff.
 *
 * Named rather than pasted as arbitrary values, so `assets/styles/app.css` reads as the design
 * does — `bg-canvas`, `text-ink`, `border-hair` — and a token that moves moves in one place.
 * Every value here is from the handoff's token list.
 */
module.exports = {
	content: [ './src/**/*.{html,jsx,js}' ],
	theme: {
		extend: {
			colors: {
				canvas: '#faf9f7',
				sunk: '#f5f3ee',
				surface: '#ffffff',
				ink: '#12161b',
				'ink-hover': '#2c343e',
				body: '#5d5a52',
				muted: '#8b8578',
				faint: '#a29b8d',
				hair: '#eeeae2',
				edge: '#e3ded5',
				'edge-warm': '#e7e3dc',
				'edge-strong': '#d5cfc4',
				'edge-input': '#ddd7cc',
				rule: '#e0dcd4',
				'rule-todo': '#cfcabf',
				pass: '#17976a',
				'pass-bg': '#eefaf3',
				'pass-tint': '#e6f6ee',
				'pass-edge': '#cdebdb',
				'pass-ink': '#0f6b4c',
				'pass-body': '#3f7d66',
				'pass-tile': '#f6f8f6',
				'pass-tile-edge': '#d9ebe1',
				warn: '#d9a520',
				'warn-bg': '#fffaf0',
				'warn-edge': '#f0e0bd',
				'warn-badge': '#fdf1d6',
				'warn-badge-edge': '#eddcae',
				'warn-badge-ink': '#9a6a05',
				'warn-title': '#3f3110',
				'warn-body': '#7d6b41',
				link: '#2563d9',
				'link-hover': '#1a49a8',
				'row-ink': '#2f3339',
			},
			fontFamily: {
				// Public Sans and JetBrains Mono, bundled in assets/fonts/ and declared in
				// app.css. The fallbacks are what wp-admin already has, which is what shows
				// while the woff2 loads and on the writing systems the latin subsets leave out.
				sans: [
					'Public Sans',
					'-apple-system',
					'BlinkMacSystemFont',
					'Segoe UI',
					'Roboto',
					'Helvetica Neue',
					'sans-serif',
				],
				mono: [
					'JetBrains Mono',
					'ui-monospace',
					'SFMono-Regular',
					'Menlo',
					'Consolas',
					'monospace',
				],
			},
			boxShadow: {
				card: '0 1px 3px rgba(0,0,0,.06)',
				lift: '0 6px 20px rgba(18,22,27,.08)',
				sunken: 'inset 0 1px 2px rgba(20,24,29,.04)',
				ring: '0 0 0 3px rgba(18,22,27,.12)',
			},
		},
	},
	plugins: [],
};
