<?php
declare(strict_types=1);

namespace core\plugin;

/**
 * 极简语义化版本约束判断（无需额外依赖）
 *
 * 支持：">=1.2.0"、">1.0"、"<=2"、"<2.0.0"、"=1.0.0"、"1.0.0"、"^1.2"、"~1.2"、"*"
 * 多个约束用空格或逗号分隔表示“且”：">=1.0 <2.0"；用 "||" 表示“或”。
 */
final class Version
{
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        foreach (explode('||', $constraint) as $or) {
            $ok = true;
            foreach (preg_split('/[\s,]+/', trim($or)) ?: [] as $part) {
                if ($part !== '' && !self::single($version, $part)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    protected static function single(string $version, string $c): bool
    {
        if ($c === '*') {
            return true;
        }
        if ($c[0] === '^' || $c[0] === '~') {
            $base = self::normalize(substr($c, 1));
            [$major, $minor] = array_map('intval', explode('.', $base));
            $upper = $c[0] === '^'
                ? ($major > 0 ? ($major + 1) . '.0.0' : '0.' . ($minor + 1) . '.0')
                : $major . '.' . ($minor + 1) . '.0';
            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }
        if (preg_match('/^(>=|<=|>|<|=|==|!=)?\s*(.+)$/', $c, $m)) {
            $op = $m[1] ?: '==';
            return version_compare(self::normalize($version), self::normalize($m[2]), $op === '=' ? '==' : $op);
        }
        return false;
    }

    protected static function normalize(string $v): string
    {
        $v = ltrim(trim($v), 'vV');
        $parts = explode('.', preg_replace('/[\-+].*$/', '', $v) ?? $v);
        while (count($parts) < 3) {
            $parts[] = '0';
        }
        return implode('.', array_slice($parts, 0, 3));
    }
}
