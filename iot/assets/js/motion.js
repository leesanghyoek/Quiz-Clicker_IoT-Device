/*
 * Motion layer
 * ------------
 * Purely presentational; nothing here changes page data or form behaviour.
 *
 *   - One entrance per page: the title condenses out of the mist, the sheet
 *     rises from the lake, and on the first visit of a session the menu
 *     words surface onto the waterline.
 *   - The horizon pins to the top while scrolling; the sheet slips under it.
 *   - Choosing a section lights its lantern before the next page loads.
 *   - Pressed buttons send a ring across the water.
 *   - Smooth mouse-wheel scrolling and a way back to the surface.
 *   - Live counters on the classroom screens set lanterns on the lake.
 */
(function () {
    'use strict';

    var root = document.documentElement;
    var body = document.body;
    var reduced = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var isPresentation = body.classList.contains('lobby-page')
        || body.classList.contains('quiz-run-page');

    var ARRIVED_KEY = 'ar-arrived';
    var LIFTED_HEADER = [
        '.main-content > .page-header',
        '.main-content > .management-page-header',
        '.main-content > .results-detail-header',
        '.main-content > .question-result-header'
    ].join(',');
    var LIFTED_FORM_TITLE = '.main-content > .form-card:first-child > h1:first-child';

    var nav = document.querySelector('.horizon');
    var sheet = body.classList.contains('has-horizon')
        ? document.querySelector('.main-content')
        : null;

    root.classList.add('motion-ready');

    function readSession(key) {
        try {
            return window.sessionStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function writeSession(key, value) {
        try {
            window.sessionStorage.setItem(key, value);
        } catch (error) {
            // Without storage the menu simply surfaces on every page.
        }
    }

    /* ---------------------------------------------------------------
     * Entrance
     * ------------------------------------------------------------- */
    function splitIntoWords(element, start) {
        var index = start || 0;

        Array.prototype.slice.call(element.childNodes).forEach(function (node) {
            if (node.nodeType === 1 && !node.classList.contains('mist-word')) {
                index = splitIntoWords(node, index);
                return;
            }

            if (node.nodeType !== 3 || !node.textContent.trim()) {
                return;
            }

            var fragment = document.createDocumentFragment();

            node.textContent.split(/(\s+)/).forEach(function (part) {
                if (!part) {
                    return;
                }

                if (/^\s+$/.test(part)) {
                    fragment.appendChild(document.createTextNode(part));
                    return;
                }

                var word = document.createElement('span');

                word.className = 'mist-word';
                word.style.setProperty('--i', String(index));
                word.textContent = part;
                fragment.appendChild(word);
                index += 1;
            });

            node.parentNode.replaceChild(fragment, node);
        });

        return index;
    }

    function finishLater(element, className, duration) {
        window.setTimeout(function () {
            element.classList.remove(className);
        }, duration);
    }

    function initEntrance() {
        if (reduced) {
            root.classList.remove('motion-boot');
            return;
        }

        var titleHost = document.querySelector(LIFTED_HEADER)
            || document.querySelector(LIFTED_FORM_TITLE)
            || document.querySelector('.lake-title');
        var title = titleHost && (titleHost.matches('h1') ? titleHost : titleHost.querySelector('h1'));

        if (title) {
            var words = splitIntoWords(title);

            titleHost.classList.add('is-condensing');
            finishLater(titleHost, 'is-condensing', 1400 + words * 90);
        }

        if (sheet) {
            sheet.classList.add('is-surfacing');
            // Drop the animation afterwards so menus are not trapped in its layers.
            finishLater(sheet, 'is-surfacing', 1400);
        }

        if (nav && readSession(ARRIVED_KEY) !== '1') {
            nav.querySelectorAll('.horizon-link').forEach(function (link, index) {
                link.style.setProperty('--i', String(index));
            });
            nav.classList.add('is-first-arrival');
            finishLater(nav, 'is-first-arrival', 1800);
            writeSession(ARRIVED_KEY, '1');
        }

        root.classList.remove('motion-boot');
    }

    /* ---------------------------------------------------------------
     * Horizon: pinned while scrolling, the sheet slips beneath it
     * ------------------------------------------------------------- */
    function initHorizon() {
        if (!nav || !sheet) {
            return;
        }

        var band = document.getElementById('sky-band');
        var ticking = false;
        var lastCut = -1;

        function update() {
            ticking = false;

            var navBox = nav.getBoundingClientRect();
            var stuck = band ? band.getBoundingClientRect().bottom <= 0.5 : navBox.top <= 0.5;
            var cut = Math.max(0, Math.round(navBox.bottom - sheet.getBoundingClientRect().top));

            nav.classList.toggle('is-stuck', stuck);

            if (cut !== lastCut) {
                lastCut = cut;
                sheet.style.clipPath = cut > 0
                    ? 'inset(' + cut + 'px -240px -240px -240px)'
                    : '';
            }
        }

        function request() {
            if (!ticking) {
                ticking = true;
                window.requestAnimationFrame(update);
            }
        }

        // Clip in the scroll handler itself so the edge never trails the menu.
        window.addEventListener('scroll', update, {passive: true});
        window.addEventListener('resize', request);
        update();

        nav.addEventListener('click', function (event) {
            var link = event.target instanceof Element && event.target.closest('.horizon-link');

            if (
                !link
                || event.defaultPrevented
                || event.button !== 0
                || event.metaKey
                || event.ctrlKey
                || event.shiftKey
                || event.altKey
                || link.classList.contains('is-current') && link.getAttribute('aria-current') === 'page'
            ) {
                return;
            }

            nav.classList.add('is-leaving');
            link.classList.add('is-arriving');
        });

        // Coming back with the back button shows the page exactly as it was left.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                nav.classList.remove('is-leaving');
                nav.querySelectorAll('.is-arriving').forEach(function (link) {
                    link.classList.remove('is-arriving');
                });
            }
        });
    }

    /* ---------------------------------------------------------------
     * Smooth mouse-wheel scrolling for the page
     * ------------------------------------------------------------- */
    function canScrollInside(element, deltaY) {
        while (element && element !== body && element !== root) {
            if (element.nodeType === 1) {
                var style = window.getComputedStyle(element);
                var overflowY = style.overflowY;

                if (
                    (overflowY === 'auto' || overflowY === 'scroll' || overflowY === 'overlay')
                    && element.scrollHeight > element.clientHeight + 1
                ) {
                    if (deltaY > 0
                        ? element.scrollTop + element.clientHeight < element.scrollHeight - 1
                        : element.scrollTop > 0) {
                        return true;
                    }
                }
            }

            element = element.parentElement;
        }

        return false;
    }

    var scroller = {
        target: 0,
        current: 0,
        frame: 0,
        lastWritten: -1,
        lastTime: 0,
        active: false
    };

    function maxScroll() {
        return Math.max(0, root.scrollHeight - window.innerHeight);
    }

    function scrollStep(now) {
        var difference = scroller.target - scroller.current;
        // Time-based easing (~0.14 per frame at 60 fps) so slow frames do not drag.
        var elapsed = Math.min(100, Math.max(1, now - (scroller.lastTime || now - 16.7)));

        scroller.lastTime = now;
        scroller.current += difference * (1 - Math.exp(-elapsed * 0.009));

        if (Math.abs(difference) < 0.6) {
            scroller.current = scroller.target;
        }

        scroller.lastWritten = Math.round(scroller.current);
        window.scrollTo({top: scroller.current, behavior: 'instant'});

        if (scroller.current !== scroller.target) {
            scroller.frame = window.requestAnimationFrame(scrollStep);
        } else {
            scroller.frame = 0;
            scroller.active = false;
            scroller.lastTime = 0;
        }
    }

    function smoothScrollTo(top) {
        if (reduced) {
            window.scrollTo(0, top);
            return;
        }

        if (!scroller.active) {
            // Halt any native smooth scroll (Tabler sets scroll-behavior: smooth)
            // so its scroll events are not mistaken for the user taking over.
            window.scrollTo({top: window.scrollY, behavior: 'instant'});
            scroller.current = window.scrollY;
            scroller.lastWritten = Math.round(window.scrollY);
        }

        scroller.target = Math.min(maxScroll(), Math.max(0, top));
        scroller.active = true;

        if (!scroller.frame) {
            scroller.frame = window.requestAnimationFrame(scrollStep);
        }
    }

    function stopSmoothScroll() {
        window.cancelAnimationFrame(scroller.frame);
        scroller.frame = 0;
        scroller.active = false;
        scroller.lastTime = 0;
    }

    function initSmoothWheel() {
        if (reduced || isPresentation) {
            return;
        }

        window.addEventListener('wheel', function (event) {
            if (
                event.defaultPrevented
                || event.ctrlKey
                || event.metaKey
                || event.shiftKey
                || event.altKey
                || Math.abs(event.deltaX) > Math.abs(event.deltaY)
                || body.classList.contains('swal2-shown')
                || document.querySelector('dialog[open]')
                || maxScroll() <= 0
            ) {
                return;
            }

            var deltaY = event.deltaY;

            if (event.deltaMode === 1) {
                deltaY *= 40;
            } else if (event.deltaMode === 2) {
                deltaY *= window.innerHeight;
            }

            if (canScrollInside(event.target, deltaY)) {
                return;
            }

            event.preventDefault();
            smoothScrollTo((scroller.active ? scroller.target : window.scrollY) + deltaY);
        }, {passive: false});

        // Scrollbar drags, keyboard and find-in-page take over immediately.
        window.addEventListener('scroll', function () {
            if (scroller.active && Math.abs(window.scrollY - scroller.lastWritten) > 3) {
                stopSmoothScroll();
            }
        }, {passive: true});

        ['keydown', 'pointerdown'].forEach(function (type) {
            window.addEventListener(type, function () {
                if (scroller.active) {
                    stopSmoothScroll();
                }
            }, {passive: true});
        });
    }

    /* ---------------------------------------------------------------
     * Back to the surface on long pages
     * ------------------------------------------------------------- */
    function initBackToTop() {
        if (isPresentation || !sheet) {
            return;
        }

        var button = document.createElement('button');

        button.type = 'button';
        button.className = 'back-to-top';
        button.setAttribute('aria-label', 'Back to top');
        button.title = 'Back to top';
        button.innerHTML = '<i class="ti ti-arrow-up" aria-hidden="true"></i>';
        button.addEventListener('click', function () {
            smoothScrollTo(0);
        });
        body.appendChild(button);

        var ticking = false;

        function update() {
            ticking = false;
            button.classList.toggle('is-visible', window.scrollY > window.innerHeight);
        }

        window.addEventListener('scroll', function () {
            if (!ticking) {
                ticking = true;
                window.requestAnimationFrame(update);
            }
        }, {passive: true});

        update();
    }

    /* ---------------------------------------------------------------
     * A ring on the water where a button is pressed
     * ------------------------------------------------------------- */
    var RING_SELECTOR = '.add-button, .save-button, .secondary-button, .cancel-button, '
        + '.danger-button, .quiz-launch-button, .login-container button[type="submit"], '
        + '.quiz-run-next-button, .lobby-start-button';

    function initWaterRings() {
        if (reduced) {
            return;
        }

        document.addEventListener('pointerdown', function (event) {
            if (!(event.target instanceof Element)) {
                return;
            }

            var button = event.target.closest(RING_SELECTOR);

            if (!button || button.disabled) {
                return;
            }

            var rect = button.getBoundingClientRect();
            var size = Math.max(rect.width, rect.height) * 1.6;

            [0, 1].forEach(function () {
                var ring = document.createElement('span');

                ring.className = 'water-ring';
                ring.setAttribute('aria-hidden', 'true');
                ring.style.width = size + 'px';
                ring.style.height = size + 'px';
                ring.style.left = (event.clientX - rect.left - size / 2) + 'px';
                ring.style.top = (event.clientY - rect.top - size / 2) + 'px';
                button.appendChild(ring);

                window.setTimeout(function () {
                    ring.remove();
                }, 1000);
            });
        }, {passive: true});
    }

    /* ---------------------------------------------------------------
     * Classroom screens: counters pulse, participants become lanterns
     * ------------------------------------------------------------- */
    function readCount(id) {
        var element = document.getElementById(id);
        var value = element ? parseInt(element.textContent, 10) : NaN;

        return isNaN(value) ? null : value;
    }

    function syncLanterns() {
        if (!window.ARWorld) {
            return;
        }

        var lobbyJoined = readCount('joined-count');
        var answered = document.getElementById('answered-summary');

        if (answered) {
            // "3 / 12 answered": every participant floats, those who answered glow.
            var match = /(\d+)\s*\/\s*(\d+)/.exec(answered.textContent);

            if (match) {
                window.ARWorld.setLanterns(parseInt(match[2], 10), parseInt(match[1], 10));
                return;
            }
        }

        if (lobbyJoined !== null) {
            window.ARWorld.setLanterns(lobbyJoined, lobbyJoined);
        }
    }

    function initLiveCounters() {
        if (!isPresentation || !('MutationObserver' in window)) {
            return;
        }

        ['joined-count', 'answered-summary', 'waiting-count', 'ready-count'].forEach(function (id) {
            var element = document.getElementById(id);

            if (!element) {
                return;
            }

            var previous = element.textContent;
            var observer = new MutationObserver(function () {
                if (element.textContent === previous) {
                    return;
                }

                previous = element.textContent;
                syncLanterns();

                if (reduced) {
                    return;
                }

                element.classList.remove('live-pulse');
                void element.offsetWidth;
                element.classList.add('live-pulse');
            });

            observer.observe(element, {childList: true, characterData: true, subtree: true});
        });

        // The background scripts start after this one; they announce themselves.
        document.addEventListener('ar-world-ready', syncLanterns);
        syncLanterns();
    }

    initEntrance();
    initHorizon();
    initSmoothWheel();
    initBackToTop();
    initWaterRings();
    initLiveCounters();
})();
