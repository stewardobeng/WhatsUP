<?php
declare(strict_types=1);
namespace WhatsUP\Core;
final class Config {
    private static array $values=[];
    public static function load(string $file): void {
        if (!is_file($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line=trim($line); if ($line==='' || str_starts_with($line,'#') || !str_contains($line,'=')) continue;
            [$key,$value]=explode('=',$line,2); $key=trim($key); $value=trim($value);
            if (strlen($value)>=2 && (($value[0]==='"' && str_ends_with($value,'"')) || ($value[0]==="'" && str_ends_with($value,"'")))) $value=substr($value,1,-1);
            if ($key!=='') self::$values[$key]=$value;
        }
    }
    public static function get(string $key, ?string $default=null): ?string { $value=getenv($key); return $value!==false ? $value : (self::$values[$key] ?? $default); }
    public static function bool(string $key,bool $default=false): bool { return filter_var(self::get($key,$default?'true':'false'),FILTER_VALIDATE_BOOLEAN); }
    public static function required(string $key): string { $v=self::get($key); if ($v===null || $v==='') throw new \RuntimeException('Missing required configuration: '.$key); return $v; }
}
