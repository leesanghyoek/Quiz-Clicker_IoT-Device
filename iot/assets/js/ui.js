(function () {
    'use strict';

    window.AppUI = Object.freeze({
        confirm: function (options) {
            if (!window.Swal) {
                return Promise.resolve({isConfirmed: false});
            }

            return window.Swal.fire(options);
        }
    });

    function scrollCardRow(targetId, direction) {
        const row = document.getElementById(targetId);

        if (!row) {
            return;
        }

        row.scrollBy({
            left: direction * Math.max(row.clientWidth * 0.8, 280),
            behavior: 'smooth'
        });
    }

    document.querySelectorAll('[data-scroll-previous]').forEach(function (button) {
        button.addEventListener('click', function () {
            scrollCardRow(button.dataset.scrollPrevious, -1);
        });
    });

    document.querySelectorAll('[data-scroll-next]').forEach(function (button) {
        button.addEventListener('click', function () {
            scrollCardRow(button.dataset.scrollNext, 1);
        });
    });

    function isCardInteractiveTarget(target, card) {
        if (!(target instanceof Element)) {
            return false;
        }

        const interactive = target.closest(
            'a, button, input, select, textarea, label, form, dialog, '
            + '[role="button"], [data-card-stop], .action-menu'
        );

        return interactive !== null && interactive !== card;
    }

    function openCard(card) {
        const destination = card.dataset.cardHref;

        if (destination) {
            window.location.assign(destination);
        }
    }

    document.querySelectorAll('[data-card-href]').forEach(function (card) {
        card.addEventListener('click', function (event) {
            if (isCardInteractiveTarget(event.target, card)) {
                return;
            }

            openCard(card);
        });

        card.addEventListener('keydown', function (event) {
            if (
                isCardInteractiveTarget(event.target, card)
                || (event.key !== 'Enter' && event.key !== ' ')
            ) {
                return;
            }

            event.preventDefault();
            openCard(card);
        });
    });

    // SortableJS is loaded by the shared layout for future Quiz Editor use.
    // No sortable instance is initialized at the foundation stage.
})();
