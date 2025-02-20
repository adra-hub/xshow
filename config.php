<?php
class Config {
    private static $configFile;
    private static $config;

    public static function init() {
        self::$configFile = __DIR__ . '/config/settings.php';
        self::loadConfig();
    }

    private static function loadConfig() {
        if (file_exists(self::$configFile)) {
            self::$config = require self::$configFile;
        } else {
            self::$config = [];
        }
    }

    private static function saveConfig() {
        $configContent = "<?php\nreturn " . var_export(self::$config, true) . ";";
        return file_put_contents(self::$configFile, $configContent);
    }

    public static function isInstalled() {
        self::init();
        return isset(self::$config['installed_at']);
    }

    public static function install($username, $password) {
        self::$config = [
            'installed_at' => date('Y-m-d H:i:s'),
            'users' => [
                $username => self::hashPassword($password)
            ]
        ];
        return self::saveConfig();
    }

    public static function verifyCredentials($username, $password) {
        self::init();
        if (!isset(self::$config['users'][$username])) {
            return false;
        }
        return password_verify($password, self::$config['users'][$username]);
    }

    private static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    // New methods for user management
    public static function getUsers() {
        self::init();
        return isset(self::$config['users']) ? self::$config['users'] : [];
    }

    public static function addUser($username, $password) {
        self::init();
        if (isset(self::$config['users'][$username])) {
            throw new Exception('Username already exists');
        }
        self::$config['users'][$username] = self::hashPassword($password);
        return self::saveConfig();
    }

    public static function updatePassword($username, $password) {
        self::init();
        if (!isset(self::$config['users'][$username])) {
            throw new Exception('User does not exist');
        }
        self::$config['users'][$username] = self::hashPassword($password);
        return self::saveConfig();
    }

    public static function deleteUser($username) {
        self::init();
        if (!isset(self::$config['users'][$username])) {
            throw new Exception('User does not exist');
        }
        if ($username === 'admin') {
            throw new Exception('Cannot delete admin user');
        }
        unset(self::$config['users'][$username]);
        return self::saveConfig();
    }
}