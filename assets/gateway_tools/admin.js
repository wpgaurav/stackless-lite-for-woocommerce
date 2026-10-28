/**
 * Stackless for WooCommerce: Payment Gateway Tools in wp-admin.
 *
 * 1. Label: every gateway Stackless adds has "(Stackless)" in its admin name. Wherever
 *    that name is shown as text (WooCommerce → Settings → Payments, a gateway's own
 *    settings, Stackless settings lists), it is swapped for a "Stackless" label, and a
 *    copy's " · EU account" suffix for a copy badge. The Payments screen is a React app,
 *    so a MutationObserver keeps up with it.
 * 2. Copies: Duplicate, Rename and Remove buttons in a copyable gateway's settings.
 */
(function () {
	'use strict';

	var config = window.stacklessGatewayTools;
	if (!config) {
		return;
	}
	var marker = config.marker;

	function badge(text, kind, title) {
		var span = document.createElement('span');
		span.className = kind === 'copy' ? 'stackless-gateway-copy' : 'stackless-gateway-label';
		span.textContent = text;
		if (title) {
			span.title = title;
		}
		return span;
	}

	function skip(node) {
		var parent = node.parentNode;
		if (!parent || parent.nodeType !== 1) {
			return true;
		}
		var tag = parent.tagName;
		return tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEXTAREA' || tag === 'OPTION' || tag === 'TITLE' ||
			parent.isContentEditable || parent.closest('.stackless-gateway-label, .stackless-gateway-copy');
	}

	function labelText(node) {
		var text = node.nodeValue;
		var at = text.indexOf(marker);
		if (at === -1 || skip(node)) {
			return;
		}
		var before = text.slice(0, at).replace(/\s+$/, '');
		var after = text.slice(at + marker.length);
		// React may write the full name back into a node that already has badges after it.
		var next = node.nextSibling;
		while (next && next.nodeType === 1 && (next.classList.contains('stackless-gateway-label') || next.classList.contains('stackless-gateway-copy'))) {
			var drop = next;
			next = next.nextSibling;
			drop.parentNode.removeChild(drop);
		}
		// The text node stays in place (React keeps a reference to it); the badges go after it.
		var fragment = document.createDocumentFragment();
		fragment.appendChild(badge(config.label, 'label', config.i18n.labelTitle));
		var copy = /^\s*·\s*(.+?)\s*$/.exec(after);
		if (copy) {
			fragment.appendChild(badge(copy[1], 'copy', config.i18n.copyTitle));
		} else if (after) {
			fragment.appendChild(document.createTextNode(after));
		}
		node.nodeValue = before + ' ';
		node.parentNode.insertBefore(fragment, node.nextSibling);
	}

	function scan(root) {
		if (!root || (root.nodeType !== 1 && root.nodeType !== 9)) {
			if (root && root.nodeType === 3) {
				labelText(root);
			}
			return;
		}
		var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
			acceptNode: function (node) {
				return node.nodeValue.indexOf(marker) !== -1 ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
			}
		});
		var found = [];
		while (walker.nextNode()) {
			found.push(walker.currentNode);
		}
		found.forEach(labelText);
	}

	/**
	 * Order screens name a gateway by its customer-facing title: "Payment via {title}" on the
	 * order, "via {title}" in the orders list, and a select of titles. Text nodes get badges;
	 * select options (plain text only) get " · Stackless".
	 */
	function labelOrders(root) {
		var gateways = config.gateways || {};
		// Longest title first, so a copy titled "Polar EU" isn't taken for the original "Polar".
		var ids = Object.keys(gateways).sort(function (a, b) {
			return gateways[b].title.length - gateways[a].title.length;
		});
		if (!ids.length || !root || !root.querySelectorAll) {
			return;
		}
		// On a single order the payment method select names the exact gateway, so a copy that
		// shares the original's title still gets its own badge.
		var select = root.querySelector('select[name="_payment_method"]');
		var current = select && gateways[select.value] ? select.value : '';
		Array.prototype.forEach.call(root.querySelectorAll('select[name="_payment_method"] option'), function (option) {
			var gateway = gateways[option.value];
			if (gateway && option.textContent.indexOf(config.label) === -1) {
				option.textContent = option.textContent + ' · ' + config.label + (gateway.badge ? ' · ' + gateway.badge : '');
			}
		});
		var containers = root.querySelectorAll('.woocommerce-order-data__meta, td.billing_address, td.column-billing_address');
		Array.prototype.forEach.call(containers, function (container) {
			if (container.querySelector('.stackless-gateway-label')) {
				return;
			}
			var candidates = current && container.classList.contains('woocommerce-order-data__meta') ? [current] : ids;
			var walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
			var node;
			while ((node = walker.nextNode())) {
				for (var i = 0; i < candidates.length; i++) {
					var gateway = gateways[candidates[i]];
					var needle = 'via ' + gateway.title;
					var at = node.nodeValue.indexOf(needle);
					if (at === -1) {
						continue;
					}
					var rest = node.splitText(at + needle.length);
					var fragment = document.createDocumentFragment();
					fragment.appendChild(badge(config.label, 'label', config.i18n.labelTitle));
					if (gateway.badge) {
						fragment.appendChild(badge(gateway.badge, 'copy', config.i18n.copyTitle));
					}
					rest.parentNode.insertBefore(fragment, rest);
					return;
				}
			}
		});
	}

	function observe() {
		scan(document.body);
		labelOrders(document);
		var queued = [];
		var timer = 0;
		new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				if (mutation.type === 'characterData') {
					queued.push(mutation.target);
				}
				Array.prototype.forEach.call(mutation.addedNodes, function (node) {
					queued.push(node);
				});
			});
			if (!timer) {
				timer = window.setTimeout(function () {
					timer = 0;
					var batch = queued;
					queued = [];
					batch.forEach(function (node) {
						if (node.isConnected) {
							scan(node);
						}
					});
				}, 60);
			}
		}).observe(document.body, { childList: true, subtree: true, characterData: true });
	}

	function post(data) {
		var body = new FormData();
		body.append('action', config.action);
		body.append('nonce', config.nonce);
		Object.keys(data).forEach(function (key) {
			body.append(key, data[key]);
		});
		return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (response) {
			return response.json();
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest && event.target.closest('[data-stackless-copy-task]');
		if (!button) {
			return;
		}
		event.preventDefault();
		var task = button.getAttribute('data-stackless-copy-task');
		var id = button.getAttribute('data-stackless-copy-id');
		var label = '';
		if (task === 'duplicate' || task === 'rename') {
			label = window.prompt(task === 'duplicate' ? config.i18n.duplicatePrompt : config.i18n.renamePrompt, '');
			if (label === null) {
				return;
			}
		} else if (task === 'remove' && !window.confirm(config.i18n.removeConfirm)) {
			return;
		}
		button.disabled = true;
		post({ task: task, id: id, label: label }).then(function (result) {
			if (!result || !result.success) {
				window.alert((result && result.data && result.data.message) || config.i18n.failed);
				button.disabled = false;
				return;
			}
			var target = result.data && result.data.id;
			if ((task === 'duplicate' || task === 'remove') && target) {
				window.location.href = config.settingsUrl + encodeURIComponent(target);
			} else {
				window.location.reload();
			}
		}).catch(function () {
			window.alert(config.i18n.failed);
			button.disabled = false;
		});
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', observe);
	} else {
		observe();
	}
})();
