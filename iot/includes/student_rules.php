<?php
/*
 * Rules shared by every way of adding or editing students.
 *
 * student_id is the number a student types on the answer device: exactly two
 * digits, 00 to 99, usually their STT (roll number in the class list).
 * mssv is the school's own student code; it is optional.
 */

const STUDENT_NUMBER_HINT = 'two digits, 00 to 99: the number typed on the answer device';

// Excel drops leading zeros and may add ".0": "5", "5.0" and " 05 " all become "05".
function normalizeStudentNumber(string $raw): string
{
    $number = str_replace(' ', '', trim($raw));

    if (preg_match('/^(\d+)\.0+$/', $number, $match)) {
        $number = $match[1];
    }

    return preg_match('/^\d$/', $number) ? '0' . $number : $number;
}

function isValidStudentNumber(string $number): bool
{
    return (bool) preg_match('/^\d{2}$/', $number);
}

function normalizeMssv(string $raw): string
{
    $mssv = str_replace(' ', '', trim($raw));

    if (preg_match('/^(\d+)\.0+$/', $mssv, $match)) {
        $mssv = $match[1];
    }

    return $mssv;
}

// Empty is allowed; otherwise letters, digits and . _ - / up to 20 characters.
function isValidMssv(string $mssv): bool
{
    return $mssv === '' || (bool) preg_match('/^[A-Za-z0-9._\/-]{1,20}$/', $mssv);
}

function normalizeStudentName(string $raw): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $raw));
}

function studentNameIsTooLong(string $name): bool
{
    return iconv_strlen($name, 'UTF-8') > 100;
}
