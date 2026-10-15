-- Adds the school's student code (MSSV) next to the 2-digit device number.
-- Already included in schema.sql; run this only on databases created before
-- 2026-10-13. Existing students keep working with an empty MSSV.
ALTER TABLE students
    ADD COLUMN mssv VARCHAR(20) NULL AFTER student_id;
