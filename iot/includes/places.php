<?php
/*
 * Navigation sections and where each page sits along the landscape.
 * Moving between places pans the background camera (assets/js/world.js),
 * so neighbouring sections are neighbouring stretches of the same lakeshore.
 */
$app_sections = [
    'dashboard' => [
        'label' => 'Dashboard',
        'href' => 'dashboard.php',
        'place' => 0,
        'pages' => ['dashboard.php']
    ],
    'classes' => [
        'label' => 'Classes',
        'href' => 'classes.php',
        'place' => 1,
        'pages' => [
            'classes.php',
            'class.php',
            'add_class.php',
            'edit_class.php',
            'students.php',
            'add_student.php',
            'edit_student.php',
            'import_students.php'
        ]
    ],
    'quizzes' => [
        'label' => 'Quizzes',
        'href' => 'quizzes.php',
        'place' => 2,
        'pages' => [
            'quizzes.php',
            'quiz.php',
            'create_quiz.php',
            'import_quiz.php',
            'ai_quiz.php',
            'add_question.php',
            'edit_question.php',
            'assign_quiz.php',
            'launch_quiz.php'
        ]
    ],
    'results' => [
        'label' => 'Results',
        'href' => 'results.php',
        'place' => 3,
        'pages' => [
            'results.php',
            'result_question.php',
            'session_results.php'
        ]
    ],
    'progress' => [
        'label' => 'Progress',
        'href' => 'progress.php',
        'place' => 3.3,
        'pages' => ['progress.php']
    ],
    'review' => [
        'label' => 'Review',
        'href' => 'review.php',
        'place' => 3.6,
        'pages' => ['review.php']
    ],
    'devices' => [
        'label' => 'Devices',
        'href' => 'devices.php',
        'place' => 3.85,
        'pages' => ['devices.php']
    ],
    'account' => [
        'label' => 'Account',
        'href' => 'account.php',
        'place' => 4,
        'pages' => ['account.php']
    ]
];

// Pages outside the navigation get their own stretch of shore.
$standalone_places = [
    'login.php' => -1,
    'register.php' => -1.3,
    'lobby.php' => 5,
    'quiz_run.php' => 5.35
];

$current_page = basename($_SERVER['PHP_SELF']);
$current_section = null;

foreach ($app_sections as $section_key => $section) {
    if (in_array($current_page, $section['pages'], true)) {
        $current_section = $section_key;
        break;
    }
}

if ($current_section !== null) {
    $current_place = $app_sections[$current_section]['place'];

    // Pages deeper inside a section sit a few steps along from its main page.
    if ($current_page !== $app_sections[$current_section]['href']) {
        $current_place += 0.22;
    }
} else {
    $current_place = $standalone_places[$current_page] ?? 0;
}
