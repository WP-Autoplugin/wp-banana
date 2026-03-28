/* global wpBananaLogs */
(function() {
	var table = document.querySelector('.wp-list-table');
	if (!table) {
		return;
	}

	var escapeHtml = function(value) {
		if (typeof value !== 'string') {
			return '';
		}
		return value
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	};

	var buildSection = function(title, content) {
		if (!content) {
			return '';
		}
		return '<div class="wp-banana-log-expanded__section">' +
			'<h4>' + escapeHtml(title) + '</h4>' +
			'<pre>' + escapeHtml(content) + '</pre>' +
		'</div>';
	};

	table.addEventListener('click', function(event) {
		var button = event.target.closest('.wp-banana-log-toggle');
		if (!button) {
			return;
		}
		event.preventDefault();

		var row = button.closest('tr');
		if (!row) {
			return;
		}

		var existing = row.nextElementSibling;
		if (existing && existing.classList.contains('wp-banana-log-expanded')) {
			existing.remove();
			button.setAttribute('aria-expanded', 'false');
			button.textContent = button.dataset.openLabel || button.textContent;
			return;
		}

		var targetId = button.dataset.logId;
		if (!targetId) {
			return;
		}

		var container = document.getElementById(targetId);
		if (!container) {
			return;
		}

		var data;
		try {
			data = JSON.parse(container.textContent);
		} catch (error) {
			return;
		}

		var sections = [];
		var labels = (window.wpBananaLogs && window.wpBananaLogs.labels) ? window.wpBananaLogs.labels : {};

		if (data && data.request) {
			sections.push(buildSection(labels.request || 'Request', data.request));
		}
		if (data && data.response) {
			sections.push(buildSection(labels.response || 'Response', data.response));
		}
		if (data && data.error) {
			sections.push(buildSection(labels.error || 'Error', data.error));
		}

		if (!sections.length) {
			return;
		}

		var expandedRow = document.createElement('tr');
		expandedRow.className = 'wp-banana-log-expanded';

		var cell = document.createElement('td');
		cell.colSpan = row.children.length;
		cell.innerHTML = '<div class="wp-banana-log-expanded__sections">' + sections.join('') + '</div>';

		expandedRow.appendChild(cell);
		row.parentNode.insertBefore(expandedRow, row.nextSibling);

		button.setAttribute('aria-expanded', 'true');
		button.textContent = button.dataset.closeLabel || button.textContent;
	});
})();
