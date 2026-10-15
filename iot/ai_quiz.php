<?php
/*
 * AI Quiz: give the AI lesson material (PDF, Word, PowerPoint or text), let it
 * write multiple-choice questions, ask for changes, check and edit every
 * question, then save the set as a normal quiz. Talking to the AI happens in
 * api/ai_quiz.php; this page holds the material, the draft and the saving.
 */
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/ai_quiz.php';

$teacher_id = (int) $_SESSION['teacher_id'];
$config = aiQuizConfig();
$error = '';

if (empty($_SESSION['ai_quiz_csrf_token'])) {
    $_SESSION['ai_quiz_csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['ai_quiz_csrf_token'];
$draft = $_SESSION['ai_quiz_draft'] ?? null;

function aiQuizForgetDraft(): void
{
    $doc = $_SESSION['ai_quiz_draft']['doc'] ?? null;

    if (is_array($doc)) {
        try {
            @unlink(aiQuizDocumentPath($doc));
        } catch (Throwable $ignored) {
            // Already gone.
        }
    }
    unset($_SESSION['ai_quiz_draft']);
}

if ($config !== null) {
    aiQuizCleanUploads();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_token = $_POST['csrf_token'] ?? '';
    $action = (string) ($_POST['action'] ?? '');

    if (!is_string($posted_token) || !hash_equals($csrf_token, $posted_token)) {
        $error = 'Your form session expired. Please try again.';
    } elseif ($config === null) {
        $error = 'The AI is not set up yet.';
    } elseif ($action === 'material') {
        try {
            $pasted = trim((string) ($_POST['pasted_text'] ?? ''));
            $has_file = ($_FILES['material']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

            if (!$has_file && $pasted === '') {
                throw new InvalidArgumentException('Choose a file or paste some text.');
            }

            $doc = $has_file
                ? aiQuizStoreUpload($_FILES['material'])
                : aiQuizStoreText($pasted, aiQuizClean((string) ($_POST['pasted_name'] ?? ''), 100) ?: 'Pasted text');

            aiQuizForgetDraft();
            $_SESSION['ai_quiz_draft'] = ['doc' => $doc, 'title' => '', 'questions' => [], 'chat' => []];
            header('Location: ai_quiz.php');
            exit;
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('AI quiz material for teacher ' . $teacher_id . ': ' . $exception->getMessage());
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The material could not be stored.';
        }
    } elseif ($action === 'reset') {
        aiQuizForgetDraft();
        header('Location: ai_quiz.php');
        exit;
    } elseif ($action === 'save') {
        $title = aiQuizClean((string) ($_POST['title'] ?? ''), 255);
        $questions = aiQuizNormalizeQuestions(is_array($_POST['questions'] ?? null) ? $_POST['questions'] : []);
        $class_ids = is_array($_POST['class_ids'] ?? null) ? $_POST['class_ids'] : [];

        if ($title === '') {
            $error = 'Give the quiz a title.';
        } elseif (empty($questions)) {
            $error = 'There is no complete question to save. Each question needs text, 2 to 4 answers and a correct answer.';
        } else {
            try {
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                $quiz_id = aiQuizSave($conn, $teacher_id, $title, $questions, $class_ids);
                aiQuizForgetDraft();
                header('Location: quiz.php?id=' . $quiz_id . '&ai_created=1');
                exit;
            } catch (Throwable $exception) {
                error_log('AI quiz save failed for teacher ' . $teacher_id . ': ' . $exception->getMessage());
                $error = 'The quiz could not be saved. Nothing was changed; please try again.';
            }
        }
    }
}

$draft = $_SESSION['ai_quiz_draft'] ?? null;

$stmt = $conn->prepare('SELECT class_id, name FROM classes WHERE teacher_id = ? ORDER BY name');
$stmt->bind_param('i', $teacher_id);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = 'AI Quiz';
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<main class="main-content management-page ai-quiz-page">
    <header class="page-header management-page-header">
        <div class="page-header-main">
            <h1>AI Quiz</h1>
            <p>Give the AI your lesson material and it writes the questions. Check every question before you save.</p>
        </div>
        <div class="page-header-actions">
            <a href="quizzes.php" class="secondary-button">
                <i class="ti ti-arrow-left" aria-hidden="true"></i>
                Quiz Library
            </a>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <div class="error-message" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($config === null): ?>
        <section class="form-card ai-setup">
            <h2>The AI is not set up yet</h2>
            <p>An administrator needs to finish these steps on the server:</p>
            <ul>
                <?php foreach (aiQuizSetupProblems() as $problem): ?>
                    <li><?= htmlspecialchars($problem, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="ai-muted">The steps are described in <code>docs/AI_QUIZ.md</code>.</p>
        </section>
    <?php elseif ($draft === null): ?>
        <section class="form-card ai-material">
            <h2><span class="ai-step">1</span> Lesson material</h2>
            <p class="ai-muted">PDF, Word, PowerPoint, OpenDocument or a text file, up to 20 MB. Or paste the text below.</p>

            <form method="POST" action="ai_quiz.php" enctype="multipart/form-data" class="ai-material-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="material">

                <div class="form-group">
                    <label for="ai-file">File</label>
                    <input id="ai-file" type="file" name="material" accept=".pdf,.docx,.doc,.pptx,.ppt,.odt,.odp,.rtf,.txt,.md">
                </div>

                <p class="ai-or"><span>or</span></p>

                <div class="form-group">
                    <label for="ai-paste-name">Name of the text (optional)</label>
                    <input id="ai-paste-name" type="text" name="pasted_name" maxlength="100" placeholder="e.g. Physics 10 · Chapter 2">
                </div>
                <div class="form-group">
                    <label for="ai-paste">Paste text</label>
                    <textarea id="ai-paste" name="pasted_text" rows="7" placeholder="Paste the lesson here…"></textarea>
                </div>

                <button type="submit" class="add-button">
                    <i class="ti ti-upload" aria-hidden="true"></i>
                    Use this material
                </button>
            </form>
            <?php if ($config['provider'] === 'local'): ?>
                <p class="ai-muted ai-privacy">The questions are written by the AI on this computer: the material stays here. It reads text only, so pictures, tables drawn as images and scanned pages are skipped, and it handles about 20-25 pages at a time.</p>
            <?php else: ?>
                <p class="ai-muted ai-privacy">The material is sent to Anthropic's Claude API to write the questions. Do not upload documents with students' personal data.</p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="ai-doc">
            <i class="ti ti-file-text" aria-hidden="true"></i>
            <span><strong><?= htmlspecialchars($draft['doc']['name'], ENT_QUOTES, 'UTF-8') ?></strong></span>
            <form method="POST" action="ai_quiz.php" class="ai-doc-reset" data-confirm-reset>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="reset">
                <button type="submit" class="secondary-button">Use other material</button>
            </form>
        </section>

        <section class="form-card ai-ask" aria-labelledby="ai-ask-title">
            <h2 id="ai-ask-title"><span class="ai-step">2</span> Ask the AI</h2>

            <ol class="ai-chat" id="ai-chat" aria-live="polite"></ol>

            <form class="ai-ask-form" id="ai-ask-form">
                <div class="ai-settings" id="ai-settings">
                    <div class="form-group">
                        <label for="ai-count">Questions</label>
                        <select id="ai-count" name="count">
                            <?php foreach ([5, 10, 15, 20, 30] as $count): ?>
                                <option value="<?= $count ?>" <?= $count === 10 ? 'selected' : '' ?>><?= $count ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ai-level">Difficulty</label>
                        <select id="ai-level" name="level">
                            <option value="mixed">Mixed</option>
                            <option value="easy">Easy</option>
                            <option value="medium">Medium</option>
                            <option value="hard">Hard</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ai-language">Language</label>
                        <select id="ai-language" name="language">
                            <option value="vi">Tiếng Việt</option>
                            <option value="en">English</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="ai-request" id="ai-request-label">Anything else? (optional)</label>
                    <textarea id="ai-request" name="request" rows="3" maxlength="2000"
                        placeholder="e.g. Focus on chapter 2, include 3 questions about Ohm's law"></textarea>
                </div>

                <div class="ai-ask-actions">
                    <button type="submit" class="add-button" id="ai-ask-button">
                        <i class="ti ti-sparkles" aria-hidden="true"></i>
                        <span>Write questions</span>
                    </button>
                    <p class="ai-status" id="ai-status" role="status"></p>
                </div>
            </form>
        </section>

        <form method="POST" action="ai_quiz.php" class="ai-review" id="ai-review" hidden>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save">

            <h2><span class="ai-step">3</span> Check the questions</h2>
            <p class="ai-muted">The AI can be wrong. Read each question, fix the text or the correct answer, and remove what you do not want.</p>

            <ol class="ai-questions" id="ai-questions"></ol>

            <button type="button" class="secondary-button ai-add" id="ai-add">
                <i class="ti ti-plus" aria-hidden="true"></i>
                Add a question
            </button>

            <div class="ai-save">
                <div class="form-group ai-save-title">
                    <label for="ai-title">Quiz title</label>
                    <input id="ai-title" type="text" name="title" maxlength="255" required>
                </div>
                <?php if (!empty($classes)): ?>
                    <fieldset class="ai-classes">
                        <legend>Assign to</legend>
                        <?php foreach ($classes as $class): ?>
                            <label class="review-chip">
                                <input type="checkbox" name="class_ids[]" value="<?= (int) $class['class_id'] ?>">
                                <span><?= htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>
                <button type="submit" class="add-button">
                    <i class="ti ti-device-floppy" aria-hidden="true"></i>
                    <span id="ai-save-label">Save quiz</span>
                </button>
            </div>
        </form>

        <template id="ai-question-template">
            <li class="ai-question">
                <div class="ai-question-head">
                    <span class="ai-question-number"></span>
                    <button type="button" class="ai-remove" title="Remove this question" aria-label="Remove this question">
                        <i class="ti ti-trash" aria-hidden="true"></i>
                    </button>
                </div>
                <textarea class="ai-question-text" rows="2" aria-label="Question"></textarea>
                <div class="ai-options"></div>
                <p class="ai-why"></p>
            </li>
        </template>
    <?php endif; ?>
</main>

<?php if ($config !== null && $draft !== null): ?>
<script>
(function () {
    var csrf = <?= json_encode($csrf_token) ?>;
    var draft = <?= json_encode(['title' => $draft['title'], 'questions' => $draft['questions'], 'chat' => $draft['chat']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var LETTERS = ['A', 'B', 'C', 'D'];

    var el = function (id) { return document.getElementById(id); };
    var list = el('ai-questions');
    var template = el('ai-question-template');
    var chat = el('ai-chat');
    var askForm = el('ai-ask-form');
    var askButton = el('ai-ask-button');
    var status = el('ai-status');
    var counter = 0;

    function optionRow(name, index, value, checked) {
        var row = document.createElement('label');
        var radio = document.createElement('input');
        var letter = document.createElement('span');
        var input = document.createElement('input');

        row.className = 'ai-option';
        radio.type = 'radio';
        radio.name = name + '[correct]';
        radio.value = LETTERS[index];
        radio.checked = checked;
        radio.setAttribute('aria-label', 'Correct answer ' + LETTERS[index]);
        letter.className = 'ai-letter';
        letter.textContent = LETTERS[index];
        input.type = 'text';
        input.name = name + '[options][]';
        input.value = value;
        input.placeholder = index < 2 ? 'Answer ' + LETTERS[index] : 'Answer ' + LETTERS[index] + ' (optional)';
        input.setAttribute('aria-label', 'Answer ' + LETTERS[index]);
        row.append(radio, letter, input);
        return row;
    }

    function addQuestion(question) {
        var node = template.content.firstElementChild.cloneNode(true);
        var name = 'questions[' + (counter++) + ']';
        var options = node.querySelector('.ai-options');
        var why = node.querySelector('.ai-why');
        var text = node.querySelector('.ai-question-text');

        text.name = name + '[question]';
        text.value = question.question || '';
        for (var i = 0; i < 4; i++) {
            options.appendChild(optionRow(name, i, (question.options || [])[i] || '', question.correct === LETTERS[i]));
        }
        ['explanation', 'source'].forEach(function (field) {
            var hidden = document.createElement('input');

            hidden.type = 'hidden';
            hidden.name = name + '[' + field + ']';
            hidden.value = question[field] || '';
            node.appendChild(hidden);
        });
        why.textContent = [question.explanation, question.source ? '(' + question.source + ')' : ''].filter(Boolean).join(' ');
        why.hidden = !why.textContent;
        node.querySelector('.ai-remove').addEventListener('click', function () {
            node.remove();
            refresh();
        });
        list.appendChild(node);
    }

    // The questions as they are on the page now, including the teacher's edits.
    function currentQuestions() {
        return Array.prototype.map.call(list.children, function (node) {
            var checked = node.querySelector('input[type=radio]:checked');

            return {
                question: node.querySelector('.ai-question-text').value,
                options: Array.prototype.map.call(node.querySelectorAll('.ai-option input[type=text]'), function (input) { return input.value; })
                    .filter(function (value) { return value.trim() !== ''; }),
                correct: checked ? checked.value : '',
                explanation: node.querySelector('input[name$="[explanation]"]').value,
                source: node.querySelector('input[name$="[source]"]').value
            };
        });
    }

    function refresh() {
        var count = list.children.length;

        Array.prototype.forEach.call(list.children, function (node, index) {
            node.querySelector('.ai-question-number').textContent = 'Question ' + (index + 1);
        });
        el('ai-review').hidden = count === 0;
        el('ai-save-label').textContent = 'Save quiz (' + count + (count === 1 ? ' question)' : ' questions)');
        askButton.querySelector('span').textContent = count ? 'Apply change' : 'Write questions';
        el('ai-request-label').textContent = count ? 'What should change?' : 'Anything else? (optional)';
        el('ai-request').placeholder = count
            ? 'e.g. Make question 3 harder · Add 5 questions about chapter 3 · Shorten the answers'
            : "e.g. Focus on chapter 2, include 3 questions about Ohm's law";
        el('ai-settings').hidden = count > 0;
    }

    function addChat(who, text) {
        var item = document.createElement('li');

        item.className = 'ai-chat-' + who;
        item.textContent = text;
        chat.appendChild(item);
    }

    function render(questions) {
        list.innerHTML = '';
        questions.forEach(addQuestion);
        refresh();
    }

    askForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var request = el('ai-request').value.trim();
        var questions = currentQuestions();

        if (questions.length && !request) {
            status.textContent = 'Write what should change first.';
            return;
        }

        askButton.disabled = true;
        status.textContent = 'The AI is reading the material and writing… this can take a minute or two.';
        if (request) { addChat('teacher', request); }

        fetch('api/ai_quiz.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrf,
                request: request,
                questions: questions,
                settings: { count: el('ai-count').value, level: el('ai-level').value, language: el('ai-language').value }
            })
        })
            .then(function (response) { return response.json(); })
            .catch(function () { return { status: 'ERROR', message: 'Could not reach the server.' }; })
            .then(function (data) {
                askButton.disabled = false;
                if (data.status !== 'OK') {
                    status.textContent = data.message || 'Something went wrong.';
                    return;
                }
                status.textContent = data.dropped ? data.dropped + ' incomplete question(s) were left out.' : '';
                addChat('ai', data.reply || 'Done.');
                if (!el('ai-title').value && data.title) { el('ai-title').value = data.title; }
                el('ai-request').value = '';
                render(data.questions);
            });
    });

    el('ai-add').addEventListener('click', function () {
        addQuestion({ question: '', options: [], correct: 'A' });
        refresh();
        list.lastElementChild.querySelector('textarea').focus();
    });

    document.querySelector('[data-confirm-reset]').addEventListener('submit', function (event) {
        if (list.children.length && !window.confirm('Drop this material and the questions written so far?')) {
            event.preventDefault();
        }
    });

    draft.chat.forEach(function (line) { addChat(line.who, line.text); });
    el('ai-title').value = draft.title || '';
    render(draft.questions);
})();
</script>
<?php endif; ?>

<?php include 'includes/layout_end.php'; ?>
