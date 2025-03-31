jQuery(function ($) {

	// Get the data element order-id from the div with ID order-attribution-data
	let orderId = $("#order-attribution-data").data("order-id");

	let data = {
		order_id: orderId,
	};

	// use fetch to post data to the server
	fetch(pmwAdminApi.root + "pmw/v1/ga4/data-api/get-order-attribution-data/", {
		method     : "POST",
		credentials: "same-origin",
		headers    : {
			"Content-Type": "application/json",
			"X-WP-Nonce"  : pmwAdminApi.nonce,
		},
		body       : JSON.stringify(data),
	})
		.then(response => response.json())
		.then(async message => {
			// If message.success is true, then we have data to display
			if (message.success) {
				$("#order-attribution-data").html(message.data);
			} else {
				// If message.success is false, then we have an error to display
				// console.log(message)
				$("#order-attribution-data").html(message.data);
			}
		});
});

