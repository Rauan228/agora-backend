<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Валидация российского ИНН с проверкой контрольной суммы.
 * Принимает 10-значный (юрлицо) или 12-значный (ИП/физлицо) ИНН.
 */
class Inn implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $inn = (string) $value;

        if (! preg_match('/^\d{10}$|^\d{12}$/', $inn)) {
            $fail('ИНН должен состоять из 10 (юрлицо) или 12 (ИП) цифр.');
            return;
        }

        $digits = array_map('intval', str_split($inn));

        $checkDigit = static function (array $digits, array $coefficients): int {
            $sum = 0;
            foreach ($coefficients as $i => $coef) {
                $sum += $coef * $digits[$i];
            }
            return ($sum % 11) % 10;
        };

        if (strlen($inn) === 10) {
            $n10 = $checkDigit($digits, [2, 4, 10, 3, 5, 9, 4, 6, 8]);
            if ($n10 !== $digits[9]) {
                $fail('Неверная контрольная сумма ИНН.');
            }
            return;
        }

        // 12 цифр
        $n11 = $checkDigit($digits, [7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);
        $n12 = $checkDigit($digits, [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);
        if ($n11 !== $digits[10] || $n12 !== $digits[11]) {
            $fail('Неверная контрольная сумма ИНН.');
        }
    }
}
