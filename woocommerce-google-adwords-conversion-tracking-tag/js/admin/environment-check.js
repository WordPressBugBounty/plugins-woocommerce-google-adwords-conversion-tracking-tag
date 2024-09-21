// jQuery(function () {
//
// 	console.log('environment-check.js loaded')
//
// 	// disable incompatible plugin warning
// 	jQuery(document).on("click", ".incompatible-plugin-error-dismissal-button", function (e) {
// 		e.preventDefault()
//
// 		let data = {
// 			action         : "environment_check_handler",
// 			nonce          : ajax_var.nonce,
// 			disable_warning: jQuery(this).data("plugin-slug"),
// 		}
//
// 		wpm_send_ajax_data(data)
// 	})
//
// 	// dismiss PayPal standard payment gateway warning
// 	jQuery(document).on("click", "#wpm-paypal-standard-error-dismissal-button", function (e) {
// 		e.preventDefault()
//
// 		let data = {
// 			action: "environment_check_handler",
// 			nonce : ajax_var.nonce,
// 			set   : "dismiss_paypal_standard_warning",
// 		}
//
// 		wpm_send_ajax_data(data)
// 	})
// })
//
// function wpm_send_ajax_data(data) {
//
// 	console.log("data", data)
// 	// jQuery.post(ajax_var.url, data, function (response) {
// 	// 	// console.log('Got this from the server: ' + response);
// 	// 	// console.log('update rating done');
// 	// 	location.reload()
// 	// })
// }
