(() => {
	// Find all products with pending statuses
	function collectPendingProductIds() {
		const pendingStatuses = document.querySelectorAll(
			".productbird-status-queued, .productbird-status-running",
		);

		const productIds = [];
		for (const element of pendingStatuses) {
			if (!element) continue;
			const productId = element.closest(".productbird-status").dataset
				.productId;
			if (productId) {
				productIds.push(Number.parseInt(productId, 10));
			}
		}

		return productIds;
	}

	// Update the status badges based on API response
	function updateStatusBadges(statuses) {
		for (const [productId, status] of Object.entries(statuses)) {
			const statusEl = document.querySelector(
				`.productbird-status[data-product-id="${productId}"]`,
			);
			if (!statusEl) continue;

			// Clear current content
			statusEl.innerHTML = "";

			// Create new status element based on response
			let newStatusHtml = "";

			if (status === "completed") {
				newStatusHtml =
					'<span class="productbird-status-completed" title="Description generated successfully"><span class="dashicons dashicons-yes-alt"></span></span>';
			} else if (status === "error") {
				newStatusHtml =
					'<span class="productbird-status-error" title="Error generating description"><span class="dashicons dashicons-no-alt"></span></span>';
			} else if (status === "queued") {
				newStatusHtml =
					'<span class="productbird-status-queued" title="Description generation queued"><span class="dashicons dashicons-clock"></span></span>';
			} else if (status === "none") {
				newStatusHtml =
					'<span class="productbird-status-none" title="No AI description requested">—</span>';
			} else {
				// Default to "running" for any other status
				newStatusHtml =
					'<span class="productbird-status-running" title="Description generation in progress"><span class="dashicons dashicons-update"></span></span>';
			}

			statusEl.innerHTML = newStatusHtml;
		}
	}

	function checkStatuses() {
		const productIds = collectPendingProductIds();
		if (productIds.length === 0) return;

		fetch(productbirdStatus.restUrl, {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": productbirdStatus.nonce,
			},
			body: JSON.stringify({ productIds: productIds }),
		})
			.then((response) => response.json())
			.then((data) => {
				if (data && typeof data === "object") {
					updateStatusBadges(data);
				}
			})
			.catch((error) =>
				console.error("Productbird status check failed:", error),
			);
	}

	checkStatuses();
	setInterval(checkStatuses, productbirdStatus.pollInterval);
})();
