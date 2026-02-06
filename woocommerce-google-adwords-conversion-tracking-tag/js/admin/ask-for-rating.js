jQuery(function () {

	const queryString = window.location.search
	const urlParams   = new URLSearchParams(queryString)
	const pageParam   = urlParams.get("page")

	// Show the rating notice on dashboard and PMW settings pages
	if ("/wp-admin/index.php" === location.pathname || "/wp-admin/" === location.pathname || "pmw" === pageParam) {
		jQuery("#pmw-rating-notice").css("display", "flex")
	}

	// Handle "Leave a Review" button click - also dismiss the notice
	jQuery(document).on("click", "#pmw-rate-it", function () {
		sendRatingAction("rating_done")
		// Link opens in new tab via href, so just hide the notice
		jQuery("#pmw-rating-notice").fadeOut(300)
	})

	// Handle secondary action buttons (Already reviewed, Maybe later)
	jQuery(document).on("click", ".pmw-rating-dismiss-button", function (e) {
		e.preventDefault()
		const action = jQuery(this).data("action")
		sendRatingAction(action)
		jQuery("#pmw-rating-notice").fadeOut(300)
	})

	/**
	 * Send the rating action to the server via AJAX
	 *
	 * @param {string} action - The action to perform: 'rating_done' or 'later'
	 */
	function sendRatingAction(action) {
		jQuery.post(ajax_var.url, {
			action: "pmw_dismissed_notice_handler",
			nonce : ajax_var.nonce,
			set   : action,
		})
	}
})
