-- Base schema for the iot database, reconstructed from the queries in the PHP code.
-- Already includes the changes from migrations/20261006_add_live_session_class_and_device_sessions.sql,
-- so do not run that migration on a database created from this file.
--
-- Usage: mysql -u iot_app -p iot < database/schema.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS teachers (
    teacher_id INT(11) NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (teacher_id),
    UNIQUE KEY uq_teachers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS classes (
    class_id INT(11) NOT NULL AUTO_INCREMENT,
    teacher_id INT(11) NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (class_id),
    UNIQUE KEY uq_classes_teacher_name (teacher_id, name),
    CONSTRAINT fk_classes_teacher
        FOREIGN KEY (teacher_id) REFERENCES teachers (teacher_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- student_id is the 2-digit code students type on their ESP device (e.g. "07"),
-- usually their STT (roll number). mssv is the school's own student code.
CREATE TABLE IF NOT EXISTS students (
    id INT(11) NOT NULL AUTO_INCREMENT,
    class_id INT(11) NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    mssv VARCHAR(20) NULL,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_class_student (class_id, student_id),
    CONSTRAINT fk_students_class
        FOREIGN KEY (class_id) REFERENCES classes (class_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS quizzes (
    quiz_id INT(11) NOT NULL AUTO_INCREMENT,
    teacher_id INT(11) NOT NULL,
    title VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (quiz_id),
    KEY idx_quizzes_teacher (teacher_id),
    CONSTRAINT fk_quizzes_teacher
        FOREIGN KEY (teacher_id) REFERENCES teachers (teacher_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS questions (
    question_id INT(11) NOT NULL AUTO_INCREMENT,
    quiz_id INT(11) NOT NULL,
    question_order INT(11) NOT NULL,
    content TEXT NOT NULL,
    PRIMARY KEY (question_id),
    KEY idx_questions_quiz_order (quiz_id, question_order),
    CONSTRAINT fk_questions_quiz
        FOREIGN KEY (quiz_id) REFERENCES quizzes (quiz_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- option_order 1..4 maps to answers A..D. The edit pages shift it by +100
-- before renumbering, which is why the unique key is safe.
CREATE TABLE IF NOT EXISTS question_options (
    option_id INT(11) NOT NULL AUTO_INCREMENT,
    question_id INT(11) NOT NULL,
    option_order INT(11) NOT NULL,
    content TEXT NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (option_id),
    UNIQUE KEY uq_question_options_order (question_id, option_order),
    CONSTRAINT fk_question_options_question
        FOREIGN KEY (question_id) REFERENCES questions (question_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS class_quizzes (
    class_id INT(11) NOT NULL,
    quiz_id INT(11) NOT NULL,
    PRIMARY KEY (class_id, quiz_id),
    KEY idx_class_quizzes_quiz (quiz_id),
    CONSTRAINT fk_class_quizzes_class
        FOREIGN KEY (class_id) REFERENCES classes (class_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_class_quizzes_quiz
        FOREIGN KEY (quiz_id) REFERENCES quizzes (quiz_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- device_code is the 2-digit ID each ESP sends as "device_id" in API calls.
CREATE TABLE IF NOT EXISTS devices (
    device_id INT(11) NOT NULL AUTO_INCREMENT,
    device_code CHAR(2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    PRIMARY KEY (device_id),
    UNIQUE KEY uq_devices_code (device_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS live_sessions (
    session_id INT(11) NOT NULL AUTO_INCREMENT,
    quiz_id INT(11) NOT NULL,
    class_id INT(11) NULL,
    join_code CHAR(2) NULL,
    status ENUM('WAITING', 'ACTIVE', 'ENDED') NOT NULL DEFAULT 'WAITING',
    current_question_id INT(11) NULL,
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id),
    KEY idx_live_sessions_quiz (quiz_id),
    KEY idx_live_sessions_class_id (class_id),
    KEY idx_live_sessions_join_code_status (join_code, status),
    CONSTRAINT fk_live_sessions_quiz
        FOREIGN KEY (quiz_id) REFERENCES quizzes (quiz_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_live_sessions_class
        FOREIGN KEY (class_id) REFERENCES classes (class_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS device_sessions (
    device_session_id INT(11) NOT NULL AUTO_INCREMENT,
    session_id INT(11) NOT NULL,
    device_id INT(11) NULL, -- NULL once the device code is deleted; the student's result stays
    student_pk INT(11) NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (device_session_id),
    UNIQUE KEY uq_device_sessions_session_device (session_id, device_id),
    UNIQUE KEY uq_device_sessions_session_student (session_id, student_pk),
    KEY idx_device_sessions_device (device_id),
    KEY idx_device_sessions_student (student_pk),
    CONSTRAINT fk_device_sessions_session
        FOREIGN KEY (session_id) REFERENCES live_sessions (session_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_device_sessions_device
        FOREIGN KEY (device_id) REFERENCES devices (device_id)
        ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_device_sessions_student
        FOREIGN KEY (student_pk) REFERENCES students (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- api/answer.php relies on the unique key to reject duplicate answers (error 1062).
CREATE TABLE IF NOT EXISTS responses (
    response_id INT(11) NOT NULL AUTO_INCREMENT,
    session_id INT(11) NOT NULL,
    question_id INT(11) NOT NULL,
    student_pk INT(11) NOT NULL,
    answer CHAR(1) NOT NULL,
    answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (response_id),
    UNIQUE KEY uq_responses_session_question_student (session_id, question_id, student_pk),
    KEY idx_responses_question (question_id),
    KEY idx_responses_student (student_pk),
    CONSTRAINT fk_responses_session
        FOREIGN KEY (session_id) REFERENCES live_sessions (session_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_responses_question
        FOREIGN KEY (question_id) REFERENCES questions (question_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_responses_student
        FOREIGN KEY (student_pk) REFERENCES students (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
