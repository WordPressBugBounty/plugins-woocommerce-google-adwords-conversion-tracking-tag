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
					input.element.appendTo(".pmw-opportunity-dismissed")
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
