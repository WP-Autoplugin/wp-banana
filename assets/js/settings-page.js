/* global wpBananaTestProvider */
(function() {
	var cfg = window.wpBananaTestProvider;
	if (!cfg || typeof window.fetch !== 'function' || typeof window.URLSearchParams !== 'function') {
		return;
	}

	var ajaxUrl = cfg.ajaxUrl || (typeof window.ajaxurl === 'string' ? window.ajaxurl : '');
	if (!ajaxUrl) {
		return;
	}

	var buttons = document.querySelectorAll('.wp-banana-test-provider');
	if (!buttons.length) {
		return;
	}

	var resetTimers = new WeakMap();

	var getDatasetValue = function(button, key, fallback) {
		if (!button || !button.dataset) {
			return fallback || '';
		}
		return button.dataset[key] || fallback || '';
	};

	var clearResetTimer = function(button) {
		if (resetTimers.has(button)) {
			window.clearTimeout(resetTimers.get(button));
			resetTimers.delete(button);
		}
	};

	var textForState = function(button, state) {
		var attr = 'label' + state.charAt(0).toUpperCase() + state.slice(1);
		switch (state) {
			case 'loading':
				return getDatasetValue(button, attr, cfg.testing);
			case 'success':
				return getDatasetValue(button, attr, cfg.success);
			case 'error':
				return getDatasetValue(button, attr, cfg.genericError);
			default:
				return getDatasetValue(button, 'labelDefault', 'Test');
		}
	};

	var ariaForState = function(button, state, message) {
		if (message) {
			return message;
		}
		var attr = 'aria' + state.charAt(0).toUpperCase() + state.slice(1);
		switch (state) {
			case 'loading':
				return getDatasetValue(button, attr, cfg.testing);
			case 'success':
				return getDatasetValue(button, attr, cfg.success);
			case 'error':
				return getDatasetValue(button, attr, cfg.genericError);
			default:
				return getDatasetValue(button, 'ariaDefault', '');
		}
	};

	var statusForState = function(button, state, message) {
		if ('default' === state) {
			return '';
		}
		if (message) {
			return message;
		}
		var attr = 'status' + state.charAt(0).toUpperCase() + state.slice(1);
		switch (state) {
			case 'loading':
				return getDatasetValue(button, attr, cfg.testing);
			case 'success':
				return getDatasetValue(button, attr, cfg.success);
			case 'error':
				return getDatasetValue(button, attr, cfg.genericError);
			default:
				return '';
		}
	};

	var targetInput = function(button) {
		var targetId = getDatasetValue(button, 'target');
		return targetId ? document.getElementById(targetId) : null;
	};

	var setAvailabilityFromInput = function(button) {
		if (!button) {
			return;
		}
		var state = button.dataset.state || 'default';
		if ('default' !== state) {
			return;
		}
		var input = targetInput(button);
		if (!input || input.disabled) {
			button.disabled = false;
			return;
		}
		var value = typeof input.value === 'string' ? input.value.trim() : '';
		button.disabled = value === '';
	};

	var scheduleReset = function(button) {
		var delay = parseInt(cfg.resetDelay, 10);
		if (!button || Number.isNaN(delay) || delay <= 0) {
			return;
		}
		var timer = window.setTimeout(function() {
			resetTimers.delete(button);
			updateState(button, 'default', '');
		}, delay);
		resetTimers.set(button, timer);
	};

	var updateState = function(button, state, message) {
		if (!button) {
			return;
		}
		state = state || 'default';
		clearResetTimer(button);
		button.dataset.state = state;

		var buttonText = textForState(button, state);
		button.textContent = buttonText;

		var ariaLabel = ariaForState(button, state, message);
		if (ariaLabel) {
			button.setAttribute('aria-label', ariaLabel);
		} else {
			button.removeAttribute('aria-label');
		}

		var status = button.parentElement ? button.parentElement.querySelector('.wp-banana-test-status') : null;
		if (status) {
			if (status.dataset) {
				status.dataset.state = state;
			}
			status.textContent = statusForState(button, state, message);
		}

		if ('loading' === state) {
			button.disabled = true;
			button.classList.add('is-loading');
		} else {
			button.classList.remove('is-loading');
			if ('default' === state) {
				setAvailabilityFromInput(button);
			} else {
				button.disabled = false;
			}
		}

		if ('success' === state || 'error' === state) {
			scheduleReset(button);
		}
	};

	Array.prototype.forEach.call(buttons, function(button) {
		var input = targetInput(button);
		updateState(button, 'default', '');
		setAvailabilityFromInput(button);

		if (input) {
			['input', 'change'].forEach(function(eventName) {
				input.addEventListener(eventName, function() {
					if ((button.dataset.state || 'default') === 'default') {
						setAvailabilityFromInput(button);
					}
				});
			});
		}

		button.addEventListener('click', function() {
			if (button.disabled) {
				return;
			}

			var provider = getDatasetValue(button, 'provider');
			if (!provider) {
				return;
			}

			var inputEl = targetInput(button);
			var apiKey = '';
			if (inputEl && !inputEl.disabled) {
				apiKey = typeof inputEl.value === 'string' ? inputEl.value.trim() : '';
				if (!apiKey) {
					setAvailabilityFromInput(button);
					return;
				}
			}

			updateState(button, 'loading', getDatasetValue(button, 'statusLoading', cfg.testing));

			var payload = new URLSearchParams();
			payload.append('action', 'wp_banana_test_provider');
			payload.append('nonce', cfg.nonce || '');
			payload.append('provider', provider);
			payload.append('apiKey', apiKey);

			var handleError = function(fallbackMessage) {
				updateState(button, 'error', fallbackMessage || cfg.genericError);
			};

			window.fetch(ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: payload.toString()
			})
				.then(function(response) {
					if (!response.ok) {
						throw response;
					}
					return response.json();
				})
				.then(function(data) {
					if (data && data.success) {
						var message = data.data && data.data.message ? data.data.message : getDatasetValue(button, 'statusSuccess', cfg.success);
						updateState(button, 'success', message);
					} else {
						var message = data && data.data && data.data.message ? data.data.message : getDatasetValue(button, 'statusError', cfg.genericError);
						updateState(button, 'error', message);
					}
				})
				.catch(function(error) {
					if (error && typeof error.json === 'function') {
						error.json().then(function(errorData) {
							var message = errorData && errorData.data && errorData.data.message ? errorData.data.message : getDatasetValue(button, 'statusError', cfg.genericError);
							handleError(message);
						}).catch(function() {
							handleError(getDatasetValue(button, 'statusError', cfg.genericError));
						});
					} else {
						handleError(getDatasetValue(button, 'statusError', cfg.genericError));
					}
				});
		});
	});
})();
