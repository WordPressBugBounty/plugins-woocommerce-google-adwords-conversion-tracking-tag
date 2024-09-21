jQuery(function () {

	const queryString = window.location.search
	const urlParams   = new URLSearchParams(queryString)

	let pageParam = urlParams.get("page")

	if ("/wp-admin/index.php" === location.pathname || "/wp-admin/" === location.pathname || "wpm" === pageParam) {
		jQuery(".wpm-rating-success-notice").show()
	}

	// go and rate it or already done
	jQuery(document).on("click", "#wpm-rate-it", function (e) {
		process_click(e, "rating_done")

		let win = window.open("https://wordpress.org/support/view/plugin-reviews/woocommerce-google-adwords-conversion-tracking-tag?rate=5#postform", "_blank")
		win.focus()
	})

	jQuery(document).on("click", "#wpm-already-did", function (e) {
		process_click(e, "rating_done")
	})

	// maybe rate later
	jQuery(document).on("click", "#wpm-maybe-later", function (e) {
		process_click(e, "later")
	})

	function process_click(e, set) {

		e.preventDefault()

		let data = {
			action: "wpm_dismissed_notice_handler",
			nonce : ajax_var.nonce,
			set   : set,
		}

		jQuery.post(ajax_var.url, data, (response) => {
			// console.log('Got this from the server: ' + response);
			// console.log('update rating done');
		})

		jQuery(".wpm-rating-success-notice").remove()
	}
})
