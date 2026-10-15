ALTER TABLE live_sessions
    ADD COLUMN class_id INT(11) NULL AFTER quiz_id,
    ADD KEY idx_live_sessions_class_id (class_id),
    ADD CONSTRAINT fk_live_sessions_class
        FOREIGN KEY (class_id)
        REFERENCES classes (class_id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT;

CREATE TABLE device_sessions (
    device_session_id INT(11) NOT NULL AUTO_INCREMENT,
    session_id INT(11) NOT NULL,
    device_id INT(11) NOT NULL,
    student_pk INT(11) NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (device_session_id),
    UNIQUE KEY uq_device_sessions_session_device (session_id, device_id),
    UNIQUE KEY uq_device_sessions_session_student (session_id, student_pk),
    KEY idx_device_sessions_device (device_id),
    KEY idx_device_sessions_student (student_pk),
    CONSTRAINT fk_device_sessions_session
        FOREIGN KEY (session_id)
        REFERENCES live_sessions (session_id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    CONSTRAINT fk_device_sessions_device
        FOREIGN KEY (device_id)
        REFERENCES devices (device_id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    CONSTRAINT fk_device_sessions_student
        FOREIGN KEY (student_pk)
        REFERENCES students (id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;
