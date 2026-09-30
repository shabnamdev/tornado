(function () {
	'use strict';

	var REVEAL_CLASS = 'shcd-tornado-dbm-image-box-reveal';
	var observed = new WeakSet();
	var resizeTimer = 0;

	function number(value) {
		var parsed = parseFloat(value);
		return Number.isFinite(parsed) ? parsed : 0;
	}

	function isResponsiveHidden(element) {
		var width = window.innerWidth || document.documentElement.clientWidth || 0;
		var node = element;

		while (node && node.nodeType === 1) {
			if (width >= 1025 && node.classList.contains('elementor-hidden-desktop')) {
				return true;
			}
			if (width >= 768 && width < 1025 && node.classList.contains('elementor-hidden-tablet')) {
				return true;
			}
			if (width < 768 && node.classList.contains('elementor-hidden-mobile')) {
				return true;
			}
			node = node.parentElement;
		}

		return false;
	}

	function isCollapsed(element) {
		if (!element) {
			return true;
		}

		var style = window.getComputedStyle(element);
		var rect = element.getBoundingClientRect();
		return style.display === 'none'
			|| style.visibility === 'hidden'
			|| style.visibility === 'collapse'
			|| number(style.opacity) <= 0.001
			|| rect.width < 1
			|| rect.height < 1;
	}

	function inspect(widget) {
		if (!widget || widget.nodeType !== 1 || isResponsiveHidden(widget)) {
			return;
		}

		var figure = widget.querySelector('.elementor-image-box-img');
		var image = figure ? figure.querySelector('img[src], img[data-src], img[data-lazy-src]') : null;
		if (!figure || !image) {
			return;
		}

		var source = image.getAttribute('src') || image.getAttribute('data-src') || image.getAttribute('data-lazy-src') || '';
		if (!source || source.indexOf('data:image/svg+xml') === 0) {
			return;
		}

		window.requestAnimationFrame(function () {
			if (isCollapsed(figure) || isCollapsed(image)) {
				widget.classList.add(REVEAL_CLASS);
			}
		});

		if (!observed.has(image)) {
			observed.add(image);
			image.addEventListener('load', function () {
				window.requestAnimationFrame(function () {
					if (isCollapsed(figure) || isCollapsed(image)) {
						widget.classList.add(REVEAL_CLASS);
					}
				});
			}, { once: true });
		}
	}

	function scan(root) {
		var scope = root && root.querySelectorAll ? root : document;
		if (scope.matches && scope.matches('.elementor-widget-image-box')) {
			inspect(scope);
		}
		scope.querySelectorAll('.elementor-widget-image-box').forEach(inspect);
	}

	function boot() {
		scan(document);

		var observer = new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				mutation.addedNodes.forEach(function (node) {
					if (node && node.nodeType === 1) {
						scan(node);
					}
				});
			});
		});
		observer.observe(document.documentElement, { childList: true, subtree: true });

		window.addEventListener('resize', function () {
			window.clearTimeout(resizeTimer);
			resizeTimer = window.setTimeout(function () {
				scan(document);
			}, 120);
		}, { passive: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot, { once: true });
	} else {
		boot();
	}

	if (window.jQuery) {
		window.jQuery(window).on('elementor/frontend/init', function () {
			if (window.elementorFrontend && window.elementorFrontend.hooks) {
				window.elementorFrontend.hooks.addAction('frontend/element_ready/image-box.default', function ($scope) {
					if ($scope && $scope[0]) {
						inspect($scope[0]);
					}
				});
			}
			scan(document);
		});
	}
}());
