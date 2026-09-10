/*
 * The back-to-top control. Its own block rather than part of the motion script
 * below, because it has to work for a reader who asked for less motion — that
 * script returns early for them, and this one must not.
 */
(function () {
	var button = document.querySelector('.to-top');

	if (!button) {
		return;
	}

	var SHOW_AFTER = 500;
	var pending = false;

	function sync() {
		pending = false;
		button.classList.toggle('is-top', window.pageYOffset < SHOW_AFTER);
	}

	function onScroll() {
		if (!pending) {
			pending = true;
			requestAnimationFrame(sync);
		}
	}

	sync();
	window.addEventListener('scroll', onScroll, { passive: true });
	window.addEventListener('resize', onScroll, { passive: true });

	// The href is the fallback for a page with no JavaScript. Where there is
	// some, scroll explicitly rather than trusting the anchor: it depends on
	// nothing above it in the document, and it leaves no #top on the URL for the
	// reader to carry around or share.
	button.addEventListener('click', function (event) {
		event.preventDefault();

		var smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

		try {
			window.scrollTo({ top: 0, behavior: smooth ? 'smooth' : 'auto' });
		} catch (e) {
			window.scrollTo(0, 0);
		}
	});
})();

/*
 * The FAQ accordion. <details> gives the behaviour, keyboard handling and a
 * working page with no script; all this adds is the height between the two
 * states, which the element does not animate on its own.
 *
 * The trick worth knowing: while CLOSING, `open` has to stay on until the
 * animation finishes, or the browser hides the content instantly and there is
 * nothing left to animate. So the click is taken over, and `open` is removed
 * only at the end.
 */
(function () {
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
		return;
	}

	if (!Element.prototype.animate) {
		return;
	}

	var EASING = 'cubic-bezier(.2, .7, .3, 1)';

	[].slice.call(document.querySelectorAll('.faq details')).forEach(function (details) {
		var summary = details.querySelector('summary');
		var body = details.querySelector('.faq-body');

		if (!summary || !body) {
			return;
		}

		var running = null;

		function play(from, to, closing) {
			if (running) {
				running.cancel();
			}

			body.style.height = from + 'px';

			running = body.animate(
				{ height: [from + 'px', to + 'px'] },
				{ duration: 260, easing: EASING }
			);

			running.onfinish = function () {
				running = null;
				body.style.height = '';

				if (closing) {
					details.open = false;
				}
			};

			running.oncancel = function () {
				running = null;
			};
		}

		summary.addEventListener('click', function (event) {
			event.preventDefault();

			if (details.open) {
				play(body.offsetHeight, 0, true);
				return;
			}

			details.open = true;
			play(0, body.offsetHeight, false);
		});
	});
})();
