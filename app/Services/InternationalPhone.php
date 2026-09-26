<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

class InternationalPhone
{
    public function normalize(string $input, string $country): string
    {
        $util = PhoneNumberUtil::getInstance();
        if (! in_array($country, $util->getSupportedRegions(), true) ||
            ! preg_match('/^\+?[0-9]{4,16}$/D', $input)) {
            $this->invalid();
        }
        try {
            $phone = $util->parse($input, $country);
            if (! $util->isValidNumberForRegion($phone, $country) ||
                ! in_array($util->getNumberType($phone), [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true)) {
                $this->invalid();
            }
            return $util->format($phone, PhoneNumberFormat::E164);
        } catch (NumberParseException) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages([
            'contact_number' => 'Enter a valid mobile number for the selected country.',
        ]);
    }
}
