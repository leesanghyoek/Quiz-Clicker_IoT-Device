<?php
/*
 * Main navigation, drawn on the waterline. world.js keeps the lake's horizon
 * level with this bar, so the words sit on the water with their reflections.
 */
?>
<nav class="horizon" id="horizon" aria-label="Main navigation">
    <div class="horizon-inner">
        <?php foreach ($app_sections as $section_key => $section): ?>
            <?php
            $is_current = $section_key === $current_section;
            $aria_current = '';

            if ($is_current) {
                $aria_current = $current_page === $section['href']
                    ? ' aria-current="page"'
                    : ' aria-current="true"';
            }
            ?>
            <a
                href="<?= htmlspecialchars($section['href'], ENT_QUOTES, 'UTF-8') ?>"
                class="horizon-link<?= $is_current ? ' is-current' : '' ?>"
                <?= $aria_current ?>
            >
                <span class="horizon-word"><?= htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="horizon-reflection" aria-hidden="true"><?= htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <svg class="horizon-filters" width="0" height="0" aria-hidden="true" focusable="false">
        <filter id="water-ripple" x="-10%" y="-20%" width="120%" height="140%">
            <feTurbulence type="fractalNoise" baseFrequency="0.01 0.16" numOctaves="1" seed="4" result="waves">
                <animate attributeName="baseFrequency" dur="11s" values="0.01 0.16;0.014 0.21;0.01 0.16" repeatCount="indefinite"/>
            </feTurbulence>
            <feDisplacementMap in="SourceGraphic" in2="waves" scale="5" xChannelSelector="R" yChannelSelector="G"/>
        </filter>
        <filter id="water-ripple-strong" x="-10%" y="-20%" width="120%" height="140%">
            <feTurbulence type="fractalNoise" baseFrequency="0.016 0.24" numOctaves="1" seed="9" result="waves">
                <animate attributeName="baseFrequency" dur="4s" values="0.016 0.24;0.022 0.3;0.016 0.24" repeatCount="indefinite"/>
            </feTurbulence>
            <feDisplacementMap in="SourceGraphic" in2="waves" scale="11" xChannelSelector="R" yChannelSelector="G"/>
        </filter>
    </svg>
</nav>
