/**
 * Updates the opportunities header statistics after dismissing an opportunity
 *
 * @param {string} impactLevel - The impact level of the dismissed opportunity (high, medium, low)
 */
const updateOpportunitiesHeaderStats = impactLevel => {
	const header = jQuery(".pmw-opportunities-header")
	if (!header.length) return

	// Update total available count
	const totalCard = header.find(".pmw-stat-card.total .pmw-stat-card-count")
	if (totalCard.length) {
		const newTotal = Math.max(0, parseInt(totalCard.text(), 10) - 1)
		totalCard.text(newTotal)

		// If total is now 0, show the "all caught up" state
		if (newTotal === 0) {
			showAllCaughtUpState(header)
			return
		}
	}

	// Update specific impact count
	const impactCard = header.find(`.pmw-stat-card.impact-${impactLevel} .pmw-stat-card-count`)
	if (impactCard.length) {
		const newCount = Math.max(0, parseInt(impactCard.text(), 10) - 1)
		impactCard.text(newCount)
	}

	// Update dismissed count
	updateDismissedCount(header, 1)
}

/**
 * Updates or creates the dismissed count stat card
 *
 * @param {jQuery} header - The header element
 * @param {number} increment - Amount to increment by
 */
const updateDismissedCount = (header, increment) => {
	const dismissedCard = header.find(".pmw-stat-card.dismissed")
	if (dismissedCard.length) {
		const countEl = dismissedCard.find(".pmw-stat-card-count")
		const newCount = parseInt(countEl.text(), 10) + increment
		countEl.text(newCount)
	} else {
		// Create dismissed card if it doesn't exist
		const dismissedHtml = `
			<div class="pmw-stat-card dismissed">
				<div class="pmw-stat-card-count">${increment}</div>
				<div class="pmw-stat-card-label">Dismissed</div>
			</div>
		`
		header.append(dismissedHtml)
	}
}

/**
 * Transforms the header to show the "all caught up" celebration state
 *
 * @param {jQuery} header - The header element
 */
const showAllCaughtUpState = header => {
	// Get current dismissed count
	const dismissedCard = header.find(".pmw-stat-card.dismissed")
	const dismissedCount = dismissedCard.length ? parseInt(dismissedCard.find(".pmw-stat-card-count").text(), 10) + 1 : 1

	// Build dismissed card HTML if count > 0
	const dismissedHtml = dismissedCount > 0 ? `
		<div class="pmw-stat-card dismissed">
			<div class="pmw-stat-card-count">${dismissedCount}</div>
			<div class="pmw-stat-card-label">Dismissed</div>
		</div>
	` : ""

	// Replace header content with celebration state
	header.html(`
		<div class="pmw-opportunities-complete">
			<div class="pmw-opportunities-complete-icon">🎉</div>
			<div class="pmw-opportunities-complete-content">
				<div class="pmw-opportunities-complete-title">All caught up!</div>
				<div class="pmw-opportunities-complete-text">You have addressed all available opportunities. Great job optimizing your tracking setup!</div>
			</div>
			${dismissedHtml}
		</div>
	`)
}

/**
 * Sends the notification details to the server
 *
 * @param input
 * @param input.element
 * @param input.type
 * @param input.id
 */
const sendPmwNotificationDetails = input => {

	fetch(pmwNotificationsApi.root + "pmw/v1/notifications/", {
		method : "POST",
		cache  : "no-cache",
		headers: {
			"Content-Type": "application/json",
			"X-WP-Nonce"  : pmwNotificationsApi.nonce,
		},
		body   : JSON.stringify({
			// notification: jQuery(e.target).attr("id"),
			type: input.type,
			id  : input.id,
		}),
	})
		.then(response => {
			if (response.ok) {
				return response.json()
			}
		})
		.then(data => {
			if (data.success) {

				if (input.type === "generic-notification") {
					input.element.closest(".notice").fadeOut(300, () => {
						input.element.remove()
					})
				}

				if (input.type === "dismiss_opportunity") {
					// Get the opportunity card (parent .pmw wrapper containing .opportunity-card)
					const opportunityCard = input.element.closest(".pmw")
					// Get the impact level before moving the card
					const impactLevel = opportunityCard.find(".opportunity-card-top-impact-level")
						.attr("class")
						.match(/impact-(high|medium|low)/)?.[1] || "low"
					// Move the entire card to the dismissed section
					opportunityCard.appendTo("#pmw-dismissed-opportunities")
					// Remove the dismiss button from the card
					input.element.closest(".opportunity-card-button-link").remove()
					// Add the dismissed class to the card
					opportunityCard.find(".opportunity-card").addClass("dismissed")
					// Update the header statistics
					updateOpportunitiesHeaderStats(impactLevel)
				}

				if (input.type === "dismiss_notification") {
					input.element.closest(".pmw.notification").fadeOut(300, () => {
						input.element.remove()
					})
				}
			}
		})
}

/**
 * Dismisses a generic notification
 */
jQuery(document).on("click", ".pmw-notification-dismiss-button, .incompatible-plugin-error-dismissal-button", e => {

	sendPmwNotificationDetails({
		type   : "generic-notification",
		element: jQuery(e.target),
		id     : jQuery(e.target).attr("data-notification-id"),
	})
})

/**
 * Dismisses an opportunity
 */
jQuery(document).on("click", ".pmw .opportunity-dismiss", (e) => {
	
	sendPmwNotificationDetails({
		type   : "dismiss_opportunity",
		element: jQuery(e.target),
		id     : jQuery(e.target).attr("data-opportunity-id"),
	})
})

/**
 * Dismisses an opportunity
 */
jQuery(document).on("click", ".pmw .notification-dismiss", (e) => {

	sendPmwNotificationDetails({
		type   : "dismiss_notification",
		element: jQuery(e.target),
		id     : jQuery(e.target).parent().attr("data-notification-id"),
	})
})
