<?php

declare(strict_types=1);

namespace Nordvarr;

use Nordvarr\Db\Database;
use Nordvarr\Db\Migrator;
use Nordvarr\Repository\AuditLogRepository;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Repository\UserRepository;
use Nordvarr\Security\Passwords;
use RuntimeException;

/**
 * Logika instalátoru: kontrola požadavků, datový adresář, databáze,
 * účet admina a nakonec config.php, jehož existence instalátor zablokuje.
 */
final class Installer
{
    public const MIN_PHP = '8.4.0';
    public const EXTENSIONS = ['pdo_sqlite', 'curl', 'openssl', 'json', 'mbstring'];

    /** Složky aplikace, ve kterých datový adresář být nesmí (aktualizace je přepisuje, public/ je webroot). */
    public const CODE_DIRS = ['public', 'src', 'templates', 'lang', 'migrations', 'bin', 'agent', 'vendor'];

    public function __construct(private readonly string $appRoot)
    {
    }

    public function configFile(): string
    {
        return $this->appRoot . '/config.php';
    }

    public function isLocked(): bool
    {
        return file_exists($this->configFile());
    }

    /**
     * Návrh datového adresáře: vedle adresáře aplikace, pokud tam webový server smí zapisovat,
     * jinak uvnitř adresáře aplikace (mimo public/). Na serverech s oddělenými uživateli pro domény bývá
     * zapisovatelný jen adresář domény, ve kterém aplikace leží. Kandidát uvnitř webrootu se
     * navrhne jen tehdy, když jiný není.
     */
    public function defaultDataDir(string $documentRoot = ''): string
    {
        $candidates = [dirname($this->appRoot) . '/nordvarr-data', $this->appRoot . '/nordvarr-data'];
        foreach ([true, false] as $outsideDocRootOnly) {
            foreach ($candidates as $dir) {
                if ($outsideDocRootOnly && self::isInsideDocumentRoot($dir, $documentRoot)) {
                    continue;
                }
                if (self::canUseDir($dir)) {
                    return $dir;
                }
            }
        }

        return $candidates[0];
    }

    /**
     * Může webový server adresář použít? Buď existuje a dá se do něj zapisovat, nebo ho
     * jde vytvořit (nejbližší existující nadřazený adresář je zapisovatelný a open_basedir
     * přístup povoluje).
     */
    public static function canUseDir(string $dir): bool
    {
        $dir = rtrim($dir, '/');
        if (!self::allowedByOpenBasedir($dir)) {
            return false;
        }
        if (file_exists($dir)) {
            return is_dir($dir) && is_writable($dir);
        }
        $parent = dirname($dir);
        while ($parent !== '/' && !file_exists($parent)) {
            $parent = dirname($parent);
        }

        return is_dir($parent) && is_writable($parent) && self::allowedByOpenBasedir($parent);
    }

    /**
     * Povoluje omezení open_basedir přístup k cestě?
     */
    public static function allowedByOpenBasedir(string $path, ?string $openBasedir = null): bool
    {
        $openBasedir ??= (string) ini_get('open_basedir');
        if ($openBasedir === '') {
            return true;
        }
        foreach (explode(PATH_SEPARATOR, $openBasedir) as $base) {
            $base = rtrim(trim($base), '/');
            if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{key: string, params: array<string, string>, ok: bool}>
     */
    public function requirements(): array
    {
        $result = [[
            'key' => 'install.req_php',
            'params' => ['required' => self::MIN_PHP, 'found' => PHP_VERSION],
            'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
        ]];
        foreach (self::EXTENSIONS as $ext) {
            $result[] = ['key' => 'install.req_ext', 'params' => ['name' => $ext], 'ok' => extension_loaded($ext)];
        }
        $result[] = ['key' => 'install.req_writable', 'params' => [], 'ok' => is_writable($this->appRoot)];

        return $result;
    }

    public function requirementsMet(): bool
    {
        foreach ($this->requirements() as $req) {
            if (!$req['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{data_dir: string, email: string, password: string, password_confirm: string, timezone: string} $input
     * @return array<string, string> pole => klíč chybové hlášky
     */
    public function validate(array $input): array
    {
        $errors = [];
        $dataDir = $input['data_dir'];
        if (!str_starts_with($dataDir, '/') || str_contains($dataDir, "\0") || preg_match('#(^|/)\.\.(/|$)#', $dataDir)) {
            $errors['data_dir'] = 'install.err_data_dir';
        } elseif ($this->isInCodeDir(rtrim($dataDir, '/'))) {
            $errors['data_dir'] = 'install.err_data_dir_in_app';
        } elseif (is_file(rtrim($dataDir, '/') . '/nordvarr.sqlite')) {
            $errors['data_dir'] = 'install.err_data_dir_used';
        } elseif (!self::canUseDir($dataDir)) {
            $errors['data_dir'] = 'install.err_data_dir_create';
        }
        if (filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'install.err_email';
        }
        if (mb_strlen($input['password']) < Passwords::MIN_LENGTH || strlen($input['password']) > Passwords::MAX_BYTES) {
            $errors['password'] = 'install.err_password';
        } elseif (!hash_equals($input['password'], $input['password_confirm'])) {
            $errors['password_confirm'] = 'install.err_password_confirm';
        }
        if (!in_array($input['timezone'], timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'install.err_timezone';
        }

        return $errors;
    }

    /**
     * Provede instalaci. Vstup musí být předem ověřený přes validate().
     *
     * @param array{data_dir: string, email: string, password: string, password_confirm: string, timezone: string} $input
     */
    public function install(array $input, string $ip = ''): void
    {
        if ($this->isLocked()) {
            throw new RuntimeException('Nordvarr už je nainstalovaný.');
        }
        $dataDir = rtrim($input['data_dir'], '/');
        // Databáze, zálohy a logy nemají být čitelné pro ostatní uživatele serveru.
        $umask = umask(0027);
        try {
            $this->installFiles($dataDir, $input, $ip);
        } finally {
            umask($umask);
        }
    }

    /**
     * @param array{data_dir: string, email: string, password: string, password_confirm: string, timezone: string} $input
     */
    private function installFiles(string $dataDir, array $input, string $ip): void
    {
        $this->prepareDataDir($dataDir);

        $pdo = Database::connectSqlite($dataDir . '/nordvarr.sqlite');
        (new Migrator($pdo, $this->appRoot . '/migrations'))->migrate();
        $users = new UserRepository($pdo);
        $userId = $users->create($input['email'], Passwords::hash($input['password']), 'admin');
        $settings = new SettingsRepository($pdo);
        $settings->set('timezone', $input['timezone']);
        $settings->set('installed_version', self::appVersion($this->appRoot));
        (new AuditLogRepository($pdo))->log('install', $userId, $ip, ['version' => self::appVersion($this->appRoot)]);

        $this->writeConfig($dataDir);
    }

    public function cronLine(): string
    {
        $script = $this->appRoot . '/bin/nordvarr.php';
        if (preg_match('#^[A-Za-z0-9/._-]+$#', $script) !== 1) {
            $script = escapeshellarg($script);
        }

        return '* * * * * php ' . $script . ' worker >/dev/null 2>&1';
    }

    public static function appVersion(string $appRoot): string
    {
        $version = @file_get_contents($appRoot . '/VERSION');

        return $version === false ? 'unknown' : trim($version);
    }

    /**
     * Leží datový adresář uvnitř webrootu?
     */
    public static function isInsideDocumentRoot(string $dataDir, string $documentRoot): bool
    {
        if ($documentRoot === '') {
            return false;
        }
        $docRoot = realpath($documentRoot) ?: $documentRoot;
        $dir = realpath($dataDir) ?: $dataDir;

        return self::isInside($dir, $docRoot);
    }

    /**
     * Ověří přes HTTP, jestli je soubor v datovém adresáři dostupný z webu.
     */
    public static function isReachableFromWeb(string $url): bool
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOBODY => false,
            CURLOPT_RANGE => '0-63',
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return $status >= 200 && $status < 300;
    }

    /**
     * Je cesta adresářem aplikace nebo leží v některé z jejích složek s kódem?
     * Vlastní podadresář aplikace (např. nordvarr-data vedle public/) je povolený.
     */
    private function isInCodeDir(string $path): bool
    {
        $root = rtrim($this->appRoot, '/');
        $real = realpath($root) ?: $root;
        foreach (array_unique([$root, $real]) as $base) {
            if ($path === $base) {
                return true;
            }
            foreach (self::CODE_DIRS as $dir) {
                if (self::isInside($path, $base . '/' . $dir)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isInside(string $path, string $parent): bool
    {
        $parent = rtrim($parent, '/');

        return $path === $parent || str_starts_with($path, $parent . '/');
    }

    private function prepareDataDir(string $dataDir): void
    {
        foreach (['', '/backups', '/logs', '/sessions', '/locks'] as $sub) {
            $dir = $dataDir . $sub;
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException('Adresář nelze vytvořit: ' . $dir);
            }
        }
        @chmod($dataDir, 0750);
        if (!is_writable($dataDir)) {
            throw new RuntimeException('Do datového adresáře nelze zapisovat: ' . $dataDir);
        }
        $htaccess = "# Zákaz přístupu z webu do datového adresáře Nordvarru.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
        file_put_contents($dataDir . '/.htaccess', $htaccess);
        file_put_contents($dataDir . '/index.html', '');
    }

    private function writeConfig(string $dataDir): void
    {
        $config = [
            'app_secret' => bin2hex(random_bytes(32)),
            'data_dir' => $dataDir,
            'debug' => false,
        ];
        $php = "<?php\n\n"
            . "// Vygenerováno instalátorem Nordvarru " . gmdate('Y-m-d H:i') . " UTC.\n"
            . "// Nová verze aplikace tento soubor nikdy nepřepisuje. Tajemství aplikace nesdílejte.\n\n"
            . 'return ' . var_export($config, true) . ";\n";

        // Režim 'x' selže, pokud soubor mezitím vznikl (souběžná instalace).
        $handle = @fopen($this->configFile(), 'x');
        if ($handle === false) {
            throw new RuntimeException('Soubor config.php nelze vytvořit.');
        }
        @chmod($this->configFile(), 0640);
        fwrite($handle, $php);
        fclose($handle);
    }
}
