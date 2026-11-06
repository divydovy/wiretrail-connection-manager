import {
	__experimentalConnectorItem as ConnectorItem,
	__experimentalRegisterConnector as registerConnector,
} from '@wordpress/connectors';

const {
	Button,
	__experimentalHStack: HStack,
	__experimentalText: Text,
	__experimentalVStack: VStack,
} = window.wp.components;
const { createElement } = window.wp.element;
const { __, sprintf } = window.wp.i18n;

function getModuleData() {
	const dataEl = document.getElementById(
		'wp-script-module-data-wiretrail-connection-manager-connectors'
	);

	if (!dataEl) {
		return {};
	}

	try {
		return JSON.parse(dataEl.textContent || '{}');
	} catch {
		return {};
	}
}

function injectStyles() {
	if (document.getElementById('wiretrail-connection-manager-connectors-styles')) {
		return;
	}

	const style = document.createElement('style');
	style.id = 'wiretrail-connection-manager-connectors-styles';
	style.textContent = `
		.wiretrail-connection-manager-logo {
			align-items: center;
			background: #1e1e1e;
			border-radius: 8px;
			color: #fff;
			display: flex;
			font-size: 13px;
			font-weight: 600;
			height: 40px;
			justify-content: center;
			width: 40px;
		}
		.wiretrail-connection-manager-pill {
			border-radius: 2px;
			display: inline-flex;
			font-size: 12px;
			font-weight: 500;
			line-height: 1.5;
			padding: 3px 8px;
			white-space: nowrap;
		}
		.wiretrail-connection-manager-pill--detected {
			background: #f0f6fc;
			color: #0969da;
		}
		.wiretrail-connection-manager-pill--healthy {
			background: #eff8f0;
			color: #345b37;
		}
		.wiretrail-connection-manager-pill--warning {
			background: #fcf9e8;
			color: #8a5a00;
		}
		.wiretrail-connection-manager-pill--danger {
			background: #fcf0f1;
			color: #b32d2e;
		}
	`;
	document.head.appendChild(style);
}

function logoFor(name) {
	const initials = String(name || 'CM')
		.split(/\s+/)
		.filter(Boolean)
		.slice(0, 2)
		.map((part) => part.charAt(0).toUpperCase())
		.join('');

	return createElement(
		'div',
		{ className: 'wiretrail-connection-manager-logo', 'aria-hidden': 'true' },
		initials || 'CM'
	);
}

function pill(text, kind = 'detected') {
	return createElement(
		'span',
		{ className: `wiretrail-connection-manager-pill wiretrail-connection-manager-pill--${kind}` },
		text
	);
}

function formatSources(sources = []) {
	if (!sources.length) {
		return __('Unknown source', 'wiretrail-connection-manager');
	}

	const visible = sources.slice(0, 4);
	const suffix =
		sources.length > visible.length
			? sprintf(
					/* translators: %d: Additional source count. */
					__(' + %d more', 'wiretrail-connection-manager'),
					sources.length - visible.length
				)
			: '';

	return visible.join(', ') + suffix;
}

function statusPill(connection) {
	if (connection.errorCount > 0) {
		return pill(
			sprintf(
				/* translators: %d: Error count. */
				__('%d errors', 'wiretrail-connection-manager'),
				connection.errorCount
			),
			'danger'
		);
	}

	if (connection.piiCount > 0) {
		return pill(
			sprintf(
				/* translators: %d: PII detection count. */
				__('%d PII', 'wiretrail-connection-manager'),
				connection.piiCount
			),
			'warning'
		);
	}

	return pill(__('Healthy', 'wiretrail-connection-manager'), 'healthy');
}

function DetectedConnectionCard({ connection, activityUrl }) {
	const description = sprintf(
		/* translators: 1: Host name, 2: Request count. */
		__('Observed traffic to %1$s across %2$d requests.', 'wiretrail-connection-manager'),
		connection.host,
		connection.requestCount
	);

	return createElement(
		ConnectorItem,
		{
			className: 'wiretrail-connection-manager-connector',
			logo: logoFor(connection.name),
			name: connection.name,
			description,
			actionArea: createElement(
				HStack,
				{ spacing: 3, expanded: false },
				pill(__('Detected', 'wiretrail-connection-manager')),
				statusPill(connection),
				createElement(
					Button,
					{
						href: activityUrl,
						size: 'compact',
						variant: 'secondary',
					},
					__('View activity', 'wiretrail-connection-manager')
				)
			),
		},
		createElement(
			VStack,
			{ spacing: 2 },
			createElement(Text, { size: 12 }, connection.classification),
			createElement(
				Text,
				{ size: 12 },
				sprintf(
					/* translators: %s: Source plugin names. */
					__('Sources: %s', 'wiretrail-connection-manager'),
					formatSources(connection.sources)
				)
			),
			connection.lastSeen
				? createElement(
						Text,
						{ size: 12 },
						sprintf(
							/* translators: %s: Last seen date. */
							__('Last seen: %s', 'wiretrail-connection-manager'),
							connection.lastSeen
						)
					)
				: null
		)
	);
}

function EmptyConnectionsCard({ settingsUrl }) {
	return createElement(ConnectorItem, {
		className: 'wiretrail-connection-manager-connector wiretrail-connection-manager-connector--empty',
		logo: logoFor('Wiretrail Connection Manager'),
		name: __('Wiretrail Connection Manager', 'wiretrail-connection-manager'),
		description: __(
			'No detected connections yet. Observed plugin, theme, and browser traffic will appear here after it is captured.',
			'wiretrail-connection-manager'
		),
		actionArea: createElement(
			Button,
			{
				href: settingsUrl,
				size: 'compact',
				variant: 'secondary',
			},
			__('Review settings', 'wiretrail-connection-manager')
		),
	});
}

function uniqueConnectorId(base, usedIds) {
	let id = base.replace(/[^a-z0-9-_]/gi, '-').toLowerCase();
	let suffix = 2;

	if (!id) {
		id = 'detected';
	}

	const original = id;
	while (usedIds.has(id)) {
		id = `${original}-${suffix}`;
		suffix += 1;
	}

	usedIds.add(id);
	return id;
}

injectStyles();

const data = getModuleData();
const connections = Array.isArray(data.connections) ? data.connections : [];
const usedIds = new Set();

if (!connections.length) {
	registerConnector('wiretrail-connection-manager-empty', {
		name: __('Wiretrail Connection Manager', 'wiretrail-connection-manager'),
		description: __(
			'Detected WordPress and WooCommerce integrations.',
			'wiretrail-connection-manager'
		),
		type: 'detected_connection',
		authentication: { method: 'none' },
		render: () =>
			createElement(EmptyConnectionsCard, {
				settingsUrl: data.settingsUrl,
			}),
	});
}

for (const connection of connections) {
	const connectorId = uniqueConnectorId(
		`wiretrail-connection-manager-${connection.id || connection.host}`,
		usedIds
	);

	registerConnector(connectorId, {
		name: connection.name,
		description: connection.host,
		type: 'detected_connection',
		authentication: { method: 'none' },
		render: () =>
			createElement(DetectedConnectionCard, {
				connection,
				activityUrl: data.activityUrl,
			}),
	});
}
