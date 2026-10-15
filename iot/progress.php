<?php
/*
 * Progress: how a class is doing across its finished quiz sessions, and
 * which students need attention (low average score or often absent).
 */
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/progress_data.php';

$teacher_id = (int) $_SESSION['teacher_id'];

$stmt = $conn->prepare('SELECT class_id, name FROM classes WHERE teacher_id = ? ORDER BY name');
$stmt->bind_param('i', $teacher_id);
$stmt->execute();
$classes = [];

foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $class_row) {
    $classes[(int) $class_row['class_id']] = $class_row;
}

$class_id = (int) ($_GET['class_id'] ?? 0);

if (!isset($classes[$class_id])) {
    $class_id = (int) (array_key_first($classes) ?? 0);
}

$progress = $class_id > 0
    ? loadClassProgress($conn, $teacher_id, $class_id)
    : ['sessions' => [], 'students' => [], 'bands' => []];
$sessions = $progress['sessions'];
$students = $progress['students'];
$session_count = count($sessions);
$class_name = $classes[$class_id]['name'] ?? '';

$attention = array_values(array_filter($students, static fn ($s) => !empty($s['reasons'])));
$latest = $sessions[$session_count - 1] ?? null;
$previous = $sessions[$session_count - 2] ?? null;
$overall = $session_count > 0
    ? (int) round(array_sum(array_column($sessions, 'average')) / $session_count)
    : 0;
$attendance = $session_count > 0
    ? (int) round(array_sum(array_map(
        static fn ($s) => $s['class_size'] > 0 ? $s['joined'] / $s['class_size'] * 100 : 0,
        $sessions
    )) / $session_count)
    : 0;

// Weakest first: students who need attention, then by average score.
usort($students, static function (array $a, array $b): int {
    return [empty($a['reasons']), $a['average'] ?? -1, $a['student_id']]
        <=> [empty($b['reasons']), $b['average'] ?? -1, $b['student_id']];
});

function progressText(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function progressDate(string $value): string
{
    $time = strtotime($value);

    return $time ? date('d/m', $time) : '';
}

function progressChange(?int $change): string
{
    if ($change === null) {
        return '<span class="progress-change">—</span>';
    }

    if ($change === 0) {
        return '<span class="progress-change">±0</span>';
    }

    $up = $change > 0;

    return '<span class="progress-change ' . ($up ? 'is-up' : 'is-down') . '">'
        . '<i class="ti ti-arrow-' . ($up ? 'up' : 'down') . '-right" aria-hidden="true"></i>'
        . ($up ? '+' : '−') . abs($change) . '</span>';
}

/* Geometry shared by the two session charts, so their points line up. */
$chart_w = 750; // room right of the plot for the latest value
$plot_left = 44;
$plot_right = 700;
$chart_x = static function (int $index) use ($session_count, $plot_left, $plot_right): float {
    return $session_count <= 1
        ? ($plot_left + $plot_right) / 2
        : $plot_left + ($plot_right - $plot_left) * $index / ($session_count - 1);
};
$column_w = $session_count <= 1 ? $plot_right - $plot_left : ($plot_right - $plot_left) / ($session_count - 1);
$label_every = max(1, (int) ceil($session_count / 8));

$page_title = 'Progress';
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<main class="main-content management-page progress-page">
    <header class="page-header management-page-header">
        <div class="page-header-main">
            <h1>Progress</h1>
            <p>Follow how each class scores across quizzes and spot students who are falling behind.</p>
        </div>
    </header>

    <?php if (empty($classes)): ?>
        <div class="empty-state">
            <h3>No classes yet</h3>
            <p>Create a class and run quizzes with it; its progress will show up here.</p>
            <a href="add_class.php" class="add-button">Create Class</a>
        </div>
    <?php else: ?>
        <form method="GET" action="progress.php" class="review-filters progress-filters">
            <div class="form-group">
                <label for="progress-class">Class</label>
                <select id="progress-class" name="class_id" onchange="this.form.submit()">
                    <?php foreach ($classes as $class_option): ?>
                        <option value="<?= (int) $class_option['class_id'] ?>" <?= (int) $class_option['class_id'] === $class_id ? 'selected' : '' ?>>
                            <?= progressText($class_option['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button type="submit" class="secondary-button">Show</button></noscript>
        </form>

        <?php if ($session_count === 0): ?>
            <div class="empty-state">
                <h3><?= progressText($class_name) ?> has no finished quiz yet</h3>
                <p>Once a quiz session with this class ends, scores and attendance are tracked here.</p>
                <a href="quizzes.php" class="add-button">Open Quiz Library</a>
            </div>
        <?php else: ?>
            <section class="progress-tiles" aria-label="Summary">
                <div class="progress-tile">
                    <span class="progress-tile-label">Latest class score</span>
                    <strong><?= $latest['average'] ?>%</strong>
                    <span class="progress-tile-note">
                        <?php if ($previous !== null): ?>
                            <?= progressChange($latest['average'] - $previous['average']) ?> vs previous quiz
                        <?php else: ?>
                            first quiz
                        <?php endif; ?>
                    </span>
                </div>
                <div class="progress-tile">
                    <span class="progress-tile-label">Average over <?= $session_count ?> <?= $session_count === 1 ? 'quiz' : 'quizzes' ?></span>
                    <strong><?= $overall ?>%</strong>
                    <span class="progress-tile-note">mean class score</span>
                </div>
                <div class="progress-tile">
                    <span class="progress-tile-label">Attendance</span>
                    <strong><?= $attendance ?>%</strong>
                    <span class="progress-tile-note">of the class joins a quiz</span>
                </div>
                <div class="progress-tile <?= count($attention) > 0 ? 'is-alert' : '' ?>">
                    <span class="progress-tile-label">Need attention</span>
                    <strong><?= count($attention) ?></strong>
                    <span class="progress-tile-note">
                        <?= count($attention) > 0 ? 'students; listed first below' : 'everyone is on track' ?>
                    </span>
                </div>
            </section>

            <section class="progress-section">
                <div class="section-header">
                    <div>
                        <h2>Class score per quiz</h2>
                        <p>Mean score of the students who joined, oldest to newest. The dashed line is <?= PROGRESS_ATTENTION_SCORE ?>%.</p>
                    </div>
                </div>

                <figure class="progress-chart" data-chart>
                    <svg viewBox="0 0 <?= $chart_w ?> 250" role="img" aria-label="Class score per quiz, from <?= $sessions[0]['average'] ?>% to <?= $latest['average'] ?>%">
                        <?php foreach ([0, 25, 50, 75, 100] as $tick): ?>
                            <?php $y = 220 - $tick * 2; ?>
                            <line class="chart-grid <?= $tick === PROGRESS_ATTENTION_SCORE ? 'is-threshold' : '' ?>" x1="<?= $plot_left ?>" x2="<?= $plot_right ?>" y1="<?= $y ?>" y2="<?= $y ?>"></line>
                            <text class="chart-axis" x="<?= $plot_left - 10 ?>" y="<?= $y + 4 ?>" text-anchor="end"><?= $tick ?>%</text>
                        <?php endforeach; ?>

                        <?php foreach ($sessions as $i => $session): ?>
                            <?php if ($i % $label_every === 0 || $i === $session_count - 1): ?>
                                <text class="chart-axis" x="<?= $chart_x($i) ?>" y="244" text-anchor="middle"><?= progressDate($session['date']) ?></text>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <line class="chart-crosshair" x1="0" x2="0" y1="20" y2="220" hidden></line>

                        <?php if ($session_count > 1): ?>
                            <polyline class="chart-line" points="<?= implode(' ', array_map(
                                static fn ($i, $s) => round($chart_x($i), 1) . ',' . (220 - $s['average'] * 2),
                                array_keys($sessions),
                                $sessions
                            )) ?>"></polyline>
                        <?php endif; ?>

                        <?php foreach ($sessions as $i => $session): ?>
                            <circle class="chart-dot" data-index="<?= $i ?>" cx="<?= $chart_x($i) ?>" cy="<?= 220 - $session['average'] * 2 ?>" r="5"></circle>
                        <?php endforeach; ?>

                        <text class="chart-value" x="<?= $chart_x($session_count - 1) + 12 ?>" y="<?= 224 - $latest['average'] * 2 ?>"><?= $latest['average'] ?>%</text>

                        <?php foreach ($sessions as $i => $session): ?>
                            <rect
                                class="chart-hit"
                                data-index="<?= $i ?>"
                                data-x="<?= $chart_x($i) ?>"
                                x="<?= $chart_x($i) - $column_w / 2 ?>" y="0" width="<?= $column_w ?>" height="230"
                                tabindex="0"
                                data-tip="<?= progressText(
                                    $session['quiz_title'] . ' · ' . progressDate($session['date'])
                                    . "\nClass score: " . $session['average'] . '%'
                                    . "\nJoined: " . $session['joined'] . ' of ' . $session['class_size']
                                    . ' · ' . $session['questions'] . ' questions'
                                ) ?>"
                            ></rect>
                        <?php endforeach; ?>
                    </svg>
                </figure>

                <h3 class="progress-subhead">Students who joined</h3>
                <figure class="progress-chart is-short" data-chart>
                    <svg viewBox="0 0 <?= $chart_w ?> 110" role="img" aria-label="Students who joined each quiz">
                        <line class="chart-grid" x1="<?= $plot_left ?>" x2="<?= $plot_right ?>" y1="90" y2="90"></line>
                        <?php $bar_w = max(4, min(28, $column_w - 6)); ?>
                        <?php foreach ($sessions as $i => $session): ?>
                            <?php
                            $share = $session['class_size'] > 0 ? $session['joined'] / $session['class_size'] : 0;
                            $h = max(2, round($share * 80));
                            ?>
                            <path class="chart-bar" data-index="<?= $i ?>" d="<?php
                                $x0 = $chart_x($i) - $bar_w / 2;
                                $r = min(4, $bar_w / 2, $h);
                                echo 'M' . $x0 . ',90 V' . (90 - $h + $r)
                                    . ' Q' . $x0 . ',' . (90 - $h) . ' ' . ($x0 + $r) . ',' . (90 - $h)
                                    . ' H' . ($x0 + $bar_w - $r)
                                    . ' Q' . ($x0 + $bar_w) . ',' . (90 - $h) . ' ' . ($x0 + $bar_w) . ',' . (90 - $h + $r)
                                    . ' V90 Z';
                            ?>"></path>
                        <?php endforeach; ?>
                        <text class="chart-axis" x="<?= $plot_left - 10 ?>" y="14" text-anchor="end">100%</text>
                        <text class="chart-axis" x="<?= $plot_left - 10 ?>" y="94" text-anchor="end">0</text>
                        <?php foreach ($sessions as $i => $session): ?>
                            <rect
                                class="chart-hit"
                                data-index="<?= $i ?>"
                                x="<?= $chart_x($i) - $column_w / 2 ?>" y="0" width="<?= $column_w ?>" height="100"
                                data-tip="<?= progressText(
                                    $session['quiz_title'] . ' · ' . progressDate($session['date'])
                                    . "\nJoined: " . $session['joined'] . ' of ' . $session['class_size']
                                    . ' (' . resultPercent($session['joined'], $session['class_size']) . '%)'
                                ) ?>"
                            ></rect>
                        <?php endforeach; ?>
                    </svg>
                </figure>
            </section>

            <section class="progress-section">
                <div class="section-header">
                    <div>
                        <h2>How students are spread</h2>
                        <p>Students by their average score, counting only quizzes they joined.</p>
                    </div>
                </div>

                <?php $band_max = max(1, ...array_map(static fn ($b) => count($b['students']), $progress['bands'])); ?>
                <div class="progress-bands" data-chart>
                    <?php foreach ($progress['bands'] as $band): ?>
                        <?php
                        $count = count($band['students']);
                        $names = array_map(static fn ($s) => $s['student_id'] . ' ' . $s['name'], array_slice($band['students'], 0, 8));
                        $more = $count - count($names);
                        ?>
                        <div
                            class="progress-band <?= $band['high'] < PROGRESS_ATTENTION_SCORE ? 'is-low' : '' ?>"
                            tabindex="0"
                            data-tip="<?= progressText(
                                $band['low'] . '–' . $band['high'] . '%: ' . $count . ($count === 1 ? ' student' : ' students')
                                . ($names ? "\n" . implode("\n", $names) : '')
                                . ($more > 0 ? "\n+" . $more . ' more' : '')
                            ) ?>"
                        >
                            <span class="progress-band-count"><?= $count ?></span>
                            <span class="progress-band-bar" style="height: <?= $count > 0 ? max(4, round($count / $band_max * 100)) : 0 ?>%"></span>
                            <span class="progress-band-label"><?= $band['low'] ?>–<?= $band['high'] ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="progress-section">
                <div class="section-header">
                    <div>
                        <h2>Students</h2>
                        <p>
                            Needs attention: average below <?= PROGRESS_ATTENTION_SCORE ?>%,
                            or joined fewer than <?= PROGRESS_ATTENTION_ATTENDANCE ?>% of the quizzes.
                        </p>
                    </div>
                    <label class="progress-toggle">
                        <input type="checkbox" id="attention-only">
                        Only students who need attention
                    </label>
                </div>

                <div class="table-card">
                    <div class="table-responsive">
                        <table class="table table-vcenter progress-table">
                            <thead>
                                <tr>
                                    <th>STT</th>
                                    <th>Name</th>
                                    <th>Joined</th>
                                    <th>Average</th>
                                    <th>Last</th>
                                    <th>Change</th>
                                    <th>Trend</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <?php $needs = !empty($student['reasons']); ?>
                                    <tr class="<?= $needs ? 'needs-attention' : '' ?>">
                                        <td><span class="table-id"><?= progressText($student['student_id']) ?></span></td>
                                        <td><?= progressText($student['name']) ?></td>
                                        <td><?= $student['joined'] ?>/<?= $session_count ?></td>
                                        <td>
                                            <?php if ($student['average'] === null): ?>
                                                —
                                            <?php else: ?>
                                                <span class="progress-score">
                                                    <span class="progress-meter" aria-hidden="true"><span style="width: <?= $student['average'] ?>%"></span></span>
                                                    <?= $student['average'] ?>%
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $student['last'] === null ? '—' : $student['last'] . '%' ?></td>
                                        <td><?= progressChange($student['change']) ?></td>
                                        <td>
                                            <?php
                                            $points = [];
                                            foreach ($student['history'] as $i => $score) {
                                                if ($score !== null) {
                                                    $points[] = [$session_count <= 1 ? 50 : 4 + 92 * $i / ($session_count - 1), 26 - $score * 0.22];
                                                }
                                            }
                                            ?>
                                            <?php if (count($points) > 0): ?>
                                                <svg class="progress-spark" viewBox="0 0 100 30" aria-label="Scores: <?= progressText(implode(', ', array_map(
                                                    static fn ($s) => $s === null ? 'absent' : $s . '%',
                                                    $student['history']
                                                ))) ?>" role="img">
                                                    <line x1="0" x2="100" y1="15" y2="15" class="spark-mid"></line>
                                                    <?php if (count($points) > 1): ?>
                                                        <polyline points="<?= implode(' ', array_map(static fn ($p) => round($p[0], 1) . ',' . round($p[1], 1), $points)) ?>"></polyline>
                                                    <?php endif; ?>
                                                    <?php $last_point = end($points); ?>
                                                    <circle cx="<?= round($last_point[0], 1) ?>" cy="<?= round($last_point[1], 1) ?>" r="3"></circle>
                                                </svg>
                                            <?php else: ?>
                                                <span class="progress-muted">no quiz yet</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($needs): ?>
                                                <span class="progress-status is-alert">
                                                    <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                                                    <?= progressText(ucfirst(implode(', ', $student['reasons']))) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="progress-status is-ok">
                                                    <i class="ti ti-circle-check" aria-hidden="true"></i>On track
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div class="chart-tooltip" id="chart-tooltip" role="tooltip" hidden></div>

            <script>
            (function () {
                var tip = document.getElementById('chart-tooltip');

                function show(target, event) {
                    var chart = target.closest('[data-chart]');
                    var index = target.dataset.index;

                    tip.textContent = target.dataset.tip;
                    tip.hidden = false;

                    var box = target.getBoundingClientRect();
                    var x = event && event.clientX ? event.clientX : box.left + box.width / 2;
                    var y = event && event.clientY ? event.clientY : box.top;
                    var left = Math.min(window.innerWidth - tip.offsetWidth - 12, Math.max(12, x + 14));
                    var top = y - tip.offsetHeight - 14 < 8 ? y + 18 : y - tip.offsetHeight - 14;

                    tip.style.left = left + 'px';
                    tip.style.top = top + 'px';

                    if (index !== undefined) {
                        document.querySelectorAll('[data-chart] [data-index]').forEach(function (el) {
                            el.classList.toggle('is-active', el.dataset.index === index && !el.classList.contains('chart-hit'));
                        });
                        var cross = chart.querySelector('.chart-crosshair');
                        if (cross && target.dataset.x) {
                            cross.setAttribute('x1', target.dataset.x);
                            cross.setAttribute('x2', target.dataset.x);
                            cross.hidden = false;
                            cross.removeAttribute('hidden');
                        }
                    }
                }

                function hide() {
                    tip.hidden = true;
                    document.querySelectorAll('[data-chart] .is-active').forEach(function (el) { el.classList.remove('is-active'); });
                    document.querySelectorAll('.chart-crosshair').forEach(function (el) { el.setAttribute('hidden', ''); });
                }

                document.querySelectorAll('[data-chart] [data-tip]').forEach(function (el) {
                    el.addEventListener('pointermove', function (event) { show(el, event); });
                    el.addEventListener('focus', function () { show(el); });
                    el.addEventListener('pointerleave', hide);
                    el.addEventListener('blur', hide);
                });

                var only = document.getElementById('attention-only');
                only.addEventListener('change', function () {
                    document.querySelectorAll('.progress-table tbody tr').forEach(function (row) {
                        row.hidden = only.checked && !row.classList.contains('needs-attention');
                    });
                });
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php include 'includes/layout_end.php'; ?>
