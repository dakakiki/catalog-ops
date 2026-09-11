/*
 * Counters. The final value is already in the HTML, so the page is correct with
 * JavaScript off, in a thumbnail, and for anyone who asked for less motion —
 * this only replaces a settled number with the same number, arrived at.
 *
 * The price cells count from their OLD value rather than from zero, because
 * that is the thing the product actually does: it moves a price from one number
 * to another, and watching it move says more than the pair sitting still.
 */
(function () {
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
		return;
	}

	var DURATION = 850;

	function format(value, decimals) {
		return decimals
			? value.toFixed(decimals)
			: Math.round(value).toLocaleString('en-US');
	}

	function run(el) {
		var to = parseFloat(el.getAttribute('data-to'));
		var fromAttr = el.getAttribute('data-from');
		var from = null === fromAttr ? 0 : parseFloat(fromAttr);
		var decimals = parseInt(el.getAttribute('data-dec') || '0', 10);

		if (isNaN(to)) {
			return;
		}

		var started = null;

		function frame(now) {
			if (null === started) {
				started = now;
			}

			var progress = Math.min(1, (now - started) / DURATION);
			// Ease out cubic: fast first, settling rather than stopping.
			var eased = 1 - Math.pow(1 - progress, 3);

			el.textContent = format(from + (to - from) * eased, decimals);

			if (progress < 1) {
				requestAnimationFrame(frame);
			}
		}

		requestAnimationFrame(frame);
	}

	var ticks = [].slice.call(document.querySelectorAll('.tick'));
	var reveals = [].slice.call(document.querySelectorAll('.reveal'));

	if (!('IntersectionObserver' in window)) {
		ticks.forEach(run);
		return;
	}

	/*
	 * The order here is the safety net, and it replaces one that was worse: a
	 * blanket timeout that uncovered every section a second and a half after
	 * load. That defeated the whole thing — by the time a reader scrolled to the
	 * pricing, the timer had already revealed it, so everything below the first
	 * screen simply appeared, finished.
	 *
	 * Instead, nothing is hidden until the observer exists AND every section is
	 * being watched. If any of it throws, the attribute is never set, no CSS
	 * rule applies, and the page is just a finished page.
	 */
	try {
		var revealObserver = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					revealObserver.unobserve(entry.target);
					entry.target.classList.add('is-in');
				}
			});
		}, { threshold: 0.1, rootMargin: '0px 0px -6% 0px' });

		reveals.forEach(function (el) {
			revealObserver.observe(el);
		});

		document.documentElement.setAttribute('data-reveal', 'on');
	} catch (e) {
		reveals.forEach(function (el) {
			el.classList.add('is-in');
		});
	}

	// Each counter runs once, when it is actually on screen — a number that
	// finished counting while the reader was three sections above it has only
	// spent motion for nothing.
	var observer = new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) {
			if (entry.isIntersecting) {
				observer.unobserve(entry.target);
				run(entry.target);
			}
		});
	}, { threshold: 0.4 });

	ticks.forEach(function (el) {
		observer.observe(el);
	});
})();
