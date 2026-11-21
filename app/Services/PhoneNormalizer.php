<?php
declare(strict_types=1);
namespace WhatsUP\Services;
use InvalidArgumentException;
final class PhoneNormalizer {
    public function __construct(private readonly string $defaultRegion='GH') {}
    /** Parse user input into strict E.164, never guessing an unsupported country. */
    public function normalize(string $raw, ?string $region=null): string {
        $raw=trim($raw);
        if ($raw==='') throw new InvalidArgumentException('Phone number is required.');
        if (!preg_match('/^\+?[0-9()\s.\-]+$/',$raw)) throw new InvalidArgumentException('Phone number contains invalid characters.');
        $region=strtoupper(preg_replace('/[^A-Za-z]/','',(string)($region ?: $this->defaultRegion)) ?? $this->defaultRegion);
        if (!preg_match('/^[A-Z]{2}$/',$region)) throw new InvalidArgumentException('A valid two-letter default region is required.');
        if (class_exists(\Normalizer::class)) $raw=\Normalizer::normalize($raw,\Normalizer::FORM_KC) ?: $raw;
        if (class_exists(\libphonenumber\PhoneNumberUtil::class)) {
            try {
                $util=\libphonenumber\PhoneNumberUtil::getInstance();
                $number=$util->parse($raw,$region);
                if (!$util->isValidNumber($number)) throw new InvalidArgumentException('Enter a valid phone number.');
                return $util->format($number,\libphonenumber\PhoneNumberFormat::E164);
            } catch (InvalidArgumentException $e) { throw $e; } catch (\Throwable) { throw new InvalidArgumentException('Enter a valid phone number in international format.'); }
        }
        return $this->fallbackNormalize($raw,$region);
    }
    private function fallbackNormalize(string $raw,string $region): string {
        $hasPlus=str_starts_with($raw,'+');$digits=preg_replace('/\D+/','',$raw) ?? '';
        if ($digits==='') throw new InvalidArgumentException('Phone number is required.');
        if (!$hasPlus) {
            $prefixes=['GH'=>'233','NG'=>'234','KE'=>'254','ZA'=>'27','US'=>'1','CA'=>'1','GB'=>'44','DE'=>'49','FR'=>'33','CI'=>'225','TG'=>'228','SN'=>'221'];
            $cc=$prefixes[$region]??null; if(!$cc) throw new InvalidArgumentException('Numbers must use international format for this region.');
            $digits=ltrim($digits,'0'); $digits=$cc.$digits;
        }
        if (str_starts_with($digits,'00')) $digits=substr($digits,2);
        if (!preg_match('/^[1-9][0-9]{7,14}$/',$digits)) throw new InvalidArgumentException('Enter a valid international phone number.');
        return '+'.$digits;
    }
}
