<?php

namespace GithubSync\Support;

/**
 * Decides whether a relative file path is excluded from syncing.
 *
 * Rules support three shapes:
 *  - "vendor/"       -> directory prefix match (also matches the bare "vendor" entry)
 *  - "*.log"         -> glob, matched against the full relative path and the basename
 *  - "composer.json" -> exact path match
 */
class ExclusionFilter {

    /**
     * The only rule that cannot be switched off.
     *
     * A repository's own metadata folder must never travel in either direction:
     * writing it into WordPress, or committing it back into the repository that
     * contains it, breaks the checkout it belongs to.
     *
     * @var string[]
     */
    private const PROTECTED_RULES = [
        '.git/',
    ];

    /**
     * Rules the Add Mapping screen offers as a starting point. They are stored
     * on the mapping like any other rule, so they can be edited or removed.
     *
     * @var string[]
     */
    private const SUGGESTED_RULES = [
        '.github/',
        'node_modules/',
        '.DS_Store',
        'Thumbs.db',
        '.env',
        '.env.*',
        '*.sql',
        '*.log',
    ];

    /**
     * @var string[]
     */
    private array $directory_rules = [];

    /**
     * @var string[]
     */
    private array $glob_rules = [];

    /**
     * @var array<string, true>
     */
    private array $exact_rules = [];

    /**
     * @param string[] $rules The mapping's own rules.
     */
    public function __construct(array $rules = []) {
        $all = array_merge(self::PROTECTED_RULES, $rules);

        foreach ($all as $rule) {
            if (!is_string($rule)) {
                continue;
            }

            $rule = trim(str_replace('\\', '/', $rule));
            $rule = ltrim($rule, '/');

            if ($rule === '') {
                continue;
            }

            if (substr($rule, -1) === '/') {
                $this->directory_rules[] = rtrim($rule, '/');
                continue;
            }

            if (strpos($rule, '*') !== false || strpos($rule, '?') !== false) {
                $this->glob_rules[] = $rule;
                continue;
            }

            $this->exact_rules[$rule] = true;
            // A plain name may also be a directory the user wants skipped.
            $this->directory_rules[] = $rule;
        }

        $this->directory_rules = array_values(array_unique($this->directory_rules));
        $this->glob_rules      = array_values(array_unique($this->glob_rules));
    }

    /**
     * Build a filter from a mapping's exclusion list.
     *
     * @param string[] $rules
     */
    public static function for_rules(array $rules): self {
        return new self($rules);
    }

    /**
     * Check a path relative to the mapping root, e.g. "src/App/Main.php".
     */
    public function is_excluded(string $relative_path): bool {
        $path = ltrim(str_replace('\\', '/', $relative_path), '/');

        if ($path === '') {
            return true;
        }

        if (isset($this->exact_rules[$path])) {
            return true;
        }

        foreach ($this->directory_rules as $dir) {
            if ($path === $dir || strpos($path, $dir . '/') === 0) {
                return true;
            }
        }

        if ($this->glob_rules) {
            $basename = basename($path);
            foreach ($this->glob_rules as $glob) {
                if (self::glob_match($glob, $path) || self::glob_match($glob, $basename)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * True when a whole directory can be skipped without walking into it.
     * Used to avoid descending huge trees such as node_modules.
     */
    public function is_excluded_directory(string $relative_dir): bool {
        $dir = trim(str_replace('\\', '/', $relative_dir), '/');

        if ($dir === '') {
            return false;
        }

        foreach ($this->directory_rules as $rule) {
            if ($dir === $rule || strpos($dir, $rule . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * fnmatch() is not compiled into every PHP build, so fall back to a regex.
     */
    private static function glob_match(string $pattern, string $subject): bool {
        if (function_exists('fnmatch')) {
            return fnmatch($pattern, $subject);
        }

        $regex = str_replace(
            ['\*', '\?'],
            ['[^/]*', '[^/]'],
            preg_quote($pattern, '#')
        );

        return (bool) preg_match('#^' . $regex . '$#', $subject);
    }

    /**
     * Rules offered as a starting point on the Add Mapping screen.
     *
     * @return string[]
     */
    public static function suggested_rules(): array {
        return self::SUGGESTED_RULES;
    }

    /**
     * Rules that always apply and are not stored on the mapping.
     *
     * @return string[]
     */
    public static function protected_rules(): array {
        return self::PROTECTED_RULES;
    }
}
