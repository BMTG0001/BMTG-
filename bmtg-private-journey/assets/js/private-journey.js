/* BMTG Private Journey — frontend behaviour.
   Progressive enhancement only: every form works without JavaScript. */
(function () {
	"use strict";

	var config = window.BmtgPjConfig || {};

	function ready(fn) {
		if (document.readyState !== "loading") {
			fn();
		} else {
			document.addEventListener("DOMContentLoaded", fn);
		}
	}

	ready(function () {
		var toggle = document.querySelector(".pj-nav-toggle");
		var nav = document.getElementById("pj-primary-nav");

		if (toggle && nav) {
			toggle.addEventListener("click", function () {
				var open = nav.classList.toggle("is-open");
				toggle.setAttribute("aria-expanded", open ? "true" : "false");
			});
		}

		if (config.googleOn && config.googleId && window.google && document.querySelector("[data-pj-google]")) {
			initGoogle();
		}
	});

	function initGoogle() {
		try {
			google.accounts.id.initialize({
				client_id: config.googleId,
				callback: onGoogleCredential
			});
			var container = document.querySelector("[data-pj-google]");
			google.accounts.id.renderButton(container, {
				theme: "outline",
				size: "large",
				width: Math.min(container.offsetWidth || 320, 400),
				text: "signin_with",
				shape: "rectangular"
			});
		} catch (error) {
			/* Google sign-in stays optional. */
		}
	}

	function onGoogleCredential(response) {
		if (!response || !response.credential) {
			return;
		}
		fetch(config.restUrl + "auth/google", {
			method: "POST",
			credentials: "same-origin",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": config.nonce
			},
			body: JSON.stringify({ credential: response.credential })
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (data) {
				if (data && data.ok && data.redirect) {
					window.location.href = data.redirect;
				} else {
					window.alert((data && data.error) || (config.i18n && config.i18n.error) || "Sign-in failed.");
				}
			})
			.catch(function () {
				window.alert((config.i18n && config.i18n.error) || "Sign-in failed.");
			});
	}
})();