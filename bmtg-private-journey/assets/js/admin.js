/* BMTG Private Journey — admin behaviour */
(function () {
	"use strict";

	function ready(fn) {
		if (document.readyState !== "loading") {
			fn();
		} else {
			document.addEventListener("DOMContentLoaded", fn);
		}
	}

	ready(function () {
		document.addEventListener("click", function (event) {
			var target = event.target;

			var mediaButton = target.closest(".pj-media-select");
			if (mediaButton) {
				event.preventDefault();
				openMedia(mediaButton);
				return;
			}

			var clearButton = target.closest(".pj-media-clear");
			if (clearButton) {
				event.preventDefault();
				var field = clearButton.closest(".pj-media-field");
				if (field) {
					var input = field.querySelector("input[type=hidden]");
					var img = field.querySelector("img");
					if (input) {
						input.value = "0";
					}
					if (img) {
						img.removeAttribute("src");
					}
				}
				return;
			}

			var del = target.closest(".button-link-delete");
			if (del && !window.confirm("Are you sure you want to delete this item?")) {
				event.preventDefault();
			}
		});
	});

	function openMedia(button) {
		if (!window.wp || !wp.media) {
			return;
		}
		var field = button.closest(".pj-media-field");
		if (!field) {
			return;
		}
		var frame = wp.media({
			title: "Select image",
			button: { text: "Use image" },
			multiple: false,
			library: { type: "image" }
		});
		frame.on("select", function () {
			var attachment = frame.state().get("selection").first().toJSON();
			var input = field.querySelector("input[type=hidden]");
			var img = field.querySelector("img");
			if (input) {
				input.value = attachment.id;
			}
			if (img) {
				img.src = attachment.url;
			}
		});
		frame.open();
	}
})();